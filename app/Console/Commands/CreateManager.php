<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('monitor:create-manager {--reset : Reset the existing manager password}')]
#[Description('建立唯一的私人監測管理者帳號')]
class CreateManager extends Command
{
    public function handle(): int
    {
        $manager = User::query()->first();
        $usersCount = User::query()->count();

        if ($usersCount > 1) {
            $this->error('目前有多個使用者，請先手動整理成唯一管理者帳號。');

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
            User::query()->create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
            $this->info('已建立私人管理者帳號，公開註冊功能已關閉。');
        } else {
            $manager->forceFill(['password' => Hash::make($password)])->save();
            $this->info('管理者密碼已重設。');
        }

        return self::SUCCESS;
    }
}
