<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('monitor:create-manager {--reset : Reset the existing manager password}')]
#[Description('建立或重設總管理員帳號')]
class CreateManager extends Command
{
    public function handle(): int
    {
        $managerQuery = User::query()
            ->where('is_platform_admin', true)
            ->orderBy('id');
        $manager = $managerQuery->first();
        $usersCount = $managerQuery->count();

        if ($usersCount > 1) {
            $this->error('目前有多個總管理員帳號，請先整理為唯一總管理員。');

            return self::FAILURE;
        }

        if ($manager !== null && ! $this->option('reset')) {
            $this->error('管理者帳號已存在，請使用 --reset 變更密碼。');

            return self::FAILURE;
        }

        $name = $manager?->name ?? trim($this->ask('管理者名稱') ?? '');
        $email = $manager?->email ?? mb_strtolower(trim($this->ask('管理者電子郵件') ?? ''));
        $password = (string) $this->secret('密碼（至少 12 個字元）');
        $confirmation = (string) $this->secret('再次輸入密碼');

        if ($name === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($password) < 12 || ! hash_equals($password, $confirmation)) {
            $this->error('請輸入名稱、有效的電子郵件地址、相符的密碼，且密碼至少 12 個字元。');

            return self::INVALID;
        }

        if ($manager === null) {
            $manager = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
            $manager->forceFill(['is_platform_admin' => true])->save();
            $this->info('已建立總管理員帳號，公開註冊功能已關閉。');
        } else {
            $manager->forceFill([
                'password' => Hash::make($password),
                'is_platform_admin' => true,
            ])->save();
            $this->info('管理者密碼已重設。');
        }

        return self::SUCCESS;
    }
}
