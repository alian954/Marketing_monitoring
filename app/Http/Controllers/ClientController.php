<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validator = Validator::make($request->query(), [
            'search' => ['sometimes', 'nullable', 'string', 'max:80'],
        ], [
            'search.string' => '客戶搜尋格式無效。',
            'search.max' => '客戶搜尋不可超過 80 個字元。',
        ]);

        if ($validator->fails()) {
            return redirect()->route('clients.index')
                ->withErrors($validator)
                ->withInput($request->query());
        }

        Client::ensureDefaultFor($user);
        $search = trim((string) ($validator->validated()['search'] ?? ''));
        $clientQuery = Client::query()
            ->withCount(['monitors', 'socialEngagementTargets'])
            ->orderBy('name');

        if ($search !== '') {
            $clientQuery->where('name', 'like', '%'.$search.'%');
        }

        $clients = $clientQuery->paginate(25)->withQueryString();

        return view('clients.index', compact('clients', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($user->canCreateClient(), 403);

        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $validated = $request->validateWithBag('createClient', [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('clients', 'name')->where('user_id', $request->user()->workspaceOwnerId()),
            ],
        ], [
            'name.required' => '請輸入客戶名稱。',
            'name.max' => '客戶名稱不可超過 120 個字元。',
            'name.unique' => '此帳戶已有相同名稱的客戶。',
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $organization = Organization::query()
                ->where('owner_user_id', $request->user()->workspaceOwnerId())
                ->lockForUpdate()
                ->first();

            if ($organization !== null && ! $organization->hasAvailableClient()) {
                $exception = ValidationException::withMessages([
                    'name' => '此組織已達客戶數上限，請聯絡平台管理員調整方案。',
                ]);
                $exception->errorBag = 'createClient';

                throw $exception;
            }

            $request->user()->clients()->create(['name' => $validated['name']]);
        });

        return redirect()->route('clients.index')->with('status', '客戶已新增。');
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        if ($client->isDefault()) {
            return back()->withErrors(['name' => '預設分類「未分類」不可重新命名。'], 'updateClient'.$client->id);
        }

        $name = $request->input('name');

        if (is_string($name)) {
            $request->merge(['name' => trim($name)]);
        }

        $validated = $request->validateWithBag('updateClient'.$client->id, [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('clients', 'name')
                    ->where('user_id', $request->user()->workspaceOwnerId())
                    ->ignore($client->id),
            ],
        ], [
            'name.required' => '請輸入客戶名稱。',
            'name.max' => '客戶名稱不可超過 120 個字元。',
            'name.unique' => '此帳戶已有相同名稱的客戶。',
        ]);

        $client->update(['name' => $validated['name']]);

        return redirect()->route('clients.index')->with('status', '客戶名稱已更新。');
    }

    public function destroy(Client $client): RedirectResponse
    {
        if ($client->isDefault()) {
            return back()->withErrors(['client' => '預設分類「未分類」不可刪除。'], 'clientManagement');
        }

        if ($client->monitors()->exists() || $client->socialEngagementTargets()->exists()) {
            return back()->withErrors(['client' => '請先將監測項目與活動目標移至其他客戶。'], 'clientManagement');
        }

        $isAssignedToSelectedWorkspaceMember = DB::table('workspace_membership_clients')
            ->join('workspace_memberships', 'workspace_memberships.id', '=', 'workspace_membership_clients.workspace_membership_id')
            ->where('workspace_membership_clients.client_id', $client->id)
            ->where('workspace_memberships.client_scope', WorkspaceMembership::CLIENT_SCOPE_SELECTED)
            ->whereNull('workspace_memberships.revoked_at')
            ->exists();

        if ($isAssignedToSelectedWorkspaceMember) {
            return back()->withErrors(['client' => '此客戶仍指派給工作區成員，請先調整成員的客戶範圍。'], 'clientManagement');
        }

        $client->delete();

        return redirect()->route('clients.index')->with('status', '客戶已刪除。');
    }
}
