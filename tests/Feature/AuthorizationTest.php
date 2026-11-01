<?php

namespace Tests\Feature;

use App\Livewire\ApprovalInbox;
use App\Models\AgentAction;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function pendingActionOnTicketOf(User $assignee): AgentAction
    {
        $ticket = Ticket::factory()->create(['assignee_id' => $assignee->id]);

        return AgentAction::create([
            'ticket_id' => $ticket->id,
            'conversation_id' => 'conv-1',
            'invocation_id' => 'inv-1',
            'tool_call_id' => 'toolu_1',
            'tool' => 'UpdateTicket',
            'arguments' => ['ticket_id' => $ticket->id, 'status' => 'closed', 'reason' => 'Spam.'],
            'required_approval' => true,
            'status' => AgentAction::PENDING,
        ]);
    }

    public function test_a_teammate_cannot_decide_actions_on_someone_elses_ticket(): void
    {
        $sam = User::factory()->create();
        $jordan = User::factory()->create();
        $action = $this->pendingActionOnTicketOf($sam);

        try {
            app(ApprovalService::class)->approve($action, $jordan);
            $this->fail('Jordan should not be able to approve.');
        } catch (AuthorizationException) {
            //
        }

        $this->assertTrue($action->fresh()->isPending());
    }

    public function test_the_inbox_explains_why_a_decision_was_refused(): void
    {
        $action = $this->pendingActionOnTicketOf(User::factory()->create());

        Livewire::actingAs(User::factory()->create())
            ->test(ApprovalInbox::class)
            ->call('reject', $action->id)
            ->assertSet('decisionError', 'This ticket is assigned to someone else, so only they or an admin can decide its actions.');

        $this->assertTrue($action->fresh()->isPending());
    }

    public function test_an_action_cannot_be_decided_twice(): void
    {
        $sam = User::factory()->create();
        $action = $this->pendingActionOnTicketOf($sam);
        $action->update(['status' => AgentAction::REJECTED]);

        $this->expectExceptionMessage('already been decided');

        app(ApprovalService::class)->approve($action, $sam);
    }

    public function test_admins_and_assignees_can_work_a_ticket_but_others_cannot(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sam = User::factory()->create();
        $jordan = User::factory()->create();
        $ticket = Ticket::factory()->create(['assignee_id' => $sam->id]);
        $unassigned = Ticket::factory()->create();

        $this->assertTrue($admin->can('work', $ticket));
        $this->assertTrue($sam->can('work', $ticket));
        $this->assertFalse($jordan->can('work', $ticket));
        $this->assertTrue($jordan->can('work', $unassigned));
    }
}
