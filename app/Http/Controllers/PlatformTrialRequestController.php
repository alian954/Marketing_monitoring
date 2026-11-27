<?php

namespace App\Http\Controllers;

use App\Models\TrialRequest;
use App\Services\TrialRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformTrialRequestController extends Controller
{
    private const RECENTLY_REVIEWED_LIMIT = 50;

    public function __construct(private readonly TrialRequestService $trialRequestService) {}

    public function index(): View
    {
        $relations = ['organization:id,name', 'product:id,name', 'plan:id,name', 'requestedBy:id,name,email', 'reviewedBy:id,name'];

        return view('platform.trial-requests.index', [
            'pendingRequests' => TrialRequest::query()
                ->where('status', TrialRequest::STATUS_PENDING)
                ->with($relations)
                ->oldest('id')
                ->get(),
            'reviewedRequests' => TrialRequest::query()
                ->where('status', '<>', TrialRequest::STATUS_PENDING)
                ->with($relations)
                ->latest('reviewed_at')
                ->latest('id')
                ->limit(self::RECENTLY_REVIEWED_LIMIT)
                ->get(),
        ]);
    }

    public function approve(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $validated = $request->validateWithBag('trialReview', [
            'trial_days' => ['required', 'integer', 'min:1', 'max:'.TrialRequest::MAX_TRIAL_DAYS],
            'review_note' => ['nullable', 'string', 'max:500'],
        ], [
            'trial_days.required' => '請輸入試用天數。',
            'trial_days.min' => '試用天數至少 1 天。',
            'trial_days.max' => '試用天數最多 '.TrialRequest::MAX_TRIAL_DAYS.' 天。',
            'review_note.max' => '審核說明不可超過 500 個字元。',
        ]);

        try {
            $subscription = $this->trialRequestService->approve(
                $trialRequest,
                (int) $validated['trial_days'],
                filled($validated['review_note'] ?? null) ? trim($validated['review_note']) : null,
                $request->user(),
            );
        } catch (ValidationException $exception) {
            throw $exception->errorBag('trialReview');
        }

        return to_route('platform.trial-requests.index')->with(
            'status',
            '已核准試用，至 '.$subscription->trial_ends_at->timezone('Asia/Taipei')->format('Y-m-d H:i').' 止。',
        );
    }

    public function reject(Request $request, TrialRequest $trialRequest): RedirectResponse
    {
        $validated = $request->validateWithBag('trialReview', [
            'review_note' => ['required', 'string', 'max:500'],
        ], [
            'review_note.required' => '婉拒時請填寫說明，申請人會看到這段文字。',
            'review_note.max' => '審核說明不可超過 500 個字元。',
        ]);

        try {
            $this->trialRequestService->reject($trialRequest, trim($validated['review_note']), $request->user());
        } catch (ValidationException $exception) {
            throw $exception->errorBag('trialReview');
        }

        return to_route('platform.trial-requests.index')->with('status', '已婉拒試用申請。');
    }
}
