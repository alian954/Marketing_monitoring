<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_index_creates_and_shows_the_default_client(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertSee('客戶清單')
            ->assertSee(route('clients.performance'), false)
            ->assertSee(Client::DEFAULT_NAME);

        $this->assertSame(Client::DEFAULT_NAME, $response->viewData('clients')->sole()->name);
    }

    public function test_client_index_searches_and_paginates_the_matching_clients(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 30) as $number) {
            $user->clients()->create(['name' => sprintf('Agency Client %02d', $number)]);
        }

        $response = $this->actingAs($user)
            ->get(route('clients.index', ['search' => 'Agency Client', 'page' => 2]))
            ->assertOk()
            ->assertSee('Agency Client 30')
            ->assertDontSee('Agency Client 01')
            ->assertSee('30 個客戶')
            ->assertSee('顯示第 26–30 筆，共 30 筆')
            ->assertSee('name="search"', false)
            ->assertSee('value="Agency Client"', false);

        $clients = $response->viewData('clients');
        $this->assertSame(30, $clients->total());
        $this->assertSame(2, $clients->currentPage());
        $this->assertCount(5, $clients);
        $this->assertStringContainsString('search=Agency', (string) $clients->previousPageUrl());
    }

    public function test_client_index_shows_empty_search_results_and_rejects_long_terms(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('clients.index', ['search' => 'No matching client']))
            ->assertOk()
            ->assertSee('找不到符合條件的客戶')
            ->assertSee('清除');

        $this->get(route('clients.index', ['search' => str_repeat('x', 81)]))
            ->assertRedirect(route('clients.index'))
            ->assertSessionHasErrors(['search' => '客戶搜尋不可超過 80 個字元。']);
    }

    public function test_user_can_create_and_rename_unique_clients(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('clients.store'), ['name' => '  Acme Studio  '])
            ->assertRedirect(route('clients.index'))
            ->assertSessionHas('status', '客戶已新增。');

        $client = Client::query()->where('name', 'Acme Studio')->sole();

        $this->put(route('clients.update', $client), ['name' => 'Acme Agency'])
            ->assertRedirect(route('clients.index'))
            ->assertSessionHas('status', '客戶名稱已更新。');

        $this->assertDatabaseHas('clients', ['id' => $client->id, 'name' => 'Acme Agency', 'user_id' => $user->id]);
    }

    public function test_client_names_must_be_unique_per_account(): void
    {
        $user = User::factory()->create();
        Client::ensureDefaultFor($user);
        $client = $user->clients()->create(['name' => 'Studio']);

        $this->actingAs($user)
            ->from(route('clients.index'))
            ->post(route('clients.store'), ['name' => 'Studio'])
            ->assertSessionHasErrorsIn('createClient', ['name' => '此帳戶已有相同名稱的客戶。']);

        $this->put(route('clients.update', $client), ['name' => Client::DEFAULT_NAME])
            ->assertSessionHasErrorsIn('updateClient'.$client->id, ['name' => '此帳戶已有相同名稱的客戶。']);

        $this->assertDatabaseCount('clients', 2);
    }

    public function test_default_client_cannot_be_renamed_or_deleted(): void
    {
        $user = User::factory()->create();
        $defaultClient = Client::ensureDefaultFor($user);

        $this->actingAs($user)
            ->put(route('clients.update', $defaultClient), ['name' => 'Renamed'])
            ->assertSessionHasErrorsIn('updateClient'.$defaultClient->id);

        $this->delete(route('clients.destroy', $defaultClient))
            ->assertSessionHasErrorsIn('clientManagement', ['client' => '預設分類「未分類」不可刪除。']);

        $this->assertDatabaseHas('clients', ['id' => $defaultClient->id, 'name' => Client::DEFAULT_NAME]);
    }

    public function test_nonempty_client_cannot_be_deleted_but_empty_client_can(): void
    {
        $user = User::factory()->create();
        $client = $user->clients()->create(['name' => 'Active Client']);
        $monitor = Monitor::factory()->create(['client_id' => $client->id]);

        $this->actingAs($user)
            ->from(route('clients.index'))
            ->delete(route('clients.destroy', $client))
            ->assertSessionHasErrorsIn('clientManagement', ['client' => '請先將監測項目與活動目標移至其他客戶。']);

        $emptyClient = $user->clients()->create(['name' => 'Empty Client']);
        $this->delete(route('clients.destroy', $emptyClient))
            ->assertRedirect(route('clients.index'));

        $this->assertDatabaseMissing('clients', ['id' => $emptyClient->id]);
        $this->assertDatabaseHas('monitors', ['id' => $monitor->id, 'client_id' => $client->id]);
    }

    public function test_user_cannot_view_or_delete_another_accounts_client(): void
    {
        $owner = User::factory()->create();
        $foreignClient = $owner->clients()->create(['name' => 'Private Client']);
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->get(route('clients.index'))
            ->assertOk()
            ->assertDontSee('Private Client');

        $this->delete(route('clients.destroy', $foreignClient))->assertNotFound();
        $this->assertDatabaseHas('clients', ['id' => $foreignClient->id, 'user_id' => $owner->id]);
    }
}
