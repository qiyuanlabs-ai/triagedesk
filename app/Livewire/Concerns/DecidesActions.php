<?php

namespace App\Livewire\Concerns;

use App\Models\AgentAction;
use App\Services\ApprovalService;
use App\Services\LiveAccess;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Approve, edit or reject pending agent actions from any page that lists them.
 */
trait DecidesActions
{
    /** @var array<int, string> */
    public array $edits = [];

    /** @var array<int, string> */
    public array $notes = [];

    public ?string $decisionError = null;

    public function startEdit(int $id): void
    {
        $action = AgentAction::findOrFail($id);

        $this->edits[$id] = json_encode($action->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function cancelEdit(int $id): void
    {
        unset($this->edits[$id]);
    }

    public function approve(int $id, ApprovalService $approvals): void
    {
        $edited = null;

        if (isset($this->edits[$id])) {
            $edited = json_decode($this->edits[$id], true);

            if (! is_array($edited)) {
                $this->decisionError = 'The edited arguments are not valid JSON.';

                return;
            }
        }

        $this->decide($id, fn (AgentAction $action) => $approvals->approve($action, auth()->user(), $edited));
    }

    public function reject(int $id, ApprovalService $approvals): void
    {
        $this->decide($id, fn (AgentAction $action) => $approvals->reject($action, auth()->user(), $this->notes[$id] ?? null));
    }

    protected function decide(int $id, callable $decision): void
    {
        $this->decisionError = null;

        // A decision resumes the agent, which is a live model run.
        if ($denial = LiveAccess::denial()) {
            $this->decisionError = $denial;

            return;
        }

        try {
            $decision(AgentAction::with('ticket')->findOrFail($id));

            unset($this->edits[$id], $this->notes[$id]);
        } catch (AuthorizationException) {
            $this->decisionError = 'This ticket is assigned to someone else, so only they or an admin can decide its actions.';
        } catch (InvalidArgumentException $exception) {
            $this->decisionError = $exception->getMessage();
        }
    }
}
