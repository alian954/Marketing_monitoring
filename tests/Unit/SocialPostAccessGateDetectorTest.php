<?php

namespace Tests\Unit;

use App\Services\Probe\SocialPostAccessGateDetector;
use PHPUnit\Framework\TestCase;

class SocialPostAccessGateDetectorTest extends TestCase
{
    public function test_detects_a_platform_login_wall_from_matching_metadata_and_visible_text(): void
    {
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads'] as $platform => $title) {
            $body = '<html><head><title>'.$title.'</title></head><body><main>Log in to see this post</main></body></html>';
            $metadata = ['title' => $title, 'description' => 'Log in to see this post'];

            $this->assertTrue(SocialPostAccessGateDetector::detects($platform, $body, $metadata));
        }
    }

    public function test_detects_a_traditional_chinese_login_prompt(): void
    {
        $body = '<html><head><title>Instagram</title></head><body><main>請登入查看貼文</main></body></html>';
        $metadata = ['title' => 'Instagram', 'description' => '請登入查看貼文'];

        $this->assertTrue(SocialPostAccessGateDetector::detects('instagram', $body, $metadata));
    }

    public function test_detects_platform_verification_and_temporary_restriction_pages(): void
    {
        $cases = [
            [
                'platform' => 'instagram',
                'title' => 'Instagram',
                'message' => 'Please complete a security check to continue',
            ],
            [
                'platform' => 'threads',
                'title' => 'Threads',
                'message' => 'Please wait a few minutes before you try again',
            ],
            [
                'platform' => 'facebook',
                'title' => 'Facebook',
                'message' => '操作過於頻繁，請稍後再試',
            ],
        ];

        foreach ($cases as $case) {
            $body = '<html><head><title>'.$case['title'].'</title></head><body><main>'.$case['message'].'</main></body></html>';
            $metadata = ['title' => $case['title'], 'description' => $case['message']];

            $this->assertTrue(SocialPostAccessGateDetector::detects($case['platform'], $body, $metadata));
        }
    }

    public function test_does_not_detect_a_login_prompt_when_platform_metadata_or_description_does_not_support_it(): void
    {
        $body = '<html><body>Log in to see this post</body></html>';

        $this->assertFalse(SocialPostAccessGateDetector::detects('instagram', $body, ['title' => 'Campaign post', 'description' => 'Log in to see this post']));
        $this->assertFalse(SocialPostAccessGateDetector::detects('instagram', $body, ['title' => 'Instagram', 'description' => 'Campaign details']));
        $this->assertFalse(SocialPostAccessGateDetector::detects('website', $body, ['title' => 'Instagram', 'description' => 'Log in to see this post']));
    }

    public function test_ignores_login_text_found_only_in_script_content(): void
    {
        $body = '<html><head><title>Instagram</title></head><body><script>Log in to see this post</script><main>Campaign headline</main></body></html>';
        $metadata = ['title' => 'Instagram', 'description' => 'Log in to see this post'];

        $this->assertFalse(SocialPostAccessGateDetector::detects('instagram', $body, $metadata));
    }

    public function test_does_not_treat_ordinary_post_copy_as_a_temporary_access_restriction(): void
    {
        $message = 'You can try again later at our next campaign';
        $body = '<html><head><title>Instagram</title></head><body><main>'.$message.'</main></body></html>';
        $metadata = ['title' => 'Instagram', 'description' => $message];

        $this->assertFalse(SocialPostAccessGateDetector::detects('instagram', $body, $metadata));
    }
}
