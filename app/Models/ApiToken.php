<?php

namespace App\Models;

use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A read-only API token that lets BI tools such as Looker Studio or Google Sheets pull a
 * workspace's marketing data. Only a SHA-256 hash of the token is stored.
 */
#[Fillable(['user_id', 'workspace_owner_user_id', 'name', 'token_hash', 'token_prefix', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    public const PREFIX = 'mm_';

    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * @return array{token: ApiToken, plain_text: string}
     */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt): array
    {
        $plainText = self::PREFIX.bin2hex(random_bytes(24));
        $token = self::query()->create([
            'user_id' => $user->getKey(),
            'workspace_owner_user_id' => $user->workspaceOwnerId(),
            'name' => $name,
            'token_hash' => self::hash($plainText),
            'token_prefix' => substr($plainText, 0, 10),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plain_text' => $plainText];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
