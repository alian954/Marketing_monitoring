<?php

namespace Tests\Unit;

use App\Services\Probe\DcardPublicEngagementMetricExtractor;
use App\Services\Probe\ResponseMetadataExtractor;
use PHPUnit\Framework\TestCase;

class SocialEngagementMetricExtractorTest extends TestCase
{
    public function test_it_extracts_dcard_like_and_comment_totals_from_the_rendered_public_page(): void
    {
        $visibleText = <<<'TEXT'
            美妝
            #開箱 #推薦 一罐搞定保養 男生或懶人都適合的凝露心得分享
            Campaign headline
            開箱
            推薦
            2026 化妝水心動榜 TOP 10
            20
            3
            推薦商品
            由舊至新
            由新至舊
            全部留言
            留言內容
            0
            TEXT;

        $this->assertSame([
            'views' => null,
            'likes' => 20,
            'comments' => 3,
            'shares' => null,
            'source' => 'rendered_dom',
        ], DcardPublicEngagementMetricExtractor::extract($visibleText));
    }

    public function test_it_does_not_use_comment_vote_counts_or_ambiguous_number_pairs_as_post_metrics(): void
    {
        $this->assertNull(DcardPublicEngagementMetricExtractor::extract("全部留言\n留言內容\n20\n3"));
        $this->assertNull(DcardPublicEngagementMetricExtractor::extract("10\n20\n3\n全部留言"));
        $this->assertNull(DcardPublicEngagementMetricExtractor::extract("貼文內容\n20\n全部留言"));
    }

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

    public function test_it_extracts_metrics_from_body_scripts_before_hashing_visible_text(): void
    {
        $html = <<<'HTML'
            <html><body>
                <p>Public post</p>
                <script type="application/json">
                    {"media":{"code":"POST123","like_count":184}}
                </script>
            </body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.instagram.com/p/POST123/');

        $this->assertSame([
            'views' => null,
            'likes' => 184,
            'comments' => null,
            'shares' => null,
            'source' => 'embedded_json',
        ], $result['public_engagement_metrics']);
        $this->assertSame(hash('sha256', 'Public post'), $result['visible_text_hash']);
    }

    public function test_it_scans_past_early_json_scripts_and_reads_nested_post_counts(): void
    {
        $irrelevantScripts = str_repeat('<script type="application/json">{}</script>', 25);
        $html = '<html><head>'.$irrelevantScripts.'<script type="application/json">'
            .'{"media":{"__typename":"XIGPolarisVideoMedia","pk":"4034361585031839456","code":"POST123","like_count":184,"if_not_gated_logged_out":{"view_count":5320,"comment_count":19}}}'
            .'</script></head><body></body></html>';

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.instagram.com/reel/POST123/')['public_engagement_metrics'];

        $this->assertSame([
            'views' => 5320,
            'likes' => 184,
            'comments' => 19,
            'shares' => null,
            'source' => 'embedded_json',
        ], $result);
    }

    public function test_it_decodes_matching_social_json_beyond_the_default_depth_limit(): void
    {
        $json = '{"media":{"code":"POST123","like_count":21}}';

        for ($depth = 0; $depth < 36; $depth++) {
            $json = '{"wrapper":'.$json.'}';
        }

        $html = '<html><body><script type="application/json">'.$json.'</script></body></html>';
        $result = ResponseMetadataExtractor::inspect($html, 'https://www.instagram.com/p/POST123/')['public_engagement_metrics'];

        $this->assertSame([
            'views' => null,
            'likes' => 21,
            'comments' => null,
            'shares' => null,
            'source' => 'embedded_json',
        ], $result);
    }

    public function test_it_merges_nested_facebook_counts_from_the_matching_post_only(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:url" content="https://www.facebook.com/KSSG12/posts/1579756554170483/">
                <script type="application/json">
                    {"node":{"post_id":"1579756554170483","feedback":{"feedback_context":{"feedback_target_with_context":{
                        "comet_ufi_summary_and_actions_renderer":{"feedback":{"reaction_count":{"count":36},"share_count":{"count":4}}},
                        "comments_count_summary_renderer":{"feedback":{"total_comment_count":9}},
                        "comments":[{"feedback":{"reaction_count":999}}],
                        "top_level_comments":{"edges":[{"node":{"feedback":{"reaction_count":999}}}]}
                    }}}}}
                </script>
            </head><body></body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.facebook.com/KSSG12/posts/1579756554170483/')['public_engagement_metrics'];

        $this->assertSame([
            'views' => null,
            'likes' => 36,
            'comments' => 9,
            'shares' => 4,
            'source' => 'embedded_json',
        ], $result);
    }

    public function test_it_matches_facebook_feedback_ids_that_encode_the_target_video_id(): void
    {
        $encodedTargetId = base64_encode('S:100057195890042:VK:2327132884801281');
        $html = '<html><head><script type="application/json">'
            .'{"feedback":{"id":"'.$encodedTargetId.'","total_comment_count":2,"share_count_reduced":"0"}}'
            .'</script></head><body></body></html>';

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.facebook.com/reel/2327132884801281/')['public_engagement_metrics'];

        $this->assertSame([
            'views' => null,
            'likes' => null,
            'comments' => 2,
            'shares' => 0,
            'source' => 'embedded_json',
        ], $result);
    }

    public function test_it_recognizes_public_reply_counts_as_comments(): void
    {
        $html = <<<'HTML'
            <html><head>
                <meta property="og:url" content="https://www.threads.com/@creator/post/POST123">
                <script type="application/json">
                    {"media":{"id":"4027866067012284001_63092002311","code":"POST123","view_count":5320,"like_count":184,"text_post_app_info":{"direct_reply_count":19,"repost_count":7}}}
                </script>
            </head><body></body></html>
            HTML;

        $result = ResponseMetadataExtractor::inspect($html, 'https://www.threads.com/share/SHARE123')['public_engagement_metrics'];

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
