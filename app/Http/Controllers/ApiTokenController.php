<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class ApiTokenController extends Controller
{
    private const MAX_TOKENS = 20;

    /** @var array<string, string> */
    public const EXPIRY_OPTIONS = [
        '30' => '30 天',
        '90' => '90 天',
        '365' => '1 年',
        'never' => '不過期',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()
            ->view('api-tokens.index', [
                'tokens' => ApiToken::query()
                    ->where('workspace_owner_user_id', $user->workspaceOwnerId())
                    ->with('user:id,name')
                    ->latest('id')
                    ->get(),
                'plainTextToken' => $request->session()->get('plainTextToken'),
                'expiryOptions' => self::EXPIRY_OPTIONS,
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expires_in' => ['required', 'string', Rule::in(array_keys(self::EXPIRY_OPTIONS))],
        ], [
            'name.required' => '請輸入名稱，例如 Looker Studio。',
            'name.max' => '名稱不得超過 80 個字。',
            'expires_in.*' => '請選擇有效期限。',
        ]);

        if (ApiToken::query()->where('workspace_owner_user_id', $user->workspaceOwnerId())->count() >= self::MAX_TOKENS) {
            return redirect()->route('api-tokens.index')->withErrors(['name' => '每個工作區最多 '.self::MAX_TOKENS.' 組 API 金鑰，請先撤銷不用的金鑰。']);
        }

        $issued = ApiToken::issue(
            $user,
            trim($validated['name']),
            $validated['expires_in'] === 'never' ? null : now()->addDays((int) $validated['expires_in']),
        );

        return redirect()
            ->route('api-tokens.index')
            ->with('plainTextToken', $issued['plain_text'])
            ->with('status', '已建立 API 金鑰，請立即複製，離開此頁後將無法再次查看。');
    }

    public function destroy(Request $request, ApiToken $apiToken): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless((int) $apiToken->workspace_owner_user_id === $user->workspaceOwnerId(), 404);

        $apiToken->delete();

        return redirect()->route('api-tokens.index')->with('status', '已撤銷 API 金鑰「'.$apiToken->name.'」。');
    }
}
