<?php

namespace App\Models;

use App\Enums\NodeType;
use App\Enums\ReportKind;
use App\Enums\ReportStatus;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    use HasFactory;

    protected $fillable = ['report_type_id', 'campaign_id', 'node_id', 'status'];

    protected $attributes = ['status' => 'new'];

    protected function casts(): array
    {
        return ['status' => ReportStatus::class];
    }

    protected static function booted(): void
    {
        static::saving(function (Report $report): void {
            if (! $report->isDirty(['report_type_id', 'campaign_id', 'node_id'])) {
                return;
            }
            if ($report->campaign_id !== null) {
                $campaign = Campaign::query()->findOrFail($report->campaign_id);
                if ((int) $campaign->report_type_id !== (int) $report->report_type_id) {
                    throw new DomainException('Вид отчёта не совпадает с видом сбора.');
                }
            } else {
                $type = ReportType::query()->findOrFail($report->report_type_id);
                $node = Node::query()->findOrFail($report->node_id);
                if (! $type->is_base || $type->kind === ReportKind::Complex || $node->type !== NodeType::Organization) {
                    throw new DomainException('Базовый отчёт разрешён только организации и базовому виду.');
                }
            }
        });
    }

    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function scopeInBranchOf(Builder $query, User $user): Builder
    {
        if ($user->isBlocked()) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->isAdmin()) {
            return $query;
        }
        if ($user->node === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('node', fn (Builder $nodes): Builder => $nodes->inBranch($user->node));
    }
}
