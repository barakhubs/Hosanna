<?php

namespace Tests\Feature\Characterization;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_sync_api_is_open_to_guests(): void
    {
        $this->getJson('/api/sync/health')->assertOk()->assertJson(['status' => 'ok']);
        $this->getJson('/api/sync?table=contacts')->assertOk();
        $this->getJson('/api/sync?table=nope')->assertStatus(400);
    }

    public function test_sync_products_query_fails_on_sqlite(): void
    {
        // GREATEST() is MySQL only
        $this->stockFor();

        $this->getJson('/api/sync?table=products')->assertStatus(500);
    }

    public function test_store_config_route_points_to_missing_method(): void
    {
        $this->getJson('/api/stores/1')->assertStatus(500);
    }

    public function test_cashier_can_reach_admin_pages(): void
    {
        $cashier = $this->makeUser('user');

        $this->actingAs($cashier);
        $this->get('/settings')->assertOk();
        $this->get('/users')->assertOk();
        $this->get('/maintenance')->assertOk();
        // 500 on sqlite, but the point is nothing stops a cashier from calling it
        $this->assertNotContains($this->getJson('/api/maintenance/database/tables')->status(), [302, 401, 403]);
    }

    public function test_receipts_are_public(): void
    {
        $this->get('/receipt/999')->assertNotFound();
        $this->getJson('/api/receipt-text-raw/999')->assertNotFound();
        $this->get("/pending-sales-receipt/{$this->customer->id}")->assertOk();
    }
}
