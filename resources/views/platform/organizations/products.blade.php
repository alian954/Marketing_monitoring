@extends('layouts.app')

@section('title', '已封存的組織訂閱')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Organization products</p>
            <h1 class="page-title">{{ $organization->name }} · 已封存的訂閱</h1>
            <p class="page-description">訂閱合約資料保留封存；開立訂單與登錄收款請使用「<a class="strong-link" href="{{ route('platform.organizations.billing', $organization) }}">訂單與收款</a>」。</p>
        </div>
        <a class="button button-secondary" href="{{ route('platform.index') }}">返回組織管理</a>
    </div>

    <details class="platform-plan-editor" aria-labelledby="archived-subscriptions-summary">
        <summary id="archived-subscriptions-summary"><strong>封存的產品訂閱管理</strong></summary>
        <p class="field-help">本階段先治理中央產品目錄與功能模組，不新增訂閱、訂單、收款或金流流程。</p>
    <section class="panel" aria-labelledby="create-subscription-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="create-subscription-title">建立訂閱合約</h2>
                <p class="panel-description">建立合約後，再加入一項或多項產品。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($errors->getBag('subscription')->any())
                <div class="alert alert-error" role="alert" aria-labelledby="subscription-errors-title">
                    <p class="alert-title" id="subscription-errors-title">請檢查訂閱資料</p>
                    <ul>@foreach ($errors->getBag('subscription')->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif
            <form action="{{ route('platform.organizations.subscriptions.store', $organization) }}" method="POST" class="platform-plan-form" aria-labelledby="create-subscription-title">
                @csrf
                <div class="form-grid platform-plan-fields">
                    <div class="field-group">
                        <label class="field-label" for="subscription-status">訂閱狀態</label>
                        <select class="field-select" id="subscription-status" name="status" required>
                            <option value="active" @selected(old('status') === 'active' || old('status') === null)>使用中</option>
                            <option value="trialing" @selected(old('status') === 'trialing')>試用中</option>
                            <option value="pending" @selected(old('status') === 'pending')>待啟用</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="subscription-starts-at">合約開始</label>
                        <input class="field-input" id="subscription-starts-at" name="starts_at" type="datetime-local" value="{{ old('starts_at', now('Asia/Taipei')->format('Y-m-d\TH:i')) }}" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="subscription-period-starts-at">本期開始</label>
                        <input class="field-input" id="subscription-period-starts-at" name="current_period_starts_at" type="datetime-local" value="{{ old('current_period_starts_at', now('Asia/Taipei')->format('Y-m-d\TH:i')) }}" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="subscription-period-ends-at">本期結束</label>
                        <input class="field-input" id="subscription-period-ends-at" name="current_period_ends_at" type="datetime-local" value="{{ old('current_period_ends_at') }}">
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="subscription-trial-ends-at">試用到期</label>
                        <input class="field-input" id="subscription-trial-ends-at" name="trial_ends_at" type="datetime-local" value="{{ old('trial_ends_at') }}">
                    </div>
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">建立合約</button>
                </div>
            </form>
        </div>
    </section>

    <section class="section-stack spaced-top" aria-labelledby="subscriptions-title">
        <div class="page-heading compact-heading">
            <div class="heading-copy">
                <h2 class="section-title" id="subscriptions-title">訂閱紀錄</h2>
            </div>
        </div>
        @forelse ($subscriptions as $subscription)
            @php($showStateErrors = (string) old('subscription_id') === (string) $subscription->id && $errors->getBag('subscriptionState')->any())
            <article class="panel" aria-labelledby="subscription-title-{{ $subscription->id }}">
                <div class="panel-header platform-catalog-header">
                    <div>
                        <h3 class="panel-title" id="subscription-title-{{ $subscription->id }}">訂閱 #{{ $subscription->id }}</h3>
                        <p class="panel-description">{{ $subscription->starts_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') }} 開始 · 本期至 {{ $subscription->current_period_ends_at?->timezone('Asia/Taipei')->format('Y-m-d H:i') ?? '未設定' }}</p>
                    </div>
                    <span class="platform-plan-status platform-plan-status-{{ $subscription->status }}">{{ $subscription->status }}</span>
                </div>
                <div class="panel-body section-stack">
                    @if ($showStateErrors)
                        <div class="alert alert-error" role="alert" aria-labelledby="subscription-state-errors-{{ $subscription->id }}">
                            <p id="subscription-state-errors-{{ $subscription->id }}" class="alert-title">請檢查訂閱設定</p>
                            <ul>@foreach ($errors->getBag('subscriptionState')->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                        </div>
                    @endif
                    <form action="{{ route('platform.organizations.subscriptions.update', [$organization, $subscription]) }}" method="POST" class="platform-plan-form" aria-label="更新訂閱 #{{ $subscription->id }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="subscription_id" value="{{ $subscription->id }}">
                        <div class="form-grid platform-plan-fields">
                            <div class="field-group">
                                <label class="field-label" for="subscription-{{ $subscription->id }}-status">訂閱狀態</label>
                                <select class="field-select" id="subscription-{{ $subscription->id }}-status" name="status" required>
                                    @foreach (['pending' => '待啟用', 'trialing' => '試用中', 'active' => '使用中', 'past_due' => '逾期', 'paused' => '暫停', 'canceled' => '已取消', 'expired' => '已到期'] as $value => $label)
                                        <option value="{{ $value }}" @selected(($showStateErrors ? old('status') : $subscription->status) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="subscription-{{ $subscription->id }}-starts-at">合約開始</label>
                                <input class="field-input" id="subscription-{{ $subscription->id }}-starts-at" name="starts_at" type="datetime-local" value="{{ $showStateErrors ? old('starts_at') : $subscription->starts_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i') }}" required>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="subscription-{{ $subscription->id }}-period-starts-at">本期開始</label>
                                <input class="field-input" id="subscription-{{ $subscription->id }}-period-starts-at" name="current_period_starts_at" type="datetime-local" value="{{ $showStateErrors ? old('current_period_starts_at') : $subscription->current_period_starts_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i') }}" required>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="subscription-{{ $subscription->id }}-period-ends-at">本期結束</label>
                                <input class="field-input" id="subscription-{{ $subscription->id }}-period-ends-at" name="current_period_ends_at" type="datetime-local" value="{{ $showStateErrors ? old('current_period_ends_at') : $subscription->current_period_ends_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i') }}">
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="subscription-{{ $subscription->id }}-trial-ends-at">試用到期</label>
                                <input class="field-input" id="subscription-{{ $subscription->id }}-trial-ends-at" name="trial_ends_at" type="datetime-local" value="{{ $showStateErrors ? old('trial_ends_at') : $subscription->trial_ends_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i') }}">
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="subscription-{{ $subscription->id }}-cancel-at-period-end">期末取消</label>
                                <select class="field-select" id="subscription-{{ $subscription->id }}-cancel-at-period-end" name="cancel_at_period_end" required>
                                    <option value="0" @selected(($showStateErrors ? old('cancel_at_period_end') : (int) $subscription->cancel_at_period_end) == 0)>否</option>
                                    <option value="1" @selected(($showStateErrors ? old('cancel_at_period_end') : (int) $subscription->cancel_at_period_end) == 1)>是</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-actions"><button class="button button-secondary" type="submit">儲存訂閱</button></div>
                    </form>

                    @if ($subscription->items->isNotEmpty())
                        <div class="data-table-wrap" tabindex="0" role="region" aria-labelledby="subscription-items-caption-{{ $subscription->id }}">
                            <table class="data-table platform-items-table">
                                <caption class="sr-only" id="subscription-items-caption-{{ $subscription->id }}">訂閱 #{{ $subscription->id }}的產品項目</caption>
                                <thead><tr><th scope="col">產品與方案</th><th scope="col">狀態</th><th scope="col">數量</th><th scope="col">操作</th></tr></thead>
                                <tbody>
                                    @foreach ($subscription->items as $item)
                                        @php($showItemErrors = (string) old('subscription_item_id') === (string) $item->id && $errors->getBag('subscriptionItemState')->any())
                                        <tr>
                                            <th scope="row">{{ $item->product->name }} · {{ $item->plan->name }} <span class="secondary-text">{{ $item->plan->currency_code ?? '' }} {{ $item->plan->amount_minor === null ? '未定價' : number_format(intdiv($item->plan->amount_minor, 100)).'.'.str_pad((string) ($item->plan->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}</span></th>
                                            <td>{{ ['active' => '使用中', 'pending' => '待啟用', 'suspended' => '已暫停', 'canceled' => '已取消'][$item->status] ?? $item->status }}</td>
                                            <td>{{ number_format($item->quantity) }}</td>
                                            <td>
                                                @if ($showItemErrors)
                                                    <div class="alert alert-error" role="alert"><ul>@foreach ($errors->getBag('subscriptionItemState')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                                                @endif
                                                <form action="{{ route('platform.organizations.subscriptions.items.update', [$organization, $subscription, $item]) }}" method="POST" class="platform-item-editor" aria-label="更新{{ $item->product->name }}訂閱項目">
                                                    @csrf
                                                    @method('PATCH')
                                                    <input type="hidden" name="subscription_item_id" value="{{ $item->id }}">
                                                    <label class="sr-only" for="item-{{ $item->id }}-status">{{ $item->product->name }}狀態</label>
                                                    <select class="field-select" id="item-{{ $item->id }}-status" name="status" required>
                                                        @foreach (['active' => '使用中', 'pending' => '待啟用', 'suspended' => '已暫停', 'canceled' => '已取消'] as $value => $label)
                                                            <option value="{{ $value }}" @selected(($showItemErrors ? old('status') : $item->status) === $value)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                    <label class="sr-only" for="item-{{ $item->id }}-plan">{{ $item->product->name }}方案</label>
                                                    <select class="field-select" id="item-{{ $item->id }}-plan" name="product_plan_id" required>
                                                        @foreach (($products->firstWhere('id', $item->product_id)?->plans ?? collect())->filter(fn ($plan) => $plan->status === 'active' || (int) $plan->id === (int) $item->product_plan_id) as $plan)
                                                            <option value="{{ $plan->id }}" @selected((string) ($showItemErrors ? old('product_plan_id') : $item->product_plan_id) === (string) $plan->id)>{{ $plan->name }} · v{{ $plan->version }}</option>
                                                        @endforeach
                                                    </select>
                                                    <label class="sr-only" for="item-{{ $item->id }}-quantity">{{ $item->product->name }}數量</label>
                                                    <input class="field-input" id="item-{{ $item->id }}-quantity" name="quantity" type="number" min="1" max="65535" step="1" value="{{ $showItemErrors ? old('quantity', $item->quantity) : $item->quantity }}" required>
                                                    <label class="sr-only" for="item-{{ $item->id }}-starts-at">{{ $item->product->name }}開始時間</label>
                                                    <input class="field-input" id="item-{{ $item->id }}-starts-at" name="starts_at" type="datetime-local" value="{{ $showItemErrors ? old('starts_at') : $item->starts_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i') }}" required>
                                                    <label class="sr-only" for="item-{{ $item->id }}-ends-at">{{ $item->product->name }}結束時間</label>
                                                    <input class="field-input" id="item-{{ $item->id }}-ends-at" name="ends_at" type="datetime-local" value="{{ $showItemErrors ? old('ends_at') : $item->ends_at?->timezone('Asia/Taipei')->format('Y-m-d\TH:i') }}">
                                                    <button class="button button-secondary button-small" type="submit">儲存</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    @if (in_array($subscription->status, ['pending', 'trialing', 'active'], true))
                        @php($showItemCreateErrors = (string) old('subscription_id') === (string) $subscription->id && $errors->getBag('subscriptionItem')->any())
                        <section class="platform-plan-editor" aria-labelledby="add-product-title-{{ $subscription->id }}">
                            <h4 class="section-title" id="add-product-title-{{ $subscription->id }}">加入產品</h4>
                            @if ($showItemCreateErrors)
                                <div class="alert alert-error" role="alert"><ul>@foreach ($errors->getBag('subscriptionItem')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                            @endif
                            <form action="{{ route('platform.organizations.subscriptions.items.store', [$organization, $subscription]) }}" method="POST" class="platform-plan-form" aria-labelledby="add-product-title-{{ $subscription->id }}">
                                @csrf
                                <input type="hidden" name="subscription_id" value="{{ $subscription->id }}">
                                <div class="form-grid platform-plan-fields">
                                    <div class="field-group">
                                        <label class="field-label" for="add-product-plan-{{ $subscription->id }}">產品方案</label>
                                        <select class="field-select" id="add-product-plan-{{ $subscription->id }}" name="product_plan_id" required>
                                            <option value="">選擇方案</option>
                                            @foreach ($products as $product)
                                                @if ($product->lifecycle === 'active' && $product->plans->contains('status', 'active'))
                                                    <optgroup label="{{ $product->name }}">
                                                        @foreach ($product->plans->where('status', 'active') as $plan)
                                                            <option value="{{ $plan->id }}" @selected($showItemCreateErrors && (string) old('product_plan_id') === (string) $plan->id)>{{ $plan->name }} · {{ $plan->currency_code ?? '未定價' }} {{ $plan->amount_minor === null ? '' : number_format(intdiv($plan->amount_minor, 100)).'.'.str_pad((string) ($plan->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}</option>
                                                        @endforeach
                                                    </optgroup>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="add-product-quantity-{{ $subscription->id }}">數量</label>
                                        <input class="field-input" id="add-product-quantity-{{ $subscription->id }}" name="quantity" type="number" min="1" max="65535" step="1" value="{{ $showItemCreateErrors ? old('quantity', 1) : 1 }}" required>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="add-product-starts-at-{{ $subscription->id }}">開始時間</label>
                                        <input class="field-input" id="add-product-starts-at-{{ $subscription->id }}" name="starts_at" type="datetime-local" value="{{ $showItemCreateErrors ? old('starts_at') : now('Asia/Taipei')->format('Y-m-d\TH:i') }}" required>
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label" for="add-product-ends-at-{{ $subscription->id }}">結束時間</label>
                                        <input class="field-input" id="add-product-ends-at-{{ $subscription->id }}" name="ends_at" type="datetime-local" value="{{ $showItemCreateErrors ? old('ends_at') : '' }}">
                                    </div>
                                </div>
                                <div class="form-actions"><button class="button button-primary" type="submit">加入產品</button></div>
                            </form>
                        </section>
                    @endif
                </div>
            </article>
        @empty
            <section class="panel"><div class="empty-state"><h3 class="empty-title">尚無產品訂閱</h3><p class="empty-copy">建立上方的訂閱合約後，即可加入產品。</p></div></section>
        @endforelse
    </section>
    </details>
@endsection
