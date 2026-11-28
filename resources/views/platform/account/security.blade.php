@extends('layouts.platform')

@section('title', '帳戶安全')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">Platform account</p>
            <h1 class="page-title">帳戶安全</h1>
            <p class="page-description">管理後台可存取所有組織資料，強烈建議啟用雙因素驗證。密碼請由主機執行 <code>php artisan monitor:create-manager --reset</code> 重設。</p>
        </div>
    </div>

    @include('auth.two-factor._settings')
@endsection
