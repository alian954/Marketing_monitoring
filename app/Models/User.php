<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\PasswordResetNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function socialEngagementTargets(): HasMany
    {
        return $this->hasMany(SocialEngagementTarget::class);
    }

    public function statusPage(): HasOne
    {
        return $this->hasOne(StatusPage::class);
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] mixed $token): void
    {
        if (! is_string($token)) {
            throw new \InvalidArgumentException('The password reset token must be a string.');
        }

        $this->notify(new PasswordResetNotification($token));
    }

    public function googleAnalyticsConnection(): HasOne
    {
        return $this->hasOne(GoogleAnalyticsConnection::class);
    }

    public function metaMarketingConnection(): HasOne
    {
        return $this->hasOne(MetaMarketingConnection::class);
    }

    public function googleAdsConnection(): HasOne
    {
        return $this->hasOne(GoogleAdsConnection::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
