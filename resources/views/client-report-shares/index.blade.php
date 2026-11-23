<div>
    <!-- An unexamined life is not worth living. - Socrates -->
</div>
@extends('layouts.app')

@section('title', '客戶報表分享')

@section('content')
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
            <form action="{{ route('clients.report-shares.store', $client) }}" method="POST" class="client-create-form" aria-labelledby="create-report-share-title">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="report-share-name">連結名稱</label>
                    <input class="field-input{{ $errors->has('name') ? ' is-invalid' : '' }}" id="report-share-name" name="name" type="text" maxlength="80" value="{{ old('name') }}" required @if ($errors->has('name')) aria-invalid="true" aria-describedby="report-share-name-error" @endif>
                    @error('name')<p id="report-share-name-error" class="field-error" role="alert">{{ $message }}</p>@enderror
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
                <button class="button button-primary" type="submit">建立連結</button>
            </form>
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
                    <caption class="sr-only">報表分享連結名稱、狀態與到期時間</caption>
                    <thead>
                        <tr>
                            <th scope="col">名稱</th>
                            <th scope="col">狀態</th>
                            <th scope="col">到期時間</th>
                            <th scope="col"><span class="sr-only">操作</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($shares as $share)
                            @php
                                $shareStatus = $share->revoked_at !== null ? '已撤銷' : ($share->expires_at->isFuture() ? '有效' : '已到期');
                            @endphp
                            <tr>
                                <th scope="row">{{ $share->name }}</th>
                                <td>{{ $shareStatus }}</td>
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
