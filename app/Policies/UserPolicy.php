<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || ($user->node !== null && $user->node->children()->exists());
    }

    public function view(User $user, User $other): bool
    {
        return $user->canManageUser($other);
    }

    public function create(User $user, Node $node): bool
    {
        return $user->isAdmin() || ($user->node !== null && $user->node->isAncestorOf($node));
    }

    public function update(User $user, User $other): bool
    {
        return $user->canManageUser($other);
    }

    public function delete(User $user, User $other): bool
    {
        return $user->canManageUser($other);
    }

    public function block(User $user, User $other): bool
    {
        return $user->canManageUser($other);
    }

    public function grantFlags(User $user): bool
    {
        return $user->isAdmin();
    }
}
