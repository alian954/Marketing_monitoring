<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSwitchController extends Controller
{
    /**
     * Switch the session to another workspace the user belongs to.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_if($user->isPlatformAdmin(), 403, '平台管理員請由管理後台登入。');

        $validated = $request->validate([
            'workspace' => ['required', 'string', 'max:20'],
        ], [
            'workspace.required' => '請選擇要切換的組織。',
        ]);
        $ownerUserId = $user->workspaceOwnerIdForSwitchKey($validated['workspace']);
        abort_if($ownerUserId === null, 404);

        $request->session()->put(User::CURRENT_WORKSPACE_SESSION_KEY, $ownerUserId);
        $user->forgetWorkspaceContext();

        if ($user->workspaceOwnerId() !== $ownerUserId) {
            return redirect()->route('dashboard')->withErrors([
                'workspace' => '所選組織的訂閱已到期或已停用，暫時無法切換。',
            ]);
        }

        return redirect()->route('dashboard')->with('status', '已切換組織。');
    }
}
