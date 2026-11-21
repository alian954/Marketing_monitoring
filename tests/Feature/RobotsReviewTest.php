<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotsReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_review_provides_a_manual_root_link_and_waits_for_the_daily_probe(): void
    {
        $url = 'https://www.example.com/campaign/page?token=must-stay-private';
        $monitor = Monitor::factory()->create([
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('monitors.robots', $monitor))
            ->assertOk()
            ->assertSee('https://www.example.com')
            ->assertSee('/robots.txt')
            ->assertSee('https://www.example.com/robots.txt', false)
            ->assertDontSee('must-stay-private')
            ->assertSee('下一次每日排程執行後');

        $this->assertDatabaseCount('check_runs', 0);
        $this->assertDatabaseCount('robots_observations', 0);
    }
}
