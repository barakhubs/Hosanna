<?php

namespace Tests\Unit\Support;

use App\Support\SqlDialect;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SqlDialectTest extends TestCase
{
    public function test_sqlite_fragments(): void
    {
        $this->assertSame("'a/' || products.image_url", SqlDialect::concat("'a/'", 'products.image_url'));
        $this->assertSame('MAX(a, b)', SqlDialect::greatest('a', 'b'));
        $this->assertSame("strftime('%Y-%m-%d', sale_date)", SqlDialect::date('sale_date'));
    }

    public function test_sqlite_fragments_run(): void
    {
        $row = DB::selectOne("SELECT ".SqlDialect::concat("'x'", "'y'")." AS c, ".SqlDialect::greatest('1', '3', '2')." AS g, ".SqlDialect::date("'2025-01-15 10:00:00'")." AS d");

        $this->assertSame('xy', $row->c);
        $this->assertEquals(3, $row->g);
        $this->assertSame('2025-01-15', $row->d);
    }

    public function test_mysql_fragments(): void
    {
        config(['database.connections.fake_mysql' => ['driver' => 'mysql', 'database' => 'x']]);
        DB::setDefaultConnection('fake_mysql');

        try {
            $this->assertSame("CONCAT('a/', products.image_url)", SqlDialect::concat("'a/'", 'products.image_url'));
            $this->assertSame('GREATEST(a, b)', SqlDialect::greatest('a', 'b'));
            $this->assertSame("DATE_FORMAT(sale_date, '%Y-%m-%d')", SqlDialect::date('sale_date'));
        } finally {
            DB::setDefaultConnection('sqlite');
        }
    }
}
