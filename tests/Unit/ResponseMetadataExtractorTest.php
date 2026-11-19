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
        $this->assertSame(['title' => null, 'description' => null], ResponseMetadataExtractor::extract(''));
        $this->assertSame(['title' => null, 'description' => null], ResponseMetadataExtractor::extract('<html><body>no metadata</body></html>'));
    }
}
