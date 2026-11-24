<?php

namespace App\Services;

use App\Models\Client;
use App\Models\GoogleAnalyticsClientProperty;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;

class GoogleAnalyticsClientPropertyResolver
{
    public function forTarget(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
    ): ?GoogleAnalyticsClientProperty {
        if ($target->client_id === null || (int) $target->user_id !== (int) $connection->user_id) {
            return null;
        }

        $client = Client::query()->find($target->client_id);

        return $client === null ? null : $this->forClient($connection, $client);
    }

    public function forClient(
        GoogleAnalyticsConnection $connection,
        Client $client,
    ): ?GoogleAnalyticsClientProperty {
        if ((int) $client->user_id !== (int) $connection->user_id) {
            return null;
        }

        $property = $connection->clientProperties()
            ->where('client_id', $client->getKey())
            ->first();

        if ($property !== null) {
            return $property;
        }

        $ownedClientIds = Client::withoutGlobalScopes()
            ->where('user_id', $connection->user_id)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        if ($ownedClientIds->count() !== 1
            || (int) $ownedClientIds->sole() !== (int) $client->getKey()
            || ! is_string($connection->property_id)
            || preg_match('/^[0-9]{1,20}$/', $connection->property_id) !== 1) {
            return null;
        }

        return new GoogleAnalyticsClientProperty([
            'google_analytics_connection_id' => $connection->getKey(),
            'client_id' => $client->getKey(),
            'property_id' => $connection->property_id,
        ]);
    }
}
