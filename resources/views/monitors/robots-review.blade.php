@extends('layouts.app')

@section('title', 'robots.txt 爬蟲檢視')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.index') }}">監測項目</a><span aria-hidden="true">/</span>
        <a href="{{ route('monitors.show', $monitor) }}">{{ $monitor->name }}</a><span aria-hidden="true">/</span>
        <span aria-current="page">robots.txt</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">搜尋與分享爬蟲</p>
            <h1 class="page-title">robots.txt</h1>
            <p class="page-description">查看「{{ $monitor->name }}」的 robots.txt 規則與爬蟲可讀性每日檢查。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('monitors.show', $monitor) }}">返回監測項目</a>
        </div>
    </div>

    @if ($isWebsiteMonitor)
        <section class="panel" aria-labelledby="robots-observation-title">
            <div class="panel-header">
                <div>
                    <h2 id="robots-observation-title" class="panel-title">每日爬蟲可讀性</h2>
                    <p class="panel-description">每天安全擷取一次網站根目錄的 robots.txt，檢查此監測網址路徑是否允許常見搜尋與分享爬蟲。</p>
                </div>
            </div>
            <div class="panel-body">
                @if ($latestObservation !== null)
                    @php
                        $crawlerLabels = [
                            'Googlebot' => 'Google 搜尋',
                            'AdsBot-Google' => 'Google Ads',
                            'FacebookExternalHit' => 'Facebook 分享預覽',
                            'Twitterbot' => 'Twitter 分享預覽',
                        ];
                        $crawlerDecisions = $latestObservation->crawler_decisions ?? [];
                        $observationState = match ($latestObservation->file_state) {
                            'available' => '已讀取 robots.txt',
                            'missing' => '找不到 robots.txt，未發現爬蟲限制',
                            'unavailable' => 'HTTP '.$latestObservation->http_status.'，無法判定規則',
                            'error' => '無法完成安全擷取'.($latestObservation->error_type === null ? '' : '（'.$latestObservation->error_type.'）'),
                            default => '尚未檢查',
                        };
                    @endphp

                    <p class="status-copy"><strong>{{ $observationState }}</strong>
                        @if ($latestObservation->checked_at !== null)
                            <span> · 最近檢查 {{ $latestObservation->checked_at->timezone(config('app.timezone'))->format('Y/m/d H:i') }}</span>
                        @endif
                    </p>

                    @if ($latestObservation->policy_changed === true)
                        <div class="alert alert-warning spaced-top" role="status">robots.txt 政策與前一次有效觀測不同。</div>
                    @elseif ($latestObservation->policy_changed === false)
                        <p class="form-note spaced-top">robots.txt 政策與前一次有效觀測相同。</p>
                    @elseif ($latestObservation->policy_hash !== null)
                        <p class="form-note spaced-top">這是目前設定版本的第一筆有效觀測，尚無前次政策可比較。</p>
                    @endif

                    @if ($crawlerDecisions !== [])
                        <dl class="metadata-list spaced-top">
                            @foreach ($crawlerLabels as $crawler => $label)
                                @php
                                    $decision = $crawlerDecisions[$crawler] ?? null;
                                @endphp
                                <div class="metadata-item">
                                    <dt>{{ $label }}（{{ $crawler }}）</dt>
                                    <dd>
                                        @if (is_array($decision))
                                            {{ ($decision['allowed'] ?? false) ? '允許' : '封鎖' }}
                                            @if (is_string($decision['matching_rule'] ?? null))
                                                · <code>{{ $decision['matching_rule'] }}</code>
                                            @endif
                                        @else
                                            未能判定
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="form-note spaced-top">規則數 {{ $latestObservation->rule_count ?? 0 }}；Sitemap 數 {{ $latestObservation->sitemap_count ?? 0 }}。結果依監測網址路徑模擬 robots.txt 規則，不代表爬蟲實際造訪或官方平台檢查。</p>
                    @endif
                @else
                    <h3>尚無檢查結果</h3>
                    <p class="form-note">下一次每日排程執行後，這裡會顯示四種爬蟲的路徑判讀與政策基準。</p>
                @endif

                @if ($observations->isNotEmpty())
                    <div class="table-wrap spaced-top">
                        <table>
                            <caption>{{ $monitor->name }} 最近 30 次 robots.txt 檢查</caption>
                            <thead>
                                <tr>
                                    <th scope="col">檢查時間</th>
                                    <th scope="col">結果</th>
                                    <th scope="col">被封鎖的爬蟲</th>
                                    <th scope="col">政策變更</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($observations as $observation)
                                    @php
                                        $blockedCrawlers = collect($observation->crawler_decisions ?? [])
                                            ->filter(static fn (mixed $decision): bool => is_array($decision) && ($decision['allowed'] ?? true) === false)
                                            ->keys()
                                            ->all();
                                    @endphp
                                    <tr>
                                        <td>{{ $observation->checked_at?->timezone(config('app.timezone'))->format('Y/m/d H:i') ?? $observation->observed_on->format('Y/m/d') }}</td>
                                        <td>{{ $observation->file_state === 'available' ? '已讀取' : ($observation->file_state === 'missing' ? '檔案不存在' : ($observation->error_type ?? '無法判定')) }}{{ $observation->http_status === null ? '' : ' · HTTP '.$observation->http_status }}</td>
                                        <td>{{ $blockedCrawlers === [] ? '無／未判定' : implode('、', $blockedCrawlers) }}</td>
                                        <td>{{ $observation->policy_changed === true ? '有' : ($observation->policy_changed === false ? '無' : '基準／未知') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </section>
    @else
        <div class="alert alert-info" role="status">每日 robots.txt 分析只適用一般網站監測；社群平台貼文網址不會拿平台本身的 robots.txt 判定貼文可見性。</div>
    @endif

    <section class="panel spaced-top" aria-labelledby="robots-review-title">
        <div class="panel-header">
            <div>
                <h2 id="robots-review-title" class="panel-title">人工檢視 robots.txt</h2>
                <p class="panel-description">若要檢視完整原始規則，可在新分頁開啟網站根目錄檔案。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($robotsUrl !== null)
                <dl class="metadata-list">
                    <div class="metadata-item">
                        <dt>網站來源</dt>
                        <dd><code>{{ $origin }}</code></dd>
                    </div>
                    <div class="metadata-item">
                        <dt>檢視路徑</dt>
                        <dd><code>/robots.txt</code></dd>
                    </div>
                </dl>
                <div class="form-actions spaced-top">
                    <a class="button button-primary" href="{{ $robotsUrl }}" target="_blank" rel="noopener noreferrer">在新分頁開啟 robots.txt</a>
                </div>
            @else
                <div class="alert alert-error" role="alert">無法從此監測網址建立 robots.txt 檢視連結。</div>
            @endif
        </div>
    </section>
@endsection
