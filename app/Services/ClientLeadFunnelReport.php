<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientLead;

/**
 * Turns imported CRM leads into a per-platform funnel with the cost of each stage, so a platform
 * that brings many cheap but unqualified leads no longer looks better than one that closes deals.
 */
class ClientLeadFunnelReport
{
    /**
     * Leads created in the period are counted at every stage they reached by the latest import.
     *
     * @param  array{currency_groups: list<array{currency_code: string, platforms: array<string, array<string, mixed>>, total: array<string, mixed>}>, primary_currency_code: string|null}  $overview
     * @return array{has_data: bool, currency_code: string|null, rows: list<array<string, mixed>>, total: array<string, mixed>|null}
     */
    public function build(Client $client, string $fromOn, string $toOn, array $overview): array
    {
        $counts = $client->leads()
            ->whereDate('created_on', '>=', $fromOn)
            ->whereDate('created_on', '<=', $toOn)
            ->selectRaw('platform, stage, COUNT(*) as lead_count, SUM(deal_value) as deal_total')
            ->groupBy('platform', 'stage')
            ->get();

        if ($counts->isEmpty()) {
            return ['has_data' => false, 'currency_code' => $overview['primary_currency_code'] ?? null, 'rows' => [], 'total' => null];
        }

        $currencyCode = $overview['primary_currency_code'] ?? null;
        $spendByPlatform = [];

        foreach ($overview['currency_groups'] ?? [] as $group) {
            if ($group['currency_code'] === $currencyCode) {
                foreach ($group['platforms'] as $source => $metrics) {
                    $spendByPlatform[$source] = (float) $metrics['spend'];
                }
            }
        }

        $labels = array_map(static fn (array $definition): string => $definition['label'], ClientCrossPlatformOverview::allPaidSources())
            + [ClientLead::PLATFORM_OTHER => '其他或未標示來源'];
        $funnels = [];

        foreach ($counts as $count) {
            $platform = array_key_exists((string) $count->platform, $labels) ? (string) $count->platform : ClientLead::PLATFORM_OTHER;
            $funnels[$platform] ??= ['leads' => 0, 'qualified' => 0, 'won' => 0, 'lost' => 0, 'deal_value' => 0.0];
            $leadCount = (int) $count->lead_count;
            $funnels[$platform]['leads'] += $leadCount;

            if (in_array($count->stage, [ClientLead::STAGE_QUALIFIED, ClientLead::STAGE_WON], true)) {
                $funnels[$platform]['qualified'] += $leadCount;
            }

            if ($count->stage === ClientLead::STAGE_WON) {
                $funnels[$platform]['won'] += $leadCount;
                $funnels[$platform]['deal_value'] += (float) $count->deal_total;
            }

            if ($count->stage === ClientLead::STAGE_LOST) {
                $funnels[$platform]['lost'] += $leadCount;
            }
        }

        $rows = [];
        $total = ['leads' => 0, 'qualified' => 0, 'won' => 0, 'lost' => 0, 'deal_value' => 0.0];
        $totalSpend = 0.0;

        foreach ($labels as $platform => $label) {
            if (! array_key_exists($platform, $funnels)) {
                continue;
            }

            $spend = $platform === ClientLead::PLATFORM_OTHER ? null : ($spendByPlatform[$platform] ?? 0.0);
            $rows[] = ['platform' => $platform, 'label' => $label] + $this->metrics($funnels[$platform], $spend);

            foreach (array_keys($total) as $key) {
                $total[$key] += $funnels[$platform][$key];
            }

            $totalSpend += $spend ?? 0.0;
        }

        return [
            'has_data' => true,
            'currency_code' => $currencyCode,
            'rows' => $rows,
            'total' => ['platform' => 'total', 'label' => '合計'] + $this->metrics($total, $currencyCode === null ? null : $totalSpend),
        ];
    }

    /**
     * @param  array{leads: int, qualified: int, won: int, lost: int, deal_value: float}  $funnel
     * @return array<string, mixed>
     */
    private function metrics(array $funnel, ?float $spend): array
    {
        $divide = static fn (?float $amount, int $count): ?float => $amount !== null && $amount > 0 && $count > 0 ? round($amount / $count, 2) : null;

        return $funnel + [
            'deal_value' => round($funnel['deal_value'], 2),
            'spend' => $spend,
            'qualified_rate_percent' => $funnel['leads'] > 0 ? round($funnel['qualified'] / $funnel['leads'] * 100, 1) : null,
            'win_rate_percent' => $funnel['leads'] > 0 ? round($funnel['won'] / $funnel['leads'] * 100, 1) : null,
            'cost_per_lead' => $divide($spend, $funnel['leads']),
            'cost_per_qualified_lead' => $divide($spend, $funnel['qualified']),
            'cost_per_deal' => $divide($spend, $funnel['won']),
            'deal_roas' => $spend !== null && $spend > 0 ? round($funnel['deal_value'] / $spend, 2) : null,
        ];
    }
}
