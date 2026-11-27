<?php

namespace Tests\Feature;

use App\Models\ClientStoreOrder;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\ClientStoreRevenueSummary;
use App\Services\MarketingCsvReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ClientStoreRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-10 12:00:00');
    }

    public function test_order_export_with_chinese_headers_is_imported_once_per_order(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '電商客戶']);
        $csv = "\xEF\xBB\xBF訂單編號,訂單日期,訂單狀態,訂單金額 (NT$),是否新客\n"
            ."A1001,2026/11/03 10:15,已完成,\"NT$1,200\",是\n"
            ."A1002,2026-11-04,已取消,800,否\n"
            ."A1003,2026年11月05日,已完成,1500,否\n"
            ."A1004,昨天,已完成,500,否\n";

        $this->actingAs($owner)
            ->post(route('clients.store-revenue.store', $client), [
                'csv_file' => UploadedFile::fake()->createWithContent('orders.csv', $csv),
                'currency_code' => 'twd',
                'source_label' => '官網',
            ])
            ->assertRedirect(route('clients.store-revenue.index', $client))
            ->assertSessionHas('importResult', fn (array $result): bool => $result['mode'] === 'orders'
                && $result['imported'] === 2
                && $result['skipped_cancelled'] === 1
                && $result['error_count'] === 1
                && $result['revenue_by_currency'] == ['TWD' => 2700]);

        $this->assertSame(['order:A1001', 'order:A1003'], $client->storeOrders()->orderBy('order_reference')->pluck('order_reference')->all());
        $this->assertSame(1, $client->storeOrders()->where('order_reference', 'order:A1001')->value('new_customer_orders'));

        $this->post(route('clients.store-revenue.store', $client), [
            'csv_file' => UploadedFile::fake()->createWithContent('orders.csv', "Order ID,Created at,Total\nA1001,2026-11-03,1300\n"),
            'currency_code' => 'TWD',
        ]);

        $this->assertSame(2, $client->storeOrders()->count(), 'Re-importing an order updates it.');
        $this->assertSame('1300.00', $client->storeOrders()->where('order_reference', 'order:A1001')->value('revenue'));

        $this->get(route('clients.store-revenue.index', $client))
            ->assertOk()
            ->assertSee('2026-11')
            ->assertSee('2,800 TWD');
    }

    public function test_daily_summary_and_big5_files_are_supported(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '電商客戶']);
        $csv = mb_convert_encoding("日期\t營收\t訂單數\t新客數\n2026-11-03\t10000\t20\t5\n2026-11-03\t2000\t4\t1\n2026-11-04\t8000\t16\t4\n", 'BIG-5', 'UTF-8');

        $this->actingAs($owner)
            ->post(route('clients.store-revenue.store', $client), [
                'csv_file' => UploadedFile::fake()->createWithContent('daily.csv', $csv),
                'currency_code' => 'TWD',
            ])
            ->assertSessionHas('importResult', fn (array $result): bool => $result['mode'] === 'daily' && $result['imported'] === 2);

        $firstDay = $client->storeOrders()->whereDate('ordered_on', '2026-11-03')->sole();
        $this->assertSame('12000.00', $firstDay->revenue);
        $this->assertSame(24, $firstDay->orders_count);
        $this->assertSame(6, $firstDay->new_customer_orders);
    }

    public function test_files_without_required_columns_are_rejected(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '電商客戶']);

        $this->actingAs($owner)
            ->post(route('clients.store-revenue.store', $client), [
                'csv_file' => UploadedFile::fake()->createWithContent('bad.csv', "商品,數量\n毛巾,3\n"),
                'currency_code' => 'TWD',
            ])
            ->assertSessionHasErrors('csv_file');

        $this->assertDatabaseCount('client_store_orders', 0);
    }

    public function test_orders_in_a_date_range_can_be_deleted_and_access_is_scoped(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '電商客戶']);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-11-03']);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-10-03']);
        $member = User::factory()->create();
        WorkspaceMembership::factory()->create([
            'owner_user_id' => $owner->id,
            'member_user_id' => $member->id,
            'role' => WorkspaceMembership::ROLE_READ_ONLY,
            'client_scope' => WorkspaceMembership::CLIENT_SCOPE_ALL,
        ]);

        $this->actingAs($member)
            ->delete(route('clients.store-revenue.destroy', $client), ['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertForbidden();
        $this->actingAs(User::factory()->create())
            ->get(route('clients.store-revenue.index', $client))
            ->assertNotFound();

        $this->actingAs($owner)
            ->delete(route('clients.store-revenue.destroy', $client), ['from' => '2026-11-01', 'to' => '2026-11-30'])
            ->assertSessionHas('status', '已刪除 1 筆營收資料。');
        $this->assertSame(1, $client->storeOrders()->count());
    }

    public function test_summary_calculates_mer_new_customer_cost_and_attribution_gap(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create(['name' => '電商客戶']);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-11-03', 'revenue' => '6000', 'new_customer_orders' => 1]);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-11-04', 'revenue' => '4000', 'new_customer_orders' => 0]);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-11-04', 'revenue' => '50', 'currency_code' => 'USD']);
        ClientStoreOrder::factory()->create(['client_id' => $client->id, 'ordered_on' => '2026-11-20', 'revenue' => '9999']);
        $overview = [
            'primary_currency_code' => 'TWD',
            'currency_groups' => [['currency_code' => 'TWD', 'total' => ['spend' => 2500.0, 'conversion_value' => 15000.0]]],
        ];

        $summary = app(ClientStoreRevenueSummary::class)->build($client, '2026-11-01', '2026-11-09', $overview);

        $this->assertSame(10000.0, $summary['revenue']);
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(4.0, $summary['mer']);
        $this->assertSame(1, $summary['new_customer_orders']);
        $this->assertSame(2500.0, $summary['new_customer_acquisition_cost']);
        $this->assertSame(1.5, $summary['platform_to_store_revenue_ratio']);
        $this->assertSame(['USD' => 50.0], $summary['other_currency_revenue']);
        $this->assertSame(['2026-11-03' => 6000.0, '2026-11-04' => 4000.0], $summary['daily_revenue']);

        $this->actingAs($owner)
            ->get(route('clients.cross-platform', ['client' => $client, 'from' => '2026-11-01', 'to' => '2026-11-09']))
            ->assertOk()
            ->assertSee('實際營收與 MER')
            ->assertSee('10,000');
    }

    public function test_csv_value_parsers(): void
    {
        $this->assertSame(1234.5, MarketingCsvReader::number('NT$1,234.50'));
        $this->assertSame(-120.0, MarketingCsvReader::number('(120)'));
        $this->assertNull(MarketingCsvReader::number('--'));
        $this->assertSame('2026-11-05', MarketingCsvReader::date('20261105'));
        $this->assertSame('2026-11-05', MarketingCsvReader::date('2026/11/5 23:10'));
        $this->assertSame('2026-11-06', MarketingCsvReader::date('2026-11-05T18:30:00Z'));
        $this->assertNull(MarketingCsvReader::date('2026-02-30'));
        $this->assertTrue(MarketingCsvReader::boolean('是'));
        $this->assertFalse(MarketingCsvReader::boolean('回購'));
        $this->assertNull(MarketingCsvReader::boolean(''));
    }
}
