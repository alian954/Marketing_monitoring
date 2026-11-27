<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class LineMessagingApi
{
    private const API_BASE_URL = 'https://api.line.me/v2/bot';

    public const MAX_TEXT_LENGTH = 5000;

    private const TARGET_ID_PATTERN = '/\A[UCR][0-9a-f]{32}\z/';

    public static function isValidTargetId(string $targetId): bool
    {
        return preg_match(self::TARGET_ID_PATTERN, $targetId) === 1;
    }

    public static function hasValidSignature(string $channelSecret, string $requestBody, string $signature): bool
    {
        if ($channelSecret === '' || $signature === '') {
            return false;
        }

        $expectedSignature = base64_encode(hash_hmac('sha256', $requestBody, $channelSecret, true));

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * @return array{user_id: string|null, basic_id: string|null, display_name: string|null}
     *
     * @throws LineMessagingApiException
     */
    public function botInfo(#[\SensitiveParameter] string $channelAccessToken): array
    {
        $response = $this->send(fn (): Response => $this->request($channelAccessToken)->get(self::API_BASE_URL.'/info'));

        if ($response->status() === 401) {
            throw new LineMessagingApiException('LINE 拒絕此頻道存取權杖，請確認權杖是否完整且未被重新發行。');
        }

        if (! $response->successful()) {
            throw new LineMessagingApiException('無法向 LINE 驗證頻道存取權杖（HTTP '.$response->status().'）。');
        }

        return [
            'user_id' => $this->stringOrNull($response->json('userId')),
            'basic_id' => $this->stringOrNull($response->json('basicId')),
            'display_name' => $this->stringOrNull($response->json('displayName')),
        ];
    }

    /**
     * @throws LineMessagingApiException
     */
    public function pushText(#[\SensitiveParameter] string $channelAccessToken, string $targetId, string $text, string $retryKey): void
    {
        if (! self::isValidTargetId($targetId)) {
            throw new LineMessagingApiException('LINE 推播對象 ID 格式無效。');
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_TEXT_LENGTH - 8)."\n[內容已截斷]";
        }

        $response = $this->send(fn (): Response => $this->request($channelAccessToken)
            ->withHeaders(['X-Line-Retry-Key' => $retryKey])
            ->post(self::API_BASE_URL.'/message/push', [
                'to' => $targetId,
                'messages' => [['type' => 'text', 'text' => $text]],
            ]));

        // 409 means LINE already accepted a request with the same retry key.
        if ($response->successful() || $response->status() === 409) {
            return;
        }

        throw new LineMessagingApiException(match ($response->status()) {
            401 => 'LINE 拒絕頻道存取權杖，請到 LINE 通知設定重新儲存權杖。',
            403 => 'LINE 官方帳號沒有推播權限，或對象已封鎖官方帳號。',
            429 => 'LINE 推播已達本月訊息額度或速率限制。',
            400 => 'LINE 拒絕推播內容或推播對象。',
            default => 'LINE 推播失敗（HTTP '.$response->status().'）。',
        });
    }

    public function groupName(#[\SensitiveParameter] string $channelAccessToken, string $groupId): ?string
    {
        return $this->optionalName($channelAccessToken, '/group/'.$groupId.'/summary', 'groupName', $groupId);
    }

    public function profileName(#[\SensitiveParameter] string $channelAccessToken, string $userId): ?string
    {
        return $this->optionalName($channelAccessToken, '/profile/'.$userId, 'displayName', $userId);
    }

    private function optionalName(string $channelAccessToken, string $path, string $field, string $targetId): ?string
    {
        if (! self::isValidTargetId($targetId)) {
            return null;
        }

        try {
            $response = $this->send(fn (): Response => $this->request($channelAccessToken)->get(self::API_BASE_URL.$path));
        } catch (LineMessagingApiException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $name = $this->stringOrNull($response->json($field));

        return $name === null ? null : mb_substr($name, 0, 255);
    }

    private function request(string $channelAccessToken): PendingRequest
    {
        return Http::connectTimeout(3)
            ->timeout(10)
            ->withoutRedirecting()
            ->acceptJson()
            ->withToken($channelAccessToken);
    }

    /**
     * @param  callable(): Response  $callback
     *
     * @throws LineMessagingApiException
     */
    private function send(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException) {
            throw new LineMessagingApiException('無法連線到 LINE Messaging API。');
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
