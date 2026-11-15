<?php

namespace App\Models;

use App\Enums\Periodicity;
use App\Enums\ReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['creator_id', 'creator_node_id', 'report_type_id', 'title', 'periodicity', 'start_weekday', 'start_day', 'start_month', 'duration_days', 'close_after_days', 'start_status', 'remind_days_before', 'last_spawned_for', 'active'];

    protected function casts(): array
    {
        return ['periodicity' => Periodicity::class, 'start_status' => ReportStatus::class, 'start_weekday' => 'integer', 'start_day' => 'integer', 'start_month' => 'integer', 'duration_days' => 'integer', 'close_after_days' => 'integer', 'remind_days_before' => 'integer', 'last_spawned_for' => 'immutable_date', 'active' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function creatorNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'creator_node_id');
    }

    public function reportType(): BelongsTo
    {
        return $this->belongsTo(ReportType::class);
    }

    public function nodes(): BelongsToMany
    {
        return $this->belongsToMany(Node::class, 'campaign_template_nodes', 'template_id', 'node_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'template_id');
    }
}
