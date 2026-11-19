@extends('layouts.public-status')

@section('title', '取消狀態更新訂閱')

@section('content')
    <header class="public-status-header">
        <span class="public-status-brand">
            <span class="brand-mark" aria-hidden="true">M</span>
            <span>服務狀態</span>
        </span>
        <span class="secondary-text">電子郵件訂閱</span>
    </header>

    <section class="panel public-status-panel" aria-labelledby="subscription-unsubscribe-title">
        <div class="panel-body">
            @if (session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif

            @if ($canUnsubscribe)
                <h1 id="subscription-unsubscribe-title" class="page-title">取消狀態更新訂閱</h1>
                <p class="panel-description">確認後會立即取消訂閱，並停止尚未寄出的狀態更新通知。</p>
                <form action="{{ request()->fullUrl() }}" method="POST" aria-labelledby="subscription-unsubscribe-title">
                    @csrf
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">確認取消訂閱</button>
                    </div>
                </form>
            @else
                <h1 id="subscription-unsubscribe-title" class="page-title">訂閱已取消</h1>
                <p class="panel-description">此訂閱已取消，電子郵件地址已從訂閱資料中移除。</p>
            @endif
        </div>
    </section>
@endsection
