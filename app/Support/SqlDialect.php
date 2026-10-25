<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Small pieces of SQL that differ between sqlite and mysql.
 * Arguments are raw SQL fragments, never user input.
 */
class SqlDialect
{
    public static function concat(string ...$parts): string
    {
        if (self::isMysql()) {
            return 'CONCAT(' . implode(', ', $parts) . ')';
        }

        return implode(' || ', $parts);
    }

    public static function greatest(string ...$columns): string
    {
        $function = self::isMysql() || DB::getDriverName() === 'pgsql' ? 'GREATEST' : 'MAX';

        return $function . '(' . implode(', ', $columns) . ')';
    }

    public static function date(string $column): string
    {
        if (self::isMysql()) {
            return "DATE_FORMAT({$column}, '%Y-%m-%d')";
        }

        return "strftime('%Y-%m-%d', {$column})";
    }

    private static function isMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }
}
