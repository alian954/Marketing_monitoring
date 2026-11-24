<?php

namespace App\Http\Requests;

use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class GoogleSearchConsolePerformanceReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $today = CarbonImmutable::now('America/Los_Angeles');
        $earliestAvailableDate = $today->subMonthsNoOverflow(16)->toDateString();
        $latestCompleteDate = $today->subDays(3)->toDateString();
        $availableDimensions = match ($this->input('search_type')) {
            'discover' => ['page', 'country'],
            'googleNews' => ['page', 'country', 'device'],
            'webMultimodal' => ['page', 'country', 'device'],
            default => ['query', 'page', 'country', 'device'],
        };

        return [
            'client_id' => [
                'sometimes',
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $user = $this->user();

                    if (! $user instanceof User || ! $user->clients()->whereKey($value)->exists()) {
                        $fail('請選擇工作區中可存取的客戶。');
                    }
                },
            ],
            'start_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$earliestAvailableDate,
                'before_or_equal:end_date',
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
                'before_or_equal:'.$latestCompleteDate,
            ],
            'dimension' => ['required', 'in:'.implode(',', $availableDimensions)],
            'search_type' => ['required', 'in:web,image,video,news,discover,googleNews,webMultimodal'],
            'compare' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $dimensionError = match ($this->input('search_type')) {
            'discover' => 'Discover 報表僅支援頁面或國家／地區維度。',
            'googleNews' => 'Google News 報表不提供搜尋查詢維度，請選擇頁面、國家／地區或裝置。',
            'webMultimodal' => '多模態搜尋不提供搜尋查詢維度，請選擇頁面、國家／地區或裝置。',
            default => '請選擇有效的報表維度。',
        };

        return [
            'start_date.after_or_equal' => 'Search Console 最多保留最近 16 個月資料，請縮短開始日期。',
            'start_date.before_or_equal' => '開始日期不可晚於結束日期。',
            'end_date.after_or_equal' => '結束日期不可早於開始日期。',
            'end_date.before_or_equal' => 'Search Console 數據通常延遲 2–3 天，請選擇較早的完整日期。',
            'dimension.in' => $dimensionError,
            'search_type.in' => '請選擇有效的搜尋類型。',
        ];
    }
}
