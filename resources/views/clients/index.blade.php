@extends('layouts.app')

@section('title', '客戶管理')

@section('content')
    <div class="breadcrumbs" aria-label="頁面導覽">
        <a href="{{ route('dashboard') }}">總覽</a><span aria-hidden="true">/</span>
        <span aria-current="page">客戶</span>
    </div>

    <div class="page-heading">
        <div class="heading-copy">
            <h1 class="page-title">客戶</h1>
            <p class="page-description">依客戶整理監測項目與活動。</p>
        </div>
    </div>

    @if ($errors->getBag('clientManagement')->any())
        <div class="alert alert-error" role="alert">
            @foreach ($errors->getBag('clientManagement')->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <section class="panel spaced-top" aria-labelledby="client-create-title">
        <div class="panel-header">
            <div>
                <h2 id="client-create-title" class="panel-title">新增客戶</h2>
            </div>
        </div>
        <div class="panel-body">
            <form action="{{ route('clients.store') }}" method="POST" class="client-create-form" aria-labelledby="client-create-title">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="new-client-name">客戶名稱</label>
                    <input class="field-input{{ $errors->getBag('createClient')->has('name') ? ' is-invalid' : '' }}" id="new-client-name" name="name" type="text" maxlength="120" value="{{ old('name', '', 'createClient') }}" autocomplete="organization" required aria-describedby="new-client-name-help{{ $errors->getBag('createClient')->has('name') ? ' new-client-name-error' : '' }}" @if ($errors->getBag('createClient')->has('name')) aria-invalid="true" @endif>
                    <p id="new-client-name-help" class="field-help">名稱需在此帳戶內唯一。</p>
                    @error('name', 'createClient')<p id="new-client-name-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <button class="button button-primary" type="submit">新增</button>
            </form>
        </div>
    </section>

    <section class="panel spaced-top" aria-labelledby="client-list-title">
        <div class="panel-header">
            <div>
                <h2 id="client-list-title" class="panel-title">客戶清單</h2>
                <p class="panel-description">{{ number_format($clients->count()) }} 個客戶</p>
            </div>
        </div>
        <div class="data-table-wrap">
            <table class="data-table">
                <caption class="sr-only">客戶名稱、監測項目與活動數量</caption>
                <thead>
                    <tr>
                        <th scope="col">客戶</th>
                        <th scope="col">監測項目</th>
                        <th scope="col">活動目標</th>
                        <th scope="col"><span class="sr-only">操作</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($clients as $client)
                        @php
                            $clientUpdateErrors = $errors->getBag('updateClient'.$client->id);
                            $hasAssignments = $client->monitors_count > 0 || $client->social_engagement_targets_count > 0;
                        @endphp
                        <tr>
                            <th scope="row">
                                @if ($client->isDefault())
                                    {{ $client->name }} <span class="secondary-text">預設分類</span>
                                @else
                                    <form action="{{ route('clients.update', $client) }}" method="POST" class="client-name-edit">
                                        @csrf
                                        @method('PUT')
                                        <label class="sr-only" for="client-name-{{ $client->id }}">更新{{ $client->name }}名稱</label>
                                        <input class="field-input{{ $clientUpdateErrors->has('name') ? ' is-invalid' : '' }}" id="client-name-{{ $client->id }}" name="name" type="text" maxlength="120" value="{{ old('name', $client->name, 'updateClient'.$client->id) }}" required aria-describedby="client-name-help-{{ $client->id }}{{ $clientUpdateErrors->has('name') ? ' client-name-error-'.$client->id : '' }}" @if ($clientUpdateErrors->has('name')) aria-invalid="true" @endif>
                                        <button class="button button-secondary button-small" type="submit">儲存</button>
                                        <span id="client-name-help-{{ $client->id }}" class="sr-only">名稱需在此帳戶內唯一。</span>
                                        @if ($clientUpdateErrors->has('name'))
                                            <span id="client-name-error-{{ $client->id }}" class="field-error" role="alert">{{ $clientUpdateErrors->first('name') }}</span>
                                        @endif
                                    </form>
                                @endif
                            </th>
                            <td class="numeric">{{ number_format($client->monitors_count) }}</td>
                            <td class="numeric">{{ number_format($client->social_engagement_targets_count) }}</td>
                            <td>
                                <a class="button button-secondary button-small" href="{{ route('clients.report-shares.index', $client) }}">報表分享</a>
                                <a class="button button-secondary button-small" href="{{ route('clients.currencies.index', $client) }}">幣別與匯率</a>
                                @unless ($client->isDefault() || $hasAssignments)
                                    <form action="{{ route('clients.destroy', $client) }}" method="POST" class="inline-form">
                                        @csrf
                                        @method('DELETE')
                                        <button class="button button-quiet button-small" type="submit" aria-label="刪除{{ $client->name }}">刪除</button>
                                    </form>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="field-help panel-body">有監測項目或活動目標的客戶需先移轉資料才能刪除；預設分類不可刪除。</p>
    </section>
@endsection
