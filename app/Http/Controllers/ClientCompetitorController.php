<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Monitor;
use App\Services\ResponseContentChangeHistory;
use Illuminate\Http\Response;

/**
 * Lists the client's monitors tagged as competitor pages, with their most recent content changes,
 * so price, promotion and copy changes on competitor sites show up in one place.
 */
class ClientCompetitorController extends Controller
{
    public const TAG = '競品';

    public function index(Client $client, ResponseContentChangeHistory $changeHistory): Response
    {
        $monitors = $client->monitors()
            ->orderBy('name')
            ->get()
            ->filter(static fn (Monitor $monitor): bool => in_array(self::TAG, $monitor->tagNames(), true))
            ->values();

        return response()
            ->view('clients.competitors', [
                'client' => $client,
                'rows' => $monitors->map(static fn (Monitor $monitor): array => [
                    'monitor' => $monitor,
                    'changes' => $changeHistory->build($monitor),
                ]),
                'tag' => self::TAG,
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}
