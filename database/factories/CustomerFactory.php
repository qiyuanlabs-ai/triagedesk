<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'company' => fake()->company(),
        ];
    }

    /**
     * Give the customer a Business subscription and two $149 charges.
     */
    public function withPayments(): static
    {
        return $this->afterCreating(function (Customer $customer) {
            $customer->subscription()->create(['plan' => 'Business', 'mrr_cents' => 14900, 'status' => 'active', 'renews_at' => now()->addMonth()]);

            foreach ([0, 3] as $minutes) {
                $customer->payments()->create(['amount_cents' => 14900, 'status' => 'paid', 'description' => 'Flowly Business', 'paid_at' => now()->subDays(3)->addMinutes($minutes)]);
            }
        });
    }
}
