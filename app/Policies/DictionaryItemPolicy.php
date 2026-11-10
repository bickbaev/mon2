<?php

namespace App\Policies;

use App\Models\DictionaryItem;
use App\Models\User;

class DictionaryItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && ! $user->isBlocked();
    }

    public function view(User $user, DictionaryItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, DictionaryItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function deactivate(User $user, DictionaryItem $item): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, DictionaryItem $item): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
