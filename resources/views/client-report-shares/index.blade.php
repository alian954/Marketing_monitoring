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
        $selectedCampaignIds = old('target_ids', $selectedTemplate?->campaign_target_ids ?? $campaignTargets->modelKeys());
        $selectedCampaignIds = is_array($selectedCampaignIds) ? array_map('intval', $selectedCampaignIds) : [];
        $selectedReportSections = old('report_sections', $selectedTemplate?->selectedReportSections() ?? \App\Models\ClientReportShare::DEFAULT_REPORT_SECTIONS);
        $selectedReportSections = is_array($selectedReportSections) ? $selectedReportSections : [];
        $selectedReportBrandName = old('report_brand_name', $selectedTemplate?->report_brand_name ?? '');
        $selectedComparePreviousPeriod = old('compare_previous_period', $selectedTemplate?->compare_previous_period ?? false);
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

    <section class="panel spaced-top" aria-labelledby="report-template-list-title">
        <div class="panel-header">
            <h2 id="report-template-list-title" class="panel-title">報表樣板</h2>
        </div>
        @if ($templates->isEmpty())
            <p class="empty-copy panel-body">建立報表時可另存設定為樣板。</p>
        @else
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">報表樣板名稱、活動數量、內容區塊與操作</caption>
                    <thead>
                        <tr>
                            <th scope="col">名稱</th>
                            <th scope="col">活動</th>
                            <th scope="col">報表內容</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $template)
                            @php
                                $templateReportSectionLabels = collect($template->selectedReportSections())
                                    ->map(static fn (string $section): ?string => \App\Models\ClientReportShare::REPORT_SECTION_LABELS[$section] ?? null)
                                    ->filter()
                                    ->implode('、');
                                $templateCampaignCount = $campaignTargets->whereIn('id', $template->campaign_target_ids)->count();
                            @endphp
                            <tr>
                                <th scope="row">{{ $template->name }}</th>
                                <td>{{ number_format($templateCampaignCount) }} 個</td>
                                <td>{{ $templateReportSectionLabels }}</td>
                                <td>
                                    <div class="row-actions">
                                        <a class="button button-secondary button-small" href="{{ route('clients.report-shares.index', ['client' => $client, 'template_id' => $template->id]) }}" @if ($selectedTemplate?->is($template)) aria-current="page" @endif>套用</a>
                                        @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                                            <form action="{{ route('clients.report-shares.templates.destroy', [$client, $template]) }}" method="POST" class="inline-form">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button button-quiet button-small" type="submit" aria-label="刪除{{ $template->name }}樣板">刪除</button>
                                            </form>
                                        @endunless
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="report-schedule-list-title">
        <div class="panel-header">
            <div>
                <h2 id="report-schedule-list-title" class="panel-title">自動寄送</h2>
                <p class="panel-description">依樣板每週或每月產生報表快照，寄送線上報表連結與 CSV 附件。</p>
            </div>
        </div>
        @if ($schedules->isNotEmpty())
            <div class="data-table-wrap">
                <table class="data-table">
                    <caption class="sr-only">自動寄送排程的樣板、頻率、收件人、下次寄送與最近結果</caption>
                    <thead>
                        <tr>
                            <th scope="col">樣板</th>
                            <th scope="col">頻率</th>
                            <th scope="col">收件人</th>
                            <th scope="col">下次寄送</th>
                            <th scope="col">最近結果</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedules as $schedule)
                            <tr>
                                <th scope="row">{{ $schedule->template?->name ?? '已刪除樣板' }}</th>
                                <td>{{ \App\Models\ClientReportSchedule::FREQUENCY_LABELS[$schedule->frequency] ?? $schedule->frequency }}</td>
                                <td>{{ implode('、', $schedule->recipientList()) }}</td>
                                <td>
                                    @if ($schedule->isPaused())
                                        已暫停
                                    @else
                                        <time datetime="{{ $schedule->next_run_on->toDateString() }}">{{ $schedule->next_run_on->format('Y/n/j') }}</time>
                                    @endif
                                </td>
                                <td>
                                    @if ($schedule->last_failure_reason !== null)
                                        <span class="field-error">{{ $schedule->last_failure_reason }}</span>
                                    @elseif ($schedule->last_delivered_at !== null)
                                        已寄送 <time datetime="{{ $schedule->last_delivered_at->toIso8601String() }}">{{ $schedule->last_delivered_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                                    @else
                                        <span class="secondary-text">尚未寄送</span>
                                    @endif
                                </td>
                                <td>
                                    @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                                        <div class="row-actions">
                                            <form action="{{ route('clients.report-schedules.update', [$client, $schedule]) }}" method="POST" class="inline-form">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="paused" value="{{ $schedule->isPaused() ? '0' : '1' }}">
                                                <button class="button button-secondary button-small" type="submit">{{ $schedule->isPaused() ? '恢復' : '暫停' }}</button>
                                            </form>
                                            <form action="{{ route('clients.report-schedules.destroy', [$client, $schedule]) }}" method="POST" class="inline-form">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button button-quiet button-small" type="submit" aria-label="刪除{{ $schedule->template?->name }}自動寄送">刪除</button>
                                            </form>
                                        </div>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="panel-body">
            @if ($templates->isEmpty())
                <p class="empty-copy">先在下方儲存報表樣板，再設定自動寄送。</p>
            @elseif (! auth()->user()?->isReadOnlyWorkspaceMember())
                @php
                    $scheduleErrors = $errors->getBag('reportSchedule');
                    $scheduleRecipientErrors = collect($scheduleErrors->getMessages())
                        ->filter(static fn (array $messages, string $key): bool => str_starts_with($key, 'recipients'))
                        ->flatten()
                        ->unique();
                @endphp
                <form action="{{ route('clients.report-schedules.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="report-schedule-list-title">
                    @csrf
                    <div class="field-group">
                        <label class="field-label" for="report-schedule-template">報表樣板</label>
                        <select class="field-select{{ $scheduleErrors->has('template_id') ? ' is-invalid' : '' }}" id="report-schedule-template" name="template_id" required>
                            @foreach ($templates as $template)
                                <option value="{{ $template->id }}" @selected((string) old('template_id') === (string) $template->id)>{{ $template->name }}</option>
                            @endforeach
                        </select>
                        @if ($scheduleErrors->has('template_id'))<p class="field-error" role="alert">{{ $scheduleErrors->first('template_id') }}</p>@endif
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-schedule-frequency">寄送頻率</label>
                        <select class="field-select{{ $scheduleErrors->has('frequency') ? ' is-invalid' : '' }}" id="report-schedule-frequency" name="frequency" required>
                            @foreach (\App\Models\ClientReportSchedule::FREQUENCY_LABELS as $frequency => $label)
                                <option value="{{ $frequency }}" @selected(old('frequency', \App\Models\ClientReportSchedule::FREQUENCY_WEEKLY) === $frequency)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if ($scheduleErrors->has('frequency'))<p class="field-error" role="alert">{{ $scheduleErrors->first('frequency') }}</p>@endif
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-schedule-recipients">收件信箱</label>
                        <textarea class="field-textarea{{ $scheduleRecipientErrors->isNotEmpty() ? ' is-invalid' : '' }}" id="report-schedule-recipients" name="recipients" rows="3" required autocomplete="email" autocorrect="off" autocapitalize="none" spellcheck="false" aria-describedby="report-schedule-recipients-help">{{ old('recipients') }}</textarea>
                        <p id="report-schedule-recipients-help" class="field-help">最多 {{ \App\Models\ClientReportSchedule::MAX_RECIPIENTS }} 個，以換行或逗號分隔。台北時間上午寄出，報表期間為上一個完整週或月。</p>
                        @foreach ($scheduleRecipientErrors as $message)
                            <p class="field-error" role="alert">{{ $message }}</p>
                        @endforeach
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">建立自動寄送</button>
                    </div>
                </form>
            @endif
        </div>
    </section>

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
                    @if ($selectedTemplate !== null)
                        <input type="hidden" name="template_id" value="{{ $selectedTemplate->id }}">
                    @endif
                    <div class="field-group">
                        <label class="field-label" for="report-share-name">連結名稱</label>
                        <input class="field-input{{ $errors->has('name') ? ' is-invalid' : '' }}" id="report-share-name" name="name" type="text" maxlength="80" value="{{ old('name', $selectedTemplate?->name ?? '') }}" required @if ($errors->has('name')) aria-invalid="true" aria-describedby="report-share-name-error" @endif>
                        @error('name')<p id="report-share-name-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="report-share-brand-name">報表品牌名稱（選填）</label>
                        <input class="field-input{{ $errors->has('report_brand_name') ? ' is-invalid' : '' }}" id="report-share-brand-name" name="report_brand_name" type="text" maxlength="80" value="{{ $selectedReportBrandName }}" aria-describedby="report-share-brand-help{{ $errors->has('report_brand_name') ? ' report-share-brand-error' : '' }}" @if ($errors->has('report_brand_name')) aria-invalid="true" @endif>
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
                                        @if ($section === \App\Models\ClientReportShare::SECTION_ESTIMATED_PROFITABILITY)
                                            <small>僅在資料完整時顯示</small>
                                        @endif
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
                                @checked($selectedComparePreviousPeriod)
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
                    @unless (auth()->user()?->isReadOnlyWorkspaceMember())
                        <div class="field-group">
                            <label class="field-label" for="report-template-name">樣板名稱</label>
                            <input class="field-input{{ $errors->has('template_name') || $errors->has('template_id') ? ' is-invalid' : '' }}" id="report-template-name" name="template_name" type="text" maxlength="80" value="{{ old('template_name', $selectedTemplate?->name ?? '') }}" aria-describedby="report-template-name-help{{ $errors->has('template_name') ? ' report-template-name-error' : '' }}{{ $errors->has('template_id') ? ' report-template-id-error' : '' }}" @if ($errors->has('template_name') || $errors->has('template_id')) aria-invalid="true" @endif>
                            <p id="report-template-name-help" class="field-help">儲存活動、品牌與報表區塊；日期和有效期限每次另選。</p>
                            @error('template_name')<p id="report-template-name-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                            @error('template_id')<p id="report-template-id-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-actions">
                            <button class="button button-secondary" type="submit" formaction="{{ route('clients.report-shares.templates.store', $client) }}" formnovalidate>{{ $selectedTemplate === null ? '儲存樣板' : '更新樣板' }}</button>
                            <button class="button button-primary" type="submit">建立連結</button>
                        </div>
                    @endunless
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
