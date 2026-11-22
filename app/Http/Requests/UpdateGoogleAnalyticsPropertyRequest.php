<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoogleAnalyticsPropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'property_id' => ['required', 'string', 'regex:/^[0-9]{1,20}$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'property_id.required' => '請輸入 GA4 資源 ID。',
            'property_id.regex' => 'GA4 資源 ID 必須是數字。',
        ];
    }
}
