<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>加入組織 · 監測台</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="login-page">
        <div class="login-wrap">
            <div class="login-brand">
                <a class="brand" href="{{ route('login') }}" aria-label="監測台">
                    <span class="brand-mark" aria-hidden="true">M</span>
                    <span class="brand-name">監測<span>台</span></span>
                </a>
            </div>

            <section class="login-card" aria-labelledby="invitation-heading">
                <p class="eyebrow">{{ $invitation->roleLabel() }}成員</p>
                <h1 id="invitation-heading" class="login-heading">加入 {{ $invitation->owner->name }} 的組織</h1>
                <p class="login-description">
                    @if ($invitation->role === \App\Models\WorkspaceMembership::ROLE_EDITOR)
                        建立帳戶後可維護授權的監測項目與活動；組織管理與整合設定仍由擁有者負責。
                    @else
                        建立帳戶後即可檢視監測與行銷資料。
                    @endif
                </p>
                <p class="field-help">受邀電子郵件：{{ $invitation->email }}</p>
                <p class="field-help">
                    客戶範圍：{{ $invitation->clientScopeLabel() }}
                    @if ($invitation->client_scope === \App\Models\WorkspaceMembership::CLIENT_SCOPE_SELECTED)
                        · {{ $invitationClients->pluck('name')->join('、') ?: '指定客戶目前無法使用' }}
                    @endif
                </p>

                @if ($errors->any())
                    <div class="alert alert-error" role="alert">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form action="{{ route('team.invitations.accept', ['token' => request()->route('token')]) }}" method="POST">
                    @csrf
                    <div class="login-fields">
                        <div class="field-group">
                            <label class="field-label" for="name">姓名</label>
                            <input class="field-input @error('name') is-invalid @enderror" id="name" name="name" type="text" value="{{ old('name') }}" maxlength="120" autocomplete="name" required @if ($errors->has('name')) aria-invalid="true" @endif>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="password">設定密碼</label>
                            <p id="password-help" class="field-help">至少 12 個字元。</p>
                            <input class="field-input @error('password') is-invalid @enderror" id="password" name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required aria-describedby="password-help" @if ($errors->has('password')) aria-invalid="true" @endif>
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="password_confirmation">再次輸入密碼</label>
                            <input class="field-input" id="password_confirmation" name="password_confirmation" type="password" minlength="12" maxlength="200" autocomplete="new-password" required>
                        </div>
                    </div>
                    <button class="button button-primary login-submit" type="submit">加入組織</button>
                </form>
            </section>
        </div>
    </main>
</body>
</html>
