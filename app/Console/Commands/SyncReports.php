<?php

namespace App\Console\Commands;

use App\Models\ReportType;
use App\Reports\ReportRegistry;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SyncReports extends Command
{
    protected $signature = 'reports:sync';

    protected $description = 'Синхронизировать виды отчётов с PHP-определениями';

    public function handle(ReportRegistry $registry): int
    {
        try {
            $definitions = $registry->all();
            DB::transaction(function () use ($definitions, $registry): void {
                foreach ($definitions as $key => $definition) {
                    $type = ReportType::query()->where('key', $key)->lockForUpdate()->first() ?? new ReportType(['key' => $key]);
                    $registry->assertSafeChange($type, $definition);
                    $type->fill(['title' => $definition->title(), 'kind' => $definition->kind()]);
                    $type->forceFill(['definition_snapshot' => json_encode($registry->snapshot($definition), JSON_THROW_ON_ERROR)])->save();
                }
            });
        } catch (DomainException|InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Виды отчётов синхронизированы.');

        return self::SUCCESS;
    }
}
