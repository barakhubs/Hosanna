<?php

namespace App\Support;

class StorageUrl
{
    /**
     * Path prefix for files on the public disk. Production installs serve the app
     * from the project root, so everything sits under public/ there.
     *
     * StorageUrl::prefix('storage/') gives 'storage/' locally and 'public/storage/' in production.
     */
    public static function prefix(string $local = ''): string
    {
        if (app()->environment('production')) {
            return 'public/' . ltrim($local, '/');
        }

        return $local;
    }
}
