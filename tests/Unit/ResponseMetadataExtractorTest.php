<?php

namespace Tests\Unit;

use App\Services\Probe\ResponseMetadataExtractor;
use PHPUnit\Framework\TestCase;

class ResponseMetadataExtractorTest extends TestCase
{
    public function test_it_hashes_normalized_body_text_without_scripts_styles_or_whitespace_changes(): void
    {
        $first = ResponseMetadataExtractor::inspect(<<<'HTML'
            <html><body><article>Campaign &amp; <strong>headline</strong></article><script>dynamic token one</script><style>.one { color: red; }</style></body></html>
            HTML);
        $sameText = ResponseMetadataExtractor::inspect(<<<'HTML'
            <html><body><article>Campaign &amp; <em> headline </em></article><script>dynamic token two</script><style>.two { color: blue; }</style></body></html>
            HTML);
        $changedText = ResponseMetadataExtractor::inspect('<html><body><article>Campaign &amp; revised headline</article></body></html>');

        $this->assertSame('71de0465ecf043c08e6d4a1f2f749de8e985f7cfe05d968d8b915606b7002995', $first['visible_text_hash']);
        $this->assertSame($first['visible_text_hash'], $sameText['visible_text_hash']);
        $this->assertNotSame($first['visible_text_hash'], $changedText['visible_text_hash']);
    }

    public function test_it_prefers_open_graph_metadata_and_normalizes_the_values(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <!doctype html>
            <html><head>
                <title>Page title</title>
                <meta property="og:title" content="  社群貼文 &amp; 活動  ">
                <meta name="twitter:title" content="Twitter title">
                <meta name="description" content="Generic page description">
                <meta property="og:description" content="  最新活動   詳情  ">
                <meta name="twitter:description" content="Twitter description">
            </head></html>
            HTML);

        $this->assertSame([
            'title' => '社群貼文 & 活動',
            'description' => '最新活動 詳情',
            'image_reference_hash' => null,
            'open_graph_type' => null,
            'canonical_reference_hash' => null,
            'published_at' => null,
            'modified_at' => null,
        ], $metadata);
    }

    public function test_it_uses_page_title_and_description_when_open_graph_metadata_is_missing(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <title>  Page   title </title>
                <meta name="description" content=" Page description ">
            </head></html>
            HTML);

        $this->assertSame([
            'title' => 'Page title',
            'description' => 'Page description',
            'image_reference_hash' => null,
            'open_graph_type' => null,
            'canonical_reference_hash' => null,
            'published_at' => null,
            'modified_at' => null,
        ], $metadata);
    }

    public function test_it_uses_twitter_card_metadata_before_generic_page_metadata(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <title>Generic page title</title>
                <meta name="description" content="Generic page description">
                <meta name="twitter:title" content="Twitter card title">
                <meta name="twitter:description" content="Twitter card description">
            </head></html>
            HTML);

        $this->assertSame([
            'title' => 'Twitter card title',
            'description' => 'Twitter card description',
            'image_reference_hash' => null,
            'open_graph_type' => null,
            'canonical_reference_hash' => null,
            'published_at' => null,
            'modified_at' => null,
        ], $metadata);
    }

    public function test_it_limits_saved_metadata_and_handles_empty_or_missing_values(): void
    {
        $metadata = ResponseMetadataExtractor::extract(
            '<html><head><meta property="og:title" content="'.str_repeat('t', 300).'">'
            .'<meta property="og:description" content="'.str_repeat('d', 600).'"></head></html>',
        );

        $this->assertSame(255, mb_strlen($metadata['title']));
        $this->assertSame(500, mb_strlen($metadata['description']));
        $this->assertSame(['title' => null, 'description' => null, 'image_reference_hash' => null, 'open_graph_type' => null, 'canonical_reference_hash' => null, 'published_at' => null, 'modified_at' => null], ResponseMetadataExtractor::extract(''));
        $this->assertSame(['title' => null, 'description' => null, 'image_reference_hash' => null, 'open_graph_type' => null, 'canonical_reference_hash' => null, 'published_at' => null, 'modified_at' => null], ResponseMetadataExtractor::extract('<html><body>no metadata</body></html>'));
    }

    public function test_it_hashes_a_normalized_open_graph_image_reference_without_query_or_fragment(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta property="og:image" content="https://CDN.Example.test:443/share.jpg?token=private-token#preview">
            </head></html>
            HTML);

        $this->assertSame([
            'title' => null,
            'description' => null,
            'image_reference_hash' => '4822f7225b9ec55db19c608f6d37b25d9f6cc7442502a566525bce0629833c6c',
            'open_graph_type' => null,
            'canonical_reference_hash' => null,
            'published_at' => null,
            'modified_at' => null,
        ], $metadata);
    }

    public function test_it_extracts_open_graph_type_and_hashes_the_canonical_url_without_query_or_fragment(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta property="og:type" content="  article  ">
                <meta property="og:url" content="https://WWW.Instagram.com:443/p/ABC123/?token=private-token#post">
            </head></html>
            HTML);

        $this->assertSame('article', $metadata['open_graph_type']);
        $this->assertSame('59276114c24f898c899a925b37854497f2cb8a64e485d5eca8077a67a175ca16', $metadata['canonical_reference_hash']);
    }

    public function test_it_rejects_unsafe_or_oversized_canonical_urls_and_limits_open_graph_type_length(): void
    {
        $unsafeMetadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta property="og:type" content="safe-type">
                <meta property="og:url" content="https://user:password@example.test/post">
            </head></html>
            HTML);
        $relativeMetadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head><meta property="og:url" content="/p/ABC123/"></head></html>
            HTML, 'https://www.instagram.com/p/ABC123/');
        $oversizedUrl = 'https://example.test/'.str_repeat('p', 2_048);
        $oversizedMetadata = ResponseMetadataExtractor::extract(
            '<html><head><meta property="og:type" content="'.str_repeat('t', 200).'"><meta property="og:url" content="'.$oversizedUrl.'"></head></html>',
        );

        $this->assertNull($unsafeMetadata['canonical_reference_hash']);
        $this->assertSame('safe-type', $unsafeMetadata['open_graph_type']);
        $this->assertNull($relativeMetadata['canonical_reference_hash']);
        $this->assertNull($oversizedMetadata['canonical_reference_hash']);
        $this->assertSame(120, mb_strlen($oversizedMetadata['open_graph_type']));
    }

    public function test_it_normalizes_publication_times_to_utc_and_skips_invalid_dates(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta property="article:published_time" content="2026-11-19T09:30:00+08:00">
                <meta property="article:published_time" content="2026-11-19T01:00:00Z">
                <meta property="article:modified_time" content="2026-02-31T01:00:00Z">
                <meta name="article:modified_time" content="2026-11-20T12:45:00Z">
            </head></html>
            HTML);

        $this->assertSame('2026-11-19T01:30:00+00:00', $metadata['published_at']?->format(DATE_ATOM));
        $this->assertSame('2026-11-20T12:45:00+00:00', $metadata['modified_at']?->format(DATE_ATOM));
    }

    public function test_it_preserves_fractional_seconds_and_rejects_invalid_datetime_values(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta property="article:published_time" content="2026-11-19T09:30:00.123456+08:00">
                <meta property="article:modified_time" content="2026-11-19">
                <meta property="article:modified_time" content="0999-11-19T09:30:00Z">
            </head></html>
            HTML);

        $this->assertSame('2026-11-19T01:30:00.123456+00:00', $metadata['published_at']?->format('Y-m-d\TH:i:s.uP'));
        $this->assertNull($metadata['modified_at']);
    }

    public function test_it_resolves_relative_twitter_images_and_rejects_unsafe_references(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta name="twitter:image" content="/media/share.png?token=private-token#preview">
            </head></html>
            HTML, 'https://www.instagram.com/p/ABC123/');
        $unsafeMetadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head><meta property="og:image" content="javascript:alert(1)"></head></html>
            HTML, 'https://www.instagram.com/p/ABC123/');

        $this->assertSame('35c9b93e6fdbdc899962ed7c818c5696c0b9dc7772a168df068b7f9d9fc54a2b', $metadata['image_reference_hash']);
        $this->assertNull($unsafeMetadata['image_reference_hash']);
    }

    public function test_it_ignores_invalid_candidates_and_rejects_userinfo_and_oversized_references(): void
    {
        $fallbackMetadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head>
                <meta property="og:image" content="data:image/png;base64,private-data">
                <meta name="twitter:image" content="https://cdn.example.test/fallback.jpg">
            </head></html>
            HTML);
        $userinfoMetadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <html><head><meta property="og:image" content="https://user:password@cdn.example.test/share.jpg"></head></html>
            HTML);
        $oversizedReference = 'https://cdn.example.test/'.str_repeat('x', 2_048);
        $oversizedMetadata = ResponseMetadataExtractor::extract(
            '<html><head><meta property="og:image" content="'.$oversizedReference.'"></head></html>',
        );

        $this->assertSame('1b0d80ee003b323be46bf82f91f8dcc964a2126ed76aae54a1155f867bd56888', $fallbackMetadata['image_reference_hash']);
        $this->assertNull($userinfoMetadata['image_reference_hash']);
        $this->assertNull($oversizedMetadata['image_reference_hash']);
    }
}
