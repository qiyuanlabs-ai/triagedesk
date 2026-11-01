<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * Admins work any ticket; teammates work their own and unassigned ones.
     */
    public function work(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin()
            || $ticket->assignee_id === null
            || $ticket->assignee_id === $user->id;
    }
}
