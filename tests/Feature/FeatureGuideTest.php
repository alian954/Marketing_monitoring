<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureGuideTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_guide_is_available_to_authenticated_users(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('features.index'))
            ->assertOk()
            ->assertSee('<h1 class="page-title">功能說明</h1>', false)
            ->assertSee('監測範圍')
            ->assertSee('公開的互動數')
            ->assertSee('不提供帳號洞察')
            ->assertSee('可依平台或活動標籤篩選貼文、互動量排行、彙總與 CSV')
            ->assertSee('Dcard 以 Playwright Chromium 渲染公開頁面')
            ->assertSee('並列比較 Facebook、Instagram、Threads 與 Dcard 的公開互動數')
            ->assertSee('公開互動數、每篇平均互動與觀看互動率')
            ->assertSee('標籤可依平均互動或觀看互動率排序')
            ->assertSee('排行榜列出互動總數最高的前五篇')
            ->assertSee('近 7／30／90 天增量及前期同篇變化')
            ->assertSee('首頁圖表顯示每日互動淨變化')
            ->assertSee('貼文清單每頁 50 篇並可分頁')
            ->assertSee('單篇歷史可查看期初至期末淨變化並匯出 CSV')
            ->assertSee('文字與分享資訊比對')
            ->assertSee('可用率與 SLO')
            ->assertSee('CSV 匯入')
            ->assertSee('公開狀態頁與資料匯出')
            ->assertSee(route('features.index'))
            ->assertSee('<title>功能說明 · 監測台</title>', false);
    }

    public function test_feature_guide_requires_authentication(): void
    {
        $this->get(route('features.index'))
            ->assertRedirect(route('login'));
    }
}
