<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'subject' => 'Charged twice this month',
            'status' => 'open',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (Ticket $ticket) => $ticket->messages()->create([
            'author_type' => 'customer',
            'author_name' => $ticket->customer->name,
            'body' => 'You charged my card twice for the same month. Please refund the duplicate.',
        ]));
    }
}
