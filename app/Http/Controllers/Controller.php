<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Message that is safe to send back to the user. Errors we throw ourselves with a
     * plain \Exception keep their text; database and framework errors are hidden
     * unless the app is in debug mode.
     */
    protected function errorMessage(\Throwable $e, string $fallback = 'Something went wrong. Please try again.'): string
    {
        if (config('app.debug') || get_class($e) === \Exception::class) {
            return $e->getMessage();
        }

        return $fallback;
    }
}
