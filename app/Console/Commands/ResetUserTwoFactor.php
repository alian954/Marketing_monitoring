<?php

namespace App\Console\Commands;

use App\Models\PlatformAuditAction;
use App\Models\User;
use App\Services\PlatformAuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:reset-two-factor {email : The account email address}')]
#[Description('為遺失驗證器與復原碼的帳戶關閉雙因素驗證')]
class ResetUserTwoFactor extends Command
{
    public function handle(PlatformAuditLogger $auditLogger): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if (! $user instanceof User) {
            $this->error('找不到這個電子郵件的帳戶。');

            return self::FAILURE;
        }

        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret === null) {
            $this->info('此帳戶未啟用雙因素驗證，無需重設。');

            return self::SUCCESS;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
        $auditLogger->record(PlatformAuditAction::TwoFactorReset, $user, ['via' => 'console_command']);

        $this->info('已關閉此帳戶的雙因素驗證，請通知使用者登入後重新設定。');

        return self::SUCCESS;
    }
}
