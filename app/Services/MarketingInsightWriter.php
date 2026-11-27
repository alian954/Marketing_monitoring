<?php

namespace App\Services;

interface MarketingInsightWriter
{
    /**
     * Whether the writer has the credentials it needs to run.
     */
    public function isConfigured(): bool;

    /**
     * Writes a performance summary from the marketing data payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array{content: array{summary: string, highlights: list<string>, issues: list<array{title: string, detail: string}>, recommendations: list<string>}, model: string, input_tokens: int|null, output_tokens: int|null}
     *
     * @throws MarketingInsightException
     */
    public function write(array $payload): array;
}
