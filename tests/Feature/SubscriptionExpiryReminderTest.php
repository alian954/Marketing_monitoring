<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductPlan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Notifications\SubscriptionExpiringNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SubscriptionExpiryReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ProductCatalogSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-12-10 09:13:00', 'Asia/Taipei'));
        Notification::fake();
    }

    public function test_owner_is_reminded_once_per_end_date_and_again_after_renewal(): void
    {
        $owner = User::factory()->create();
        $item = $this->subscriptionItem($owner, 'website-monitoring', now()->addDays(5));

        $this->artisan('billing:send-expiry-reminders')->assertExitCode(0);
        $this->artisan('billing:send-expiry-reminders')->assertExitCode(0);

        Notification::assertSentToTimes($owner, SubscriptionExpiringNotification::class, 1);
        Notification::assertSentTo($owner, function (SubscriptionExpiringNotification $notification): bool {
            return $notification->expiringProducts[0]['product_name'] === '網站可靠度監測'
                && SubscriptionExpiringNotification::lastServiceDay($notification->expiringProducts[0]['ends_at']) === '2026/12/15';
        });

        $item->update(['ends_at' => now()->addDays(12)]);
        $this->artisan('billing:send-expiry-reminders')->assertExitCode(0);

        Notification::assertSentToTimes($owner, SubscriptionExpiringNotification::class, 2);
    }

    public function test_items_outside_the_window_canceled_or_for_disabled_organizations_are_skipped(): void
    {
        $farOwner = User::factory()->create();
        $this->subscriptionItem($farOwner, 'website-monitoring', now()->addDays(30));
        $canceledOwner = User::factory()->create();
        $this->subscriptionItem($canceledOwner, 'website-monitoring', now()->addDays(3))->update(['status' => 'canceled']);
        $disabledOwner = User::factory()->create();
        $this->subscriptionItem($disabledOwner, 'website-monitoring', now()->addDays(3));
        Organization::query()->where('owner_user_id', $disabledOwner->id)->update(['is_enabled' => false]);

        $this->artisan('billing:send-expiry-reminders')->assertExitCode(0);

        Notification::assertNothingSent();
    }

    public function test_products_expiring_for_one_organization_are_combined_into_one_message(): void
    {
        $owner = User::factory()->create();
        $monitoringItem = $this->subscriptionItem($owner, 'website-monitoring', now()->addDays(4));
        $this->subscriptionItem($owner, 'marketing-insights', now()->addDays(9), $monitoringItem->subscription);

        $this->artisan('billing:send-expiry-reminders')->assertExitCode(0);

        Notification::assertSentToTimes($owner, SubscriptionExpiringNotification::class, 1);
        Notification::assertSentTo($owner, fn (SubscriptionExpiringNotification $notification): bool => count($notification->expiringProducts) === 2);
        $this->assertSame(0, SubscriptionItem::query()->whereNull('expiry_reminder_sent_for')->count());
    }

    private function subscriptionItem(User $owner, string $productCode, CarbonInterface $endsAt, ?Subscription $subscription = null): SubscriptionItem
    {
        $organization = Organization::query()->firstOrCreate(
            ['owner_user_id' => $owner->id],
            ['name' => $owner->name.' 組織', 'is_enabled' => true],
        );
        $product = Product::query()->where('product_code', $productCode)->firstOrFail();
        $plan = ProductPlan::factory()->for($product)->create(['status' => 'active', 'amount_minor' => 1000, 'currency_code' => 'TWD']);
        $subscription ??= Subscription::query()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'current_period_starts_at' => now()->subMonth(),
            'current_period_ends_at' => now()->addMonths(2),
            'cancel_at_period_end' => false,
        ]);

        return SubscriptionItem::query()->create([
            'organization_id' => $organization->id,
            'subscription_id' => $subscription->id,
            'product_id' => $product->id,
            'product_plan_id' => $plan->id,
            'status' => 'active',
            'quantity' => 1,
            'starts_at' => now()->subMonth(),
            'ends_at' => $endsAt,
        ]);
    }
}
