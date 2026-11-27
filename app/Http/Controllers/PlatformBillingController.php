<?php

namespace App\Http\Controllers;

use App\Models\BillingOrder;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\ProductPlan;
use App\Services\BillingLedger;
use App\Services\MoneyAmount;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformBillingController extends Controller
{
    public function __construct(private readonly BillingLedger $billingLedger) {}

    public function index(Organization $organization): View
    {
        return view('platform.organizations.billing', [
            'organization' => $organization,
            'orders' => BillingOrder::query()
                ->where('organization_id', $organization->getKey())
                ->with(['items', 'payments.recordedBy:id,name', 'refunds.recordedBy:id,name'])
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->get(),
            'sellablePlans' => ProductPlan::query()
                ->with('product:id,name')
                ->where('status', 'active')
                ->whereNotNull('amount_minor')
                ->whereNotNull('currency_code')
                ->whereHas('product', static fn (Builder $query): Builder => $query->where('lifecycle', 'active'))
                ->orderBy('product_id')
                ->orderBy('name')
                ->get(),
            'paymentMethods' => BillingPayment::METHODS,
        ]);
    }

    public function storeOrder(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'product_plan_id' => ['required', 'integer', 'exists:product_plans,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:65535'],
            'service_starts_on' => ['required', 'date_format:Y-m-d'],
            'service_ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:service_starts_on'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'product_plan_id.required' => '請選擇產品方案。',
            'product_plan_id.exists' => '請選擇目前可銷售的產品方案。',
            'quantity.min' => '數量至少為 1。',
            'service_starts_on.required' => '請輸入服務開始日。',
            'service_ends_on.required' => '請輸入服務結束日。',
            'service_ends_on.after_or_equal' => '服務結束日不可早於開始日。',
            'notes.max' => '備註不可超過 500 個字元。',
        ]);

        $order = $this->billingLedger->createOrder($organization, [[
            'product_plan_id' => (int) $validated['product_plan_id'],
            'quantity' => (int) $validated['quantity'],
            'service_starts_on' => $validated['service_starts_on'],
            'service_ends_on' => $validated['service_ends_on'],
        ]], $validated['notes'] ?? null);

        return to_route('platform.organizations.billing', $organization)
            ->with('status', '已開立訂單 '.$order->order_number.'。');
    }

    public function storePayment(Request $request, Organization $organization, BillingOrder $billingOrder): RedirectResponse
    {
        abort_unless((int) $billingOrder->organization_id === (int) $organization->getKey(), 404);

        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:'.MoneyAmount::DECIMAL_PATTERN],
            'method' => ['required', Rule::in(BillingPayment::METHODS)],
            'received_at' => ['bail', 'required', 'date', static function (string $attribute, mixed $value, Closure $fail): void {
                // The form submits Taiwan local time; compare as an instant so "now" is not shifted by the UTC app timezone.
                if (CarbonImmutable::parse((string) $value, 'Asia/Taipei')->greaterThan(now())) {
                    $fail('收款時間不可晚於現在。');
                }
            }],
            'reference' => ['nullable', 'string', 'max:120'],
        ], [
            'amount.required' => '請輸入收款金額。',
            'amount.regex' => '收款金額格式無效，最多兩位小數。',
            'method.in' => '請選擇有效的收款方式。',
            'received_at.required' => '請輸入收款時間。',
            'reference.max' => '交易參考不可超過 120 個字元。',
        ]);

        $this->billingLedger->recordPayment(
            $billingOrder,
            MoneyAmount::toMinorUnits($validated['amount']),
            $validated['method'],
            CarbonImmutable::parse($validated['received_at'], 'Asia/Taipei')->utc(),
            $validated['reference'] ?? null,
        );

        return to_route('platform.organizations.billing', $organization)
            ->with('status', '已登錄訂單 '.$billingOrder->order_number.' 的收款。');
    }

    public function storeRefund(Request $request, Organization $organization, BillingOrder $billingOrder): RedirectResponse
    {
        abort_unless((int) $billingOrder->organization_id === (int) $organization->getKey(), 404);

        $validated = $request->validate([
            'refund_amount' => ['required', 'string', 'regex:'.MoneyAmount::DECIMAL_PATTERN],
            'refund_method' => ['required', Rule::in(BillingPayment::METHODS)],
            'refunded_at' => ['bail', 'required', 'date', static function (string $attribute, mixed $value, Closure $fail): void {
                if (CarbonImmutable::parse((string) $value, 'Asia/Taipei')->greaterThan(now())) {
                    $fail('退款時間不可晚於現在。');
                }
            }],
            'refund_reason' => ['required', 'string', 'max:255'],
            'refund_reference' => ['nullable', 'string', 'max:120'],
        ], [
            'refund_amount.required' => '請輸入退款金額。',
            'refund_amount.regex' => '退款金額格式無效，最多兩位小數。',
            'refund_method.in' => '請選擇有效的退款方式。',
            'refunded_at.required' => '請輸入退款時間。',
            'refund_reason.required' => '請輸入退款原因。',
            'refund_reason.max' => '退款原因不可超過 255 個字元。',
            'refund_reference.max' => '交易參考不可超過 120 個字元。',
        ]);

        $this->billingLedger->recordRefund(
            $billingOrder,
            MoneyAmount::toMinorUnits($validated['refund_amount']),
            $validated['refund_method'],
            CarbonImmutable::parse($validated['refunded_at'], 'Asia/Taipei')->utc(),
            $validated['refund_reason'],
            $validated['refund_reference'] ?? null,
        );

        return to_route('platform.organizations.billing', $organization)
            ->with('status', '已登錄訂單 '.$billingOrder->order_number.' 的退款；如需停用服務，請另行調整訂閱。');
    }

    public function cancelOrder(Request $request, Organization $organization, BillingOrder $billingOrder): RedirectResponse
    {
        abort_unless((int) $billingOrder->organization_id === (int) $organization->getKey(), 404);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => '請輸入取消原因。',
            'reason.max' => '取消原因不可超過 255 個字元。',
        ]);

        $this->billingLedger->cancelOrder($billingOrder, $validated['reason']);

        return to_route('platform.organizations.billing', $organization)
            ->with('status', '已取消訂單 '.$billingOrder->order_number.'。');
    }
}
