<?php

namespace App\Models;

use Database\Factories\ClientReportSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_report_share_id', 'created_by_user_id', 'report_sections', 'report_starts_on', 'report_ends_on', 'csv_content', 'csv_sha256'])]
#[Hidden(['csv_content'])]
class ClientReportSnapshot extends Model
{
    /** @use HasFactory<ClientReportSnapshotFactory> */
    use HasFactory;

    public function reportShare(): BelongsTo
    {
        return $this->belongsTo(ClientReportShare::class, 'client_report_share_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'report_sections' => 'array',
            'report_starts_on' => 'immutable_date',
            'report_ends_on' => 'immutable_date',
            'csv_content' => 'encrypted',
        ];
    }
}
