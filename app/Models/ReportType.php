<?php

namespace App\Models;

use App\Enums\ReportKind;
use App\Services\BaseReportAssignment;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class ReportType extends Model
{
    use HasFactory;

    protected $attributes = [
        'is_base' => false,
        'is_required' => false,
        'auto_create' => false,
        'excel_enabled' => true,
        'list_allows_empty' => true,
        'active' => true,
    ];

    protected $fillable = ['key', 'kind', 'title', 'is_base', 'is_required', 'auto_create', 'excel_enabled', 'list_allows_empty', 'active'];

    protected function casts(): array
    {
        return ['kind' => ReportKind::class, 'is_base' => 'boolean', 'is_required' => 'boolean', 'auto_create' => 'boolean', 'excel_enabled' => 'boolean', 'list_allows_empty' => 'boolean', 'active' => 'boolean'];
    }

    /** @param array{touch?: bool} $options */
    public function save(array $options = []): bool
    {
        $attributes = $this->getAttributes();
        $original = $this->original;
        $changes = $this->changes;
        $previous = $this->previous;
        $exists = $this->exists;
        $wasRecentlyCreated = $this->wasRecentlyCreated;

        try {
            return $this->getConnection()->transaction(fn (): bool => parent::save($options));
        } catch (Throwable $exception) {
            $this->setRawAttributes($attributes);
            $this->original = $original;
            $this->changes = $changes;
            $this->previous = $previous;
            $this->exists = $exists;
            $this->wasRecentlyCreated = $wasRecentlyCreated;

            throw $exception;
        }
    }

    protected static function booted(): void
    {
        static::saving(function (ReportType $type): void {
            if ($type->exists && ((! $type->is_base && $type->isDirty('is_base')) || (! $type->active && $type->isDirty('active')))
                && Report::query()->where('report_type_id', $type->id)->whereNull('campaign_id')->exists()) {
                throw new DomainException('Нельзя отключить вид с назначенными базовыми отчётами.');
            }
            if ($type->exists && $type->is_base && $type->isDirty('is_base')
                && Campaign::query()->where('report_type_id', $type->id)->exists()) {
                throw new DomainException('Вид с существующими сборами нельзя сделать базовым.');
            }
            if ($type->kind === ReportKind::Complex) {
                if ($type->is_base) {
                    throw new DomainException('Сложный отчёт не может быть базовым.');
                }
                $type->excel_enabled = false;
            }
        });
        static::saved(function (ReportType $type): void {
            if ($type->is_base && $type->auto_create && $type->active && ($type->wasRecentlyCreated || $type->wasChanged(['is_base', 'auto_create', 'active']))) {
                app(BaseReportAssignment::class)->backfill($type);
            }
        });
    }
}
