<?php

namespace App\Services;

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Models\Node;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;

class BaseReportGate
{
    public function blocks(Node $node): bool
    {
        if ($node->type !== NodeType::Organization) {
            return false;
        }

        return Report::query()->where('node_id', $node->id)->whereNull('campaign_id')
            ->where('status', ReportStatus::New)
            ->whereHas('reportType', fn (Builder $types): Builder => $types->where('is_base', true)->where('is_required', true))
            ->exists();
    }
}
