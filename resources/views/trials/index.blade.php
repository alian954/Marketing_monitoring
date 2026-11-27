@extends('layouts.app')

@section('title', '申請試用')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Product trials</p>
            <h1 class="page-title">申請試用</h1>
            <p class="page-description">選擇想試用的產品方案並送出申請；平台審核通過後即開通，試用期間依核准天數計算。每項產品限試用一次。</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-error" role="alert" aria-labelledby="trial-errors-title">
            <p class="alert-title" id="trial-errors-title">無法送出試用申請</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($isPlatformAdmin)
        <div class="alert alert-warning" role="status">平台管理員請至「<a class="strong-link" href="{{ route('platform.trial-requests.index') }}">試用申請</a>」審核組織的申請。</div>
    @elseif ($organization === null)
        <div class="alert alert-warning" role="status">此帳號尚未建立組織，暫時無法申請試用。請聯絡平台管理員。</div>
    @else
        <section class="panel" aria-labelledby="trial-products-title">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title" id="trial-products-title">可試用的產品</h2>
                    <p class="panel-description">{{ $organization->name }}</p>
                </div>
            </div>
            @if ($plansByProduct->isEmpty())
                <div class="empty-state">
                    <h3 class="empty-title">目前沒有開放試用的產品</h3>
                    <p class="empty-copy">平台開放新方案後，會顯示於此處。</p>
                </div>
            @else
                <div class="panel-body section-stack">
                    @foreach ($plansByProduct as $productId => $plans)
                        @php($product = $plans->first()->product)
                        @php($subscriptionItem = $subscriptionItems->get($productId))
                        @php($isPending = in_array($productId, $pendingProductIds, true))
                        <article class="platform-plan-editor" aria-labelledby="trial-product-{{ $productId }}">
                            <div class="platform-catalog-header">
                                <h3 class="panel-title" id="trial-product-{{ $productId }}">{{ $product->name }}</h3>
                                @if ($subscriptionItem !== null)
                                    <span class="platform-plan-status platform-plan-status-active">{{ $subscriptionItem->subscription?->status === 'trialing' ? '試用中' : '已訂閱' }}</span>
                                @elseif ($isPending)
                                    <span class="platform-plan-status platform-plan-status-pending">審核中</span>
                                @endif
                            </div>
                            @if ($subscriptionItem !== null)
                                @if ($subscriptionItem->subscription?->status === 'trialing' && $subscriptionItem->subscription->trial_ends_at !== null)
                                    <p class="field-help">試用至 {{ $subscriptionItem->subscription->trial_ends_at->timezone('Asia/Taipei')->format('Y-m-d H:i') }}（台灣時間）。如需續用，請聯絡平台開立訂單。</p>
                                @else
                                    <p class="field-help">組織已訂閱或試用過此產品。</p>
                                @endif
                            @elseif ($isPending)
                                <p class="field-help">申請已送出，平台審核後會開通試用。</p>
                            @else
                                <form action="{{ route('trials.store') }}" method="POST" class="platform-plan-form" aria-label="申請試用 {{ $product->name }}">
                                    @csrf
                                    <div class="form-grid platform-plan-fields">
                                        <div class="field-group">
                                            <label class="field-label" for="trial-plan-{{ $productId }}">方案</label>
                                            <select class="field-select" id="trial-plan-{{ $productId }}" name="product_plan_id" required>
                                                @foreach ($plans as $plan)
                                                    <option value="{{ $plan->id }}" @selected((string) old('product_plan_id') === (string) $plan->id)>{{ $plan->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="field-group">
                                            <label class="field-label" for="trial-message-{{ $productId }}">申請說明（選填）</label>
                                            <textarea class="field-textarea" id="trial-message-{{ $productId }}" name="message" maxlength="500" rows="2" placeholder="例如：預計試用的客戶數或使用情境"></textarea>
                                        </div>
                                    </div>
                                    <div class="form-actions">
                                        <button class="button button-primary" type="submit">申請試用</button>
                                    </div>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="panel spaced-top" aria-labelledby="trial-history-title">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title" id="trial-history-title">申請紀錄</h2>
                    <p class="panel-description">時間以台灣時間顯示。</p>
                </div>
            </div>
            @if ($trialRequests->isEmpty())
                <div class="empty-state">
                    <h3 class="empty-title">尚無試用申請</h3>
                    <p class="empty-copy">送出申請後會顯示審核進度。</p>
                </div>
            @else
                <div class="data-table-wrap" role="region" aria-labelledby="trial-history-title" tabindex="0">
                    <table class="data-table">
                        <caption class="sr-only">申請時間、產品、方案、狀態與審核說明</caption>
                        <thead>
                            <tr>
                                <th scope="col">申請時間</th>
                                <th scope="col">產品</th>
                                <th scope="col">方案</th>
                                <th scope="col">狀態</th>
                                <th scope="col">審核說明</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trialRequests as $trialRequest)
                                <tr>
                                    <td>{{ $trialRequest->created_at->timezone('Asia/Taipei')->format('Y-m-d H:i') }}</td>
                                    <td>{{ $trialRequest->product?->name }}</td>
                                    <td>{{ $trialRequest->plan?->name }}</td>
                                    <td>
                                        <span class="platform-plan-status platform-plan-status-{{ $trialRequest->status }}">{{ $trialRequest->statusLabel() }}</span>
                                        @if ($trialRequest->trial_days !== null)
                                            <span class="field-help">{{ $trialRequest->trial_days }} 天</span>
                                        @endif
                                    </td>
                                    <td>{{ $trialRequest->review_note ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
