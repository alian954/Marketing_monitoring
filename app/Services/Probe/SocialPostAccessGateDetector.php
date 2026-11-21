<?php

namespace App\Services\Probe;

class SocialPostAccessGateDetector
{
    /**
     * @param  array{title: ?string, description: ?string}  $metadata
     */
    public static function detects(string $platform, string $body, array $metadata): bool
    {
        $platformName = match ($platform) {
            'facebook' => 'facebook',
            'instagram' => 'instagram',
            'threads' => 'threads',
            'dcard' => 'dcard',
            default => null,
        };

        if ($platformName === null || ! str_contains(mb_strtolower((string) $metadata['title']), $platformName)) {
            return false;
        }

        $description = mb_strtolower((string) $metadata['description']);
        $visibleBody = preg_replace('/<(script|style|template)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
        $visibleBody = preg_replace('/<[^>]*>/u', ' ', $visibleBody) ?? $visibleBody;
        $visibleBody = html_entity_decode($visibleBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $visibleBody = mb_strtolower(preg_replace('/\s+/u', ' ', trim($visibleBody)) ?? trim($visibleBody));

        return self::containsAccessRestriction($description)
            && self::containsAccessRestriction($visibleBody);
    }

    private static function containsAccessRestriction(string $text): bool
    {
        $patterns = [
            '/\b(?:log[\s-]?in|sign[\s-]?in)\b.{0,100}\b(?:to see|to view|to continue|to access|or sign up)\b/iu',
            '/\b(?:please|you must|you need to)\s+(?:log[\s-]?in|sign[\s-]?in)\b/iu',
            '/\b(?:to see|to view|to continue|to access)\b.{0,100}\b(?:log[\s-]?in|sign[\s-]?in)\b/iu',
            '/(?:請先|請|需要|必須)?登[入錄].{0,30}(?:查看|瀏覽|觀看|繼續|貼文|內容)/u',
            '/(?:查看|瀏覽|觀看|繼續).{0,30}(?:貼文|內容)?.{0,20}(?:請先|請|需要|必須)?登[入錄]/u',
            '/\btemporar(?:y|ily)\s+(?:blocked|restricted|limited|unavailable)\b/iu',
            '/\b(?:rate limit(?:ed)?|too many requests|try again in (?:a few|several|some) minutes)\b/iu',
            '/\bplease wait (?:a few|several|some) minutes.{0,40}\btry again\b/iu',
            '/\b(?:complete|pass|solve)\s+(?:a\s+)?(?:security check|captcha|checkpoint)\b/iu',
            '/\b(?:verify|confirm)\s+(?:your|the)\s+(?:identity|account)\b/iu',
            '/\b(?:security check|captcha|checkpoint|unusual activity)\b/iu',
            '/(?:請先|需要|必須)?驗證(?:你的|您)?(?:帳號|身分|身份)/u',
            '/(?:操作|請求|嘗試|活動).{0,20}(?:過於頻繁|次數過多|異常)/u',
            '/(?:暫時|目前).{0,10}(?:封鎖|限制|停用)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
