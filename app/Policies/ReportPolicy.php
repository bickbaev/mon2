<?php

namespace App\Policies;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;

class ReportPolicy
{
    public function view(User $user, Report $report): bool
    {
        if ($user->isBlocked()) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return ! ($report->campaign?->isClosed() ?? false) && $user->canSeeNode($report->node);
    }

    public function edit(User $user, Report $report): bool
    {
        if (! $this->view($user, $report)) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }

        return match ($report->status) {
            ReportStatus::New, ReportStatus::InProgress, ReportStatus::Rework => $user->node_id === $report->node_id,
            ReportStatus::Blocked => $this->isAncestor($user, $report),
            ReportStatus::Accepted => $this->canChangeAccepted($user, $report),
        };
    }

    public function update(User $user, Report $report): bool
    {
        return $this->edit($user, $report);
    }

    public function setStatus(User $user, Report $report, ReportStatus $to): bool
    {
        if (! $report->status->canTransitionTo($to) || ! $this->view($user, $report)) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        if ($report->status === ReportStatus::Accepted) {
            return $this->canChangeAccepted($user, $report);
        }
        if ($to === ReportStatus::InProgress && in_array($report->status, [ReportStatus::New, ReportStatus::Rework], true)) {
            return $user->node_id === $report->node_id;
        }

        return $this->isAncestor($user, $report);
    }

    private function isAncestor(User $user, Report $report): bool
    {
        return $user->node !== null && $user->node->isAncestorOf($report->node);
    }

    private function canChangeAccepted(User $user, Report $report): bool
    {
        return $report->campaign_id === null
            ? $this->isAncestor($user, $report)
            : $report->campaign->creator_id === $user->id;
    }
}
