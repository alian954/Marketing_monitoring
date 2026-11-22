<?php

namespace App\Models;

use Database\Factories\SocialEngagementTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['tag', 'social_platform', 'target_interactions'])]
class SocialEngagementTarget extends Model
{
    /** @use HasFactory<SocialEngagementTargetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'target_interactions' => 'integer',
        ];
    }
}
