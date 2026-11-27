<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientMarketingAnnotation;
use App\Models\Incident;
use App\Models\MarketingAlertEvent;
use Carbon\CarbonImmutable;

/**
 * Collects the dated events that explain a client's performance changes: manual calendar notes,
 * confirmed website monitoring incidents and triggered marketing alerts.
 */
class ClientMarketingTimeline
{
    public const SOURCE_ANNOTATION = 'annotation';

    public const SOURCE_INCIDENT = 'incident';

    public const SOURCE_ALERT = 'alert';

    /** @var array<string, string> */
    public const SOURCE_LABELS = [
        self::SOURCE_ANNOTATION => '行銷註記',
        self::SOURCE_INCIDENT => '網站監測事件',
        self::SOURCE_ALERT => '行銷警示',
    ];

    private const MAX_ENTRIES = 200;

    private const TIMEZONE = 'Asia/Taipei';

    /**
     * @return list<array{date: string, source: string, category_label: string, title: string, note: string|null}>
     */
    public function build(Client $client, string $fromOn, string $toOn): array
    {
        $entries = [];

        $annotations = $client->marketingAnnotations()
            ->whereDate('occurred_on', '>=', $fromOn)
            ->whereDate('occurred_on', '<=', $toOn)
            ->orderBy('occurred_on')
            ->orderBy('id')
            ->limit(self::MAX_ENTRIES)
            ->get();

        foreach ($annotations as $annotation) {
            /** @var ClientMarketingAnnotation $annotation */
            $entries[] = [
                'date' => $annotation->occurred_on->toDateString(),
                'source' => self::SOURCE_ANNOTATION,
                'category_label' => $annotation->categoryLabel(),
                'title' => $annotation->title,
                'note' => $annotation->note,
            ];
        }

        $fromUtc = CarbonImmutable::createFromFormat('!Y-m-d', $fromOn, self::TIMEZONE)->utc();
        $toUtc = CarbonImmutable::createFromFormat('!Y-m-d', $toOn, self::TIMEZONE)->addDay()->utc();
        $incidents = Incident::query()
            ->whereHas('monitor', static fn ($query) => $query->where('client_id', $client->getKey()))
            ->whereNotNull('opened_at')
            ->where('opened_at', '>=', $fromUtc)
            ->where('opened_at', '<', $toUtc)
            ->with('monitor:id,name')
            ->orderBy('opened_at')
            ->limit(self::MAX_ENTRIES)
            ->get();

        foreach ($incidents as $incident) {
            /** @var Incident $incident */
            $entries[] = [
                'date' => $incident->opened_at->timezone(self::TIMEZONE)->toDateString(),
                'source' => self::SOURCE_INCIDENT,
                'category_label' => $incident->ruleLabel(),
                'title' => (string) ($incident->monitor?->name ?? '監測項目'),
                'note' => null,
            ];
        }

        $alertEvents = MarketingAlertEvent::query()
            ->whereHas('rule', static fn ($query) => $query->where('client_id', $client->getKey()))
            ->whereDate('observed_on', '>=', $fromOn)
            ->whereDate('observed_on', '<=', $toOn)
            ->with('rule:id,metric')
            ->orderBy('observed_on')
            ->limit(self::MAX_ENTRIES)
            ->get();

        foreach ($alertEvents as $event) {
            /** @var MarketingAlertEvent $event */
            $entries[] = [
                'date' => $event->observed_on->toDateString(),
                'source' => self::SOURCE_ALERT,
                'category_label' => (string) ($event->rule?->label() ?? '行銷警示'),
                'title' => $event->message,
                'note' => null,
            ];
        }

        $sourceOrder = array_flip(array_keys(self::SOURCE_LABELS));
        usort($entries, static fn (array $first, array $second): int => strcmp($first['date'], $second['date'])
            ?: $sourceOrder[$first['source']] <=> $sourceOrder[$second['source']]);

        return array_slice($entries, 0, self::MAX_ENTRIES);
    }

    /**
     * Places one marker per date with events on a chart whose bars are keyed by date.
     *
     * @param  list<array{date: string, source: string, category_label: string, title: string, note: string|null}>  $entries
     * @param  list<array{date: string, x: float, width: float}>  $bars
     * @return list<array{date: string, x: float, sources: list<string>, label: string}>
     */
    public function chartMarkers(array $entries, array $bars): array
    {
        $centerByDate = [];

        foreach ($bars as $bar) {
            $centerByDate[$bar['date']] = round($bar['x'] + $bar['width'] / 2, 2);
        }

        $grouped = [];

        foreach ($entries as $entry) {
            if (! array_key_exists($entry['date'], $centerByDate)) {
                continue;
            }

            $grouped[$entry['date']]['sources'][$entry['source']] = true;
            $grouped[$entry['date']]['titles'][] = $entry['category_label'].'：'.$entry['title'];
        }

        $markers = [];

        foreach ($grouped as $date => $group) {
            $markers[] = [
                'date' => $date,
                'x' => $centerByDate[$date],
                'sources' => array_keys($group['sources']),
                'label' => $date.' '.implode('；', $group['titles']),
            ];
        }

        return $markers;
    }
}
