<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function create(User $user): bool
    {
        return ! $user->isBlocked() && ($user->isAdmin() || ($user->node_id !== null && $user->can_start_campaigns));
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return ! $user->isBlocked() && ($user->isAdmin() || $campaign->creator_id === $user->id
            || ($user->node !== null && $campaign->creatorNode !== null && $user->node->isAncestorOf($campaign->creatorNode)));
    }

    public function manage(User $user, Campaign $campaign): bool
    {
        return ! $campaign->isClosed() && $this->view($user, $campaign);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->manage($user, $campaign);
    }
}
