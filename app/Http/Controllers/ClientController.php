<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        Client::ensureDefaultFor($user);

        $clients = Client::query()
            ->withCount(['monitors', 'socialEngagementTargets'])
            ->orderBy('name')
            ->get();

        return view('clients.index', compact('clients'));
    }

    public function store(Request $request): RedirectResponse
    {
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

        $request->user()->clients()->create(['name' => $validated['name']]);

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

        $client->delete();

        return redirect()->route('clients.index')->with('status', '客戶已刪除。');
    }
}
