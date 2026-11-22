<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoogleAdsCustomerRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'googleAds';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $customerId = $this->input('customer_id');
        $loginCustomerId = $this->input('login_customer_id');
        $normalized = [];

        if (is_string($customerId)) {
            $normalized['customer_id'] = preg_replace('/[\s-]+/', '', trim($customerId));
        }

        if (is_string($loginCustomerId)) {
            $normalized['login_customer_id'] = preg_replace('/[\s-]+/', '', trim($loginCustomerId));
        }

        $this->merge($normalized);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'string', 'regex:/^\d{10}$/'],
            'login_customer_id' => ['nullable', 'string', 'regex:/^\d{10}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_id.required' => '請輸入 Google Ads 客戶 ID。',
            'customer_id.regex' => 'Google Ads 客戶 ID 必須是 10 位數字。',
            'login_customer_id.regex' => '管理員客戶 ID 必須是 10 位數字。',
        ];
    }
}
