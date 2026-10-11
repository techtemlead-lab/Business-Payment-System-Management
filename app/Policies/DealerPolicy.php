<?php

namespace App\Policies;

use App\Models\Dealer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DealerPolicy
{
    public function view(User $user, Dealer $dealer): Response
    {
        return $user->role === 'admin' || $dealer->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Dealer $dealer): Response
    {
        return $this->view($user, $dealer);
    }
}
