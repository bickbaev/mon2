<?php

namespace App\Filament\Pages;

use App\Enums\NodeType;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use App\Services\ReportStatusService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class BaseReports extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Назначенные отчёты';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected string $view = 'filament.pages.base-reports';

    public static function canAccess(): bool
    {
        $actor = self::currentActor();

        return $actor !== null && ! $actor->isBlocked() && ($actor->isAdmin()
            || in_array($actor->node?->type, [NodeType::Authority, NodeType::Organization], true));
    }

    public function table(Table $table): Table
    {
        return $table->heading('Базовые отчёты')->queryStringIdentifier('base')->query(function (): Builder {
            $actor = self::currentActor();
            $query = Report::query()->whereNull('campaign_id')->with(['node', 'reportType']);

            return $actor === null ? $query->whereRaw('1 = 0') : $query->inBranchOf($actor);
        })->columns([
            TextColumn::make('node.name')->label('Организация')->searchable(),
            TextColumn::make('reportType.title')->label('Отчёт'),
            TextColumn::make('status')->label('Статус')->formatStateUsing(fn (ReportStatus $state): string => $state->label())->badge(),
        ])->defaultSort('id')->filters([
            SelectFilter::make('status')->label('Статус')->options(collect(ReportStatus::cases())->mapWithKeys(fn (ReportStatus $status): array => [$status->value => $status->label()])->all()),
        ])->recordActions(self::reportActions());
    }

    /** @return array<Action> */
    public static function reportActions(): array
    {
        return [
            Action::make('open')->label('Открыть')->url(fn (Report $record): string => ReportForm::getUrl(['report' => $record->id])),
            self::statusAction('rework', ReportStatus::Rework),
            self::statusAction('accept', ReportStatus::Accepted),
        ];
    }

    private static function statusAction(string $name, ReportStatus $status): Action
    {
        return Action::make($name)->label($status->label())->requiresConfirmation()
            ->visible(function (Report $record) use ($status): bool {
                $actor = self::currentActor();

                return $actor !== null && Gate::forUser($actor)->allows('setStatus', [$record, $status]);
            })
            ->action(function (Report $record, ReportStatusService $service) use ($status): void {
                $actor = self::currentActor();
                abort_if($actor === null, 403);
                $service->transition($record, $status, $actor);
            });
    }

    private static function currentActor(): ?User
    {
        $id = Filament::auth()->id();

        return $id === null ? null : User::query()->with('node')->find($id);
    }
}
