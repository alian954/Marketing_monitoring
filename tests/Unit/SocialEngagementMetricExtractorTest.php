<?php

namespace Tests\Unit;

use App\Services\Probe\ResponseMetadataExtractor;
use PHPUnit\Framework\TestCase;

class SocialEngagementMetricExtractorTest extends TestCase
{
    public function test_it_extracts_explicit_schema_interaction_counts_for_the_current_post(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:url" content="https://www.instagram.com/reel/POST123/">
                <script type="application/ld+json">
                    {"@context":"https://schema.org","@type":"VideoObject","url":"https://www.instagram.com/reel/POST123/","interactionStatistic":[
                        {"@type":"InteractionCounter","interactionType":{"@type":"WatchAction"},"userInteractionCount":5320},
                        {"@type":"InteractionCounter","interactionType":{"@type":"LikeAction"},"userInteractionCount":184},
                        {"@type":"InteractionCounter","interactionType":{"@type":"CommentAction"},"userInteractionCount":19},
                        {"@type":"InteractionCounter","interactionType":{"@type":"ShareAction"},"userInteractionCount":7}
                    ]}
                </script>
            </head><body></body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.instagram.com/reel/POST123/?utm_source=test')['public_engagement_metrics'];

        $this->assertSame([
            'views' => 5320,
            'likes' => 184,
            'comments' => 19,
            'shares' => 7,
            'source' => 'json_ld',
        ], $result);
    }

    public function test_it_extracts_only_embedded_counts_for_a_matching_post_identifier(): void
    {
        $html = <<<'HTML'
            <html><head><script type="application/json">
                {"posts":[
                    {"shortcode":"OTHER456","like_count":900},
                    {"shortcode":"POST123","video_view_count":5320,"edge_media_preview_like":{"count":184},"edge_media_to_comment":{"count":19},"share_count":7}
                ]}
            </script></head><body></body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.instagram.com/reel/POST123/')['public_engagement_metrics'];

        $this->assertSame([
            'views' => 5320,
            'likes' => 184,
            'comments' => 19,
            'shares' => 7,
            'source' => 'embedded_json',
        ], $result);
    }

    public function test_it_ignores_ambiguous_counts_and_rounded_display_values(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:likes" content="4.2K">
                <script type="application/json">
                    {"posts":[{"shortcode":"POST123","like_count":20},{"shortcode":"POST123","like_count":21}]}
                </script>
            </head><body><p>1,000 views</p></body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.instagram.com/p/POST123/')['public_engagement_metrics'];

        $this->assertNull($result);
    }

    public function test_it_uses_exact_public_meta_counts_as_a_fallback(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:video:views" content="5,320">
                <meta property="og:likes" content="184">
            </head><body></body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.example.com/post/POST123')['public_engagement_metrics'];

        $this->assertSame([
            'views' => 5320,
            'likes' => 184,
            'comments' => null,
            'shares' => null,
            'source' => 'meta',
        ], $result);
    }

    public function test_truncated_html_does_not_produce_engagement_metrics(): void
    {
        $html = '<html><head><meta property="og:likes" content="184"></head></html>';

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.example.com/post/POST123', [], false);

        $this->assertNull($result['public_engagement_metrics']);
    }
}
