<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    public function test_home_redirects_to_login(): void
    {
        $this->seedPos();

        $this->get('/')->assertRedirect('login');
    }
}
