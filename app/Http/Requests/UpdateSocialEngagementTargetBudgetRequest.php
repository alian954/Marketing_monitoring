<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSocialEngagementTargetBudgetRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'socialEngagementBudget';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('campaign_budget_amount');
        $currency = $this->input('campaign_budget_currency');

        $this->merge([
            'campaign_budget_amount' => is_string($amount) && trim($amount) === '' ? null : $amount,
            'campaign_budget_currency' => is_string($currency) && trim($currency) !== ''
                ? strtoupper(trim($currency))
                : null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'campaign_budget_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'campaign_budget_currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $amount = $this->input('campaign_budget_amount');
            $currency = $this->input('campaign_budget_currency');

            if ($amount !== null && $currency === null) {
                $validator->errors()->add('campaign_budget_currency', '輸入活動預算時，請一併選擇幣別。');
            }

            if ($amount === null && $currency !== null) {
                $validator->errors()->add('campaign_budget_amount', '選擇幣別時，請一併輸入活動預算。');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'campaign_budget_amount.numeric' => '活動預算必須是數字。',
            'campaign_budget_amount.min' => '活動預算不可小於 0。',
            'campaign_budget_amount.max' => '活動預算不可超過 999,999,999,999,999.9999。',
            'campaign_budget_amount.decimal' => '活動預算最多可輸入 4 位小數。',
            'campaign_budget_currency.size' => '幣別需使用 3 個英文字母，例如 TWD 或 USD。',
            'campaign_budget_currency.regex' => '幣別需使用 3 個英文字母，例如 TWD 或 USD。',
        ];
    }
}
