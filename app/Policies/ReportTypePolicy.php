<?php

namespace App\Policies;

use App\Models\ReportType;
use App\Models\User;

class ReportTypePolicy
{
    public function viewAny(User $user): bool
    {
        $current = User::query()->find($user->id);

        return $current !== null && ! $current->isBlocked()
            && ($current->isAdmin() || ($current->node_id !== null && $current->can_start_campaigns));
    }

    public function view(User $user, ReportType $type): bool
    {
        $current = User::query()->find($user->id);

        return $current !== null && $current->isAdmin() && ! $current->isBlocked();
    }

    public function update(User $user, ReportType $type): bool
    {
        return $this->view($user, $type);
    }

    public function assign(User $user, ReportType $type): bool
    {
        return $this->view($user, $type);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, ReportType $type): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
