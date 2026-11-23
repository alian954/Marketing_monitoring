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
            ->assertSee('頁面公開互動數')
            ->assertSee('活動報告可連結 GA4 API')
            ->assertSee('Meta Marketing API 讀取已授權廣告帳戶的花費、曝光、觸及、點擊、行動數量與轉換價值')
            ->assertSee('Google Ads API 讀取客戶帳戶')
            ->assertSee('手動記錄投放花費')
            ->assertSee('只有廣告花費與 GA4 營收幣別相同時才計算 ROAS')
            ->assertSee('活動報告顯示 Meta Ads 的頻率、CTR、CPC、CPM')
            ->assertSee('各行動類型數量與轉換價值')
            ->assertSee('Google Ads 的轉換動作、每次轉換成本與點擊轉換率')
            ->assertSee('Google Ads 搜尋與購物活動提供每日搜尋曝光、預算流失及排名流失比重')
            ->assertSee('CSV 保留原始標記')
            ->assertSee('各平台行動數據依帳戶追蹤與歸因設定回傳')
            ->assertSee('兩平台歷史趨勢可匯出 CSV')
            ->assertSee('資料超過 24 小時未更新時會提示')
            ->assertSee('跨平台花費僅同幣別合計')
            ->assertSee('活動平均每日消耗僅供參考')
            ->assertSee('可依平台或活動標籤篩選貼文、互動排行與彙總，並匯出 CSV')
            ->assertSee('互動數擷取覆蓋率依近 7／30 天取得 HTTP 狀態碼')
            ->assertSee('發文時間成效依台灣時間分為四個時段')
            ->assertSee('貼文年齡成效比較發文後 24 小時、7 天及 30 天')
            ->assertSee('平均與中位數互動、觀看互動率')
            ->assertSee('活動互動目標可依標籤與平台設定期間')
            ->assertSee('進行中有完整讀值時，以尚差互動數除以剩餘活動日並進位')
            ->assertSee('每日所需互動可匯出 CSV')
            ->assertSee('Dcard 先讀公開 HTML，互動數不完整且未遇限制時才以 Playwright Chromium 渲染')
            ->assertSee('取得監測同意後，可向 Dcard 提供背景工作出口 IP 申請放行')
            ->assertSee('遇到驗證時不會自動處理，預設延後 6 小時再檢查')
            ->assertSee('支援比較 Facebook、Instagram、Threads 與 Dcard 的公開互動數')
            ->assertSee('Instagram 與 Dcard 以公開的讚與留言計')
            ->assertSee('全部平台的互動排行與彙總只計 Facebook／Threads 的完整三項數據')
            ->assertSee('公開互動數、每篇平均互動及觀看互動率')
            ->assertSee('貼文排行可依互動合計或觀看互動率排序；標籤排行可依平均互動或觀看互動率排序')
            ->assertSee('首頁顯示近 7／30／90 天每日互動淨變化')
            ->assertSee('前五篇貼文變化、互動成長率及同篇前期變化差；單篇排行可依淨變化或成長率排序')
            ->assertSee('完整排行可匯出 CSV')
            ->assertSee('前期比較只計兩期都有讀值的同項指標')
            ->assertSee('期初互動量為零時不計成長率')
            ->assertSee('貼文清單每頁 50 篇並可分頁')
            ->assertSee('單篇歷史可查看期初至期末變化並匯出 CSV')
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
