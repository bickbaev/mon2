<?php

namespace App\Services;

final class NodeSelection
{
    /** @param list<int> $nodeIds
     * @param  list<int>  $allInBranch
     * @param  list<int>  $organizationsInBranch
     * @param  list<int>  $branchesInBranch
     */
    public function __construct(
        public array $nodeIds = [],
        public array $allInBranch = [],
        public array $organizationsInBranch = [],
        public array $branchesInBranch = [],
    ) {}
}
