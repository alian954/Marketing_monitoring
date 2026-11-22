<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSocialEngagementTargetAdSpendRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'socialEngagementSpend';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('ad_spend_amount');
        $currency = $this->input('ad_spend_currency');

        $this->merge([
            'ad_spend_amount' => is_string($amount) && trim($amount) === '' ? null : $amount,
            'ad_spend_currency' => is_string($currency) && trim($currency) !== ''
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
            'ad_spend_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999999.9999', 'decimal:0,4'],
            'ad_spend_currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $amount = $this->input('ad_spend_amount');
            $currency = $this->input('ad_spend_currency');

            if ($amount !== null && $currency === null) {
                $validator->errors()->add('ad_spend_currency', '輸入花費時，請一併選擇幣別。');
            }

            if ($amount === null && $currency !== null) {
                $validator->errors()->add('ad_spend_amount', '選擇幣別時，請一併輸入花費。');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ad_spend_amount.numeric' => '花費必須是數字。',
            'ad_spend_amount.min' => '花費不可小於 0。',
            'ad_spend_amount.max' => '花費不可超過 999,999,999,999,999.9999。',
            'ad_spend_amount.decimal' => '花費最多可輸入 4 位小數。',
            'ad_spend_currency.size' => '幣別需使用 3 個英文字母，例如 TWD 或 USD。',
            'ad_spend_currency.regex' => '幣別需使用 3 個英文字母，例如 TWD 或 USD。',
        ];
    }
}
