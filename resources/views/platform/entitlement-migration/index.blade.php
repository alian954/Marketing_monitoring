@extends('layouts.platform')

@section('title', '授權切換')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Entitlement migration</p>
            <h1 class="page-title">舊客戶授權切換</h1>
            <p class="page-description">
                {{ number_format($rows->count()) }} 個組織仍使用舊方案模式，其中 {{ number_format($readyCount) }} 個啟用訂閱授權後不會失去功能。
                先為缺少功能的組織建立對照訂閱（到期日沿用舊方案到期日），確認「可啟用」後再批次啟用。
            </p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert" aria-labelledby="migration-errors-title">
            <p class="alert-title" id="migration-errors-title">無法處理</p>
            <ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($rows->isEmpty())
        <section class="panel">
            <p class="empty-copy panel-body">所有組織都已依訂閱授權產品功能。</p>
        </section>
    @else
        <form action="{{ route('platform.entitlement-migration.enforce') }}" method="POST" aria-label="舊客戶授權切換">
            @csrf
            <section class="panel" aria-labelledby="migration-plan-title">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title" id="migration-plan-title">對照方案</h2>
                        <p class="panel-description">為每項產品選擇舊客戶要對照的方案；不選表示不建立該產品的訂閱。已訂閱該產品的組織會略過。</p>
                    </div>
                </div>
                <div class="panel-body">
                    @if ($plansByProduct->isEmpty())
                        <p class="empty-copy">目前沒有可銷售的方案，請先到「<a class="strong-link" href="{{ route('platform.products.index') }}">產品與功能</a>」上架方案。</p>
                    @else
                        <div class="form-grid">
                            @foreach ($plansByProduct as $productId => $plans)
                                <div class="field-group">
                                    <label class="field-label" for="migration-plan-{{ $productId }}">{{ $plans->first()->product->name }}</label>
                                    <select class="field-select" id="migration-plan-{{ $productId }}" name="plan_ids[{{ $productId }}]">
                                        <option value="">不建立</option>
                                        @foreach ($plans as $plan)
                                            <option value="{{ $plan->getKey() }}" @selected((string) old('plan_ids.'.$productId) === (string) $plan->getKey())>{{ $plan->name }}（{{ $plan->plan_code }} v{{ $plan->version }}）</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            <section class="panel spaced-top" aria-labelledby="migration-organizations-title">
                <div class="panel-header">
                    <div>
                        <h2 class="panel-title" id="migration-organizations-title">尚未啟用的組織</h2>
                        <p class="panel-description">勾選後可建立對照訂閱或啟用授權；啟用只會套用到「可啟用」的組織。</p>
                    </div>
                </div>
                <div class="data-table-wrap">
                    <table class="data-table">
                        <caption class="sr-only">尚未啟用訂閱授權的組織、舊方案、狀態與會失去的功能</caption>
                        <thead>
                            <tr>
                                <th scope="col"><span class="sr-only">選取</span></th>
                                <th scope="col">組織</th>
                                <th scope="col">舊方案</th>
                                <th scope="col">啟用後</th>
                                <th scope="col">會失去的功能</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php($organization = $row['organization'])
                                <tr>
                                    <td>
                                        @if ($row['has_active_legacy_access'])
                                            <input type="checkbox" name="organization_ids[]" value="{{ $organization->getKey() }}" id="migration-organization-{{ $organization->getKey() }}" @checked(in_array((string) $organization->getKey(), array_map('strval', (array) old('organization_ids', [])), true))>
                                        @endif
                                    </td>
                                    <th scope="row">
                                        <label for="migration-organization-{{ $organization->getKey() }}">{{ $organization->name }}</label>
                                        <span class="secondary-text">{{ $organization->owner?->email }}</span>
                                    </th>
                                    <td>
                                        {{ $organization->plan_name ?: '未命名方案' }}
                                        <span class="secondary-text">{{ $organization->subscription_ends_on ? '到期 '.$organization->subscription_ends_on->format('Y/n/j') : '無到期日' }}</span>
                                    </td>
                                    <td>
                                        @if (! $row['has_active_legacy_access'])
                                            已停用或到期
                                        @elseif ($row['missing_features'] === [])
                                            <strong>可啟用</strong>
                                        @else
                                            會失去 {{ count($row['missing_features']) }} 項
                                        @endif
                                    </td>
                                    <td>{{ $row['has_active_legacy_access'] ? (implode('、', $row['missing_features']) ?: '—') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="panel-body form-actions">
                    @if ($plansByProduct->isNotEmpty())
                        <button class="button button-secondary" type="submit" formaction="{{ route('platform.entitlement-migration.subscriptions') }}">為勾選組織建立對照訂閱</button>
                    @endif
                    <button class="button button-primary" type="submit">為勾選組織啟用訂閱授權</button>
                </div>
            </section>
        </form>
    @endif
@endsection
