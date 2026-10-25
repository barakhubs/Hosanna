<?php

namespace Tests\Feature\Characterization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class RouteAccessTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
    }

    public function test_guests_are_sent_to_login_for_app_pages(): void
    {
        foreach (['/dashboard', '/pos', '/sales', '/settings', '/maintenance'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_only_sync_health_is_open_to_guests(): void
    {
        $this->getJson('/api/sync/health')->assertOk()->assertJson(['status' => 'ok']);
        $this->getJson('/api/sync?table=contacts')->assertStatus(401);
        $this->postJson('/api/sync?table=stock')->assertStatus(401);
        $this->getJson('/api/stores/1')->assertStatus(401);
    }

    public function test_sync_api_works_with_a_token(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/sync?table=contacts')->assertOk();
        $this->getJson('/api/sync?table=nope')->assertStatus(400);
    }

    public function test_sync_products_query_fails_on_sqlite(): void
    {
        // GREATEST() is MySQL only
        Sanctum::actingAs($this->admin);
        $this->stockFor();

        $this->getJson('/api/sync?table=products')->assertStatus(500);
    }

    public function test_store_config_route_points_to_missing_method(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/stores/1')->assertStatus(500);
    }

    public function test_receipts_are_public(): void
    {
        $this->get('/receipt/999')->assertNotFound();
        $this->getJson('/api/receipt-text-raw/999')->assertNotFound();
        $this->get("/pending-sales-receipt/{$this->customer->id}")->assertOk();
    }
}
