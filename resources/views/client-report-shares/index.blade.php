@extends('layouts.app')

@section('title', '客戶報表分享')

@section('content')
    @php
        $campaignSelectionErrors = collect($errors->getMessages())
            ->filter(static fn (array $messages, string $key): bool => $key === 'target_ids' || str_starts_with($key, 'target_ids.'))
            ->flatten();
        $reportSectionErrors = collect($errors->getMessages())
            ->filter(static fn (array $messages, string $key): bool => $key === 'report_sections' || str_starts_with($key, 'report_sections.'))
            ->flatten();
        $selectedCampaignIds = old('target_ids', $campaignTargets->modelKeys());
        $selectedCampaignIds = is_array($selectedCampaignIds) ? array_map('intval', $selectedCampaignIds) : [];
        $selectedReportSections = old('report_sections', \App\Models\ClientReportShare::DEFAULT_REPORT_SECTIONS);
        $selectedReportSections = is_array($selectedReportSections) ? $selectedReportSections : [];
    @endphp

    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <a href="{{ route('clients.index') }}">客戶</a><span aria-hidden="true">/</span>
        <span aria-current="page">報表分享</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">{{ $client->name }}｜報表分享</h1>
        </div>
        <div class="heading-actions">
            <a class="button button-secondary" href="{{ route('clients.index') }}">返回客戶</a>
        </div>
    </div>

    @if (session('createdShareUrl'))
        <section class="panel spaced-top" aria-labelledby="created-report-share-title">
            <div class="panel-header">
                <h2 id="created-report-share-title" class="panel-title">分享網址已建立</h2>
            </div>
            <div class="panel-body">
                <div class="field-group">
                    <label class="field-label" for="created-report-share-url">分享網址</label>
                    <input class="field-input" id="created-report-share-url" type="url" value="{{ session('createdShareUrl') }}" readonly>
                    <p class="field-help">網址只在建立後顯示一次，請複製給客戶。</p>
                </div>
            </div>
        </section>
    @endif

    <section class="panel spaced-top" aria-labelledby="create-report-share-title">
        <div class="panel-header">
            <h2 id="create-report-share-title" class="panel-title">建立分享連結</h2>
        </div>
        <div class="panel-body">
            @if ($campaignTargets->isEmpty())
                <p class="empty-copy">先新增至少一項行銷活動，再建立分享連結。</p>
            @else
                <form action="{{ route('clients.report-shares.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="create-report-share-title">
                    @csrf
                    <div class="field-group">
                        <label class="field-label" for="report-share-name">連結名稱</label>
                        <input class="field-input{{ $errors->has('name') ? ' is-invalid' : '' }}" id="report-share-name" name="name" type="text" maxlength="80" value="{{ old('name') }}" required @if ($errors->has('name')) aria-invalid="true" aria-describedby="report-share-name-error" @endif>
                        @error('name')<p id="report-share-name-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-share-brand-name">報表品牌名稱（選填）</label>
                        <input class="field-input{{ $errors->has('report_brand_name') ? ' is-invalid' : '' }}" id="report-share-brand-name" name="report_brand_name" type="text" maxlength="80" value="{{ old('report_brand_name') }}" aria-describedby="report-share-brand-help{{ $errors->has('report_brand_name') ? ' report-share-brand-error' : '' }}" @if ($errors->has('report_brand_name')) aria-invalid="true" @endif>
                        <p id="report-share-brand-help" class="field-help">留白時顯示監測台品牌。</p>
                        @error('report_brand_name')<p id="report-share-brand-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-share-expiry">有效期限</label>
                        <select class="field-select{{ $errors->has('expires_in_days') ? ' is-invalid' : '' }}" id="report-share-expiry" name="expires_in_days" required @if ($errors->has('expires_in_days')) aria-invalid="true" aria-describedby="report-share-expiry-error" @endif>
                            <option value="7" @selected(old('expires_in_days', '30') === '7')>7 天</option>
                            <option value="30" @selected(old('expires_in_days', '30') === '30')>30 天</option>
                            <option value="90" @selected(old('expires_in_days', '30') === '90')>90 天</option>
                        </select>
                        @error('expires_in_days')<p id="report-share-expiry-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-share-from">報表開始日期</label>
                        <input class="field-input{{ $errors->has('from') ? ' is-invalid' : '' }}" id="report-share-from" name="from" type="date" value="{{ old('from', $reportPeriod['from']) }}" required @if ($errors->has('from')) aria-invalid="true" aria-describedby="report-share-period-help report-share-from-error" @else aria-describedby="report-share-period-help" @endif>
                        @error('from')<p id="report-share-from-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-share-to">報表結束日期</label>
                        <input class="field-input{{ $errors->has('to') ? ' is-invalid' : '' }}" id="report-share-to" name="to" type="date" value="{{ old('to', $reportPeriod['to']) }}" max="{{ $reportPeriod['to'] }}" required @if ($errors->has('to')) aria-invalid="true" aria-describedby="report-share-period-help report-share-to-error" @else aria-describedby="report-share-period-help" @endif>
                        @error('to')<p id="report-share-to-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <p id="report-share-period-help" class="field-help">最多 365 天，結束日限昨天以前。</p>
                    <fieldset class="report-share-targets" aria-describedby="report-share-sections-help{{ $reportSectionErrors->isNotEmpty() ? ' report-share-sections-error' : '' }}" @if ($reportSectionErrors->isNotEmpty()) aria-invalid="true" @endif>
                        <legend class="field-label">報表內容</legend>
                        <p id="report-share-sections-help" class="field-help">選擇要公開的數據區塊；網頁與 CSV 會套用相同設定。</p>
                        <div class="report-share-target-list">
                            @foreach (\App\Models\ClientReportShare::REPORT_SECTION_LABELS as $section => $label)
                                <label class="report-share-target-option">
                                    <input type="checkbox" name="report_sections[]" value="{{ $section }}" @checked(in_array($section, $selectedReportSections, true)) @if ($reportSectionErrors->isNotEmpty()) aria-invalid="true" aria-describedby="report-share-sections-error" @endif>
                                    <span>
                                        <strong>{{ $label }}</strong>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($reportSectionErrors->isNotEmpty())
                            <p id="report-share-sections-error" class="field-error" role="alert">{{ $reportSectionErrors->first() }}</p>
                        @endif
                    </fieldset>
                    <fieldset class="report-share-targets" aria-describedby="report-share-comparison-help{{ $errors->has('compare_previous_period') ? ' report-share-comparison-error' : '' }}">
                        <legend class="field-label">成效比較</legend>
                        <p id="report-share-comparison-help" class="field-help">選用時比較前一個等長期間的 GA4 營收與廣告花費。</p>
                        <label class="report-share-target-option" for="report-share-compare-previous">
                            <input
                                id="report-share-compare-previous"
                                type="checkbox"
                                name="compare_previous_period"
                                value="1"
                                @checked(old('compare_previous_period'))
                                @if ($errors->has('compare_previous_period')) aria-invalid="true" @endif
                            >
                            <span><strong>顯示前期變化</strong></span>
                        </label>
                        @error('compare_previous_period')
                            <p id="report-share-comparison-error" class="field-error" role="alert">{{ $message }}</p>
                        @enderror
                    </fieldset>
                    <fieldset class="report-share-targets" aria-describedby="report-share-targets-help{{ $campaignSelectionErrors->isNotEmpty() ? ' report-share-targets-error' : '' }}" @if ($campaignSelectionErrors->isNotEmpty()) aria-invalid="true" @endif>
                        <legend class="field-label">包含活動</legend>
                        <p id="report-share-targets-help" class="field-help">只會公開勾選活動的數據。</p>
                        <div class="report-share-target-list">
                            @foreach ($campaignTargets as $campaignTarget)
                                <label class="report-share-target-option">
                                    <input type="checkbox" name="target_ids[]" value="{{ $campaignTarget->id }}" @checked(in_array((int) $campaignTarget->id, $selectedCampaignIds, true))>
                                    <span>
                                        <strong>{{ $campaignTarget->tag }}</strong>
                                        <small>{{ ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads', 'dcard' => 'Dcard'][$campaignTarget->social_platform] ?? $campaignTarget->social_platform }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @if ($campaignSelectionErrors->isNotEmpty())
                            <p id="report-share-targets-error" class="field-error" role="alert">{{ $campaignSelectionErrors->first() }}</p>
                        @endif
                    </fieldset>
                    <button class="button button-primary" type="submit">建立連結</button>
                </form>
            @endif
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="report-share-list-title">
        <div class="panel-header">
            <div>
                <h2 id="report-share-list-title" class="panel-title">已建立的連結</h2>
                <p class="panel-description">{{ number_format($shares->count()) }} 個連結</p>
            </div>
        </div>
        @if ($shares->isEmpty())
            <p class="empty-copy panel-body">尚未建立分享連結</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">報表分享連結名稱、活動範圍、報表期間、狀態與到期時間</caption>
                    <thead>
                        <tr>
                            <th scope="col">名稱</th>
                            <th scope="col">活動範圍</th>
                            <th scope="col">報表期間</th>
                            <th scope="col">報表內容</th>
                            <th scope="col">狀態</th>
                            <th scope="col">封存快照</th>
                            <th scope="col">到期時間</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shares as $share)
                            @php
                                $shareStatus = $share->revoked_at !== null ? '已撤銷' : ($share->expires_at->isFuture() ? '有效' : '已到期');
                                $shareCampaignCount = $share->campaign_target_ids === null
                                    ? $campaignTargets->count()
                                    : $campaignTargets->whereIn('id', $share->campaign_target_ids)->count();
                                $shareCampaignScope = $share->campaign_target_ids === null ? '全部' : '所選';
                                $shareReportSectionLabels = collect($share->selectedReportSections())
                                    ->map(static fn (string $section): ?string => \App\Models\ClientReportShare::REPORT_SECTION_LABELS[$section] ?? null)
                                    ->filter()
                                    ->implode('、');
                            @endphp
                            <tr>
                                <th scope="row">{{ $share->name }}</th>
                                <td>{{ $shareCampaignScope }} {{ number_format($shareCampaignCount) }} 項</td>
                                <td>
                                    {{ $share->report_starts_on?->format('Y/n/j') ?? '不限' }} – {{ $share->report_ends_on?->format('Y/n/j') ?? '不限' }}
                                    @if ($share->compare_previous_period)
                                        <span class="secondary-text">含前期比較</span>
                                    @endif
                                </td>
                                <td>{{ $shareReportSectionLabels }}</td>
                                <td>{{ $shareStatus }}</td>
                                <td>
                                    @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                                        <form action="{{ route('clients.report-shares.snapshots.store', [$client, $share]) }}" method="POST" class="inline-form">
                                            @csrf
                                            <button class="button button-secondary button-small" type="submit">建立快照</button>
                                        </form>
                                    @endunless
                                    <span class="secondary-text">{{ number_format($share->snapshots->count()) }} 份</span>
                                    @if ($share->snapshots->isNotEmpty())
                                        <ul class="report-snapshot-list">
                                            @foreach ($share->snapshots as $snapshot)
                                                <li>
                                                    <a href="{{ route('clients.report-shares.snapshots.export', [$client, $share, $snapshot]) }}">下載</a>
                                                    <time datetime="{{ $snapshot->created_at->toIso8601String() }}">{{ $snapshot->created_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </td>
                                <td><time datetime="{{ $share->expires_at->toIso8601String() }}">{{ $share->expires_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time></td>
                                <td>
                                    @if ($shareStatus === '有效')
                                        <form action="{{ route('clients.report-shares.destroy', [$client, $share->id]) }}" method="POST" class="inline-form">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-quiet button-small" type="submit" aria-label="撤銷{{ $share->name }}">撤銷</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
