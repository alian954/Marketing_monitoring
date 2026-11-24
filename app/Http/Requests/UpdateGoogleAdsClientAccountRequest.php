<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateGoogleAdsClientAccountRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'googleAds';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $client = $this->route('client');

        if (! $user instanceof User
            || ! $client instanceof Client
            || ! $user->canManageWorkspace()
            || (int) $client->user_id !== $user->workspaceOwnerId()) {
            return false;
        }

        $accessibleClientIds = $user->workspaceClientIds();

        return $accessibleClientIds === null || in_array((int) $client->getKey(), $accessibleClientIds, true);
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['customer_id', 'login_customer_id'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalized[$field] = preg_replace('/[\s-]+/', '', trim($value));
            }
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
            'customer_id' => [
                'required',
                'string',
                'regex:/^\d{10}$/',
            ],
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
