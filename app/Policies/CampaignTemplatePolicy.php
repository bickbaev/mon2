<?php

namespace App\Policies;

use App\Models\CampaignTemplate;
use App\Models\User;

class CampaignTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function create(User $user): bool
    {
        return ! $user->isBlocked() && ($user->isAdmin() || ($user->node_id !== null && $user->can_start_campaigns));
    }

    public function view(User $user, CampaignTemplate $template): bool
    {
        return ! $user->isBlocked() && ($user->isAdmin() || $template->creator_id === $user->id
            || ($user->node !== null && $template->creatorNode !== null && $user->node->isAncestorOf($template->creatorNode)));
    }

    public function update(User $user, CampaignTemplate $template): bool
    {
        return $this->view($user, $template);
    }
}
