<?php

namespace Tests;

use App\Models\Client;
use App\Models\GoogleAdsConnection;
use App\Models\GoogleAnalyticsConnection;
use App\Models\SocialEngagementTarget;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    private ?GoogleAdsConnection $googleAdsFixtureConnection = null;

    private ?GoogleAnalyticsConnection $googleAnalyticsFixtureConnection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        Sleep::fake(syncWithCarbon: true);
    }

    protected function bindGoogleAdsTargetToClient(
        User $user,
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
    ): void {
        $client = Client::ensureDefaultFor($user);

        $target->forceFill([
            'user_id' => $user->workspaceOwnerId(),
            'client_id' => $client->getKey(),
        ])->save();

        $connection->clientAccounts()->updateOrCreate(
            ['client_id' => $client->getKey()],
            [
                'customer_id' => $connection->customer_id,
                'login_customer_id' => $connection->login_customer_id,
                'customer_name' => $connection->customer_name,
                'currency_code' => $connection->currency_code,
            ],
        );
    }

    protected function bindGoogleAnalyticsTargetToClient(
        User $user,
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
    ): void {
        $client = Client::ensureDefaultFor($user);

        $target->forceFill([
            'user_id' => $user->workspaceOwnerId(),
            'client_id' => $client->getKey(),
        ])->save();

        $connection->clientProperties()->updateOrCreate(
            ['client_id' => $client->getKey()],
            ['property_id' => $connection->property_id],
        );
    }

    protected function mapGoogleAnalyticsConnectionToTargetClient(
        GoogleAnalyticsConnection $connection,
        SocialEngagementTarget $target,
    ): void {
        $user = User::query()->findOrFail($connection->user_id);
        $ownerUserId = $user->workspaceOwnerId();

        if ($target->client_id === null) {
            if ($target->user_id !== null && (int) $target->user_id !== $ownerUserId) {
                return;
            }

            $client = Client::ensureDefaultFor($user);
            $target->forceFill([
                'user_id' => $ownerUserId,
                'client_id' => $client->getKey(),
            ])->save();
        } else {
            $client = Client::withoutGlobalScopes()->find($target->client_id);
        }

        if ((int) $target->user_id !== $ownerUserId
            || $client === null
            || (int) $client->user_id !== $ownerUserId
            || ! $this->hasValidGoogleAnalyticsFixtureProperty($connection)) {
            return;
        }

        $connection->clientProperties()->updateOrCreate(
            ['client_id' => $client->getKey()],
            ['property_id' => $connection->property_id],
        );
    }

    protected function trackGoogleAnalyticsFixtureConnection(GoogleAnalyticsConnection $connection): void
    {
        $this->googleAnalyticsFixtureConnection = $connection;

        if (! $this->hasValidGoogleAnalyticsFixtureProperty($connection)) {
            return;
        }

        $user = User::query()->findOrFail($connection->user_id);

        SocialEngagementTarget::withoutGlobalScopes()
            ->whereNull('client_id')
            ->get()
            ->each(function (SocialEngagementTarget $target) use ($user, $connection): void {
                if ($target->user_id === null || (int) $target->user_id === $user->workspaceOwnerId()) {
                    $this->bindGoogleAnalyticsTargetToClient($user, $connection, $target);
                }
            });
    }

    protected function bindGoogleAnalyticsTargetFixture(SocialEngagementTarget $target): void
    {
        if ($this->googleAnalyticsFixtureConnection === null
            || ! $this->hasValidGoogleAnalyticsFixtureProperty($this->googleAnalyticsFixtureConnection)
            || $target->client_id !== null) {
            return;
        }

        $user = User::query()->findOrFail($this->googleAnalyticsFixtureConnection->user_id);

        if ($target->user_id !== null && (int) $target->user_id !== $user->workspaceOwnerId()) {
            return;
        }

        $this->bindGoogleAnalyticsTargetToClient($user, $this->googleAnalyticsFixtureConnection, $target);
    }

    protected function mapGoogleAdsConnectionToTargetClient(
        GoogleAdsConnection $connection,
        SocialEngagementTarget $target,
    ): void {
        $user = User::query()->findOrFail($connection->user_id);
        $ownerUserId = $user->workspaceOwnerId();

        if ($target->client_id === null) {
            if ($target->user_id !== null && (int) $target->user_id !== $ownerUserId) {
                return;
            }

            $client = Client::ensureDefaultFor($user);
            $target->forceFill([
                'user_id' => $ownerUserId,
                'client_id' => $client->getKey(),
            ])->save();
        } else {
            $client = Client::withoutGlobalScopes()->find($target->client_id);
        }

        if ((int) $target->user_id !== $ownerUserId
            || $client === null
            || (int) $client->user_id !== $ownerUserId) {
            return;
        }

        $connection->clientAccounts()->updateOrCreate(
            ['client_id' => $client->getKey()],
            [
                'customer_id' => $connection->customer_id,
                'login_customer_id' => $connection->login_customer_id,
                'customer_name' => filled($connection->customer_name) ? $connection->customer_name : 'Test Google Ads account',
                'currency_code' => filled($connection->currency_code) ? $connection->currency_code : 'TWD',
            ],
        );
    }

    protected function trackGoogleAdsFixtureConnection(GoogleAdsConnection $connection): void
    {
        $this->googleAdsFixtureConnection = $connection;

        if (! $this->hasValidGoogleAdsFixtureAccount($connection)) {
            return;
        }

        $user = User::query()->findOrFail($connection->user_id);

        SocialEngagementTarget::withoutGlobalScopes()
            ->whereNull('client_id')
            ->get()
            ->each(function (SocialEngagementTarget $target) use ($user, $connection): void {
                if ($target->user_id === null || (int) $target->user_id === $user->workspaceOwnerId()) {
                    $this->bindGoogleAdsTargetToClient($user, $connection, $target);
                }
            });
    }

    protected function bindGoogleAdsTargetFixture(SocialEngagementTarget $target): void
    {
        if ($this->googleAdsFixtureConnection === null
            || ! $this->hasValidGoogleAdsFixtureAccount($this->googleAdsFixtureConnection)
            || $target->client_id !== null) {
            return;
        }

        $user = User::query()->findOrFail($this->googleAdsFixtureConnection->user_id);

        if ($target->user_id !== null && (int) $target->user_id !== $user->workspaceOwnerId()) {
            return;
        }

        $this->bindGoogleAdsTargetToClient($user, $this->googleAdsFixtureConnection, $target);
    }

    private function hasValidGoogleAdsFixtureAccount(GoogleAdsConnection $connection): bool
    {
        return is_string($connection->customer_id)
            && preg_match('/^\d{10}$/', $connection->customer_id) === 1
            && is_string($connection->customer_name)
            && trim($connection->customer_name) !== ''
            && is_string($connection->currency_code)
            && preg_match('/^[A-Z]{3}$/', $connection->currency_code) === 1;
    }

    private function hasValidGoogleAnalyticsFixtureProperty(GoogleAnalyticsConnection $connection): bool
    {
        return is_string($connection->property_id)
            && preg_match('/^[0-9]{1,20}$/', $connection->property_id) === 1;
    }
}
