<?php

namespace App\Http\Requests;

use App\Models\Monitor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSocialEngagementTargetRequest extends FormRequest
{
    /** @var string */
    protected $errorBag = 'socialEngagementTarget';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tag' => ['required', 'string', 'max:100', Rule::in($this->trackedTagsForPlatform())],
            'social_platform' => ['required', 'string', Rule::in(['facebook', 'instagram', 'threads', 'dcard'])],
            'target_interactions' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'ga4_campaign_name' => ['nullable', 'string', 'max:100'],
            'ga4_source_name' => ['nullable', 'string', 'max:100'],
            'ga4_medium_name' => ['nullable', 'string', 'max:100'],
            'ga4_goal_metric' => ['nullable', 'string', Rule::in(['key_events', 'total_revenue'])],
            'ga4_goal_value' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999999999'],
            'ga4_goal_currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'paid_media_goal_source' => ['nullable', 'string', Rule::in(['ga4', 'meta_ads', 'google_ads'])],
            'paid_media_goal_roas' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:9999999999999999'],
            'meta_campaign_id' => ['nullable', 'string', 'between:5,32', 'regex:/^\d+$/'],
            'google_ads_campaign_id' => ['nullable', 'string', 'between:5,32', 'regex:/^\d+$/'],
            'engagement_platform' => ['nullable', 'string', Rule::in(['facebook', 'instagram', 'threads', 'dcard'])],
            'engagement_tag' => ['nullable', 'string', 'max:110'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsOn = $this->input('starts_on');
            $endsOn = $this->input('ends_on');

            if (($startsOn === null) !== ($endsOn === null)) {
                $validator->errors()->add('starts_on', '活動期間需同時設定開始日與結束日。');
                $validator->errors()->add('ends_on', '活動期間需同時設定開始日與結束日。');

                return;
            }

            if (is_string($startsOn) && is_string($endsOn) && $endsOn < $startsOn) {
                $validator->errors()->add('ends_on', '活動結束日不可早於開始日。');

                return;
            }

            $goalMetric = $this->input('ga4_goal_metric');
            $goalValue = $this->input('ga4_goal_value');
            $goalCurrency = $this->input('ga4_goal_currency');

            if ($goalMetric !== null) {
                if ($goalValue === null) {
                    $validator->errors()->add('ga4_goal_value', '請輸入 GA4 商業目標數值。');
                }

                if ($startsOn === null || $endsOn === null) {
                    $validator->errors()->add('ga4_goal_metric', '設定 GA4 商業目標前，請先設定活動期間。');
                }

                if ($goalMetric === 'total_revenue' && $goalCurrency === null) {
                    $validator->errors()->add('ga4_goal_currency', '營收目標需要設定幣別。');
                }
            }

            $paidMediaGoalSource = $this->input('paid_media_goal_source');

            if ($paidMediaGoalSource !== null && $this->input('paid_media_goal_roas') === null) {
                $validator->errors()->add('paid_media_goal_roas', '請輸入付費媒體 ROAS 目標。');
            }

            if ($paidMediaGoalSource !== null && ($startsOn === null || $endsOn === null)) {
                $validator->errors()->add('paid_media_goal_source', '設定付費媒體 ROAS 目標前，請先設定活動期間。');
            }
        }];
    }

    /** @return list<string> */
    private function trackedTagsForPlatform(): array
    {
        $platform = $this->input('social_platform');

        if (! is_string($platform) || ! in_array($platform, ['facebook', 'instagram', 'threads', 'dcard'], true)) {
            return [];
        }

        return Monitor::query()
            ->where('social_platform', $platform)
            ->get(['tags', 'label'])
            ->flatMap(static fn (Monitor $monitor): array => $monitor->tagNames())
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tag.required' => '請選擇活動標籤。',
            'tag.in' => '所選標籤沒有此平台的監測貼文。',
            'tag.max' => '活動標籤不可超過 100 個字元。',
            'social_platform.required' => '請選擇社群平台。',
            'social_platform.in' => '所選平台不支援互動目標。',
            'target_interactions.required' => '請輸入互動目標。',
            'target_interactions.integer' => '互動目標必須是整數。',
            'target_interactions.min' => '互動目標至少為 1。',
            'target_interactions.max' => '互動目標不可超過 1,000,000,000。',
            'starts_on.date_format' => '活動開始日格式無效。',
            'ends_on.date_format' => '活動結束日格式無效。',
            'ga4_campaign_name.max' => 'GA4 活動名稱不可超過 100 個字元。',
            'ga4_source_name.max' => 'GA4 來源名稱不可超過 100 個字元。',
            'ga4_medium_name.max' => 'GA4 媒介名稱不可超過 100 個字元。',
            'ga4_goal_metric.in' => 'GA4 商業目標指標無效。',
            'ga4_goal_value.numeric' => 'GA4 商業目標必須是數字。',
            'ga4_goal_value.decimal' => 'GA4 商業目標最多可輸入 4 位小數。',
            'ga4_goal_value.gt' => 'GA4 商業目標必須大於 0。',
            'ga4_goal_value.max' => 'GA4 商業目標數值過大。',
            'ga4_goal_currency.regex' => '營收目標幣別請使用 3 位大寫代碼，例如 TWD。',
            'paid_media_goal_source.in' => '付費媒體 ROAS 目標來源無效。',
            'paid_media_goal_roas.numeric' => '付費媒體 ROAS 目標必須是數字。',
            'paid_media_goal_roas.decimal' => '付費媒體 ROAS 目標最多可輸入 4 位小數。',
            'paid_media_goal_roas.gt' => '付費媒體 ROAS 目標必須大於 0。',
            'paid_media_goal_roas.max' => '付費媒體 ROAS 目標數值過大。',
            'meta_campaign_id.between' => 'Meta 活動 ID 需為 5 至 32 位數字。',
            'meta_campaign_id.regex' => 'Meta 活動 ID 必須是數字。',
            'google_ads_campaign_id.between' => 'Google Ads 活動 ID 需為 5 至 32 位數字。',
            'google_ads_campaign_id.regex' => 'Google Ads 活動 ID 必須是數字。',
        ];
    }
}
