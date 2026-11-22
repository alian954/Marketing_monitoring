<?php

namespace App\Http\Requests;

use App\Models\Monitor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'engagement_platform' => ['nullable', 'string', Rule::in(['facebook', 'instagram', 'threads', 'dcard'])],
            'engagement_tag' => ['nullable', 'string', 'max:110'],
        ];
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
        ];
    }
}
