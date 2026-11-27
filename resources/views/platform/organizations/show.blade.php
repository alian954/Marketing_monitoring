@extends('layouts.platform')

@section('title', $organization->name.' · 組織詳情')

@section('content')
    @php
        $severityLabels = ['critical' => '嚴重', 'warning' => '警告', 'info' => '資訊'];
        $stateLabels = [
            \App\Models\Incident::STATE_SUSPECT => '待確認',
            \App\Models\Incident::STATE_OPEN => '進行中',
            \App\Models\Incident::STATE_RESOLVED => '已恢復',
            \App\Models\Incident::STATE_PAUSED => '已暫停',
            \App\Models\Incident::STATE_SUPERSEDED => '已取代',
        ];
        $formatLimit = static fn (?int $limit): string => $limit === null ? '不限' : number_format($limit);
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('platform.index') }}">組織管理</a><span aria-hidden="true">/</span>
        <span aria-current="page">{{ $organization->name }}</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Organization overview</p>
            <h1 class="page-title">{{ $organization->name }}</h1>
            <p class="page-description">唯讀檢視，供客服與營運查詢使用；調整方案與狀態請回到組織管理，客戶資料由組織成員在客戶平台維護。</p>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('platform.organizations.billing', $organization) }}">訂單與收款</a>
            <a class="button button-secondary" href="{{ route('platform.index') }}">返回組織管理</a>
        </div>
    </div>

    <section class="panel" aria-labelledby="organization-profile-title">
        <div class="panel-header">
            <h2 class="panel-title" id="organization-profile-title">基本資料</h2>
        </div>
        <div class="panel-body">
            <dl class="platform-detail-list">
                <div>
                    <dt>狀態</dt>
                    <dd>{{ $organization->statusLabel() }}</dd>
                </div>
                <div>
                    <dt>負責人</dt>
                    <dd>{{ $organization->owner?->name ?? '—' }}<span class="secondary-text">{{ $organization->owner?->email }}</span></dd>
                </div>
                <div>
                    <dt>方案</dt>
                    <dd>{{ $organization->plan_name ?? '待設定' }}</dd>
                </div>
                <div>
                    <dt>訂閱到期日</dt>
                    <dd>{{ $organization->subscription_ends_on?->format('Y/n/j') ?? '未設定' }}</dd>
                </div>
                <div>
                    <dt>產品授權模式</dt>
                    <dd>{{ $organization->enforcesEntitlements() ? '依訂閱授權' : '舊模式（不限制）' }}</dd>
                </div>
                <div>
                    <dt>建立時間</dt>
                    <dd>{{ $organization->created_at?->timezone('Asia/Taipei')->format('Y/n/j H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <section class="stat-grid platform-stat-grid spaced-top" aria-label="用量摘要">
        <article class="stat-card">
            <span class="stat-label">帳號</span>
            <strong class="stat-value">{{ number_format(1 + $members->count()) }}</strong>
            <p class="stat-hint">席次上限 {{ $formatLimit($organization->seat_limit) }}</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">客戶</span>
            <strong class="stat-value">{{ number_format($clientRows->where('is_default', false)->count()) }}</strong>
            <p class="stat-hint">上限 {{ $formatLimit($organization->client_limit) }}</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">監測項目</span>
            <strong class="stat-value">{{ number_format($monitorCount) }}</strong>
            <p class="stat-hint">上限 {{ $formatLimit($organization->monitor_limit) }}</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">活動目標</span>
            <strong class="stat-value">{{ number_format($campaignCount) }}</strong>
            <p class="stat-hint">上限 {{ $formatLimit($organization->campaign_limit) }}</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">未結事件</span>
            <strong class="stat-value">{{ number_format($openIncidentCount) }}</strong>
            <p class="stat-hint">待確認與進行中</p>
        </article>
    </section>

    <section class="panel spaced-top" aria-labelledby="organization-members-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="organization-members-title">成員</h2>
                <p class="panel-description">負責人與 {{ number_format($members->count()) }} 位已加入的成員。</p>
            </div>
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <caption class="sr-only">組織成員的姓名、電子郵件、角色與客戶範圍</caption>
                <thead>
                    <tr>
                        <th scope="col">姓名</th>
                        <th scope="col">電子郵件</th>
                        <th scope="col">角色</th>
                        <th scope="col">客戶範圍</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row">{{ $organization->owner?->name ?? '—' }}</th>
                        <td>{{ $organization->owner?->email ?? '—' }}</td>
                        <td>擁有者</td>
                        <td>全部客戶</td>
                    </tr>
                    @foreach ($members as $member)
                        <tr>
                            <th scope="row">{{ $member->name }}</th>
                            <td>{{ $member->email }}</td>
                            <td>{{ $member->role === \App\Models\WorkspaceMembership::ROLE_EDITOR ? '可編輯' : '唯讀' }}</td>
                            <td>{{ $member->client_scope === \App\Models\WorkspaceMembership::CLIENT_SCOPE_SELECTED ? '指定客戶' : '全部客戶' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="organization-clients-title">
        <div class="panel-header">
            <h2 class="panel-title" id="organization-clients-title">客戶與用量</h2>
        </div>
        @if ($clientRows->isEmpty())
            <p class="empty-copy panel-body">尚未建立客戶。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">各客戶的監測項目與活動目標數量</caption>
                    <thead>
                        <tr>
                            <th scope="col">客戶</th>
                            <th scope="col" class="numeric">監測項目</th>
                            <th scope="col" class="numeric">活動目標</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($clientRows as $clientRow)
                            <tr>
                                <th scope="row">{{ $clientRow['name'] }}@if ($clientRow['is_default'])<span class="secondary-text">預設分類</span>@endif</th>
                                <td class="numeric">{{ number_format($clientRow['monitor_count']) }}</td>
                                <td class="numeric">{{ number_format($clientRow['campaign_count']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="organization-incidents-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="organization-incidents-title">最近監測事件</h2>
                <p class="panel-description">顯示最近 10 筆，不含網址與通知內容。</p>
            </div>
        </div>
        @if ($recentIncidents->isEmpty())
            <p class="empty-copy panel-body">尚無監測事件。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">最近監測事件的監測項目、類型、嚴重度、狀態與開始時間</caption>
                    <thead>
                        <tr>
                            <th scope="col">監測項目</th>
                            <th scope="col">類型</th>
                            <th scope="col">嚴重度</th>
                            <th scope="col">狀態</th>
                            <th scope="col">開始時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentIncidents as $incident)
                            @php($openedAt = $incident->opened_at === null ? null : \Illuminate\Support\Carbon::parse($incident->opened_at)->timezone('Asia/Taipei'))
                            <tr>
                                <th scope="row">{{ $incident->monitor_name }}</th>
                                <td>{{ \App\Models\Incident::RULE_LABELS[$incident->rule_key] ?? '其他監測異常' }}</td>
                                <td>{{ $severityLabels[$incident->severity] ?? $incident->severity }}</td>
                                <td>{{ $stateLabels[$incident->state] ?? $incident->state }}</td>
                                <td>
                                    @if ($openedAt !== null)
                                        <time datetime="{{ $openedAt->toIso8601String() }}">{{ $openedAt->format('Y/n/j H:i') }}</time>
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="organization-audit-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="organization-audit-title">最近管理操作</h2>
                <p class="panel-description">顯示最近 10 筆。</p>
            </div>
            <a class="strong-link" href="{{ route('platform.audit-events.index', ['organization_id' => $organization->id]) }}">查看全部稽核紀錄 <span aria-hidden="true">→</span></a>
        </div>
        @if ($recentAuditEvents->isEmpty())
            <p class="empty-copy panel-body">尚無管理操作紀錄。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">最近的管理操作、執行者與時間</caption>
                    <thead>
                        <tr>
                            <th scope="col">時間</th>
                            <th scope="col">操作</th>
                            <th scope="col">執行者</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentAuditEvents as $auditEvent)
                            <tr>
                                <td><time datetime="{{ $auditEvent->created_at->toIso8601String() }}">{{ $auditEvent->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td>{{ $auditEvent->actionLabel() }}</td>
                                <td>{{ $auditEvent->actor?->name ?? '系統' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
