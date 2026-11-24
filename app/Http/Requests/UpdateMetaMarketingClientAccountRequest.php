<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMetaMarketingClientAccountRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'metaMarketing';

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

    public function validationData(): array
    {
        $data = $this->all();
        $client = $this->route('client');
        $accountIds = $data['ad_account_id'] ?? null;
        $accountId = $client instanceof Client && is_array($accountIds)
            ? ($accountIds[$client->getKey()] ?? null)
            : null;

        if (is_string($accountId)) {
            $accountId = trim($accountId);

            if (str_starts_with($accountId, 'act_')) {
                $accountId = substr($accountId, 4);
            }

        }

        $data['ad_account_id'] = $accountId;

        return $data;
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
