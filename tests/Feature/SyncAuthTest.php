<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SyncAuthTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
    }

    public function test_bearer_token_grants_access(): void
    {
        $token = $this->admin->createToken('pos-sync')->plainTextToken;

        $this->withToken($token)->getJson('/api/sync?table=contacts')->assertOk();
    }

    public function test_bad_token_is_rejected(): void
    {
        $this->withToken('1|not-a-real-token')->getJson('/api/sync?table=contacts')->assertStatus(401);
    }

    public function test_logged_in_browser_session_still_works(): void
    {
        $this->actingAs($this->admin)->getJson('/api/sync?table=contacts')->assertOk();
    }
}
