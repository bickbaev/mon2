<?php

namespace App\Events;

use App\Models\Node;

class NodeCreated
{
    public function __construct(public readonly Node $node)
    {
    }
}
