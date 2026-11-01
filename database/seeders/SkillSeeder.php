<?php

namespace Database\Seeders;

use App\Models\TeamSkill;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    public function run(): void
    {
        TeamSkill::create([
            'team' => 'support',
            'name' => 'escalation',
            'description' => 'When and how to hand a ticket to engineering or billing. Load for bugs, outages and account access problems.',
            'instructions' => implode("\n", [
                '- Bugs that block a paying customer: set priority to urgent and assign to jordan@flowly.test (on-call engineering liaison).',
                '- Account access or data deletion requests: assign to alex@flowly.test; never change account data yourself.',
                '- Payment disputes or chargebacks: assign to alex@flowly.test and do not refund until they decide.',
                '- In the reason, say which rule you applied.',
            ]),
        ]);

        TeamSkill::create([
            'team' => 'support',
            'name' => 'holiday-hours',
            'description' => 'Support hours over the end-of-year holidays.',
            'instructions' => 'Between Dec 24 and Jan 1, tell customers replies may take up to 48 hours.',
            'enabled' => false,
        ]);
    }
}
