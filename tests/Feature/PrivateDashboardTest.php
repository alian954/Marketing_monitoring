<?php

namespace Tests\Feature;

use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class PrivateDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_pages_redirect_to_login_and_public_registration_is_missing(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('monitors.index'))->assertRedirect(route('login'));
        $this->get('/register')->assertNotFound();
    }

    public function test_manager_sign_in_grants_private_dashboard_access(): void
    {
        $user = User::factory()->create(['email' => 'manager@example.test', 'password' => bcrypt('correct-horse-battery')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'correct-horse-battery'])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_dashboard_and_edit_form_never_render_raw_sensitive_url_query_values(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/landing?email=owner%40example.test&token=do-not-render-this';
        $monitor = Monitor::factory()->create([
            'name' => '<script>alert(1)</script>',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('email=REDACTED', false)
            ->assertSee('token=REDACTED', false)
            ->assertDontSee('do-not-render-this');

        $this->get(route('monitors.edit', $monitor))
            ->assertOk()
            ->assertSee('email=REDACTED', false)
            ->assertDontSee('do-not-render-this');
    }

    public function test_export_escapes_spreadsheet_formulas_and_masks_sensitive_url_values(): void
    {
        $user = User::factory()->create();
        $url = 'https://example.com/page?email=secret%40example.test';
        Monitor::factory()->create([
            'name' => '=HYPERLINK("https://evil.example")',
            'url' => $url,
            'url_hash' => Monitor::fingerprintUrl($url),
        ]);

        $response = $this->actingAs($user)->get(route('monitors.export'))->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $content);
        $this->assertStringContainsString('email=REDACTED', $content);
        $this->assertStringNotContainsString('secret%40example.test', $content);
    }

    public function test_csv_preview_reports_invalid_and_duplicate_rows_then_imports_only_confirmed_valid_rows(): void
    {
        Config::set('monitoring.transport', 'fixture');
        $user = User::factory()->create();
        $csv = "name,url,label\nValid,http://fixture.test/ok,Campaign\nDuplicate,http://fixture.test/ok,Campaign\nPrivate,http://127.0.0.1/admin,Local\n";

        $preview = $this->actingAs($user)->post(route('monitors.import.preview'), [
            'csv_file' => UploadedFile::fake()->createWithContent('monitors.csv', $csv),
        ])->assertOk()->assertSee('此網址在 CSV 檔案中重複。')->assertSee('離線測試模式只接受 fixture.test 網域。');

        $this->assertSame(1, $preview->viewData('validCount'));
        $this->assertSame(2, $preview->viewData('invalidCount'));
        $this->assertDatabaseCount('monitors', 0);

        $token = $preview->viewData('previewToken');
        $this->post(route('monitors.import.commit'), ['preview_token' => $token])
            ->assertRedirect(route('monitors.index'));

        $this->assertDatabaseCount('monitors', 1);
        $storedUrl = \DB::table('monitors')->value('url');
        $this->assertNotSame('http://fixture.test/ok', $storedUrl);
    }

    public function test_csv_commit_rejects_a_stale_or_mismatched_preview_token(): void
    {
        $this->actingAs(User::factory()->create())->withSession([
            'monitor_import_preview' => Crypt::encryptString('[]'),
            'monitor_import_token' => str_repeat('a', 40),
        ])->post(route('monitors.import.commit'), ['preview_token' => str_repeat('b', 40)])
            ->assertRedirect(route('monitors.import'));

        $this->assertDatabaseCount('monitors', 0);
    }
}
