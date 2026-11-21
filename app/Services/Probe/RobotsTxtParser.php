<?php

namespace App\Services\Probe;

class RobotsTxtParser
{
    /** @var array<string, string> */
    public const CRAWLERS = [
        'Googlebot' => 'googlebot',
        'AdsBot-Google' => 'adsbot-google',
        'FacebookExternalHit' => 'facebookexternalhit',
        'Twitterbot' => 'twitterbot',
    ];

    /**
     * @return array{
     *     policy_hash: string,
     *     rule_count: int,
     *     sitemap_count: int,
     *     crawler_decisions: array<string, array{allowed: bool, matching_rule: ?string}>
     * }
     */
    public function analyze(string $contents, string $targetPath): array
    {
        $groups = [];
        $sitemaps = [];
        $groupIndex = null;
        $groupHasDirectives = false;

        foreach ($this->lines($contents) as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));
            $name = mb_strtolower($name);

            if ($name === 'user-agent') {
                $agent = mb_strtolower($value);

                if ($agent === '') {
                    continue;
                }

                if ($groupIndex === null || $groupHasDirectives) {
                    $groups[] = ['agents' => [], 'rules' => []];
                    $groupIndex = array_key_last($groups);
                    $groupHasDirectives = false;
                }

                $groups[$groupIndex]['agents'][] = $agent;

                continue;
            }

            if ($name === 'sitemap') {
                if ($value !== '') {
                    $sitemaps[] = $this->normalizePercentEncoding($value);
                }

                continue;
            }

            if ($groupIndex === null) {
                continue;
            }

            $groupHasDirectives = true;

            if (! in_array($name, ['allow', 'disallow'], true) || $value === '' || ! str_starts_with($value, '/')) {
                continue;
            }

            $groups[$groupIndex]['rules'][] = [
                'type' => $name,
                'path' => $this->normalizePercentEncoding($value),
            ];
        }

        $normalizedSitemaps = array_values(array_unique($sitemaps));
        sort($normalizedSitemaps, SORT_STRING);
        $normalizedGroups = array_map(static function (array $group): array {
            $agents = array_values(array_unique($group['agents']));
            sort($agents, SORT_STRING);
            $rules = $group['rules'];
            usort($rules, static fn (array $left, array $right): int => strcmp($left['type']."\0".$left['path'], $right['type']."\0".$right['path']));

            return ['agents' => $agents, 'rules' => $rules];
        }, $groups);
        $canonicalPolicy = json_encode([
            'groups' => $normalizedGroups,
            'sitemaps' => $normalizedSitemaps,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $crawlerDecisions = [];

        foreach (self::CRAWLERS as $crawlerName => $productToken) {
            $rules = $this->rulesForCrawler($groups, $productToken);
            $crawlerDecisions[$crawlerName] = $this->decisionForPath($rules, $targetPath);
        }

        return [
            'policy_hash' => hash('sha256', $canonicalPolicy),
            'rule_count' => array_sum(array_map(static fn (array $group): int => count($group['rules']), $groups)),
            'sitemap_count' => count($normalizedSitemaps),
            'crawler_decisions' => $crawlerDecisions,
        ];
    }

    /** @return list<string> */
    private function lines(string $contents): array
    {
        $contents = preg_replace('/\A\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => mb_check_encoding($line, 'UTF-8')));
    }

    /**
     * @param  list<array{agents: list<string>, rules: list<array{type: string, path: string}>}>  $groups
     * @return list<array{type: string, path: string}>
     */
    private function rulesForCrawler(array $groups, string $productToken): array
    {
        $specificGroups = [];
        $wildcardGroups = [];

        foreach ($groups as $group) {
            if (in_array($productToken, $group['agents'], true)) {
                $specificGroups[] = $group;
            } elseif (in_array('*', $group['agents'], true)) {
                $wildcardGroups[] = $group;
            }
        }

        $matchingGroups = $specificGroups !== [] ? $specificGroups : $wildcardGroups;

        return array_merge(...array_map(static fn (array $group): array => $group['rules'], $matchingGroups ?: [['rules' => []]]));
    }

    /**
     * @param  list<array{type: string, path: string}>  $rules
     * @return array{allowed: bool, matching_rule: ?string}
     */
    private function decisionForPath(array $rules, string $targetPath): array
    {
        $targetPath = $this->normalizePercentEncoding($targetPath === '' ? '/' : $targetPath);
        $winningRule = null;
        $winningSpecificity = -1;

        foreach ($rules as $rule) {
            if (! $this->matches($targetPath, $rule['path'])) {
                continue;
            }

            $specificity = strlen(str_replace(['*', '$'], '', $rule['path']));

            if ($specificity > $winningSpecificity
                || ($specificity === $winningSpecificity && $rule['type'] === 'allow' && $winningRule !== null && $winningRule['type'] === 'disallow')) {
                $winningRule = $rule;
                $winningSpecificity = $specificity;
            }
        }

        return [
            'allowed' => $winningRule === null || $winningRule['type'] === 'allow',
            'matching_rule' => $winningRule === null ? null : ucfirst($winningRule['type']).': '.$winningRule['path'],
        ];
    }

    private function matches(string $targetPath, string $rulePath): bool
    {
        $isEndAnchored = str_ends_with($rulePath, '$');
        $pattern = preg_quote($isEndAnchored ? substr($rulePath, 0, -1) : $rulePath, '~');
        $pattern = str_replace('\\*', '.*', $pattern);
        $regex = '~\A'.$pattern.($isEndAnchored ? '\z' : '.*').'~';

        return preg_match($regex, $targetPath) === 1;
    }

    private function normalizePercentEncoding(string $value): string
    {
        return preg_replace_callback('/%([0-9a-f]{2})/i', static function (array $matches): string {
            $decoded = chr(hexdec($matches[1]));

            return preg_match('/[A-Za-z0-9._~-]/', $decoded) === 1
                ? $decoded
                : '%'.strtoupper($matches[1]);
        }, $value) ?? $value;
    }
}
