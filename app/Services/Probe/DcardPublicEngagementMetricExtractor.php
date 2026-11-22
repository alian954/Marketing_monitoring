<?php

namespace App\Services\Probe;

class DcardPublicEngagementMetricExtractor
{
    /**
     * @return array{views: ?int, likes: ?int, comments: ?int, shares: ?int, source: string}|null
     */
    public static function extract(string $visibleText): ?array
    {
        $lines = preg_split('/\R/u', $visibleText) ?: [];
        $commentSectionIndex = null;

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:全部留言|所有留言)\s*$/u', $line) === 1) {
                $commentSectionIndex = $index;

                break;
            }
        }

        if ($commentSectionIndex === null) {
            return null;
        }

        $countBlocks = [];
        $currentBlock = [];

        foreach (array_slice($lines, 0, $commentSectionIndex) as $line) {
            $count = self::parseCount($line);

            if ($count === null) {
                if (count($currentBlock) === 2) {
                    $countBlocks[] = $currentBlock;
                }

                $currentBlock = [];

                $sameLineCounts = self::parseCountPair($line);

                if ($sameLineCounts !== null) {
                    $countBlocks[] = $sameLineCounts;
                }

                continue;
            }

            $currentBlock[] = $count;

            if (count($currentBlock) > 2) {
                $currentBlock = [];
            }
        }

        if (count($currentBlock) === 2) {
            $countBlocks[] = $currentBlock;
        }

        if (count($countBlocks) !== 1) {
            return null;
        }

        return [
            'views' => null,
            'likes' => $countBlocks[0][0],
            'comments' => $countBlocks[0][1],
            'shares' => null,
            'source' => 'rendered_dom',
        ];
    }

    private static function parseCount(string $line): ?int
    {
        $normalized = str_replace(',', '', trim($line));

        if (preg_match('/\A\d{1,15}\z/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    /** @return array{0: int, 1: int}|null */
    private static function parseCountPair(string $line): ?array
    {
        if (preg_match('/\A([\d,]{1,19})\s+([\d,]{1,19})\z/u', trim($line), $matches) !== 1) {
            return null;
        }

        $firstCount = self::parseCount($matches[1]);
        $secondCount = self::parseCount($matches[2]);

        return $firstCount === null || $secondCount === null
            ? null
            : [$firstCount, $secondCount];
    }
}
