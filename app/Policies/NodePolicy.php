<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

class NodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->node_id !== null;
    }

    public function view(User $user, Node $node): bool
    {
        return $user->canSeeNode($node);
    }

    public function update(User $user, Node $node): bool
    {
        return false;
    }

    public function delete(User $user, Node $node): bool
    {
        return false;
    }

    public function create(User $user, ?Node $parent = null): bool
    {
        return $user->isAdmin() || ($parent !== null
            && $user->can_manage_structure && $user->canSeeNode($parent));
    }
}
