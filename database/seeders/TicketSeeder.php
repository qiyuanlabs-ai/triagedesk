<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;

class TicketSeeder extends Seeder
{
    /**
     * [category, priority, sentiment, subject, body, summary]. {first}, {plan} and {amount} are filled per customer.
     */
    public const TEMPLATES = [
        ['refund', 'normal', 'neutral', 'Refund for this month?', "Hi, I'm {first}. We moved our team to another tool last week and won't use Flowly this month. Could you refund the {amount} you charged for {plan}? Thanks.", 'Customer switched tools and asks for a refund of the latest charge.'],
        ['refund', 'high', 'negative', 'Charged after I cancelled', 'I cancelled our {plan} plan days ago and you still took {amount}. Please refund it. — {first}', 'Customer says they were charged after cancelling and wants the money back.'],
        ['refund', 'urgent', 'angry', 'You charged me TWICE', 'Two charges of {amount} on my card for the same month. This is the second time something like this happens. Refund the duplicate today. {first}', 'Duplicate charge for the same month; customer is angry and wants it refunded.'],
        ['billing', 'normal', 'neutral', 'Need VAT number on invoice', "Hello, our accountant needs our VAT number and company address on last month's invoice. How do we add them? {first}", 'Customer wants the VAT number and address added to an invoice.'],
        ['billing', 'normal', 'neutral', 'Upgrade mid-cycle pricing', 'If we upgrade from {plan} today, do we pay the full price or the difference? {first}', 'Customer asks how mid-cycle upgrades are prorated.'],
        ['billing', 'high', 'negative', 'Account says past due but card is fine', "We got a past-due banner but our card works everywhere else. Our team can't afford downtime. {first}", 'Past-due banner despite a working card; customer worried about downtime.'],
        ['bug', 'high', 'negative', 'CSV import lost all due dates', 'I imported 300 tasks from a CSV and every due date is gone. The file uses 31/10/2026 style dates. {first}', 'CSV import dropped due dates on 300 tasks (DD/MM/YYYY format).'],
        ['bug', 'urgent', 'angry', 'Calendar sync broken, missed a client meeting', 'Tasks stopped showing in Google Calendar on Monday and I missed a client call because of it. Fix this. {first}', 'Calendar sync stopped and the customer missed a meeting.'],
        ['bug', 'normal', 'neutral', 'Slack notifications stopped', 'Since yesterday no Flowly notifications arrive in our #design channel. Nothing changed on our side. {first}', 'Slack notifications stopped arriving in one channel.'],
        ['feature_request', 'low', 'positive', 'Gantt view please', "Love Flowly! Any plans for a Gantt view? It's the one thing our PMs keep asking for. {first}", 'Customer requests a Gantt chart view.'],
        ['feature_request', 'low', 'neutral', 'Dark mode on mobile', 'Is dark mode coming to the iOS app? {first}', 'Customer asks for dark mode in the iOS app.'],
        ['feature_request', 'normal', 'neutral', 'SSO for our team', "We're growing and need SAML SSO before we can roll Flowly out company-wide. Is it on the roadmap? {first}", 'Customer needs SAML SSO for a wider rollout.'],
        ['account', 'high', 'negative', 'Locked out after losing phone', "I lost my phone and can't get past two-factor. I'm the workspace owner. Help. {first}", 'Workspace owner locked out after losing their 2FA device.'],
        ['account', 'normal', 'neutral', 'How do I export everything?', 'We need a full export of our projects for an audit. What formats are available? {first}', 'Customer wants a full data export for an audit.'],
        ['account', 'normal', 'neutral', 'Please delete our workspace', "We're closing the company. Please delete our workspace and all data. {first}", 'Customer asks support to delete their workspace.'],
        ['spam', 'low', 'neutral', 'Boost your SEO ranking 300%', 'Dear sir, we offer guaranteed first-page Google rankings for your website. Reply for our price list.', 'Unsolicited SEO sales pitch.'],
        ['spam', 'low', 'neutral', 'Partnership opportunity!!!', 'Hello friend, I have a lucrative crypto partnership for your esteemed company. Contact me on WhatsApp.', 'Unsolicited crypto partnership pitch.'],
        ['refund', 'normal', 'negative', 'Outage yesterday cost us a day', 'Flowly was down for most of yesterday afternoon, about 5 hours for us. Do we get anything back on {plan}? {first}', 'Customer asks for compensation after a 5-hour outage.'],
    ];

    public function run(): void
    {
        $customers = Customer::with(['subscription', 'payments'])->orderBy('id')->get();
        $duplicateCustomers = $customers->filter(fn (Customer $customer) => $customer->payments->contains(fn ($payment) => str_contains($payment->description, 'duplicate')))->values();
        $sam = User::where('email', 'sam@flowly.test')->value('id');
        $jordan = User::where('email', 'jordan@flowly.test')->value('id');

        for ($i = 0; $i < 60; $i++) {
            [$category, $priority, $sentiment, $subject, $body, $summary] = self::TEMPLATES[$i % count(self::TEMPLATES)];

            $customer = $subject === 'You charged me TWICE'
                ? $duplicateCustomers[$i % $duplicateCustomers->count()]
                : $customers[($i * 7) % $customers->count()];

            $latest = $customer->payments->sortByDesc('paid_at')->first();
            $triaged = $i < 45;
            $createdAt = now()->subHours(240 - $i * 4);

            $ticket = Ticket::create([
                'customer_id' => $customer->id,
                'assignee_id' => match (true) {
                    $i % 5 === 1 => $sam,
                    $i % 7 === 2 => $jordan,
                    default => null,
                },
                'subject' => $subject,
                'status' => $triaged ? ['open', 'open', 'pending', 'solved'][$i % 4] : 'new',
                'priority' => $triaged ? $priority : null,
                'category' => $triaged ? $category : null,
                'sentiment' => $triaged ? $sentiment : null,
                'summary' => $triaged ? $summary : null,
                'triaged_at' => $triaged ? $createdAt->copy()->addMinute() : null,
            ]);

            $ticket->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

            $ticket->messages()->create([
                'author_type' => 'customer',
                'author_name' => $customer->name,
                'body' => strtr($body, [
                    '{first}' => strtok($customer->name, ' '),
                    '{plan}' => $customer->subscription->plan,
                    '{amount}' => '$'.number_format($latest->amount_cents / 100, 2),
                ]),
            ])->forceFill(['created_at' => $createdAt])->save();
        }
    }
}
