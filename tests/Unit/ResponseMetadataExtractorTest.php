<?php

namespace Tests\Unit;

use App\Services\Probe\ResponseMetadataExtractor;
use PHPUnit\Framework\TestCase;

class ResponseMetadataExtractorTest extends TestCase
{
    public function test_it_prefers_open_graph_metadata_and_normalizes_the_values(): void
    {
        $metadata = ResponseMetadataExtractor::extract(<<<'HTML'
            <!doctype html>
            <html><head>
                <title>Page title</title>
                <meta property="og:title" content="  社群貼文 &amp; 活動  ">
                <meta name="description" content="Generic page description">
                <meta property="og:description" content="  最新活動   詳情  ">
            </head></html>
            HTML);

        $this->assertSame([
            'title' => '社群貼文 & 活動',
            'description' => '最新活動 詳情',
            'image_reference_hash' => null,
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
        $this->assertSame(['title' => null, 'description' => null, 'image_reference_hash' => null], ResponseMetadataExtractor::extract(''));
        $this->assertSame(['title' => null, 'description' => null, 'image_reference_hash' => null], ResponseMetadataExtractor::extract('<html><body>no metadata</body></html>'));
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
        ], $metadata);
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
