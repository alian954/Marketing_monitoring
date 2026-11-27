<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReportShare;
use App\Models\Monitor;
use App\Models\Product;
use App\Models\StatusPage;
use App\Models\StatusPageSubscription;
use App\Services\ProductRegistry;
use App\Services\ScheduledCommandName;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ProductLifecycleEntryPointTest extends TestCase
{
    use RefreshDatabase;

    /** Central maintenance commands intentionally keep running regardless of product lifecycle. */
    private const CENTRAL_SCHEDULED_COMMANDS = ['monitors:prune', 'auth:clear-resets', 'billing:send-expiry-reminders'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
    }

    public function test_public_status_page_follows_the_monitoring_product_lifecycle(): void
    {
        $token = Str::random(64);
        $statusPage = StatusPage::factory()->create([
            'setting_key' => 'default',
            'public_token' => $token,
            'public_token_hash' => hash('sha256', $token),
            'is_published' => true,
        ]);
        Monitor::factory()->create(['public_status' => true])
            ->forceFill(['user_id' => $statusPage->user_id])
            ->save();
        $url = route('status-pages.public', ['token' => $token]);

        $this->get($url)->assertOk();

        $this->setLifecycle('website-monitoring', 'maintenance');
        $this->get($url)->assertServiceUnavailable();

        $this->setLifecycle('website-monitoring', 'retired');
        $this->get($url)->assertNotFound();
    }

    public function test_recipients_can_still_unsubscribe_after_the_product_is_retired(): void
    {
        $subscription = StatusPageSubscription::factory()->create();
        $this->setLifecycle('website-monitoring', 'retired');
        $unsubscribeUrl = URL::signedRoute('status-page-subscriptions.unsubscribe', ['subscription' => $subscription->id]);

        $this->get($unsubscribeUrl)->assertOk();
        $this->post($unsubscribeUrl)->assertRedirect();

        $this->assertNotNull($subscription->fresh()->unsubscribed_at);
    }

    public function test_public_report_share_follows_the_marketing_product_lifecycle(): void
    {
        $token = str_repeat('a', 64);
        ClientReportShare::factory()->for(Client::factory())->create(['token_hash' => hash('sha256', $token)]);
        $url = route('client-report-shares.public', ['token' => $token]);

        $this->get($url)->assertOk();

        $this->setLifecycle('marketing-insights', 'maintenance');
        $this->get($url)->assertServiceUnavailable();
        $this->get(route('client-report-shares.public.export', ['token' => $token]))->assertServiceUnavailable();

        $this->setLifecycle('marketing-insights', 'retired');
        $this->get($url)->assertNotFound();
    }

    public function test_every_scheduled_command_is_owned_by_a_product_or_declared_central(): void
    {
        $registry = app(ProductRegistry::class);

        foreach ($this->scheduledCommandNames() as $commandName) {
            $this->assertTrue(
                $registry->productCodeForScheduledCommand($commandName) !== null
                    || in_array($commandName, self::CENTRAL_SCHEDULED_COMMANDS, true),
                "Scheduled command [{$commandName}] must be declared by a product manifest or listed as central.",
            );
        }

        foreach ($registry->all() as $manifest) {
            foreach ($manifest['scheduled_commands'] ?? [] as $declaredCommand) {
                $this->assertContains($declaredCommand, $this->scheduledCommandNames(), "Declared command [{$declaredCommand}] is not scheduled.");
            }
        }
    }

    public function test_retired_product_stops_its_scheduled_work_while_maintenance_and_central_jobs_continue(): void
    {
        $this->assertTrue($this->scheduledEvent('monitors:dispatch-due')->filtersPass($this->app));

        $this->setLifecycle('website-monitoring', 'maintenance');
        $this->assertTrue($this->scheduledEvent('monitors:dispatch-due')->filtersPass($this->app));

        $this->setLifecycle('website-monitoring', 'retired');
        $this->assertFalse($this->scheduledEvent('monitors:dispatch-due')->filtersPass($this->app));
        $this->assertFalse($this->scheduledEvent('monitors:dispatch-tls')->filtersPass($this->app));
        $this->assertTrue($this->scheduledEvent('google-analytics:sync-due')->filtersPass($this->app));
        $this->assertTrue($this->scheduledEvent('monitors:prune')->filtersPass($this->app));

        $this->setLifecycle('marketing-insights', 'retired');
        $this->assertFalse($this->scheduledEvent('google-ads:sync-due')->filtersPass($this->app));
        $this->assertTrue($this->scheduledEvent('auth:clear-resets')->filtersPass($this->app));
    }

    public function test_a_scheduled_command_cannot_be_claimed_by_two_products(): void
    {
        $manifests = config('products');
        $manifests['marketing-insights']['scheduled_commands'][] = 'monitors:dispatch-due';

        $this->expectException(InvalidArgumentException::class);

        (new ProductRegistry($manifests))->all();
    }

    private function setLifecycle(string $productCode, string $lifecycle): void
    {
        Product::query()->where('product_code', $productCode)->update(['lifecycle' => $lifecycle]);
    }

    /** @return list<string> */
    private function scheduledCommandNames(): array
    {
        return collect(app(Schedule::class)->events())
            ->map(static fn (Event $event): ?string => ScheduledCommandName::fromEvent($event))
            ->filter()
            ->values()
            ->all();
    }

    private function scheduledEvent(string $commandName): Event
    {
        return collect(app(Schedule::class)->events())
            ->first(static fn (Event $event): bool => ScheduledCommandName::fromEvent($event) === $commandName)
            ?? $this->fail("Scheduled command [{$commandName}] not found.");
    }
}
