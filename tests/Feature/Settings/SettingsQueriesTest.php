<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsPos;
use Tests\TestCase;

class SettingsQueriesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsPos;

    protected function settingsQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count) {
            if (str_contains($query->sql, '"settings"')) {
                $count++;
            }
        });
        $callback();

        return $count;
    }

    public function test_pages_read_settings_from_cache(): void
    {
        $this->seedPos();
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();

        $count = $this->settingsQueries(function () {
            $this->get('/dashboard')->assertOk();
            $this->get('/pos')->assertOk();
        });

        $this->assertSame(0, $count);
    }
}
