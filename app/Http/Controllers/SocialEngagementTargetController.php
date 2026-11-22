<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSocialEngagementTargetRequest;
use App\Models\SocialEngagementTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialEngagementTargetController extends Controller
{
    public function store(StoreSocialEngagementTargetRequest $request): RedirectResponse
    {
        $data = $request->validated();

        SocialEngagementTarget::query()->updateOrCreate(
            [
                'tag' => $data['tag'],
                'social_platform' => $data['social_platform'],
            ],
            ['target_interactions' => (int) $data['target_interactions']],
        );

        return redirect()->route('dashboard', $this->dashboardFilters($data))
            ->with('status', '活動互動目標已儲存。');
    }

    public function destroy(Request $request, SocialEngagementTarget $socialEngagementTarget): RedirectResponse
    {
        $socialEngagementTarget->delete();

        return redirect()->route('dashboard', $this->dashboardFilters($request->only([
            'engagement_platform',
            'engagement_tag',
        ])))
            ->with('status', '活動互動目標已刪除。');
    }

    /** @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function dashboardFilters(array $data): array
    {
        $filters = [];

        if (is_string($data['engagement_platform'] ?? null)
            && in_array($data['engagement_platform'], ['facebook', 'instagram', 'threads', 'dcard'], true)) {
            $filters['engagement_platform'] = $data['engagement_platform'];
        }

        if (is_string($data['engagement_tag'] ?? null)
            && ($data['engagement_tag'] === 'all'
                || $data['engagement_tag'] === 'unlabeled'
                || str_starts_with($data['engagement_tag'], 'tag:'))) {
            $filters['engagement_tag'] = $data['engagement_tag'];
        }

        return $filters;
    }
}
