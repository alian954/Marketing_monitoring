@extends('layouts.platform')

@section('title', '試用申請')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Platform trials</p>
            <h1 class="page-title">試用申請</h1>
            <p class="page-description">核准後會為組織建立試用中的訂閱，並在試用到期時自動停止授權；婉拒時需填寫說明，申請人會看到。</p>
        </div>
        <a class="button button-secondary" href="{{ route('platform.index') }}">返回組織管理</a>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->getBag('trialReview')->any())
        <div class="alert alert-error" role="alert" aria-labelledby="trial-review-errors-title">
            <p class="alert-title" id="trial-review-errors-title">無法完成審核</p>
            <ul>@foreach ($errors->getBag('trialReview')->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="panel" aria-labelledby="pending-trials-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="pending-trials-title">待審核</h2>
                <p class="panel-description">共 {{ number_format($pendingRequests->count()) }} 筆；依申請先後排列。</p>
            </div>
        </div>
        @if ($pendingRequests->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">目前沒有待審核的申請</h3>
                <p class="empty-copy">組織負責人送出試用申請後會顯示於此處。</p>
            </div>
        @else
            <div class="panel-body section-stack">
                @foreach ($pendingRequests as $trialRequest)
                    @php($isReviewTarget = (string) old('trial_request_id') === (string) $trialRequest->id)
                    <article class="platform-plan-editor" aria-labelledby="trial-request-{{ $trialRequest->id }}">
                        <div class="platform-catalog-header">
                            <div>
                                <h3 class="panel-title" id="trial-request-{{ $trialRequest->id }}">{{ $trialRequest->organization?->name }} · {{ $trialRequest->product?->name }}</h3>
                                <p class="panel-description">
                                    方案 {{ $trialRequest->plan?->name }} ·
                                    {{ $trialRequest->requestedBy?->name ?? '已刪除帳號' }} 於 {{ $trialRequest->created_at->timezone('Asia/Taipei')->format('Y-m-d H:i') }} 申請
                                </p>
                            </div>
                            <span class="platform-plan-status platform-plan-status-pending">審核中</span>
                        </div>
                        @if ($trialRequest->message !== null)
                            <p class="field-help">申請說明：{{ $trialRequest->message }}</p>
                        @endif
                        <form action="{{ route('platform.trial-requests.approve', $trialRequest) }}" method="POST" class="platform-plan-form" aria-label="核准 {{ $trialRequest->organization?->name }} 的試用申請">
                            @csrf
                            <input type="hidden" name="trial_request_id" value="{{ $trialRequest->id }}">
                            <div class="form-grid platform-plan-fields">
                                <div class="field-group">
                                    <label class="field-label" for="trial-days-{{ $trialRequest->id }}">試用天數</label>
                                    <input class="field-input" id="trial-days-{{ $trialRequest->id }}" name="trial_days" type="number" min="1" max="{{ \App\Models\TrialRequest::MAX_TRIAL_DAYS }}" value="{{ $isReviewTarget ? old('trial_days', \App\Models\TrialRequest::DEFAULT_TRIAL_DAYS) : \App\Models\TrialRequest::DEFAULT_TRIAL_DAYS }}" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="trial-approve-note-{{ $trialRequest->id }}">審核說明（選填）</label>
                                    <input class="field-input" id="trial-approve-note-{{ $trialRequest->id }}" name="review_note" type="text" maxlength="500">
                                </div>
                            </div>
                            <div class="form-actions">
                                <button class="button button-primary" type="submit">核准並開通試用</button>
                            </div>
                        </form>
                        <form action="{{ route('platform.trial-requests.reject', $trialRequest) }}" method="POST" class="platform-plan-form" aria-label="婉拒 {{ $trialRequest->organization?->name }} 的試用申請">
                            @csrf
                            <input type="hidden" name="trial_request_id" value="{{ $trialRequest->id }}">
                            <div class="form-grid platform-plan-fields">
                                <div class="field-group">
                                    <label class="field-label" for="trial-reject-note-{{ $trialRequest->id }}">婉拒說明</label>
                                    <input class="field-input" id="trial-reject-note-{{ $trialRequest->id }}" name="review_note" type="text" maxlength="500" required>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button class="button button-secondary" type="submit">婉拒申請</button>
                            </div>
                        </form>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    <section class="panel spaced-top" aria-labelledby="reviewed-trials-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="reviewed-trials-title">最近審核</h2>
                <p class="panel-description">顯示最近 50 筆；時間以台灣時間顯示。</p>
            </div>
        </div>
        @if ($reviewedRequests->isEmpty())
            <div class="empty-state">
                <h3 class="empty-title">尚無審核紀錄</h3>
            </div>
        @else
            <div class="data-table-wrap" role="region" aria-labelledby="reviewed-trials-title" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">審核時間、組織、產品、方案、結果、審核者與說明</caption>
                    <thead>
                        <tr>
                            <th scope="col">審核時間</th>
                            <th scope="col">組織</th>
                            <th scope="col">產品／方案</th>
                            <th scope="col">結果</th>
                            <th scope="col">審核者</th>
                            <th scope="col">說明</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reviewedRequests as $trialRequest)
                            <tr>
                                <td>{{ $trialRequest->reviewed_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>
                                    @if ($trialRequest->organization !== null)
                                        <a class="strong-link" href="{{ route('platform.organizations.products', $trialRequest->organization) }}">{{ $trialRequest->organization->name }}</a>
                                    @endif
                                </td>
                                <td>{{ $trialRequest->product?->name }}／{{ $trialRequest->plan?->name }}</td>
                                <td>
                                    <span class="platform-plan-status platform-plan-status-{{ $trialRequest->status }}">{{ $trialRequest->statusLabel() }}</span>
                                    @if ($trialRequest->trial_days !== null)
                                        <span class="field-help">{{ $trialRequest->trial_days }} 天</span>
                                    @endif
                                </td>
                                <td>{{ $trialRequest->reviewedBy?->name ?? '—' }}</td>
                                <td>{{ $trialRequest->review_note ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
