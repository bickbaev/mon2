<?php

namespace App\Services;

use App\Enums\ReportStatus;
use App\Events\ReportReturnedForRework;
use App\Models\Campaign;
use App\Models\Node;
use App\Models\Report;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReportStatusService
{
    public function transition(Report $report, ReportStatus $to, User $actor): void
    {
        DB::transaction(function () use ($report, $to, $actor): void {
            $campaignId = Report::query()->whereKey($report->getKey())->value('campaign_id');
            $campaign = $campaignId === null ? null : Campaign::query()->lockForUpdate()->findOrFail($campaignId);
            $current = Report::query()->lockForUpdate()->findOrFail($report->getKey());
            if ($current->campaign_id !== $campaignId) {
                throw new DomainException('Сбор отчёта изменился. Повторите действие.');
            }
            $current->setRelation('campaign', $campaign);
            $currentActor = User::query()->lockForUpdate()->find($actor->getKey());
            if ($currentActor === null || $currentActor->isBlocked()) {
                throw new AuthorizationException('Доступ запрещён.');
            }
            $nodes = Node::query()->whereIn('id', array_filter([$currentActor->node_id, $current->node_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current->setRelation('node', $nodes->get($current->node_id));
            $currentActor->setRelation('node', $nodes->get($currentActor->node_id));
            if (! $current->status->canTransitionTo($to)) {
                throw new DomainException('Недопустимый переход статуса отчёта.');
            }
            Gate::forUser($currentActor)->authorize('setStatus', [$current, $to]);
            $current->status = $to;
            $current->save();
            if ($to === ReportStatus::Rework) {
                ReportReturnedForRework::dispatch($current, $currentActor);
            }
        }, 3);
        $report->refresh();
    }
}
