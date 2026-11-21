<?php

namespace App\Models;

use Database\Factories\IncidentFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['monitor_id', 'config_version', 'rule_key', 'severity', 'state', 'classification', 'acknowledged_at', 'acknowledged_by_id', 'snoozed_until', 'active_key', 'note', 'failure_count', 'recovery_count', 'confirm_after', 'opened_at', 'last_seen_at', 'resolved_at'])]
class Incident extends Model
{
    public const STATE_SUSPECT = 'suspect';

    public const STATE_OPEN = 'open';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_PAUSED = 'paused';

    public const STATE_SUPERSEDED = 'superseded';

    /** @var array<string, string> */
    public const RULE_LABELS = [
        'http' => 'HTTP 回應異常',
        'probe_restricted' => '檢查受到限制',
        'probe_incomplete' => '文字驗證未完成',
        'content' => '原始回應未包含預期文字',
        'forbidden_text' => '回應包含不應出現的文字',
        'response_header' => 'HTTP 回應標頭不符合設定',
        'response_time' => 'HTTP 回應時間超過設定門檻',
        'response_content_change' => '原始回應文字或分享預覽資訊已變更',
        'response_size' => 'HTTP 回應本文大小超出設定範圍或無法判定',
        'monitor_stale' => '監測資料逾時',
        'adsbot_difference' => 'AdsBot 模擬結果不同',
        'tls_expired' => 'TLS 憑證已過期',
        'tls_handshake' => 'TLS 交握失敗',
        'tls_7d' => 'TLS 憑證將於 7 天內到期',
        'tls_14d' => 'TLS 憑證將於 14 天內到期',
        'tls_30d' => 'TLS 憑證將於 30 天內到期',
        'domain_expired' => '網域已到期',
        'domain_7d' => '網域將於 7 天內到期',
        'domain_14d' => '網域將於 14 天內到期',
        'domain_30d' => '網域將於 30 天內到期',
    ];

    /** @use HasFactory<IncidentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'config_version' => 'integer',
            'acknowledged_by_id' => 'integer',
            'acknowledged_at' => 'immutable_datetime',
            'snoozed_until' => 'immutable_datetime',
            'failure_count' => 'integer',
            'recovery_count' => 'integer',
            'confirm_after' => 'immutable_datetime',
            'opened_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function outboxMessages(): HasMany
    {
        return $this->hasMany(NotificationOutbox::class);
    }

    public function ruleLabel(): string
    {
        return self::RULE_LABELS[$this->rule_key] ?? '其他監測異常';
    }

    public function confirmedDurationSeconds(?DateTimeInterface $asOf = null): ?int
    {
        if ($this->classification === 'false_alarm' || $this->opened_at === null) {
            return null;
        }

        $durationEndsAt = match ($this->state) {
            self::STATE_OPEN => $asOf ?? now(),
            self::STATE_RESOLVED => $this->resolved_at,
            default => null,
        };

        if ($durationEndsAt === null) {
            return null;
        }

        return max(0, $durationEndsAt->getTimestamp() - $this->opened_at->getTimestamp());
    }

    public function confirmedDurationLabel(?DateTimeInterface $asOf = null): ?string
    {
        $durationSeconds = $this->confirmedDurationSeconds($asOf);

        if ($durationSeconds === null) {
            return null;
        }

        if ($durationSeconds < 60) {
            return $durationSeconds.' 秒';
        }

        $durationMinutes = intdiv($durationSeconds, 60);

        if ($durationMinutes < 60) {
            $remainingSeconds = $durationSeconds % 60;

            return $durationMinutes.' 分鐘'.($remainingSeconds > 0 ? ' '.$remainingSeconds.' 秒' : '');
        }

        $durationHours = intdiv($durationMinutes, 60);

        if ($durationHours < 24) {
            $remainingMinutes = $durationMinutes % 60;

            return $durationHours.' 小時'.($remainingMinutes > 0 ? ' '.$remainingMinutes.' 分鐘' : '');
        }

        $durationDays = intdiv($durationHours, 24);
        $remainingHours = $durationHours % 24;

        return $durationDays.' 天'.($remainingHours > 0 ? ' '.$remainingHours.' 小時' : '');
    }
}
