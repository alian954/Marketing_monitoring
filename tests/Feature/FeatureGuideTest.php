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
            ->assertSee('可依平台或活動標籤篩選貼文')
            ->assertSee('Dcard 以 Chrome 渲染公開頁面')
            ->assertSee('單篇與標籤觀看互動率')
            ->assertSee('近 7／30／90 天增量及前期同篇變化')
            ->assertSee('貼文清單每頁 50 篇並可分頁')
            ->assertSee('標籤可依互動率排序')
            ->assertSee('可依平台或活動標籤篩選貼文、彙總與 CSV')
            ->assertSee('單篇歷史可切換期間、查看期初至期末淨變化並匯出 CSV')
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
