<?php

namespace App\Http\Controllers;

use App\Models\Monitor;
use App\Models\StatusPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StatusPageController extends Controller
{
    public function edit(): View
    {
        $statusPage = StatusPage::query()->where('setting_key', 'default')->first();
        $monitors = Monitor::query()
            ->with(['latestCheckRun', 'activeIncidents'])
            ->orderBy('name')
            ->get();

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
        }

        $publicMonitorCount = $monitors->where('public_status', true)->count();

        $publicUrl = $statusPage?->is_published
            ? route('status-pages.public', ['token' => $statusPage->public_token])
            : null;

        return view('status-pages.manage', compact('statusPage', 'monitors', 'publicMonitorCount', 'publicUrl'));
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'is_published' => ['required', 'boolean'],
            'rotate_public_link' => ['nullable', 'boolean'],
        ]);
        $shouldPublish = (bool) $validated['is_published'];

        if ($shouldPublish && ! Monitor::query()->where('public_status', true)->exists()) {
            throw ValidationException::withMessages([
                'is_published' => '請先在至少一個監測項目的設定中，勾選「顯示於公開狀態頁」。',
            ]);
        }

        DB::transaction(function () use ($validated, $shouldPublish): void {
            $token = Str::random(64);
            $statusPage = StatusPage::query()->firstOrCreate(
                ['setting_key' => 'default'],
                [
                    'title' => $validated['title'],
                    'public_token' => $token,
                    'public_token_hash' => hash('sha256', $token),
                    'is_published' => false,
                ],
            );
            $statusPage = StatusPage::query()->whereKey($statusPage->id)->lockForUpdate()->firstOrFail();

            $values = [
                'title' => $validated['title'],
                'is_published' => $shouldPublish,
            ];

            if (($validated['rotate_public_link'] ?? false) === true || ($validated['rotate_public_link'] ?? null) === '1') {
                $nextToken = Str::random(64);
                $values['public_token'] = $nextToken;
                $values['public_token_hash'] = hash('sha256', $nextToken);
            }

            $statusPage->forceFill($values)->save();
        });

        return redirect()->route('status-pages.edit')->with('status', '狀態頁設定已儲存。');
    }

    public function toggleMonitor(Monitor $monitor): RedirectResponse
    {
        [$isPublic, $pageWasUnpublished] = DB::transaction(function () use ($monitor): array {
            $lockedMonitor = Monitor::query()->lockForUpdate()->findOrFail($monitor->id);
            $isPublic = ! $lockedMonitor->public_status;
            $lockedMonitor->forceFill(['public_status' => $isPublic])->save();
            $pageWasUnpublished = false;

            if (! $isPublic && ! Monitor::query()->where('public_status', true)->exists()) {
                $statusPage = StatusPage::query()->where('setting_key', 'default')->lockForUpdate()->first();

                if ($statusPage?->is_published) {
                    $statusPage->forceFill(['is_published' => false])->save();
                    $pageWasUnpublished = true;
                }
            }

            return [$isPublic, $pageWasUnpublished];
        });

        $message = $isPublic
            ? '監測項目已加入公開狀態頁。'
            : ($pageWasUnpublished ? '最後一個公開項目已移除，狀態頁也已取消發布。' : '監測項目已從公開狀態頁移除。');

        return redirect()->route('status-pages.edit')->with('status', $message);
    }

    public function show(string $token): Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1, 404);

        $statusPage = StatusPage::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->where('is_published', true)
            ->firstOrFail();
        $monitors = Monitor::query()
            ->where('public_status', true)
            ->with(['latestCheckRun', 'activeIncidents'])
            ->orderBy('name')
            ->get();

        abort_if($monitors->isEmpty(), 404);

        foreach ($monitors as $monitor) {
            $monitor->setAttribute('health_state', $monitor->healthState());
        }

        $pageState = $monitors->contains(fn (Monitor $monitor): bool => $monitor->getAttribute('health_state') === 'issue')
            ? 'issue'
            : ($monitors->every(fn (Monitor $monitor): bool => $monitor->getAttribute('health_state') === 'healthy') ? 'healthy' : 'warning');
        $lastCheckedAt = $monitors->pluck('last_checked_at')->filter()->max();

        return response()
            ->view('status-pages.public', compact('statusPage', 'monitors', 'pageState', 'lastCheckedAt'))
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
