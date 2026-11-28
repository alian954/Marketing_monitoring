<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Time-based one-time passwords (RFC 6238, SHA-1, 6 digits, 30 seconds) and single-use recovery codes.
 */
class TwoFactorAuthenticator
{
    public const PERIOD_SECONDS = 30;

    public const DIGITS = 6;

    public const RECOVERY_CODE_COUNT = 8;

    /** Accept one step on either side of the current one to absorb clock drift. */
    private const ALLOWED_DRIFT_STEPS = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * The otpauth URI an authenticator app imports.
     */
    public function provisioningUri(string $secret, string $accountName): string
    {
        $issuer = (string) config('app.name');

        return 'otpauth://totp/'.rawurlencode($issuer.':'.$accountName).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Group the secret into blocks of four for manual entry.
     */
    public function formatSecretForDisplay(string $secret): string
    {
        return implode(' ', str_split($secret, 4));
    }

    /**
     * Return the matching time step when the code is valid for the secret, otherwise null.
     */
    public function matchingStep(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return null;
        }

        $key = $this->base32Decode($secret);

        if ($key === '') {
            return null;
        }

        $currentStep = intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD_SECONDS);

        for ($offset = -self::ALLOWED_DRIFT_STEPS; $offset <= self::ALLOWED_DRIFT_STEPS; $offset++) {
            $step = $currentStep + $offset;

            if (hash_equals($this->codeForStep($key, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function codeAt(string $secret, int $timestamp): string
    {
        return $this->codeForStep($this->base32Decode($secret), intdiv($timestamp, self::PERIOD_SECONDS));
    }

    /**
     * Verify a login or confirmation code for the user's stored secret and burn its time step so it cannot be replayed.
     */
    public function verifyAndConsumeCode(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $step = $this->matchingStep($secret, $code);

        if ($step === null) {
            return false;
        }

        $consumed = DB::table('users')
            ->where('id', $user->getKey())
            ->where(static function ($query) use ($step): void {
                $query->whereNull('two_factor_last_used_step')->orWhere('two_factor_last_used_step', '<', $step);
            })
            ->update(['two_factor_last_used_step' => $step]);

        if ($consumed !== 1) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_step' => $step])->syncOriginalAttribute('two_factor_last_used_step');

        return true;
    }

    /**
     * @return list<string> Plain recovery codes, shown to the user once.
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($index = 0; $index < self::RECOVERY_CODE_COUNT; $index++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code): string => $this->hashRecoveryCode($code), $codes);
    }

    /**
     * Use one recovery code; each code works only once.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalizedCode = Str::lower(preg_replace('/\s+/', '', $code) ?? '');

        if ($normalizedCode === '') {
            return false;
        }

        $hashedCode = $this->hashRecoveryCode($normalizedCode);

        return DB::transaction(function () use ($user, $hashedCode): bool {
            $lockedUser = $user->newQuery()->lockForUpdate()->find($user->getKey());
            $remainingCodes = $lockedUser?->two_factor_recovery_codes ?? [];
            $matchIndex = null;

            foreach ($remainingCodes as $index => $storedCode) {
                if (is_string($storedCode) && hash_equals($storedCode, $hashedCode)) {
                    $matchIndex = $index;
                }
            }

            if ($matchIndex === null) {
                return false;
            }

            unset($remainingCodes[$matchIndex]);
            $lockedUser->forceFill(['two_factor_recovery_codes' => array_values($remainingCodes)])->save();
            $user->setRawAttributes($lockedUser->getAttributes(), true);

            return true;
        });
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function codeForStep(string $key, int $step): string
    {
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[\s=]+/', '', $encoded) ?? '');
        $bits = '';

        foreach (str_split($encoded) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);

            if ($position === false) {
                return '';
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr((int) bindec($chunk));
            }
        }

        return $decoded;
    }
}
