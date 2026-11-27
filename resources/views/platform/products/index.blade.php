@extends('layouts.app')

@section('title', '產品與功能')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Platform catalog</p>
            <h1 class="page-title">產品與功能</h1>
            <p class="page-description">管理產品登錄、功能模組與入口狀態。</p>
        </div>
        <a class="button button-secondary" href="{{ route('platform.index') }}">返回總管理平台</a>
    </div>

    @if ($products->isEmpty())
        <section class="panel" aria-labelledby="catalog-empty-title">
            <div class="empty-state">
                <h2 class="empty-title" id="catalog-empty-title">產品目錄尚未同步</h2>
                <p class="empty-copy">在專案環境執行 <code>php artisan products:sync</code>，將已登錄產品載入管理目錄。</p>
            </div>
        </section>
    @else
        @foreach ($products as $product)
            @php($manifest = $productManifests[$product->product_code] ?? [])
            @php($productFormId = 'product-plan-create-'.$product->id)
            @php($showPlanErrors = (string) old('product_id') === (string) $product->id && $errors->getBag('productPlan')->any())
            @php($showLifecycleErrors = (string) old('product_id') === (string) $product->id && $errors->getBag('productLifecycle')->any())
            @php($showPlanStatusErrors = (string) old('product_plan_id') !== '' && $errors->getBag('productPlanStatus')->any() && $product->plans->contains('id', (int) old('product_plan_id')))
            <section class="panel spaced-top" aria-labelledby="product-title-{{ $product->id }}">
                <div class="panel-header platform-catalog-header">
                    <div>
                        <h2 class="panel-title" id="product-title-{{ $product->id }}">{{ $product->name }}</h2>
                        <p class="panel-description">{{ $product->product_code }} · 版本 {{ $product->version }} · {{ ['active' => '啟用中', 'maintenance' => '維護中', 'retired' => '已停售'][$product->lifecycle] ?? $product->lifecycle }}</p>
                    </div>
                    <span class="platform-chip">{{ $product->features->count() }} 項功能</span>
                </div>

                <div class="panel-body">
                    <section aria-labelledby="product-features-title-{{ $product->id }}">
                        <h3 class="section-title" id="product-features-title-{{ $product->id }}">已登錄功能</h3>
                        <div class="platform-entitlement-grid">
                            @foreach ($product->features as $feature)
                                @php($featureRouteNames = collect($manifest['route_features'] ?? [])->filter(fn (array $featureKeys): bool => in_array($feature->feature_key, $featureKeys, true))->keys())
                                <article class="platform-entitlement-field">
                                    <strong>{{ $feature->display_name }}</strong>
                                    <p class="secondary-text"><code>{{ $feature->feature_key }}</code></p>
                                    <details>
                                        <summary>{{ $featureRouteNames->count() }} 個功能入口</summary>
                                        @if ($featureRouteNames->isNotEmpty())
                                            <ul>
                                                @foreach ($featureRouteNames as $routeName)
                                                    <li><code>{{ $routeName }}</code></li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <p class="secondary-text">尚未連結產品入口。</p>
                                        @endif
                                    </details>
                                </article>
                            @endforeach
                        </div>
                    </section>

                    <section class="platform-plan-editor" aria-labelledby="product-lifecycle-title-{{ $product->id }}">
                        <h3 class="section-title" id="product-lifecycle-title-{{ $product->id }}">產品狀態</h3>
                        <form action="{{ route('platform.products.lifecycle.update', $product) }}" method="POST" class="platform-plan-form" aria-labelledby="product-lifecycle-title-{{ $product->id }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            <div class="form-grid platform-plan-fields">
                                <div class="field-group">
                                    <label class="field-label" for="product-lifecycle-{{ $product->id }}">可用狀態</label>
                                    <select class="field-select" id="product-lifecycle-{{ $product->id }}" name="lifecycle" required>
                                        <option value="active" @selected($product->lifecycle === 'active')>啟用中</option>
                                        <option value="maintenance" @selected($product->lifecycle === 'maintenance')>維護中</option>
                                        <option value="retired" @selected($product->lifecycle === 'retired')>已停售</option>
                                    </select>
                                </div>
                            </div>
                            @if ($showLifecycleErrors)
                                <div class="alert alert-error" role="alert" aria-label="產品狀態錯誤">
                                    @foreach ($errors->getBag('productLifecycle')->all() as $error)<p>{{ $error }}</p>@endforeach
                                </div>
                            @endif
                            <div class="form-actions"><button class="button button-secondary" type="submit">儲存產品狀態</button></div>
                        </form>
                    </section>

                    <details class="platform-plan-editor" @if ($showPlanErrors || $showPlanStatusErrors) open @endif>
                        <summary><strong>封存項目：方案與訂閱設定</strong></summary>
                        <p class="field-help">本階段先處理產品與功能目錄；方案、訂閱、訂單及金流暫緩。既有資料與管理程式保留供後續使用。</p>
                    @if ($product->plans->isNotEmpty())
                        <div class="data-table-wrap" tabindex="0" role="region" aria-labelledby="product-plans-caption-{{ $product->id }}">
                            <table class="data-table platform-plans-table">
                                <caption class="sr-only" id="product-plans-caption-{{ $product->id }}">{{ $product->name }}的方案與功能權益</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">方案</th>
                                        <th scope="col">價格</th>
                                        <th scope="col">功能權益</th>
                                        <th scope="col">狀態</th>
                                        <th scope="col">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($product->plans as $plan)
                                        <tr>
                                            <th scope="row">
                                                {{ $plan->name }}
                                                <span class="secondary-text">{{ $plan->plan_code }} · v{{ $plan->version }} · {{ $plan->billing_interval }}</span>
                                            </th>
                                            <td>
                                                @if ($plan->amount_minor === null || $plan->currency_code === null)
                                                    未定價
                                                @else
                                                    {{ $plan->currency_code }} {{ number_format(intdiv($plan->amount_minor, 100)) }}.{{ str_pad((string) ($plan->amount_minor % 100), 2, '0', STR_PAD_LEFT) }}
                                                @endif
                                            </td>
                                            <td>
                                                @foreach ($plan->entitlements as $entitlement)
                                                    <span class="platform-entitlement-chip {{ $entitlement->is_enabled ? 'is-enabled' : 'is-disabled' }}">
                                                        {{ $product->features->firstWhere('feature_key', $entitlement->feature_key)?->display_name ?? $entitlement->feature_key }}
                                                        @if ($entitlement->is_enabled && $entitlement->limit_value !== null)
                                                            · {{ number_format($entitlement->limit_value) }}
                                                        @endif
                                                    </span>
                                                @endforeach
                                            </td>
                                            <td><span class="platform-plan-status platform-plan-status-{{ $plan->status }}">{{ ['draft' => '草稿', 'active' => '銷售中', 'retired' => '已停售'][$plan->status] ?? $plan->status }}</span></td>
                                            <td>
                                                @if ($plan->status !== 'draft')
                                                    <form action="{{ route('platform.products.plans.update', $plan) }}" method="POST" class="platform-inline-form" aria-label="更新{{ $plan->name }}狀態">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="product_plan_id" value="{{ $plan->id }}">
                                                        <input type="hidden" name="status" value="{{ $plan->status === 'active' ? 'retired' : 'active' }}">
                                                        <button class="button button-secondary button-small" type="submit">{{ $plan->status === 'active' ? '停售' : '重新上架' }}</button>
                                                    </form>
                                                @else
                                                    <span class="secondary-text">完成後再上架</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="empty-copy">尚無方案，請建立第一個方案版本。</p>
                    @endif

                    @if ($showPlanStatusErrors)
                        <div class="alert alert-error" role="alert" aria-label="方案狀態錯誤">
                            @foreach ($errors->getBag('productPlanStatus')->all() as $error)<p>{{ $error }}</p>@endforeach
                        </div>
                    @endif

                    <section class="platform-plan-editor" aria-labelledby="create-plan-title-{{ $product->id }}">
                        <h3 class="section-title" id="create-plan-title-{{ $product->id }}">新增方案版本</h3>
                        <p class="field-help">已建立的方案資料不直接改寫；價格或權益變更請建立新版本。</p>
                        @if ($showPlanErrors)
                            <div class="alert alert-error" role="alert" aria-labelledby="product-plan-errors-{{ $product->id }}">
                                <p id="product-plan-errors-{{ $product->id }}" class="alert-title">請檢查方案資料</p>
                                <ul>
                                    @foreach ($errors->getBag('productPlan')->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                        <form id="{{ $productFormId }}" action="{{ route('platform.products.plans.store', $product) }}" method="POST" class="platform-plan-form" aria-labelledby="create-plan-title-{{ $product->id }}">
                            @csrf
                            <input type="hidden" name="product_id" value="{{ $product->id }}">
                            <div class="form-grid platform-plan-fields">
                                <div class="field-group">
                                    <label class="field-label" for="plan-code-{{ $product->id }}">方案代碼</label>
                                    <input class="field-input" id="plan-code-{{ $product->id }}" name="plan_code" type="text" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="{{ $showPlanErrors ? old('plan_code') : '' }}" required autocomplete="off" @if($showPlanErrors && $errors->getBag('productPlan')->has('plan_code')) aria-invalid="true" aria-describedby="plan-code-error-{{ $product->id }}" @endif>
                                    @if($showPlanErrors && $errors->getBag('productPlan')->has('plan_code'))<p class="field-error" id="plan-code-error-{{ $product->id }}" role="alert">{{ $errors->getBag('productPlan')->first('plan_code') }}</p>@endif
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="plan-version-{{ $product->id }}">版本</label>
                                    <input class="field-input" id="plan-version-{{ $product->id }}" name="version" type="number" min="1" max="65535" step="1" value="{{ $showPlanErrors ? old('version', 1) : 1 }}" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="plan-name-{{ $product->id }}">方案名稱</label>
                                    <input class="field-input" id="plan-name-{{ $product->id }}" name="name" type="text" maxlength="120" value="{{ $showPlanErrors ? old('name') : '' }}" required>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="plan-status-{{ $product->id }}">建立狀態</label>
                                    <select class="field-select" id="plan-status-{{ $product->id }}" name="status" required>
                                        <option value="draft" @selected(! $showPlanErrors || old('status') === 'draft')>草稿</option>
                                        <option value="active" @selected($showPlanErrors && old('status') === 'active')>銷售中</option>
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="plan-interval-{{ $product->id }}">計費週期</label>
                                    <select class="field-select" id="plan-interval-{{ $product->id }}" name="billing_interval" required>
                                        <option value="monthly" @selected(! $showPlanErrors || old('billing_interval') === 'monthly')>每月</option>
                                        <option value="yearly" @selected($showPlanErrors && old('billing_interval') === 'yearly')>每年</option>
                                        <option value="one_time" @selected($showPlanErrors && old('billing_interval') === 'one_time')>一次性</option>
                                        <option value="custom" @selected($showPlanErrors && old('billing_interval') === 'custom')>自訂</option>
                                    </select>
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="plan-price-{{ $product->id }}">價格</label>
                                    <input class="field-input" id="plan-price-{{ $product->id }}" name="price" type="text" inputmode="decimal" pattern="(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?" value="{{ $showPlanErrors ? old('price') : '' }}" placeholder="留空代表未定價">
                                </div>
                                <div class="field-group">
                                    <label class="field-label" for="plan-currency-{{ $product->id }}">幣別</label>
                                    <select class="field-select" id="plan-currency-{{ $product->id }}" name="currency_code">
                                        <option value="" @selected(! $showPlanErrors || old('currency_code') === null)>未定價</option>
                                        <option value="TWD" @selected($showPlanErrors && old('currency_code') === 'TWD')>TWD</option>
                                        <option value="USD" @selected($showPlanErrors && old('currency_code') === 'USD')>USD</option>
                                    </select>
                                </div>
                            </div>

                            <fieldset class="platform-entitlement-editor">
                                <legend class="section-title">功能權益</legend>
                                <div class="platform-entitlement-grid">
                                    @foreach ($product->features as $feature)
                                        @php($featureEnabled = $showPlanErrors ? (bool) old("features.{$feature->id}.is_enabled", false) : false)
                                        <div class="platform-entitlement-field">
                                            <label class="field-label" for="feature-enabled-{{ $product->id }}-{{ $feature->id }}">{{ $feature->display_name }}</label>
                                            <select class="field-select" id="feature-enabled-{{ $product->id }}-{{ $feature->id }}" name="features[{{ $feature->id }}][is_enabled]" required>
                                                <option value="0" @selected(! $featureEnabled)>停用</option>
                                                <option value="1" @selected($featureEnabled)>啟用</option>
                                            </select>
                                            <label class="field-label" for="feature-limit-{{ $product->id }}-{{ $feature->id }}">用量上限</label>
                                            <input class="field-input" id="feature-limit-{{ $product->id }}-{{ $feature->id }}" name="features[{{ $feature->id }}][limit_value]" type="number" min="0" step="1" value="{{ $showPlanErrors ? old("features.{$feature->id}.limit_value") : '' }}" placeholder="留空代表不限">
                                        </div>
                                    @endforeach
                                </div>
                            </fieldset>
                            <div class="form-actions">
                                <button class="button button-primary" type="submit">建立方案</button>
                            </div>
                        </form>
                    </section>
                    </details>
                </div>
            </section>
        @endforeach
    @endif
@endsection
