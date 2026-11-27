@extends('layouts.platform')

@section('title', '訂單與收款')

@section('content')
    @php($money = \App\Services\MoneyAmount::class)
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Billing ledger</p>
            <h1 class="page-title">{{ $organization->name }} · 訂單與收款</h1>
            <p class="page-description">依產品方案價格開立訂單並登錄人工收款。收款紀錄只能新增；所有操作都會寫入稽核紀錄。</p>
        </div>
        <a class="button button-secondary" href="{{ route('platform.index') }}">返回組織管理</a>
    </div>

    <section class="panel" aria-labelledby="create-order-title">
        <div class="panel-header">
            <div>
                <h2 class="panel-title" id="create-order-title">開立訂單</h2>
                <p class="panel-description">金額依方案價格自動計算，不可手動改價。</p>
            </div>
        </div>
        <div class="panel-body">
            @if ($sellablePlans->isEmpty())
                <p class="empty-copy">目前沒有可銷售的方案。請先到 <a class="strong-link" href="{{ route('platform.products.index') }}">產品與功能</a> 上架有價格的方案。</p>
            @else
                <form action="{{ route('platform.organizations.billing.orders.store', $organization) }}" method="POST" aria-labelledby="create-order-title">
                    @csrf
                    <div class="form-grid">
                        <div class="field-group">
                            <label class="field-label" for="order-plan">產品方案</label>
                            <select class="field-select" id="order-plan" name="product_plan_id" required>
                                @foreach ($sellablePlans as $plan)
                                    <option value="{{ $plan->id }}" @selected((string) old('product_plan_id') === (string) $plan->id)>
                                        {{ $plan->product->name }} · {{ $plan->name }} · {{ $money::format((int) $plan->amount_minor, $plan->currency_code) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="order-quantity">數量</label>
                            <input class="field-input" id="order-quantity" name="quantity" type="number" min="1" max="65535" value="{{ old('quantity', 1) }}" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="order-starts-on">服務開始日</label>
                            <input class="field-input" id="order-starts-on" name="service_starts_on" type="date" value="{{ old('service_starts_on') }}" required>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="order-ends-on">服務結束日</label>
                            <input class="field-input" id="order-ends-on" name="service_ends_on" type="date" value="{{ old('service_ends_on') }}" required>
                        </div>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="order-notes">備註（選填）</label>
                        <input class="field-input" id="order-notes" name="notes" type="text" maxlength="500" value="{{ old('notes') }}">
                    </div>
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">開立訂單</button>
                    </div>
                </form>
            @endif
        </div>
    </section>

    @forelse ($orders as $order)
        @php($paidMinor = (int) $order->payments->sum('amount_minor'))
        @php($balanceMinor = max(0, $order->total_minor - $paidMinor))
        @php($refundedMinor = (int) $order->refunds->sum('amount_minor'))
        <section class="panel spaced-top" aria-labelledby="order-title-{{ $order->id }}">
            <div class="panel-header">
                <div>
                    <h2 class="panel-title" id="order-title-{{ $order->id }}">{{ $order->order_number }}</h2>
                    <p class="panel-description">
                        {{ $order->statusLabel() }} · 金額 {{ $money::format($order->total_minor, $order->currency_code) }}
                        · 已收 {{ $money::format($paidMinor, $order->currency_code) }}
                        · 未收 {{ $money::format($balanceMinor, $order->currency_code) }}
                        @if ($refundedMinor > 0)· 已退 {{ $money::format($refundedMinor, $order->currency_code) }}@endif
                        · 開立於 <time datetime="{{ $order->issued_at->toIso8601String() }}">{{ $order->issued_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                    </p>
                    @if ($order->cancellation_reason !== null)
                        <p class="secondary-text">取消原因：{{ $order->cancellation_reason }}</p>
                    @endif
                    @if ($order->notes !== null)
                        <p class="secondary-text">備註：{{ $order->notes }}</p>
                    @endif
                </div>
            </div>

            <div class="data-table-wrap" role="region" aria-label="{{ $order->order_number }} 訂單項目" tabindex="0">
                <table class="data-table">
                    <caption class="sr-only">訂單項目、數量、單價、小計與服務期間</caption>
                    <thead>
                        <tr>
                            <th scope="col">項目</th>
                            <th scope="col">數量</th>
                            <th scope="col">單價</th>
                            <th scope="col">小計</th>
                            <th scope="col">服務期間</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($order->items as $item)
                            <tr>
                                <th scope="row">{{ $item->description }}</th>
                                <td>{{ $item->quantity }}</td>
                                <td>{{ $money::format($item->unit_amount_minor) }}</td>
                                <td>{{ $money::format($item->amount_minor) }}</td>
                                <td>{{ $item->service_starts_on->format('Y/n/j') }} – {{ $item->service_ends_on->format('Y/n/j') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="panel-body">
                <h3 class="section-title">收款紀錄</h3>
                @forelse ($order->payments as $payment)
                    <p class="secondary-text">
                        <time datetime="{{ $payment->received_at->toIso8601String() }}">{{ $payment->received_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                        · {{ \App\Models\BillingPayment::methodLabel($payment->method) }}
                        · {{ $money::format($payment->amount_minor, $payment->currency_code) }}
                        @if ($payment->reference !== null)· 參考：{{ $payment->reference }}@endif
                        · 登錄者：{{ $payment->recordedBy?->name ?? '已刪除帳號' }}
                    </p>
                @empty
                    <p class="secondary-text">尚未收款。</p>
                @endforelse

                @if ($order->refunds->isNotEmpty())
                    <h3 class="section-title spaced-top">退款紀錄</h3>
                    @foreach ($order->refunds as $refund)
                        <p class="secondary-text">
                            <time datetime="{{ $refund->refunded_at->toIso8601String() }}">{{ $refund->refunded_at->timezone('Asia/Taipei')->format('Y/n/j H:i') }}</time>
                            · {{ \App\Models\BillingPayment::methodLabel($refund->method) }}
                            · {{ $money::format($refund->amount_minor, $refund->currency_code) }}
                            · 原因：{{ $refund->reason }}
                            @if ($refund->reference !== null)· 參考：{{ $refund->reference }}@endif
                            · 登錄者：{{ $refund->recordedBy?->name ?? '已刪除帳號' }}
                        </p>
                    @endforeach
                @endif

                @if ($order->status === \App\Models\BillingOrder::STATUS_PAID)
                    <details class="spaced-top">
                        <summary>登錄退款</summary>
                        <p class="field-help">全額退款後訂單會標示為已退款；退款不會自動停用服務，如需停用請另行調整訂閱。</p>
                        <form action="{{ route('platform.organizations.billing.refunds.store', [$organization, $order]) }}" method="POST">
                            @csrf
                            <div class="form-grid">
                                <div class="field-group">
                                    <label class="field-label" for="refund-amount-{{ $order->id }}">退款金額（{{ $order->currency_code }}）</label>
                                    <input class="field-input" id="refund-amount-{{ $order->id }}" name="refund_amount" type="text" inputmode="decimal" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="refund-method-{{ $order->id }}">退款方式</label>
                                    <select class="field-select" id="refund-method-{{ $order->id }}" name="refund_method" required>
                                        @foreach ($paymentMethods as $method)
                                            <option value="{{ $method }}">{{ \App\Models\BillingPayment::methodLabel($method) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="refunded-at-{{ $order->id }}">退款時間（台灣時間）</label>
                                    <input class="field-input" id="refunded-at-{{ $order->id }}" name="refunded_at" type="datetime-local" value="{{ now('Asia/Taipei')->format('Y-m-d\TH:i') }}" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="refund-reference-{{ $order->id }}">交易參考（選填）</label>
                                    <input class="field-input" id="refund-reference-{{ $order->id }}" name="refund_reference" type="text" maxlength="120">
                                </div>
                            </div>
                            <div class="field-group">
                                <label class="field-label" for="refund-reason-{{ $order->id }}">退款原因</label>
                                <input class="field-input" id="refund-reason-{{ $order->id }}" name="refund_reason" type="text" maxlength="255" required>
                            </div>
                            <div class="form-actions">
                                <button class="button button-danger" type="submit">登錄退款</button>
                            </div>
                        </form>
                    </details>
                @endif

                @if ($order->status === \App\Models\BillingOrder::STATUS_ISSUED)
                    <details class="spaced-top">
                        <summary>登錄收款</summary>
                        <form action="{{ route('platform.organizations.billing.payments.store', [$organization, $order]) }}" method="POST">
                            @csrf
                            <div class="form-grid">
                                <div class="field-group">
                                    <label class="field-label" for="payment-amount-{{ $order->id }}">收款金額（{{ $order->currency_code }}）</label>
                                    <input class="field-input" id="payment-amount-{{ $order->id }}" name="amount" type="text" inputmode="decimal" value="{{ $money::toDecimal($balanceMinor) }}" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="payment-method-{{ $order->id }}">收款方式</label>
                                    <select class="field-select" id="payment-method-{{ $order->id }}" name="method" required>
                                        @foreach ($paymentMethods as $method)
                                            <option value="{{ $method }}">{{ \App\Models\BillingPayment::methodLabel($method) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="payment-received-at-{{ $order->id }}">收款時間（台灣時間）</label>
                                    <input class="field-input" id="payment-received-at-{{ $order->id }}" name="received_at" type="datetime-local" value="{{ now('Asia/Taipei')->format('Y-m-d\TH:i') }}" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="payment-reference-{{ $order->id }}">交易參考（選填）</label>
                                    <input class="field-input" id="payment-reference-{{ $order->id }}" name="reference" type="text" maxlength="120" aria-describedby="payment-reference-help-{{ $order->id }}">
                                    <p class="field-help" id="payment-reference-help-{{ $order->id }}">例如匯款末五碼或收據編號；請勿輸入完整帳號。</p>
                                </div>
                            </div>
                            <div class="form-actions">
                                <button class="button button-primary" type="submit">登錄收款</button>
                            </div>
                        </form>
                    </details>

                    @if ($order->payments->isEmpty())
                        <details class="spaced-top">
                            <summary>取消訂單</summary>
                            <form action="{{ route('platform.organizations.billing.orders.cancel', [$organization, $order]) }}" method="POST">
                                @csrf
                                <div class="field-group">
                                    <label class="field-label" for="cancel-reason-{{ $order->id }}">取消原因</label>
                                    <input class="field-input" id="cancel-reason-{{ $order->id }}" name="reason" type="text" maxlength="255" required>
                                </div>
                                <div class="form-actions">
                                    <button class="button button-danger" type="submit">取消訂單</button>
                                </div>
                            </form>
                        </details>
                    @endif
                @endif
            </div>
        </section>
    @empty
        <section class="panel spaced-top" aria-labelledby="no-orders-title">
            <div class="empty-state">
                <h2 class="empty-title" id="no-orders-title">尚無訂單</h2>
                <p class="empty-copy">開立訂單後，訂單、收款紀錄與未收餘額會顯示於此處。</p>
            </div>
        </section>
    @endforelse
@endsection
