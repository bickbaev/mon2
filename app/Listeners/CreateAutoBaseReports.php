<?php

namespace App\Listeners;

use App\Events\NodeCreated;
use App\Services\BaseReportAssignment;

class CreateAutoBaseReports
{
    public function __construct(private BaseReportAssignment $assignment) {}

    /**
     * Handle the event.
     */
    public function handle(NodeCreated $event): void
    {
        $this->assignment->forNewNode($event->node);
    }
}
