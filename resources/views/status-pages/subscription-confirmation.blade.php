@extends('layouts.public-status')

@section('title', '確認狀態更新訂閱')

@section('content')
    <header class="public-status-header">
        <span class="public-status-brand">
            <span class="brand-mark" aria-hidden="true">M</span>
            <span>服務狀態</span>
        </span>
        <span class="secondary-text">電子郵件訂閱</span>
    </header>

    <section class="panel public-status-panel" aria-labelledby="subscription-confirmation-title">
        <div class="panel-body">
            @if ($isConfirmed)
                <h1 id="subscription-confirmation-title" class="page-title">訂閱已確認</h1>
                <p class="panel-description">已完成電子郵件確認。公開狀態頁出現服務異常或恢復時，會寄送更新通知。</p>
            @elseif ($canConfirm)
                <h1 id="subscription-confirmation-title" class="page-title">確認狀態更新訂閱</h1>
                <p class="panel-description">按下確認後，這個電子郵件信箱會收到公開狀態頁服務異常與恢復通知。</p>
                <form action="{{ route('status-page-subscriptions.confirm.store', ['subscription' => $subscription->id, 'token' => request()->query('token')]) }}" method="POST" aria-labelledby="subscription-confirmation-title">
                    @csrf
                    <div class="form-actions">
                        <button class="button button-primary" type="submit">確認訂閱</button>
                    </div>
                </form>
            @else
                <h1 id="subscription-confirmation-title" class="page-title">確認連結已失效</h1>
                <p class="panel-description">這個連結已使用、到期或無效。請回到公開狀態頁重新提出訂閱要求。</p>
            @endif
        </div>
    </section>
@endsection
