<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\ReleaseGroup;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReleaseGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ReleaseGroup $group): bool
    {
        return $user->teams->contains('id', $group->team_id);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ReleaseGroup $group): bool
    {
        return $user->isAdminOfTeam($group->team_id);
    }

    public function delete(User $user, ReleaseGroup $group): bool
    {
        return $user->isAdminOfTeam($group->team_id);
    }

    /**
     * Starting or controlling a release needs the deploy permission on every resource in it.
     */
    public function release(User $user, ReleaseGroup $group): Response
    {
        if (! $user->isAdminOfTeam($group->team_id)) {
            return Response::deny('You need at least admin permissions in this team to start a release.');
        }

        $applications = Application::query()
            ->whereIn('id', $group->members()->where('resource_type', Application::class)->pluck('resource_id'))
            ->get();
        foreach ($applications as $application) {
            if ($user->cannot('deploy', $application)) {
                return Response::deny("You are not allowed to deploy {$application->name}.");
            }
        }

        return Response::allow();
    }
}
