<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user)
    {
        return true;
    }

    public function view(User $user, Project $project)
    {
        return true;
    }

    public function create(User $user)
    {
        return $user->is_admin;
    }

    public function update(User $user, Project $project)
    {
        return $user->is_admin;
    }

    public function delete(User $user, Project $project)
    {
        return $user->is_admin;
    }

    public function restore(User $user, Project $project)
    {
        return $user->is_admin;
    }

    public function forceDelete(User $user, Project $project)
    {
        return $user->is_admin;
    }
}

