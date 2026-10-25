<?php

namespace Tests\Unit\Support;

use App\Support\StorageUrl;
use Tests\TestCase;

class StorageUrlTest extends TestCase
{
    public function test_local_prefix_is_returned_as_is(): void
    {
        $this->assertSame('/storage/', StorageUrl::prefix('/storage/'));
        $this->assertSame('storage/', StorageUrl::prefix('storage/'));
        $this->assertSame('', StorageUrl::prefix());
    }

    public function test_production_puts_everything_under_public(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertSame('public/storage/', StorageUrl::prefix('/storage/'));
        $this->assertSame('public/storage/', StorageUrl::prefix('storage/'));
        $this->assertSame('public/', StorageUrl::prefix());
    }
}
