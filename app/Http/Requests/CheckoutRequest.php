<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checks the fields checkout reads without a fallback. Anything missing here
 * used to end in a server error part way through saving the sale.
 */
class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required'],
            'net_total' => ['required', 'numeric'],

            'cartItems' => ['present', 'array'],
            'cartItems.*.id' => ['present'],
            'cartItems.*.batch_id' => ['present'],
            'cartItems.*.quantity' => ['required', 'numeric'],
            'cartItems.*.price' => ['required', 'numeric'],
            'cartItems.*.cost' => ['present'],
            'cartItems.*.discount' => ['present'],
            'cartItems.*.is_stock_managed' => ['present'],
            'cartItems.*.product_type' => ['present'],

            'charges' => ['nullable', 'array'],
            'charges.*.id' => ['present'],
            'charges.*.name' => ['present'],
            'charges.*.charge_type' => ['present'],
            'charges.*.rate_type' => ['present'],
            'charges.*.rate_value' => ['required', 'numeric'],

            'payments' => ['required_unless:payment_method,Cash', 'array'],
            'payments.*.payment_method' => ['required'],
            'payments.*.amount' => ['required', 'numeric'],
        ];
    }
}
