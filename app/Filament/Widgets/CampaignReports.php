<?php

namespace App\Filament\Widgets;

use App\Enums\ReportStatus;
use App\Filament\Pages\BaseReports;
use App\Models\Report;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class CampaignReports extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function boot(): void
    {
        abort_unless(BaseReports::canAccess(), 403);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Отчёты по сборам')
            ->queryStringIdentifier('campaign')
            ->query(function (): Builder {
                $actor = User::query()->find(Filament::auth()->id());
                $query = Report::query()->with(['node', 'reportType', 'campaign'])
                    ->whereHas('campaign', fn (Builder $campaigns): Builder => $campaigns
                        ->whereNull('closed_at')
                        ->where(fn (Builder $dates): Builder => $dates
                            ->whereNull('closes_at')->orWhereDate('closes_at', '>', today())));

                return $actor === null ? $query->whereRaw('1 = 0') : $query->inBranchOf($actor);
            })
            ->columns([
                TextColumn::make('reportType.title')->label('Отчёт')->wrap(),
                TextColumn::make('campaign.starts_at')->label('Начало сбора')->date('d.m.Y'),
                TextColumn::make('campaign.ends_at')->label('Сдать до')->date('d.m.Y'),
                TextColumn::make('status')->label('Статус')
                    ->formatStateUsing(fn (ReportStatus $state): string => $state->label())->badge(),
            ])
            ->defaultSort('id')
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(collect(ReportStatus::cases())
                    ->mapWithKeys(fn (ReportStatus $status): array => [$status->value => $status->label()])->all()),
            ])
            ->recordActions(BaseReports::reportActions());
    }
}
