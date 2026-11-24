<?php

namespace Tests\Feature;

use App\Models\ClientExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientCurrencySettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_reporting_currency_and_manage_effective_dated_rates(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Northwind']);

        $this->actingAs($user)
            ->patch(route('clients.currencies.reporting.update', $client), ['reporting_currency_code' => ' twd '])
            ->assertRedirect(route('clients.currencies.index', $client))
            ->assertSessionHas('status', '報表幣別已更新為 TWD。');

        $this->post(route('clients.exchange-rates.store', $client), [
            'reporting_currency_code' => 'TWD',
            'source_currency_code' => ' usd ',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '31.50000000',
        ])
            ->assertRedirect(route('clients.currencies.index', $client))
            ->assertSessionHas('status', '匯率已新增。');

        $exchangeRate = ClientExchangeRate::query()->sole();
        $this->assertSame('USD', $exchangeRate->source_currency_code);
        $this->assertSame('TWD', $exchangeRate->reporting_currency_code);
        $this->assertSame('2026-11-01', $exchangeRate->effective_on->toDateString());
        $this->assertSame('31.50000000', $exchangeRate->rate_to_reporting_currency);

        $this->get(route('clients.currencies.index', $client))
            ->assertOk()
            ->assertSee('Northwind')
            ->assertSee('TWD')
            ->assertSee('USD');

        $this->put(route('clients.exchange-rates.update', [$client, $exchangeRate]), [
            'source_currency_code' => 'jpy',
            'effective_on' => '2026-11-02',
            'rate_to_reporting_currency' => '0.22000000',
        ])
            ->assertRedirect(route('clients.currencies.index', $client))
            ->assertSessionHas('status', '匯率已更新。');

        $this->assertDatabaseHas('client_exchange_rates', [
            'id' => $exchangeRate->id,
            'source_currency_code' => 'JPY',
            'reporting_currency_code' => 'TWD',
        ]);
        $updatedExchangeRate = ClientExchangeRate::query()->findOrFail($exchangeRate->id);
        $this->assertSame('2026-11-02', $updatedExchangeRate->effective_on->toDateString());
        $this->assertSame('0.22000000', $updatedExchangeRate->rate_to_reporting_currency);

        $this->delete(route('clients.exchange-rates.destroy', [$client, $exchangeRate]))
            ->assertRedirect(route('clients.currencies.index', $client))
            ->assertSessionHas('status', '匯率已刪除。');

        $this->assertDatabaseMissing('client_exchange_rates', ['id' => $exchangeRate->id]);
    }

    public function test_invalid_currency_codes_rates_and_duplicate_effective_dates_are_rejected(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create([
            'name' => 'Northwind',
            'reporting_currency_code' => 'TWD',
        ]);

        $this->actingAs($user)
            ->from(route('clients.currencies.index', $client))
            ->patch(route('clients.currencies.reporting.update', $client), ['reporting_currency_code' => 'TWD1'])
            ->assertSessionHasErrors(['reporting_currency_code']);

        $this->post(route('clients.exchange-rates.store', $client), [
            'reporting_currency_code' => 'TWD',
            'source_currency_code' => 'twd',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '1',
        ])->assertSessionHasErrorsIn('createExchangeRate', ['source_currency_code']);

        $this->post(route('clients.exchange-rates.store', $client), [
            'reporting_currency_code' => 'TWD',
            'source_currency_code' => 'USD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '31.5',
        ])->assertRedirect(route('clients.currencies.index', $client));

        $this->post(route('clients.exchange-rates.store', $client), [
            'reporting_currency_code' => 'TWD',
            'source_currency_code' => 'USD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '32',
        ])->assertSessionHasErrorsIn('createExchangeRate', ['source_currency_code']);

        $this->post(route('clients.exchange-rates.store', $client), [
            'reporting_currency_code' => 'TWD',
            'source_currency_code' => 'EUR',
            'effective_on' => '2026-11-02',
            'rate_to_reporting_currency' => '0',
        ])->assertSessionHasErrorsIn('createExchangeRate', ['rate_to_reporting_currency']);

        $this->assertDatabaseCount('client_exchange_rates', 1);
    }

    public function test_an_account_cannot_view_or_change_another_accounts_currency_settings(): void
    {
        $owner = User::factory()->create();
        $client = $owner->clients()->create([
            'name' => 'Private Client',
            'reporting_currency_code' => 'TWD',
        ]);
        $exchangeRate = $client->exchangeRates()->create([
            'source_currency_code' => 'USD',
            'reporting_currency_code' => 'TWD',
            'effective_on' => '2026-11-01',
            'rate_to_reporting_currency' => '31.50000000',
        ]);
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get(route('clients.currencies.index', $client))
            ->assertNotFound();

        $this->put(route('clients.exchange-rates.update', [$client, $exchangeRate]), [
            'source_currency_code' => 'EUR',
            'effective_on' => '2026-11-02',
            'rate_to_reporting_currency' => '34.5',
        ])->assertNotFound();

        $this->delete(route('clients.exchange-rates.destroy', [$client, $exchangeRate]))
            ->assertNotFound();

        $this->assertDatabaseHas('client_exchange_rates', [
            'id' => $exchangeRate->id,
            'source_currency_code' => 'USD',
            'rate_to_reporting_currency' => '31.50000000',
        ]);
    }

    public function test_client_index_links_to_currency_settings_and_guests_are_redirected(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Northwind']);

        $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee(route('clients.currencies.index', $client), false)
            ->assertSee('幣別與匯率');

        auth()->logout();

        $this->get(route('clients.currencies.index', $client))
            ->assertRedirect(route('login'));
    }
}
