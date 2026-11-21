<?php

namespace Tests\Unit;

use App\Services\Probe\RobotsTxtParser;
use PHPUnit\Framework\TestCase;

class RobotsTxtParserTest extends TestCase
{
    public function test_specific_agent_groups_override_wildcard_rules_and_longest_matching_rule_wins(): void
    {
        $parser = new RobotsTxtParser;
        $contents = <<<'ROBOTS'
            User-agent: *
            Disallow: /
            Allow: /campaign/offer$
            User-agent: Googlebot
            Disallow: /campaign
            Allow: /campaign/public
            Disallow: /campaign/public/private
            Allow: /campaign/offer$
            User-agent: FacebookExternalHit
            Disallow: /campaign/private
            ROBOTS;

        $analysis = $parser->analyze($contents, '/campaign/public/private');

        $this->assertFalse($analysis['crawler_decisions']['Googlebot']['allowed']);
        $this->assertSame('Disallow: /campaign/public/private', $analysis['crawler_decisions']['Googlebot']['matching_rule']);
        $this->assertFalse($analysis['crawler_decisions']['AdsBot-Google']['allowed']);
        $this->assertTrue($analysis['crawler_decisions']['FacebookExternalHit']['allowed']);
        $this->assertFalse($analysis['crawler_decisions']['Twitterbot']['allowed']);

        $offerAnalysis = $parser->analyze($contents, '/campaign/offer');

        $this->assertTrue($offerAnalysis['crawler_decisions']['Googlebot']['allowed']);
        $this->assertSame('Allow: /campaign/offer$', $offerAnalysis['crawler_decisions']['Googlebot']['matching_rule']);
    }

    public function test_specific_groups_for_the_same_agent_merge_and_wildcards_match_paths(): void
    {
        $parser = new RobotsTxtParser;
        $contents = <<<'ROBOTS'
            User-agent: FacebookExternalHit
            Disallow: /campaign/*/private$
            User-agent: FacebookExternalHit
            Allow: /campaign/spring/public
            Sitemap: https://example.com/sitemap.xml
            ROBOTS;

        $blocked = $parser->analyze($contents, '/campaign/spring/private');
        $allowed = $parser->analyze($contents, '/campaign/spring/public');

        $this->assertFalse($blocked['crawler_decisions']['FacebookExternalHit']['allowed']);
        $this->assertTrue($allowed['crawler_decisions']['FacebookExternalHit']['allowed']);
        $this->assertSame(1, $allowed['sitemap_count']);
        $this->assertSame(2, $allowed['rule_count']);
    }

    public function test_empty_rules_allow_crawling_and_policy_hash_ignores_comments_and_formatting(): void
    {
        $parser = new RobotsTxtParser;
        $first = $parser->analyze("\xEF\xBB\xBFUser-agent: *\r\nDisallow:\r\n# campaign comment\r\n", '/campaign');
        $second = $parser->analyze("User-agent: *\nDisallow:  \n", '/campaign');

        $this->assertSame($first['policy_hash'], $second['policy_hash']);
        $this->assertSame(0, $first['rule_count']);

        foreach (RobotsTxtParser::CRAWLERS as $crawlerName => $productToken) {
            $this->assertTrue($first['crawler_decisions'][$crawlerName]['allowed']);
            $this->assertNull($first['crawler_decisions'][$crawlerName]['matching_rule']);
        }
    }

    public function test_unknown_agents_use_wildcard_rules_and_no_matching_group_defaults_to_allowed(): void
    {
        $parser = new RobotsTxtParser;
        $wildcard = $parser->analyze("User-agent: *\nDisallow: /private\n", '/private/page');
        $specific = $parser->analyze("User-agent: ExampleBot\nDisallow: /\n", '/private/page');

        $this->assertFalse($wildcard['crawler_decisions']['Googlebot']['allowed']);
        $this->assertTrue($specific['crawler_decisions']['Googlebot']['allowed']);
    }
}
