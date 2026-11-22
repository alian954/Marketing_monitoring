<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSocialEngagementTargetGoogleAdsCampaignRequest extends FormRequest
{
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
            'google_ads_campaign_id' => ['required', 'string', 'between:5,32', 'regex:/^\d+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'google_ads_campaign_id.required' => '請選擇 Google Ads 活動。',
            'google_ads_campaign_id.between' => 'Google Ads 活動 ID 需為 5 至 32 位數字。',
            'google_ads_campaign_id.regex' => 'Google Ads 活動 ID 必須是數字。',
        ];
    }
}
