<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'balance' => 0,
            'type' => 'customer',
        ];
    }

    public function vendor(): static
    {
        return $this->state(fn () => ['type' => 'vendor']);
    }
}
