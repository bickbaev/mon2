<?php

namespace App\Models;

use App\Enums\ReportKind;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportType extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'kind', 'title', 'is_base', 'is_required', 'auto_create', 'excel_enabled', 'list_allows_empty', 'active'];

    protected function casts(): array
    {
        return ['kind' => ReportKind::class, 'is_base' => 'boolean', 'is_required' => 'boolean', 'auto_create' => 'boolean', 'excel_enabled' => 'boolean', 'list_allows_empty' => 'boolean', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (ReportType $type): void {
            if ($type->kind === ReportKind::Complex) {
                if ($type->is_base) {
                    throw new DomainException('Сложный отчёт не может быть базовым.');
                }
                $type->excel_enabled = false;
            }
        });
    }
}
