@php
    $recoveryCodes = session(\App\Http\Controllers\TwoFactorSettingsController::RECOVERY_CODES_SESSION_KEY);
@endphp

<section class="panel spaced-top" aria-labelledby="two-factor-title">
    <div class="panel-header">
        <div>
            <h2 id="two-factor-title" class="panel-title">雙因素驗證</h2>
            <p class="panel-description">
                @if ($twoFactorUser->hasTwoFactorEnabled())
                    已啟用：登入時除了密碼，還需輸入驗證器 App 的驗證碼。剩餘 {{ $twoFactorUser->remainingTwoFactorRecoveryCodeCount() }} 組復原碼。
                @else
                    尚未啟用。啟用後即使密碼外洩，沒有手機上的驗證碼也無法登入；建議管理廣告帳戶授權的帳號都啟用。
                @endif
            </p>
        </div>
    </div>

    <div class="panel-body">
        @if (is_array($recoveryCodes) && $recoveryCodes !== [])
            <div class="alert alert-warning" role="status">
                <p class="alert-title">請立即保存這組復原碼</p>
                <p>手機遺失時可用復原碼登入，每組只能使用一次。離開此頁後不會再顯示。</p>
                <ul class="two-factor-recovery-codes">
                    @foreach ($recoveryCodes as $recoveryCode)
                        <li><code>{{ $recoveryCode }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($twoFactorUser->hasTwoFactorEnabled())
            <div class="form-grid">
                <form action="{{ route($twoFactorRoutePrefix.'.recovery-codes') }}" method="POST" aria-label="重新產生復原碼">
                    @csrf
                    <div class="field-group">
                        <label class="field-label" for="two-factor-regenerate-password">目前密碼</label>
                        <input class="field-input @error('current_password') is-invalid @enderror" id="two-factor-regenerate-password" name="current_password" type="password" autocomplete="current-password" maxlength="200" required>
                    </div>
                    <div class="form-actions">
                        <button class="button button-secondary" type="submit">重新產生復原碼</button>
                    </div>
                </form>
                <form action="{{ route($twoFactorRoutePrefix.'.disable') }}" method="POST" aria-label="停用雙因素驗證">
                    @csrf
                    @method('DELETE')
                    <div class="field-group">
                        <label class="field-label" for="two-factor-disable-password">目前密碼</label>
                        <input class="field-input @error('current_password') is-invalid @enderror" id="two-factor-disable-password" name="current_password" type="password" autocomplete="current-password" maxlength="200" required>
                    </div>
                    <div class="form-actions">
                        <button class="button button-quiet" type="submit">停用雙因素驗證</button>
                    </div>
                </form>
            </div>
            @error('current_password')<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @elseif ($twoFactorSetup !== null)
            <ol class="two-factor-steps">
                <li>在 Google Authenticator、Microsoft Authenticator 或 1Password 等驗證器 App 選擇「輸入設定金鑰」。</li>
                <li>帳戶名稱填 <strong>{{ $twoFactorUser->email }}</strong>，金鑰填入下方內容，類型選「依時間」。</li>
                <li>輸入 App 顯示的 6 位數驗證碼完成啟用。</li>
            </ol>
            <div class="field-group">
                <p class="field-label" id="two-factor-secret-label">設定金鑰</p>
                <p><code class="two-factor-secret" aria-labelledby="two-factor-secret-label">{{ $twoFactorSetup['secret'] }}</code></p>
                <p class="field-help">支援連結匯入的 App 可使用：<a class="strong-link" href="{{ $twoFactorSetup['uri'] }}">在此裝置開啟驗證器</a></p>
            </div>
            <form action="{{ route($twoFactorRoutePrefix.'.confirm') }}" method="POST" aria-label="確認雙因素驗證">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="two-factor-confirm-code">驗證碼</label>
                    <input class="field-input @error('code') is-invalid @enderror" id="two-factor-confirm-code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="20" autocomplete="one-time-code" required @if ($errors->has('code')) aria-invalid="true" aria-describedby="two-factor-confirm-code-error" @endif>
                    @error('code')<p id="two-factor-confirm-code-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">確認並啟用</button>
                </div>
            </form>
            <form action="{{ route($twoFactorRoutePrefix.'.disable') }}" method="POST" class="spaced-top" aria-label="取消雙因素驗證設定">
                @csrf
                @method('DELETE')
                <div class="field-group">
                    <label class="field-label" for="two-factor-cancel-password">目前密碼</label>
                    <p id="two-factor-cancel-help" class="field-help">暫時不想啟用？輸入目前密碼即可取消這次設定。</p>
                    <input class="field-input" id="two-factor-cancel-password" aria-describedby="two-factor-cancel-help" name="current_password" type="password" autocomplete="current-password" maxlength="200" required>
                </div>
                <div class="form-actions">
                    <button class="button button-quiet" type="submit">取消設定</button>
                </div>
            </form>
        @else
            <form action="{{ route($twoFactorRoutePrefix.'.enable') }}" method="POST" aria-label="開始設定雙因素驗證">
                @csrf
                <div class="field-group">
                    <label class="field-label" for="two-factor-enable-password">目前密碼</label>
                    <input class="field-input @error('current_password') is-invalid @enderror" id="two-factor-enable-password" name="current_password" type="password" autocomplete="current-password" maxlength="200" required @if ($errors->has('current_password')) aria-invalid="true" aria-describedby="two-factor-enable-password-error" @endif>
                    @error('current_password')<p id="two-factor-enable-password-error" class="field-error" role="alert">{{ $message }}</p>@enderror
                </div>
                <div class="form-actions">
                    <button class="button button-primary" type="submit">開始設定</button>
                </div>
            </form>
        @endif
    </div>
</section>
