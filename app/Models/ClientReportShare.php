<?php

namespace App\Models;

use Database\Factories\ClientReportShareFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'token_hash', 'campaign_target_ids', 'report_sections', 'expires_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class ClientReportShare extends Model
{
    public const SECTION_PUBLIC_ENGAGEMENT = 'public_engagement';

    public const SECTION_GOOGLE_ANALYTICS = 'google_analytics';

    public const SECTION_META_MARKETING = 'meta_marketing';

    public const SECTION_GOOGLE_ADS = 'google_ads';

    /** @var list<string> */
    public const DEFAULT_REPORT_SECTIONS = [
        self::SECTION_PUBLIC_ENGAGEMENT,
        self::SECTION_GOOGLE_ANALYTICS,
        self::SECTION_META_MARKETING,
        self::SECTION_GOOGLE_ADS,
    ];

    /** @var array<string, string> */
    public const REPORT_SECTION_LABELS = [
        self::SECTION_PUBLIC_ENGAGEMENT => '公開互動',
        self::SECTION_GOOGLE_ANALYTICS => 'GA4 分析',
        self::SECTION_META_MARKETING => 'Meta Ads',
        self::SECTION_GOOGLE_ADS => 'Google Ads',
    ];

    /** @use HasFactory<ClientReportShareFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return list<string> */
    public function selectedReportSections(): array
    {
        if (! is_array($this->report_sections)) {
            return self::DEFAULT_REPORT_SECTIONS;
        }

        return array_values(array_filter(
            self::DEFAULT_REPORT_SECTIONS,
            fn (string $section): bool => in_array($section, $this->report_sections, true),
        ));
    }

    protected function casts(): array
    {
        return [
            'campaign_target_ids' => 'array',
            'report_sections' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
