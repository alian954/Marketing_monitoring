<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientMarketingAnnotation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ClientMarketingAnnotationController extends Controller
{
    private const LIST_LIMIT = 100;

    private const MAX_ANNOTATIONS_PER_CLIENT = 2000;

    public function index(Client $client): Response
    {
        $annotations = $client->marketingAnnotations()
            ->with('createdBy:id,name')
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        return response()
            ->view('clients.annotations', [
                'client' => $client,
                'annotations' => $annotations,
                'totalCount' => $client->marketingAnnotations()->count(),
                'today' => CarbonImmutable::now('Asia/Taipei')->toDateString(),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, Client $client): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validator = Validator::make($request->only(['occurred_on', 'category', 'title', 'note']), [
            'occurred_on' => ['required', 'string', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'category' => ['required', 'string', Rule::in(array_keys(ClientMarketingAnnotation::CATEGORIES))],
            'title' => ['required', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'occurred_on.required' => '請選擇日期。',
            'occurred_on.date_format' => '日期格式無效。',
            'occurred_on.after_or_equal' => '日期超出可記錄範圍。',
            'occurred_on.before_or_equal' => '日期超出可記錄範圍。',
            'category.required' => '請選擇類型。',
            'category.in' => '所選類型無效。',
            'title.required' => '請輸入標題。',
            'title.max' => '標題不得超過 120 個字。',
            'note.max' => '說明不得超過 2000 個字。',
        ]);
        $validator->after(function ($validator) use ($client): void {
            if ($client->marketingAnnotations()->count() >= self::MAX_ANNOTATIONS_PER_CLIENT) {
                $validator->errors()->add('title', '每個客戶最多可保存 '.number_format(self::MAX_ANNOTATIONS_PER_CLIENT).' 則註記，請先刪除舊註記。');
            }
        });

        if ($validator->fails()) {
            return redirect()
                ->route('clients.annotations.index', $client)
                ->withErrors($validator)
                ->withInput($request->only(['occurred_on', 'category', 'title', 'note']));
        }

        $validated = $validator->validated();
        $note = isset($validated['note']) ? trim($validated['note']) : '';

        $client->marketingAnnotations()->create([
            'occurred_on' => $validated['occurred_on'],
            'category' => $validated['category'],
            'title' => trim($validated['title']),
            'note' => $note === '' ? null : $note,
            'created_by_user_id' => $user->getKey(),
        ]);

        return redirect()
            ->route('clients.annotations.index', $client)
            ->with('status', '已新增行銷註記。');
    }

    public function destroy(Client $client, ClientMarketingAnnotation $marketingAnnotation): RedirectResponse
    {
        $marketingAnnotation->delete();

        return redirect()
            ->route('clients.annotations.index', $client)
            ->with('status', '已刪除行銷註記。');
    }
}
