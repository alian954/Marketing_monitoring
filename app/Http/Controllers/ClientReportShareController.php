<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ClientReportShareController extends Controller
{
    public function index(Client $client): Response
    {
        $shares = $client->reportShares()->latest()->get();

        return response()
            ->view('client-report-shares.index', compact('client', 'shares'))
            ->header('Cache-Control', 'private, no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expires_in_days' => ['required', 'integer', Rule::in([7, 30, 90])],
        ], [
            'name.required' => '請輸入分享連結名稱。',
            'name.max' => '分享連結名稱不可超過 80 個字元。',
            'expires_in_days.in' => '請選擇 7、30 或 90 天的有效期限。',
        ]);

        $token = bin2hex(random_bytes(32));
        $share = $client->reportShares()->create([
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) $validated['expires_in_days']),
        ]);

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '報表分享連結已建立。')
            ->with('createdShareUrl', route('client-report-shares.public', ['token' => $token]));
    }

    public function destroy(Client $client, int $reportShare): RedirectResponse
    {
        $share = $client->reportShares()->whereKey($reportShare)->firstOrFail();

        if ($share->revoked_at === null) {
            $share->forceFill(['revoked_at' => now()])->save();
        }

        return redirect()
            ->route('clients.report-shares.index', $client)
            ->with('status', '報表分享連結已撤銷。');
    }
}
