<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

class NodePolicy
{
    public function create(User $user, ?Node $parent = null): bool
    {
        return $user->isAdmin() || ($parent !== null
            && $user->can_manage_structure && $user->canSeeNode($parent));
    }
}
