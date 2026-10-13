<?php

namespace App\Policies;

use App\Models\DeploymentFreeze;
use App\Models\User;

class DeploymentFreezePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DeploymentFreeze $freeze): bool
    {
        return $user->teams->contains('id', $freeze->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, DeploymentFreeze $freeze): bool
    {
        return $user->isAdminOfTeam($freeze->team_id);
    }
}
