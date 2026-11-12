<?php

namespace App\Services;

use App\Enums\NodeType;
use App\Enums\ReportKind;
use App\Enums\ReportStatus;
use App\Models\Node;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class BaseReportAssignment
{
    /** @param list<int|string>|null $organizationIds Null assigns all organizations. */
    public function assign(ReportType $type, ?array $organizationIds, User $actor): void
    {
        DB::transaction(function () use ($type, $organizationIds, $actor): void {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $currentType = ReportType::query()->lockForUpdate()->findOrFail($type->id);
            Gate::forUser($currentActor)->authorize('assign', $currentType);
            if (! $currentType->is_base || ! $currentType->active || $currentType->auto_create || $currentType->kind === ReportKind::Complex) {
                throw ValidationException::withMessages(['organizations' => 'Ручное назначение доступно активному базовому виду без автоматического создания.']);
            }
            $query = Node::query()->where('type', NodeType::Organization);
            if ($organizationIds !== null) {
                $ids = array_values(array_unique($organizationIds));
                $organizations = (clone $query)->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
                if ($ids === [] || $organizations->count() !== count($ids)) {
                    throw ValidationException::withMessages(['organizations' => 'Выберите существующие организации.']);
                }
                foreach ($organizations as $organization) {
                    $this->create($currentType, $organization);
                }

                return;
            }
            $this->assignQuery($currentType, $query);
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateSettings(ReportType $type, array $data, User $actor): ReportType
    {
        return DB::transaction(function () use ($type, $data, $actor): ReportType {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $currentType = ReportType::query()->lockForUpdate()->findOrFail($type->id);
            Gate::forUser($currentActor)->authorize('update', $currentType);
            try {
                $currentType->fill(Arr::only($data, ['is_base', 'is_required', 'auto_create', 'excel_enabled', 'list_allows_empty', 'active']))->save();
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['is_base' => $exception->getMessage()]);
            }

            return $currentType;
        }, 3);
    }

    public function backfill(ReportType $type): void
    {
        DB::transaction(function () use ($type): void {
            $currentType = ReportType::query()->lockForUpdate()->findOrFail($type->id);
            if ($this->isAutomatic($currentType)) {
                $this->assignQuery($currentType, Node::query()->where('type', NodeType::Organization));
            }
        }, 3);
    }

    public function forNewNode(Node $node): void
    {
        $typeIds = ReportType::query()->where('is_base', true)->where('auto_create', true)->where('active', true)->orderBy('id')->pluck('id');
        foreach ($typeIds as $typeId) {
            DB::transaction(function () use ($typeId, $node): void {
                $type = ReportType::query()->lockForUpdate()->find($typeId);
                $currentNode = Node::query()->lockForUpdate()->find($node->id);
                if ($type !== null && $this->isAutomatic($type) && $currentNode?->type === NodeType::Organization) {
                    $this->create($type, $currentNode);
                }
            }, 3);
        }
    }

    private function isAutomatic(ReportType $type): bool
    {
        return $type->is_base && $type->auto_create && $type->active && $type->kind !== ReportKind::Complex;
    }

    private function assignQuery(ReportType $type, Builder $organizations): void
    {
        $organizations->orderBy('id')->chunkById(100, function ($nodes) use ($type): void {
            foreach ($nodes as $node) {
                $this->create($type, $node);
            }
        });
    }

    private function create(ReportType $type, Node $organization): void
    {
        Report::query()->firstOrCreate([
            'report_type_id' => $type->id,
            'campaign_id' => null,
            'node_id' => $organization->id,
        ], ['status' => ReportStatus::New]);
    }
}
