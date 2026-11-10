<?php

namespace App\Policies;

use App\Models\Dictionary;
use App\Models\User;

class DictionaryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() && ! $user->isBlocked();
    }

    public function view(User $user, Dictionary $dictionary): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Dictionary $dictionary): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Dictionary $dictionary): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
