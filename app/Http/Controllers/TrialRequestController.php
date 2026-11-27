<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProductPlan;
use App\Models\SubscriptionItem;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\TrialRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrialRequestController extends Controller
{
    public function __construct(private readonly TrialRequestService $trialRequestService) {}

    public function index(Request $request): View
    {
        $organization = $this->ownedOrganization($request->user());
        $plansByProduct = $this->trialRequestService->trialablePlans()
            ->with('product:id,name')
            ->orderBy('product_id')
            ->orderBy('name')
            ->get()
            ->groupBy('product_id');

        $subscriptionItems = $organization === null ? collect() : SubscriptionItem::query()
            ->where('organization_id', $organization->getKey())
            ->with('subscription:id,status,trial_ends_at')
            ->get()
            ->keyBy('product_id');
        $trialRequests = $organization === null ? collect() : $organization->trialRequests()
            ->with(['product:id,name', 'plan:id,name'])
            ->latest('id')
            ->get();

        return view('trials.index', [
            'organization' => $organization,
            'isPlatformAdmin' => $request->user()->isPlatformAdmin(),
            'plansByProduct' => $plansByProduct,
            'subscriptionItems' => $subscriptionItems,
            'pendingProductIds' => $trialRequests->where('status', TrialRequest::STATUS_PENDING)->pluck('product_id')->all(),
            'trialRequests' => $trialRequests,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($request->user()->isPlatformAdmin(), 403);
        $organization = $this->ownedOrganization($request->user());
        abort_if($organization === null, 403);

        $validated = $request->validate([
            'product_plan_id' => ['required', 'integer', 'exists:'.ProductPlan::class.',id'],
            'message' => ['nullable', 'string', 'max:500'],
        ], [
            'product_plan_id.required' => '請選擇要試用的方案。',
            'product_plan_id.exists' => '請選擇目前開放試用的產品方案。',
            'message.max' => '申請說明不可超過 500 個字元。',
        ]);

        $trialRequest = $this->trialRequestService->request(
            $organization,
            (int) $validated['product_plan_id'],
            filled($validated['message'] ?? null) ? trim($validated['message']) : null,
            $request->user(),
        );

        return to_route('trials.index')->with('status', '已送出「'.$trialRequest->product->name.'」試用申請，平台審核後即可開始使用。');
    }

    private function ownedOrganization(User $user): ?Organization
    {
        return $user->isPlatformAdmin() ? null : $user->workspaceOrganization();
    }
}
