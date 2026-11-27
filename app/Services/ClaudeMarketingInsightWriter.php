<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use JsonException;

/**
 * Writes marketing performance insights with Claude through the official Anthropic SDK.
 *
 * Uses structured outputs so the reply always matches the sections the page renders, and the
 * server-side refusal fallback so a safety-classifier false positive does not fail the summary.
 */
class ClaudeMarketingInsightWriter implements MarketingInsightWriter
{
    private const MAX_TOKENS = 16000;

    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private const SYSTEM_PROMPT = <<<'PROMPT'
你是台灣數位行銷代理商的資深成效分析師，負責替客戶的廣告與網站數據寫出給行銷人員看的期間摘要。

你會收到一份 JSON，包含：報表期間與前一等長期間的跨平台廣告數據（Google Ads、Meta Ads、可能還有 TikTok、LINE 等匯入的平台）、GA4 網站數據、轉換重複歸因比值、月預算消耗進度，以及期間內的行銷註記、網站監測事件和行銷警示。

寫作原則：
- 使用繁體中文與台灣行銷業慣用詞彙（例如 ROAS、CPA、轉換、檔期、素材）。
- 只根據提供的數據下結論；數據缺少或不完整時直接說明，不要推測沒有的數字。
- 提到數字時附上單位或幣別，變化幅度用百分比表達。
- 解釋變化時優先對照期間內的註記、網站事件與警示，說明可能的因果關係，並標明這是推論。
- 建議要具體可執行，例如「把預算從 A 平台移 15% 到 B 平台」，不要寫空泛的原則。
- summary 用 2 到 3 句話總結本期表現；highlights、issues、recommendations 各 2 到 5 點，每點一到兩句。
PROMPT;

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function write(array $payload): array
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw new MarketingInsightException('尚未設定 ANTHROPIC_API_KEY。');
        }

        try {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MarketingInsightException('數據無法轉成 JSON。');
        }

        $model = (string) config('services.anthropic.insight_model', 'claude-opus-5-5');

        try {
            $message = (new Client(apiKey: $apiKey))->beta->messages->create(
                model: $model,
                maxTokens: self::MAX_TOKENS,
                system: [
                    ['type' => 'text', 'text' => self::SYSTEM_PROMPT, 'cacheControl' => ['type' => 'ephemeral']],
                ],
                messages: [
                    ['role' => 'user', 'content' => "以下是本期數據，請依格式寫出摘要：\n\n".$payloadJson],
                ],
                outputConfig: [
                    'effort' => (string) config('services.anthropic.insight_effort', 'medium'),
                    'format' => ['type' => 'json_schema', 'schema' => self::schema()],
                ],
                fallbacks: 'default',
                betas: [self::FALLBACK_BETA],
            );
        } catch (AuthenticationException) {
            throw new MarketingInsightException('Claude API 金鑰無效，請確認 ANTHROPIC_API_KEY。');
        } catch (RateLimitException) {
            throw new MarketingInsightException('Claude API 目前請求過多，請稍後再試。');
        } catch (APIStatusException $exception) {
            throw new MarketingInsightException('Claude API 回應錯誤（HTTP '.$exception->status.'），請稍後再試。');
        } catch (APIConnectionException) {
            throw new MarketingInsightException('無法連線到 Claude API，請稍後再試。');
        }

        if ($message->stopReason === 'refusal') {
            throw new MarketingInsightException('Claude 未產生此期間的摘要，請調整期間後再試。');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new MarketingInsightException('摘要內容過長而被截斷，請縮短期間後再試。');
        }

        $text = '';

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        try {
            $content = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MarketingInsightException('摘要格式無法解析，請重新產生。');
        }

        if (! is_array($content) || ! is_string($content['summary'] ?? null)) {
            throw new MarketingInsightException('摘要格式不完整，請重新產生。');
        }

        return [
            'content' => [
                'summary' => $content['summary'],
                'highlights' => self::strings($content['highlights'] ?? []),
                'issues' => array_values(array_filter(
                    is_array($content['issues'] ?? null) ? $content['issues'] : [],
                    static fn (mixed $issue): bool => is_array($issue) && is_string($issue['title'] ?? null) && is_string($issue['detail'] ?? null),
                )),
                'recommendations' => self::strings($content['recommendations'] ?? []),
            ],
            'model' => $message->model,
            'input_tokens' => $message->usage->inputTokens,
            'output_tokens' => $message->usage->outputTokens,
        ];
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $stringList = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string', 'description' => '本期表現總結，2 到 3 句'],
                'highlights' => $stringList + ['description' => '表現好的重點'],
                'issues' => [
                    'type' => 'array',
                    'description' => '需要注意的異常或問題與可能原因',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'detail'],
                        'additionalProperties' => false,
                    ],
                ],
                'recommendations' => $stringList + ['description' => '下期具體可執行的建議'],
            ],
            'required' => ['summary', 'highlights', 'issues', 'recommendations'],
            'additionalProperties' => false,
        ];
    }

    /** @return list<string> */
    private static function strings(mixed $items): array
    {
        return array_values(array_filter(
            is_array($items) ? $items : [],
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }

    private function apiKey(): ?string
    {
        $apiKey = config('services.anthropic.api_key');

        return is_string($apiKey) && $apiKey !== '' ? $apiKey : null;
    }
}
