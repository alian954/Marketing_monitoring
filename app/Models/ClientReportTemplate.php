<?php

namespace App\Models;

use Database\Factories\ClientReportTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'report_brand_name', 'campaign_target_ids', 'report_sections', 'compare_previous_period'])]
class ClientReportTemplate extends Model
{
    /** @use HasFactory<ClientReportTemplateFactory> */
    use HasFactory;

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return list<string> */
    public function selectedReportSections(): array
    {
        if (! is_array($this->report_sections)) {
            return ClientReportShare::DEFAULT_REPORT_SECTIONS;
        }

        return array_values(array_filter(
            array_keys(ClientReportShare::REPORT_SECTION_LABELS),
            fn (string $section): bool => in_array($section, $this->report_sections, true),
        ));
    }

    protected function casts(): array
    {
        return [
            'campaign_target_ids' => 'array',
            'report_sections' => 'array',
            'compare_previous_period' => 'boolean',
        ];
    }
}
