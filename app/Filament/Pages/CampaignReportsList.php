<?php

namespace App\Filament\Pages;

use App\Enums\ReportStatus;
use App\Models\Report;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

class CampaignReportsList extends CampaignStats
{
    protected static ?string $slug = 'campaign-reports/{campaign}/{node}';

    protected string $view = 'filament.pages.campaign-reports-list';

    #[Locked]
    public ?string $status = null;

    public function mount(int $campaign): void
    {
        parent::mount($campaign);
        $status = request()->query('status', $this->status);
        abort_unless($status === null || is_string($status), 404);
        $this->status = $status;
        abort_if($this->status !== null && ReportStatus::tryFrom($this->status) === null, 404);
    }

    public function getTitle(): string
    {
        return parent::getTitle().' · '.($this->status === null ? 'Все отчёты' : ReportStatus::from($this->status)->label());
    }

    public function getBreadcrumbs(): array
    {
        return [CampaignsOverview::getUrl() => 'Статистика сборов', CampaignStats::getUrl(['campaign' => $this->campaignId, 'node' => $this->nodeId]) => $this->selectedNode()->name, 'Отчёты'];
    }

    public function table(Table $table): Table
    {
        return $table->query(function (): Builder {
            $this->authorizeContext();
            $node = $this->selectedNode();

            return Report::query()->with(['node', 'reportType', 'campaign'])->where('campaign_id', $this->campaignId)
                ->whereHas('node', fn (Builder $nodes): Builder => $nodes->inBranch($node))
                ->when($this->status !== null, fn (Builder $reports): Builder => $reports->where('status', $this->status));
        })->columns([
            TextColumn::make('node.name')->label('Организация')->searchable(),
            TextColumn::make('reportType.title')->label('Отчёт'),
            TextColumn::make('status')->label('Статус')->formatStateUsing(fn (ReportStatus $state): string => $state->label())->badge(),
        ])->filters([
            SelectFilter::make('status')->label('Статус')->options(collect(ReportStatus::cases())->mapWithKeys(fn (ReportStatus $status): array => [$status->value => $status->label()])->all()),
        ])->defaultSort('id')->recordActions(BaseReports::reportActions());
    }
}
