<?php

namespace App\Services;

use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ReportRecipients
{
    /** @return Collection<int, User> */
    public function for(Node $node): Collection
    {
        return User::query()->where('node_id', $node->id)->whereNull('blocked_at')
            ->whereNotNull('email_verified_at')->whereNotNull('email')->where('email', '!=', '')
            ->orderBy('id')->get();
    }
}
