@extends('layouts.app')

@section('title', '總管理平台')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Platform administration</p>
            <h1 class="page-title">總管理平台</h1>
            <p class="page-description">管理組織方案、用量上限與服務期限。</p>
        </div>
    </div>

    <section class="stat-grid platform-stat-grid" aria-label="全平台摘要">
        <article class="stat-card">
            <span class="stat-label">組織</span>
            <strong class="stat-value">{{ number_format($organizationCount) }}</strong>
            <p class="stat-hint">{{ number_format($activeOrganizationCount) }} 個可使用</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">帳號</span>
            <strong class="stat-value">{{ number_format($accountCount) }}</strong>
            <p class="stat-hint">包含平台與組織成員</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">客戶</span>
            <strong class="stat-value">{{ number_format($clientCount) }}</strong>
            <p class="stat-hint">所有組織的客戶資料</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">監測項目</span>
            <strong class="stat-value">{{ number_format($monitorCount) }}</strong>
            <p class="stat-hint">所有組織的監測資料</p>
        </article>
        <article class="stat-card">
            <span class="stat-label">活動目標</span>
            <strong class="stat-value">{{ number_format($campaignCount) }}</strong>
            <p class="stat-hint">行銷活動 · {{ number_format($incidentCount) }} 筆監測事件</p>
        </article>
    </section>

    <section class="panel spaced-top" aria-labelledby="create-organization-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="create-organization-title">新增組織</h2>
                <p class="panel-description">建立組織時，同時建立負責人帳號並設定方案用量。</p>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('platform.organizations.store') }}" method="POST" class="platform-create-form" aria-labelledby="create-organization-title">
                @csrf
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="organization-name">組織名稱</label>
                        <input class="field-input @error('name') is-invalid @enderror" id="organization-name" name="name" type="text" maxlength="120" value="{{ old('name') }}" required autocomplete="organization">
                        @error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-owner-name">負責人姓名</label>
                        <input class="field-input @error('owner_name') is-invalid @enderror" id="organization-owner-name" name="owner_name" type="text" maxlength="120" value="{{ old('owner_name') }}" required autocomplete="name">
                        @error('owner_name')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-owner-email">負責人電子郵件</label>
                        <input class="field-input @error('owner_email') is-invalid @enderror" id="organization-owner-email" name="owner_email" type="email" maxlength="254" value="{{ old('owner_email') }}" required autocomplete="email">
                        @error('owner_email')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-owner-password">初始密碼</label>
                        <input class="field-input @error('owner_password') is-invalid @enderror" id="organization-owner-password" name="owner_password" type="password" minlength="12" maxlength="200" required autocomplete="new-password">
                        <p class="field-help">至少 12 個字元，建立後請安全地交給負責人。</p>
                        @error('owner_password')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-owner-password-confirmation">確認初始密碼</label>
                        <input class="field-input" id="organization-owner-password-confirmation" name="owner_password_confirmation" type="password" minlength="12" maxlength="200" required autocomplete="new-password">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-subscription-end">訂閱到期日</label>
                        <input class="field-input @error('subscription_ends_on') is-invalid @enderror" id="organization-subscription-end" name="subscription_ends_on" type="date" value="{{ old('subscription_ends_on') }}">
                        <p class="field-help">留空代表不設定到期日；到期日當天結束後會自動停用。</p>
                        @error('subscription_ends_on')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-seat-limit">席次上限</label>
                        <input class="field-input @if($errors->has('seat_limit') && ! old('organization_id')) is-invalid @endif" id="organization-seat-limit" name="seat_limit" type="number" inputmode="numeric" min="1" max="65535" step="1" value="{{ old('organization_id') ? '' : old('seat_limit') }}" @if($errors->has('seat_limit') && ! old('organization_id')) aria-invalid="true" aria-describedby="organization-seat-limit-help organization-seat-limit-error" @else aria-describedby="organization-seat-limit-help" @endif>
                        <p class="field-help" id="organization-seat-limit-help">包含負責人、成員及尚未接受的邀請；留空代表不設上限。</p>
                        @if($errors->has('seat_limit') && ! old('organization_id'))<p class="field-error" id="organization-seat-limit-error" role="alert">{{ $errors->first('seat_limit') }}</p>@endif
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="organization-plan-name">方案名稱</label>
                        <p class="field-help" id="organization-plan-name-help">名稱依人工約定填寫，例如「試用」。</p>
                        <input class="field-input @if($errors->has('plan_name') && ! old('organization_id')) is-invalid @endif" id="organization-plan-name" name="plan_name" type="text" maxlength="80" value="{{ old('organization_id') ? '' : old('plan_name') }}" required aria-describedby="organization-plan-name-help @if($errors->has('plan_name') && ! old('organization_id')) organization-plan-name-error @endif" @if($errors->has('plan_name') && ! old('organization_id')) aria-invalid="true" @endif>
                        @if($errors->has('plan_name') && ! old('organization_id'))<p class="field-error" id="organization-plan-name-error" role="alert">{{ $errors->first('plan_name') }}</p>@endif
                    </div>
                    @foreach ([
                        ['name' => 'client_limit', 'id' => 'client-limit', 'label' => '客戶數上限', 'help' => '不計內部「未分類」客戶。'],
                        ['name' => 'monitor_limit', 'id' => 'monitor-limit', 'label' => '監測項目上限', 'help' => '包含停用中的監測項目。'],
                        ['name' => 'campaign_limit', 'id' => 'campaign-limit', 'label' => '活動目標上限', 'help' => '依活動目標筆數計算。'],
                    ] as $limitField)
                        @php($createLimitError = $errors->has($limitField['name']) && ! old('organization_id'))
                        <div class="field-group">
                            <label class="field-label" for="organization-{{ $limitField['id'] }}">{{ $limitField['label'] }}</label>
                            <p class="field-help" id="organization-{{ $limitField['id'] }}-help">{{ $limitField['help'] }}留空代表不設上限。</p>
                            <input class="field-input @if($createLimitError) is-invalid @endif" id="organization-{{ $limitField['id'] }}" name="{{ $limitField['name'] }}" type="number" inputmode="numeric" min="1" max="65535" step="1" value="{{ old('organization_id') ? '' : old($limitField['name']) }}" aria-describedby="organization-{{ $limitField['id'] }}-help @if($createLimitError) organization-{{ $limitField['id'] }}-error @endif" @if($createLimitError) aria-invalid="true" @endif>
                            @if($createLimitError)<p class="field-error" id="organization-{{ $limitField['id'] }}-error" role="alert">{{ $errors->first($limitField['name']) }}</p>@endif
                        </div>
                    @endforeach
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">建立組織</button>
                </div>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="organization-list-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="organization-list-title">組織與資料概況</h2>
                <p class="panel-description">方案與上限由管理員按約定設定；配額留空代表不設限制。</p>
            </div>
        </div>
        @if ($organizationRows->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">目前尚無組織</h3>
                <p class="empty-copy">建立第一個組織後，便可在此管理訂閱與資料。</p>
            </div>
        @else
            <div class="data-table-wrap" tabindex="0" role="region" aria-labelledby="organization-table-caption">
                <table class="data-table platform-organization-table">
                    <caption class="sr-only" id="organization-table-caption">各組織方案、席次、資源用量與訂閱狀態</caption>
                    <thead>
                        <tr>
                            <th scope="col">組織 / 負責人</th>
                            <th scope="col">方案與用量上限</th>
                            <th scope="col">席次使用</th>
                            <th scope="col">席次上限</th>
                            <th scope="col">客戶</th>
                            <th scope="col">監測</th>
                            <th scope="col">活動</th>
                            <th scope="col">事件</th>
                            <th scope="col">訂閱到期</th>
                            <th scope="col">狀態</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($organizationRows as $row)
                            @php($organization = $row['organization'])
                            @php($organizationFormId = 'organization-update-'.$organization->id)
                            @php($sameOldOrganization = (string) old('organization_id') === (string) $organization->id)
                            <tr>
                                <th scope="row">
                                    <input class="field-input platform-organization-name" form="{{ $organizationFormId }}" name="name" type="text" maxlength="120" value="{{ $organization->name }}" required aria-label="{{ $organization->name }} 組織名稱">
                                    <span class="secondary-text">{{ $organization->owner?->name }} · {{ $organization->owner?->email }}</span>
                                </th>
                                <td>
                                    <div class="platform-quota-editor">
                                        <div class="field-group platform-quota-field platform-plan-name-field">
                                            <label class="field-label" for="organization-plan-name-{{ $organization->id }}">方案名稱</label>
                                            <span class="field-help" id="organization-plan-name-help-{{ $organization->id }}">{{ $organization->plan_name === null ? '待設定' : '依人工約定設定' }}</span>
                                            <input class="field-input @if($errors->has('plan_name') && $sameOldOrganization) is-invalid @endif" form="{{ $organizationFormId }}" id="organization-plan-name-{{ $organization->id }}" name="plan_name" type="text" maxlength="80" value="{{ $sameOldOrganization ? old('plan_name') : ($organization->plan_name ?? '') }}" aria-describedby="organization-plan-name-help-{{ $organization->id }} @if($errors->has('plan_name') && $sameOldOrganization) organization-plan-name-error-{{ $organization->id }} @endif" @if($errors->has('plan_name') && $sameOldOrganization) aria-invalid="true" @endif>
                                            @if($errors->has('plan_name') && $sameOldOrganization)<span class="field-error" id="organization-plan-name-error-{{ $organization->id }}" role="alert">{{ $errors->first('plan_name') }}</span>@endif
                                        </div>
                                        @foreach ([
                                            ['name' => 'client_limit', 'id' => 'client-limit', 'label' => '客戶上限'],
                                            ['name' => 'monitor_limit', 'id' => 'monitor-limit', 'label' => '監測上限'],
                                            ['name' => 'campaign_limit', 'id' => 'campaign-limit', 'label' => '活動上限'],
                                        ] as $limitField)
                                            @php($fieldId = 'organization-'.$limitField['id'].'-'.$organization->id)
                                            @php($fieldHelpId = $fieldId.'-help')
                                            @php($fieldErrorId = $fieldId.'-error')
                                            @php($hasLimitError = $errors->has($limitField['name']) && $sameOldOrganization)
                                            <div class="field-group platform-quota-field">
                                                <label class="field-label" for="{{ $fieldId }}">{{ $limitField['label'] }}</label>
                                                <span class="field-help" id="{{ $fieldHelpId }}">留空不限</span>
                                                <input class="field-input @if($hasLimitError) is-invalid @endif" form="{{ $organizationFormId }}" id="{{ $fieldId }}" name="{{ $limitField['name'] }}" type="number" inputmode="numeric" min="1" max="65535" step="1" value="{{ $sameOldOrganization ? old($limitField['name']) : $organization->{$limitField['name']} }}" aria-describedby="{{ $fieldHelpId }} @if($hasLimitError) {{ $fieldErrorId }} @endif" @if($hasLimitError) aria-invalid="true" @endif>
                                                @if($hasLimitError)<span class="field-error" id="{{ $fieldErrorId }}" role="alert">{{ $errors->first($limitField['name']) }}</span>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="numeric">
                                    {{ number_format($row['occupied_seat_count']) }} / {{ $organization->seat_limit === null ? '不限' : number_format($organization->seat_limit) }}
                                    <span class="secondary-text">{{ number_format($row['account_count']) }} 個帳號 · {{ number_format($row['pending_invitation_count']) }} 個待接受邀請</span>
                                </td>
                                <td>
                                    <label class="sr-only" for="organization-seat-limit-{{ $organization->id }}">{{ $organization->name }}席次上限</label>
                                    <input class="field-input platform-subscription-date @if($errors->has('seat_limit') && (string) old('organization_id') === (string) $organization->id) is-invalid @endif" form="{{ $organizationFormId }}" id="organization-seat-limit-{{ $organization->id }}" name="seat_limit" type="number" inputmode="numeric" min="1" max="65535" step="1" value="{{ old('organization_id') == $organization->id ? old('seat_limit') : $organization->seat_limit }}" aria-describedby="organization-seat-limit-help-{{ $organization->id }} @if($errors->has('seat_limit') && (string) old('organization_id') === (string) $organization->id) organization-seat-limit-error-{{ $organization->id }} @endif" @if($errors->has('seat_limit') && (string) old('organization_id') === (string) $organization->id) aria-invalid="true" @endif>
                                    <span class="sr-only" id="organization-seat-limit-help-{{ $organization->id }}">留空代表不設上限；有效邀請會保留一個席次。</span>
                                    @if($errors->has('seat_limit') && (string) old('organization_id') === (string) $organization->id)
                                        <span class="field-error" id="organization-seat-limit-error-{{ $organization->id }}" role="alert">{{ $errors->first('seat_limit') }}</span>
                                    @endif
                                </td>
                                <td class="numeric">{{ number_format($row['client_count']) }} / {{ $organization->client_limit === null ? '不限' : number_format($organization->client_limit) }}</td>
                                <td class="numeric">{{ number_format($row['monitor_count']) }} / {{ $organization->monitor_limit === null ? '不限' : number_format($organization->monitor_limit) }}</td>
                                <td class="numeric">{{ number_format($row['campaign_count']) }} / {{ $organization->campaign_limit === null ? '不限' : number_format($organization->campaign_limit) }}</td>
                                <td class="numeric">{{ number_format($row['incident_count']) }}</td>
                                <td>
                                    <label class="sr-only" for="organization-subscription-{{ $organization->id }}">{{ $organization->name }}訂閱到期日</label>
                                    <input class="field-input platform-subscription-date" form="{{ $organizationFormId }}" id="organization-subscription-{{ $organization->id }}" name="subscription_ends_on" type="date" value="{{ $organization->subscription_ends_on?->format('Y-m-d') }}">
                                </td>
                                <td>
                                    <span @class([
                                        'platform-org-status',
                                        'platform-org-status-active' => $organization->isAccessible(),
                                        'platform-org-status-inactive' => ! $organization->isAccessible(),
                                    ])>{{ $organization->statusLabel() }}</span>
                                    <label class="sr-only" for="organization-enabled-{{ $organization->id }}">{{ $organization->name }}平台啟用狀態</label>
                                    <select class="field-select platform-org-enabled" form="{{ $organizationFormId }}" id="organization-enabled-{{ $organization->id }}" name="is_enabled" required>
                                        <option value="1" @selected($organization->is_enabled)>啟用</option>
                                        <option value="0" @selected(! $organization->is_enabled)>停用</option>
                                    </select>
                                </td>
                                <td>
                                    <form id="{{ $organizationFormId }}" action="{{ route('platform.organizations.update', $organization) }}" method="POST" class="platform-org-actions">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="organization_id" value="{{ $organization->id }}">
                                        <button class="button button-secondary button-small" type="submit">儲存</button>
                                    </form>
                                    <form action="{{ route('platform.organizations.open', $organization) }}" method="POST" class="platform-org-actions">
                                        @csrf
                                        <button class="button button-primary button-small" type="submit">進入組織</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
