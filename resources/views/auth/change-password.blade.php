@extends('layouts.app')

@section('title', '帳戶安全')

@section('content')
    <div class="page-heading">
        <div class="heading-copy">
            <p class="eyebrow">帳戶安全</p>
            <h1 class="page-title">登入密碼與雙因素驗證</h1>
            <p class="page-description">更新登入密碼，或啟用驗證器 App 的第二道登入驗證。</p>
        </div>
    </div>

    <section class="panel" aria-labelledby="change-password-title">
        <div class="panel-header">
            <div>
                <h2 id="change-password-title" class="panel-title">更新密碼</h2>
                <p class="panel-description">新密碼需為 12 至 200 個字元，且需與目前密碼不同。</p>
            </div>
        </div>

        <div class="panel-body">
            <form action="{{ route('account.password.update') }}" method="POST" class="account-password-form" aria-labelledby="change-password-title">
                @csrf
                @method('PUT')
                <div class="form-grid">
                    <div class="field-group">
                        <label class="field-label" for="current_password">目前密碼</label>
                        <input
                            class="field-input @error('current_password') is-invalid @enderror"
                            id="current_password"
                            name="current_password"
                            type="password"
                            autocomplete="current-password"
                            maxlength="200"
                            required
                            @if ($errors->has('current_password')) aria-invalid="true" aria-describedby="current-password-error" @endif
                        >
                        @error('current_password')
                            <p id="current-password-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password">新密碼</label>
                        <p id="password-help" class="field-help">至少 12 個字元，且需與目前密碼不同。</p>
                        <input
                            class="field-input @error('password') is-invalid @enderror"
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                            minlength="12"
                            maxlength="200"
                            required
                            @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-help password-error" @else aria-describedby="password-help" @endif
                        >
                        @error('password')
                            <p id="password-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="password_confirmation">再次輸入新密碼</label>
                        <input
                            class="field-input @error('password') is-invalid @enderror"
                            id="password_confirmation"
                            name="password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            minlength="12"
                            maxlength="200"
                            required
                            @if ($errors->has('password')) aria-invalid="true" aria-describedby="password-confirmation-error" @endif
                        >
                        @error('password')
                            <p id="password-confirmation-error" class="field-error">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                <div class="form-actions">
                    <button class="button button-primary" type="submit">儲存新密碼</button>
                </div>
            </form>
        </div>
    </section>

    @include('auth.two-factor._settings')
@endsection
