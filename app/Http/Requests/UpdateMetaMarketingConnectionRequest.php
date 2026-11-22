<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMetaMarketingConnectionRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'metaMarketing';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $accountId = $this->input('ad_account_id');

        if (is_string($accountId)) {
            $accountId = trim($accountId);

            if (str_starts_with($accountId, 'act_')) {
                $accountId = substr($accountId, 4);
            }

            $this->merge(['ad_account_id' => $accountId]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ad_account_id' => ['required', 'string', 'between:5,32', 'regex:/^\d+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ad_account_id.required' => '請輸入 Meta 廣告帳戶 ID。',
            'ad_account_id.between' => '廣告帳戶 ID 需為 5 至 32 位數字。',
            'ad_account_id.regex' => '廣告帳戶 ID 需為數字；可輸入 act_ 後方的 ID。',
        ];
    }
}
