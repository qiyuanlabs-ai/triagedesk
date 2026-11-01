<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    protected const FIRST = ['Maya', 'Liam', 'Priya', 'Noah', 'Elena', 'Omar', 'Grace', 'Diego', 'Hana', 'Felix'];

    protected const LAST = ['Okafor', 'Lindqvist', 'Moreau', 'Tanaka'];

    protected const COMPANIES = ['Brightpath Studio', 'Northwind Labs', 'Copperleaf Co', 'Bluefin Analytics', 'Harbor & Pine', 'Quill Agency', 'Tidewater Health', 'Ember Robotics'];

    /**
     * Plan name => [monthly price in cents, billed annually].
     */
    protected const PLANS = [
        'Starter' => [1200, false],
        'Team' => [4900, false],
        'Business' => [14900, false],
        'Team Annual' => [49000, true],
    ];

    public function run(): void
    {
        $plans = array_keys(self::PLANS);

        for ($i = 0; $i < 40; $i++) {
            $first = self::FIRST[$i % 10];
            $last = self::LAST[intdiv($i, 10)];
            $plan = $plans[$i % 4];
            [$price, $annual] = self::PLANS[$plan];

            $customer = Customer::create([
                'name' => "{$first} {$last}",
                'email' => strtolower("{$first}.{$last}@example.com"),
                'company' => self::COMPANIES[$i % count(self::COMPANIES)],
            ]);

            $status = match (true) {
                $i % 13 === 5 => 'past_due',
                $i % 17 === 9 => 'canceled',
                default => 'active',
            };

            // Charged a few days ago, so the 14-day refund window is open for most customers after every reset.
            $lastCharge = now()->subDays(2 + ($i % 9))->startOfDay()->addHours(9);

            $customer->subscription()->create([
                'plan' => $plan,
                'mrr_cents' => $annual ? intdiv($price, 12) : $price,
                'status' => $status,
                'renews_at' => $status === 'canceled' ? null : ($annual ? $lastCharge->copy()->addYear() : $lastCharge->copy()->addMonth()),
            ]);

            $charges = $annual ? 1 : 3;

            for ($n = $charges - 1; $n >= 0; $n--) {
                $customer->payments()->create([
                    'amount_cents' => $price,
                    'status' => 'paid',
                    'description' => "Flowly {$plan}".($annual ? ' (annual)' : ''),
                    'paid_at' => $lastCharge->copy()->subMonths($n),
                ]);
            }

            // Every seventh customer was charged twice for the same month.
            if ($i % 7 === 3) {
                $customer->payments()->create([
                    'amount_cents' => $price,
                    'status' => 'paid',
                    'description' => "Flowly {$plan}".($annual ? ' (annual)' : '').' (duplicate charge)',
                    'paid_at' => $lastCharge->copy()->addMinutes(3),
                ]);
            }
        }
    }
}
