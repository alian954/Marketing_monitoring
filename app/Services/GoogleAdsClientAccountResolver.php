<?php

namespace App\Services;

use App\Models\Client;
use App\Models\GoogleAdsClientAccount;
use App\Models\GoogleAdsConnection;
use App\Models\SocialEngagementTarget;

class GoogleAdsClientAccountResolver
{
    public function forTarget(
        ?GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
    ): ?GoogleAdsClientAccount {
        if ($connection === null
            || $target->client_id === null
            || (int) $target->user_id !== (int) $connection->user_id) {
            return null;
        }

        $client = Client::query()->find($target->client_id);

        return $client === null ? null : $this->forClient($connection, $client);
    }

    public function forClient(
        GoogleAdsConnection $connection,
        Client $client,
    ): ?GoogleAdsClientAccount {
        if ((int) $client->user_id !== (int) $connection->user_id) {
            return null;
        }

        $account = $connection->clientAccounts()
            ->where('client_id', $client->getKey())
            ->first();

        if ($account !== null) {
            return $account;
        }

        $ownedClientIds = Client::withoutGlobalScopes()
            ->where('user_id', $connection->user_id)
            ->orderBy('id')
            ->limit(2)
            ->pluck('id');

        if ($ownedClientIds->count() !== 1
            || (int) $ownedClientIds->sole() !== (int) $client->getKey()
            || ! is_string($connection->customer_id)
            || preg_match('/^\d{10}$/', $connection->customer_id) !== 1
            || ! is_string($connection->customer_name)
            || trim($connection->customer_name) === ''
            || ! is_string($connection->currency_code)
            || preg_match('/^[A-Z]{3}$/', $connection->currency_code) !== 1
            || ($connection->login_customer_id !== null
                && (! is_string($connection->login_customer_id)
                    || preg_match('/^\d{10}$/', $connection->login_customer_id) !== 1))) {
            return null;
        }

        return new GoogleAdsClientAccount([
            'google_ads_connection_id' => $connection->getKey(),
            'client_id' => $client->getKey(),
            'customer_id' => $connection->customer_id,
            'login_customer_id' => $connection->login_customer_id,
            'customer_name' => $connection->customer_name,
            'currency_code' => $connection->currency_code,
        ]);
    }
}
