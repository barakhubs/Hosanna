<?php

namespace App\Services\Sales;

use RuntimeException;

/**
 * A checkout problem the till should be told about, with the exact response to send.
 */
class CheckoutException extends RuntimeException
{
    public function __construct(public readonly array $payload, public readonly int $status)
    {
        parent::__construct($payload['error'] ?? 'Checkout failed');
    }

    public static function stockMissing(): self
    {
        return new self(['error' => 'Stock for product not found in the specified store or batch'], 500);
    }

    public static function reloadAccountMissing($errors): self
    {
        return new self([
            'error' => 'Account number is required for reload product type.',
            'messages' => $errors,
        ], 400);
    }
}
