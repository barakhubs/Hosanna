<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class UpdateTokenTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPos();
    }

    public function test_update_is_rejected_when_no_token_is_configured(): void
    {
        config(['version.update_token' => null]);

        $this->postJson('/api/application-update')->assertStatus(401);
        $this->postJson('/api/application-update', ['update_token' => ''])->assertStatus(401);
        $this->postJson('/api/application-update-v2')->assertStatus(401);
    }

    public function test_update_is_rejected_with_wrong_token(): void
    {
        config(['version.update_token' => 'secret']);

        $this->postJson('/api/application-update', ['update_token' => 'nope'])
            ->assertStatus(401)
            ->assertJson(['error' => 'Invalid update token']);
        $this->postJson('/api/application-update', ['update_token' => ['secret']])->assertStatus(401);
    }

    public function test_correct_token_reaches_upload(): void
    {
        config(['version.update_token' => 'secret']);

        $this->postJson('/api/application-update', ['update_token' => 'secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('zip_file');
    }

    public function test_update_endpoint_is_rate_limited(): void
    {
        config(['version.update_token' => 'secret']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/application-update', ['update_token' => 'nope'])->assertStatus(401);
        }

        $this->postJson('/api/application-update', ['update_token' => 'nope'])->assertStatus(429);
    }
}
