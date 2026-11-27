<?php

namespace App\Http\Resources;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'reporting_currency_code' => $this->reporting_currency_code,
            'links' => [
                'summary' => route('api.v1.clients.summary', $this->resource),
                'daily_performance' => route('api.v1.clients.daily-performance', $this->resource),
            ],
        ];
    }
}
