<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /** 查看与回复：普通用户仅限自己的；客服按角色的数据范围（全部/仅我受理/仅我提交） */
    public function view(User $user, Ticket $ticket): bool
    {
        return $user->canSeeTicket($ticket);
    }

    /** 更新：客服可改状态/指派等；普通用户仅限自己的工单改标题内容 */
    public function update(User $user, Ticket $ticket): bool
    {
        return $user->canSeeTicket($ticket);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->hasPermission('ticket.delete');
    }
}
