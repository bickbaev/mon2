<?php

namespace App\Models;

use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = ['report_type_id', 'creator_id', 'creator_node_id', 'title', 'starts_at', 'ends_at', 'closes_at', 'closed_at', 'start_status', 'remind_days_before', 'template_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_date', 'ends_at' => 'immutable_date', 'closes_at' => 'immutable_date', 'closed_at' => 'immutable_datetime', 'start_status' => ReportStatus::class, 'remind_days_before' => 'integer'];
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null || ($this->closes_at !== null && $this->closes_at->toDateString() <= today()->toDateString());
    }

    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function creatorNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'creator_node_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }
}
