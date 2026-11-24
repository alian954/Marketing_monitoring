<?php

namespace App\Services;

use App\Models\Client;
use App\Models\MetaMarketingClientAccount;
use App\Models\MetaMarketingConnection;
use App\Models\SocialEngagementTarget;

class MetaMarketingClientAccountResolver
{
    public function forTarget(
        ?MetaMarketingConnection $connection,
        SocialEngagementTarget $target,
    ): ?MetaMarketingClientAccount {
        if ($connection === null
            || $target->client_id === null
            || (int) $target->user_id !== (int) $connection->user_id) {
            return null;
        }

        $client = Client::query()->find($target->client_id);

        return $client === null ? null : $this->forClient($connection, $client);
    }

    public function forClient(
        MetaMarketingConnection $connection,
        Client $client,
    ): ?MetaMarketingClientAccount {
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
            || ! is_string($connection->ad_account_id)
            || preg_match('/^[0-9]{5,32}$/', $connection->ad_account_id) !== 1
            || ! is_string($connection->ad_account_name)
            || trim($connection->ad_account_name) === ''
            || ! is_string($connection->currency_code)
            || preg_match('/^[A-Z]{3}$/', $connection->currency_code) !== 1) {
            return null;
        }

        return new MetaMarketingClientAccount([
            'meta_marketing_connection_id' => $connection->getKey(),
            'client_id' => $client->getKey(),
            'ad_account_id' => $connection->ad_account_id,
            'ad_account_name' => $connection->ad_account_name,
            'currency_code' => $connection->currency_code,
        ]);
    }
}
