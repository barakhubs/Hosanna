<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SyncStoreIdTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
    }

    public function test_fetch_rejects_bad_store_id(): void
    {
        foreach (['abc', '1 OR 1=1', '1; DROP TABLE sales', '999'] as $storeId) {
            $this->getJson('/api/sync?table=products&store_id='.urlencode($storeId))
                ->assertStatus(422)
                ->assertJsonValidationErrors('store_id');
        }
    }

    public function test_push_rejects_bad_store_id(): void
    {
        $this->postJson('/api/sync?table=stock', ['store_id' => '1 OR 1=1', 'updates' => [['product_id' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('store_id');
    }

    public function test_valid_or_missing_store_id_still_works(): void
    {
        $this->getJson('/api/sync?table=contacts&store_id=1')->assertOk()->assertJsonPath('data.0.store_id', '1');
        $this->getJson('/api/sync?table=contacts')->assertOk();
        $this->getJson('/api/sync?table=stock')->assertStatus(400);
    }
}
