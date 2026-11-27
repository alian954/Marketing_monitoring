<?php

namespace App\Http\Controllers;

use App\Models\PlatformAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceActivityController extends Controller
{
    /**
     * Show governance events recorded for the current workspace, including platform staff access.
     */
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $workspaceId = $user->currentWorkspace()?->getKey();

        $events = PlatformAuditEvent::query()
            ->with('actor:id,name,is_platform_admin')
            ->when(
                $workspaceId === null,
                static fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
                static fn (Builder $query): Builder => $query->where('workspace_id', $workspaceId),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('workspace-access.activity', [
            'events' => $events,
            'memberNames' => PlatformAuditEvent::memberNamesFor($events->getCollection()),
        ]);
    }
}
