@extends('layouts.public-status')

@section('title', $statusPage->title)

@section('content')
    @php
        $summary = match ($pageState) {
            'healthy' => ['label' => '所有服務正常', 'description' => '目前沒有偵測到服務異常。'],
            'issue' => ['label' => '部分服務受到影響', 'description' => '至少一個服務目前有異常。'],
            default => ['label' => '部分服務需要留意', 'description' => '部分服務回應變慢、檢查逾時或尚未完成檢查。'],
        };
    @endphp

    <header class="public-status-header">
        <a class="public-status-brand" href="{{ route('status-pages.public', ['token' => request()->route('token')]) }}" aria-label="{{ $statusPage->title }}狀態首頁">
            <span class="brand-mark" aria-hidden="true">M</span>
            <span>服務狀態</span>
        </a>
        <span class="secondary-text">即時監測</span>
    </header>

    <section class="public-status-overview" aria-labelledby="public-status-title">
        <div class="public-status-title-row">
            <div>
                <p class="eyebrow">服務狀態</p>
                <h1 id="public-status-title" class="page-title">{{ $statusPage->title }}</h1>
            </div>
            <x-state-badge :state="$pageState" />
        </div>
        <p class="public-status-summary">{{ $summary['label'] }}</p>
        <p class="public-status-description">{{ $summary['description'] }}</p>
        @if ($lastCheckedAt)
            <p class="public-status-updated">最近更新：<time datetime="{{ $lastCheckedAt->toIso8601String() }}">{{ $lastCheckedAt->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}（台灣時間）</time></p>
        @else
            <p class="public-status-updated">監測項目尚未完成第一次檢查。</p>
        @endif
    </section>

    <section class="panel public-status-panel" aria-labelledby="public-status-services-title">
        <div class="panel-header">
            <div>
                <h2 id="public-status-services-title" class="panel-title">監測服務</h2>
                <p class="panel-description">此頁只公開所選服務的名稱、狀態、檢查時間、近 90 天檢查成功率與估算時間可用率，以及目前事件與近 30 天已恢復事件；不會公開網址、比對文字或事件備註。社群貼文未設定預期文字且未啟用可見文字基準時不計入統計；啟用基準後，首次可比較回應只建立基準，之後依文字變更結果計算。</p>
            </div>
        </div>
        @foreach ($monitorGroups as $groupKey => $group)
            <section class="public-status-service-group" aria-labelledby="public-status-services-{{ $groupKey }}-title">
                <h3 class="public-status-service-group-title" id="public-status-services-{{ $groupKey }}-title">{{ $group['label'] }}</h3>
                <div class="public-status-service-list">
                    @foreach ($group['monitors'] as $monitor)
                        <article class="public-status-service">
                            <div class="public-status-service-header">
                                <div class="public-status-service-name">
                                    <h4>{{ $monitor->name }}</h4>
                                    @if ($monitor->last_checked_at)
                                        <p>最近檢查 <time datetime="{{ $monitor->last_checked_at->toIso8601String() }}">{{ $monitor->last_checked_at->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time></p>
                                    @else
                                        <p>尚未完成第一次檢查</p>
                                    @endif
                                </div>
                                <x-state-badge :state="$monitor->health_state" />
                            </div>

                            <div class="public-status-history">
                                <div class="public-status-history-summary">
                                    <span>近 90 天檢查成功率</span>
                                    <strong>{{ $monitor->public_history['success_rate'] === null ? '—' : number_format($monitor->public_history['success_rate'], 2).'%' }}</strong>
                                    <span class="secondary-text">成功 {{ $monitor->public_history['successful_checks'] }} / 可判定 {{ $monitor->public_history['total_checks'] }} 次</span>
                                </div>
                                <div class="public-status-history-summary">
                                    <span>近 90 天估算時間可用率</span>
                                    <strong>{{ $monitor->public_history['availability']['availability_rate'] === null ? '—' : number_format($monitor->public_history['availability']['availability_rate'], 2).'%' }}</strong>
                                    <span class="secondary-text">
                                        資料涵蓋 {{ $monitor->public_history['availability']['coverage_rate'] === null ? '—' : number_format($monitor->public_history['availability']['coverage_rate'], 2).'%' }}
                                        （{{ number_format($monitor->public_history['availability']['observed_minutes'], 1) }} / {{ number_format($monitor->public_history['availability']['eligible_minutes'], 1) }} 分鐘）
                                    </span>
                                </div>
                                <div class="public-status-history-chart-scroll" role="region" aria-label="{{ $monitor->name }}近 90 天每日檢查狀態圖，向左右捲動查看" tabindex="0">
                                    <ol class="public-status-history-bars public-status-history-bars--90-day" role="list" aria-label="{{ $monitor->name }}近 90 天每日檢查狀態">
                                        @foreach ($monitor->public_history['days'] as $day)
                                            <li>
                                                <span class="public-status-history-bar public-status-history-bar--{{ $day['state'] }}" role="img" aria-label="{{ $day['accessible_label'] }}"></span>
                                            </li>
                                        @endforeach
                                    </ol>
                                    <div class="public-status-history-axis public-status-history-axis--90-day" aria-hidden="true">
                                        <span>{{ $monitor->public_history['days'][0]['display_date'] }}</span>
                                        <span>今天</span>
                                    </div>
                                </div>
                                <details class="public-status-history-details">
                                    <summary>查看每日檢查明細</summary>
                                    <div class="data-table-wrap">
                                        <table class="data-table">
                                            <caption class="sr-only">{{ $monitor->name }}近 90 天每日檢查成功率</caption>
                                            <thead>
                                                <tr>
                                                    <th scope="col">日期（台灣時間）</th>
                                                    <th scope="col">成功率</th>
                                                    <th scope="col">成功次數</th>
                                                    <th scope="col">可判定檢查</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($monitor->public_history['days'] as $day)
                                                    <tr>
                                                        <th scope="row"><time datetime="{{ $day['date'] }}">{{ $day['date'] }}</time></th>
                                                        <td>{{ $day['success_rate'] === null ? '無檢查資料' : number_format($day['success_rate'], 2).'%' }}</td>
                                                        <td>{{ $day['successful_checks'] }}</td>
                                                        <td>{{ $day['total_checks'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </section>

    <section class="panel public-status-panel" aria-labelledby="public-status-maintenance-title">
        <div class="panel-header">
            <div>
                <h2 id="public-status-maintenance-title" class="panel-title">預定維護</h2>
                <p class="panel-description">顯示所選公開服務目前及未來 30 天內的維護時段；不公開內部維護備註。</p>
            </div>
        </div>

        @if ($publicMaintenanceWindows->isEmpty())
            <p class="panel-description public-status-incidents-empty">近期沒有預定維護時段。</p>
        @else
            <ul class="public-status-incident-list public-status-maintenance-list" role="list">
                @foreach ($publicMaintenanceWindows as $maintenance)
                    <li class="public-status-incident">
                        <div class="public-status-incident-header">
                            <h4>{{ $maintenance['monitor_name'] }}</h4>
                            <span class="secondary-text">{{ $maintenance['state'] === 'active' ? '維護中' : '即將維護' }}</span>
                        </div>
                        <p class="public-status-incident-meta">
                            <span>開始：<time datetime="{{ $maintenance['starts_at']->toIso8601String() }}">{{ $maintenance['starts_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>（台灣時間）</span>
                            <span>結束：<time datetime="{{ $maintenance['ends_at']->toIso8601String() }}">{{ $maintenance['ends_at']->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>（台灣時間）</span>
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="panel public-status-panel" aria-labelledby="public-status-incidents-title">
        <div class="panel-header">
            <div>
                <h2 id="public-status-incidents-title" class="panel-title">近期事件</h2>
                <p class="panel-description">顯示目前進行中的已確認事件，以及近 30 天最近 20 筆已恢復紀錄；不公開事件備註。</p>
            </div>
        </div>

        @if ($activePublicIncidents->isEmpty() && $recentResolvedPublicIncidents->isEmpty())
            <p class="panel-description public-status-incidents-empty">近 30 天沒有已確認或已恢復的事件。</p>
        @else
            <div class="public-status-incident-groups">
                @if ($activePublicIncidents->isNotEmpty())
                    <div class="public-status-incident-group">
                        <h3>進行中</h3>
                        <ul class="public-status-incident-list" role="list">
                            @foreach ($activePublicIncidents as $incident)
                                <li class="public-status-incident">
                                    <div class="public-status-incident-header">
                                        <h4>{{ $incident['monitor_name'] }}</h4>
                                        <x-state-badge :state="$incident['severity_state']" />
                                    </div>
                                    <p class="public-status-incident-meta">
                                        @if ($incident['opened_at'])
                                            <span>開始時間：<time datetime="{{ $incident['opened_at']->toIso8601String() }}">{{ $incident['opened_at']->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>（台灣時間）</span>
                                        @endif
                                        @if ($incident['last_seen_at'])
                                            <span>最近確認：<time datetime="{{ $incident['last_seen_at']->toIso8601String() }}">{{ $incident['last_seen_at']->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>（台灣時間）</span>
                                        @endif
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($recentResolvedPublicIncidents->isNotEmpty())
                    <div class="public-status-incident-group">
                        <h3>已恢復</h3>
                        <ul class="public-status-incident-list" role="list">
                            @foreach ($recentResolvedPublicIncidents as $incident)
                                <li class="public-status-incident">
                                    <div class="public-status-incident-header">
                                        <h4>{{ $incident['monitor_name'] }}</h4>
                                        <x-state-badge state="resolved" />
                                    </div>
                                    <p class="public-status-incident-meta">
                                        <span>開始時間：<time datetime="{{ $incident['opened_at']->toIso8601String() }}">{{ $incident['opened_at']->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>（台灣時間）</span>
                                        <span>恢復時間：<time datetime="{{ $incident['resolved_at']->toIso8601String() }}">{{ $incident['resolved_at']->timezone('Asia/Taipei')->format('Y/n/j H:i:s') }}</time>（台灣時間）</span>
                                    </p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </section>

    <section class="panel public-status-panel" aria-labelledby="public-status-subscription-title">
        <div class="panel-header">
            <div>
                <h2 id="public-status-subscription-title" class="panel-title">訂閱服務更新</h2>
                <p class="panel-description">確認電子郵件後，服務異常或恢復時會收到通知。每封通知都附有取消訂閱連結。</p>
            </div>
        </div>
        <div class="panel-body">
            @if (session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif

            <form action="{{ route('status-page-subscriptions.subscribe', ['token' => request()->route('token')]) }}" method="POST" aria-labelledby="public-status-subscription-title">
                @csrf
                <div class="form-grid">
                    <div class="field-group field-group-full">
                        <label class="field-label" for="subscriber-email">電子郵件地址<span class="required-mark" aria-hidden="true">*</span></label>
                        <input class="field-input @error('email') is-invalid @enderror" id="subscriber-email" name="email" type="email" inputmode="email" autocomplete="email" autocapitalize="none" spellcheck="false" maxlength="254" value="{{ old('email') }}" required aria-describedby="subscriber-email-help{{ $errors->has('email') ? ' subscriber-email-error' : '' }}" @if ($errors->has('email')) aria-invalid="true" @endif>
                        <p id="subscriber-email-help" class="field-help">我們會先寄出確認信；尚未確認的地址不會收到服務更新。</p>
                        @error('email')<p id="subscriber-email-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                </div>

                <div class="status-subscription-honeypot" aria-hidden="true">
                    <label for="subscriber-website">請勿填寫此欄位</label>
                    <input id="subscriber-website" name="website" type="text" tabindex="-1" autocomplete="off">
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit">訂閱事件更新</button>
                </div>
            </form>
        </div>
    </section>

    <p class="public-status-disclaimer">此頁只顯示已選取服務的即時狀態、近 90 天彙總檢查結果與估算時間可用率、公開事件時間及預定維護時段，不包含網站網址、比對文字或內部事件備註。</p>
@endsection
