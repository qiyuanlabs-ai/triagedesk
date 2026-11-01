<?php

namespace Database\Seeders;

use App\Models\KbArticle;
use Illuminate\Database\Seeder;

class KnowledgeBaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['Refunds and cancellations', 'Monthly plans can be refunded in full within 14 days of a charge. Annual plans get a prorated refund for unused months within 60 days. Cancelling stops the next renewal; your workspace stays available until the end of the paid period.'],
            ['Duplicate charges', 'If your card was charged twice for the same period, we refund the duplicate in full within 5 to 10 business days. Banks may show a pending authorization that drops off on its own after 3 days.'],
            ['Changing your plan', 'Upgrades take effect immediately and are prorated. Downgrades take effect at the next renewal. Switch plans under Settings, Billing, Change plan.'],
            ['Updating your card', 'Go to Settings, Billing, Payment method. If a renewal failed, updating the card retries the charge within an hour and lifts the past-due banner.'],
            ['Invoices and receipts', 'Invoices are emailed to the billing contact and listed under Settings, Billing, Invoices. To add a VAT number or company address, edit Billing details and re-download the invoice.'],
            ['Past-due accounts', 'When a renewal fails we retry 3 times over 7 days. The workspace becomes read-only after 14 days past due and is never deleted without 30 days notice.'],
            ['Resetting your password', 'Use Forgot password on the sign-in page. Reset links expire after 1 hour. If you use Google sign-in, reset the password in your Google account instead.'],
            ['Two-factor authentication', 'Turn on 2FA under Profile, Security. If you lost your device, an owner of the workspace can reset 2FA for you; Flowly support can only do it after verifying ownership by email.'],
            ['Exporting your data', 'Owners can export every project as CSV or JSON under Settings, Data, Export. Large exports are emailed as a download link within 30 minutes.'],
            ['Deleting a workspace', 'Only the workspace owner can delete it, under Settings, Danger zone. Deletion is permanent after 30 days. Support cannot delete workspaces on request.'],
            ['Calendar sync issues', 'If tasks stop appearing in Google Calendar, disconnect and reconnect the calendar under Integrations. Sync runs every 15 minutes; recurring tasks sync only their next occurrence.'],
            ['Slack notifications not arriving', 'Check that the Flowly app is still installed in your Slack workspace and that the channel is not archived. Reinstalling the app keeps your notification rules.'],
            ['Known issue: CSV import drops due dates', 'Imports from CSV files using DD/MM/YYYY dates currently lose the due date. Use YYYY-MM-DD as a workaround. Engineering is working on a fix.'],
            ['Requesting a feature', 'We log every feature request with the product team and read them weekly. We do not share roadmap dates. Upvote existing ideas on the community board.'],
            ['Service status and outage credits', 'Live status is at status.flowly.test. For an outage longer than 4 hours in a billing month, customers can ask for a 10 percent credit or refund of that month.'],
        ] as [$title, $body]) {
            KbArticle::create(compact('title', 'body'));
        }
    }
}
