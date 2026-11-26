<?php

namespace Tests\Feature;

use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\MetaMarketingConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScheduledSyncHealthIntegrationStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_connected_data_source_explains_the_first_scheduled_sync(): void
    {
        config([
            'services.google_analytics.client_id' => 'google-analytics-client-id',
            'services.google_analytics.client_secret' => 'google-analytics-client-secret',
            'services.google_ads.client_id' => 'google-ads-client-id',
            'services.google_ads.client_secret' => 'google-ads-client-secret',
            'services.meta_marketing.client_id' => 'meta-client-id',
            'services.meta_marketing.client_secret' => 'meta-client-secret',
        ]);
        $user = User::factory()->create();

        GoogleAnalyticsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'ga-refresh-token',
        ]);
        MetaMarketingConnection::query()->create([
            'user_id' => $user->id,
            'access_token' => 'meta-access-token',
        ]);
        GoogleAdsConnection::query()->create([
            'user_id' => $user->id,
            'refresh_token' => 'ads-refresh-token',
        ]);

        $this->actingAs($user)
            ->get(route('integrations.google-analytics.edit'))
            ->assertOk()
            ->assertSee('帳戶已連結。')
            ->assertSee('尚無紀錄')
            ->assertSee('活動目標後，排程每日')
            ->assertSee('<time datetime="06:30">06:30</time>（台北時間）', false)
            ->assertDontSee('同步正常');

        $this->get(route('integrations.meta-marketing.edit'))
            ->assertOk()
            ->assertSee('Meta 帳戶已連結。')
            ->assertSee('尚無紀錄')
            ->assertSee('<time datetime="06:40">06:40</time>（台北時間）', false)
            ->assertDontSee('同步正常');

        $this->get(route('integrations.google-ads.edit'))
            ->assertOk()
            ->assertSee('Google Ads 帳戶已連結。')
            ->assertSee('尚無紀錄')
            ->assertSee('<time datetime="06:50">06:50</time>（台北時間）', false)
            ->assertDontSee('同步正常');
    }
}
