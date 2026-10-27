<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /** 查看与回复：客服可见全部，普通用户仅限自己的 */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() || $ticket->user_id === $user->id;
    }

    /** 更新：客服可改状态/指派等；普通用户仅限自己的工单改标题内容 */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->isStaff() || $ticket->user_id === $user->id;
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.delete');
    }
}
