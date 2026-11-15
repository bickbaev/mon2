<?php

namespace App\Filament\Pages;

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Services\StatisticsAccess;
use App\Services\StatusStats;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class CampaignsOverview extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Статистика сборов';

    protected string $view = 'filament.pages.campaigns-overview';

    /** @var array<int, array<string, int>> */
    private array $countCache = [];

    private ?int $rootId = null;

    private bool $countsLoaded = false;

    public static function canAccess(): bool
    {
        return app(StatisticsAccess::class)->allowed();
    }

    public function table(Table $table): Table
    {
        return $table->query(fn () => app(StatisticsAccess::class)->campaigns()->with('reportType'))
            ->columns([
                TextColumn::make('reportType.title')->label('Вид отчёта')->description(fn (Campaign $record): ?string => $record->title)->url(fn (Campaign $record): string => CampaignStats::getUrl(['campaign' => $record->id])),
                TextColumn::make('starts_at')->label('Начало периода')->date('d.m.Y'),
                TextColumn::make('ends_at')->label('Конец периода')->date('d.m.Y'),
                ...$this->countColumns(),
            ])->defaultSort('id', 'desc');
    }

    private function rootId(): int
    {
        return $this->rootId ??= app(StatisticsAccess::class)->authorizeNode()->id;
    }

    private function campaignCounts(Campaign $campaign): array
    {
        if (! $this->countsLoaded) {
            foreach (app(StatusStats::class)->overview(app(StatisticsAccess::class)->actor()) as $row) {
                $this->countCache[$row['campaign']->id] = $row['counts'];
            }
            $this->countsLoaded = true;
        }

        return $this->countCache[$campaign->id];
    }

    /** @return array<TextColumn> */
    private function countColumns(): array
    {
        $columns = [];
        foreach ([...array_column(ReportStatus::cases(), 'value'), 'total'] as $status) {
            $columns[] = TextColumn::make($status)->label($status === 'total' ? 'Всего' : ReportStatus::from($status)->label())
                ->state(fn (Campaign $record): int => $this->campaignCounts($record)[$status])
                ->url(fn (Campaign $record): string => CampaignReportsList::getUrl(['campaign' => $record->id, 'node' => $this->rootId(), 'status' => $status === 'total' ? null : $status]));
        }

        return $columns;
    }
}
