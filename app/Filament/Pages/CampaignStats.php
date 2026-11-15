<?php

namespace App\Filament\Pages;

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Node;
use App\Services\StatisticsAccess;
use App\Services\StatusStats;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class CampaignStats extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'campaign-statistics/{campaign}/{node?}';

    protected string $view = 'filament.pages.campaign-stats';

    #[Locked]
    public int $campaignId;

    #[Locked]
    public int $nodeId;

    #[Locked]
    public ?int $node = null;

    private ?Campaign $authorizedCampaign = null;

    private ?Node $authorizedNode = null;

    private ?array $branchStats = null;

    public static function canAccess(): bool
    {
        return app(StatisticsAccess::class)->allowed();
    }

    public function mount(int $campaign): void
    {
        $this->campaignId = $campaign;
        $this->nodeId = app(StatisticsAccess::class)->authorizeNode($this->node)->id;
        $this->authorizeContext();
    }

    public function hydrate(): void
    {
        $this->authorizeContext();
    }

    protected function authorizeContext(): void
    {
        $this->authorizedNode = app(StatisticsAccess::class)->authorizeNode($this->nodeId);
        $this->authorizedCampaign = app(StatisticsAccess::class)->authorizeCampaign($this->campaignId);
    }

    public function campaign(): Campaign
    {
        return $this->authorizedCampaign ??= app(StatisticsAccess::class)->authorizeCampaign($this->campaignId);
    }

    public function selectedNode(): Node
    {
        return $this->authorizedNode ??= app(StatisticsAccess::class)->authorizeNode($this->nodeId);
    }

    public function getTitle(): string
    {
        $campaign = $this->campaign();

        return $campaign->reportType->title.($campaign->title ? ' · '.$campaign->title : '');
    }

    public function getSubheading(): string
    {
        $campaign = $this->campaign();

        return $this->selectedNode()->name.' · '.$campaign->starts_at->format('d.m.Y').' — '.$campaign->ends_at->format('d.m.Y');
    }

    public function getBreadcrumbs(): array
    {
        $breadcrumbs = [CampaignsOverview::getUrl() => 'Статистика сборов'];
        $root = app(StatisticsAccess::class)->authorizeNode();
        $selected = $this->selectedNode();
        foreach (Node::query()->inBranch($root)->where('depth', '<', $selected->depth)->orderBy('depth')->get() as $ancestor) {
            if ($ancestor->isAncestorOf($selected)) {
                $breadcrumbs[CampaignStats::getUrl(['campaign' => $this->campaignId, 'node' => $ancestor->id])] = $ancestor->name;
            }
        }
        $breadcrumbs[] = $selected->name;

        return $breadcrumbs;
    }

    /** @return array<string, int> */
    public function totals(): array
    {
        return $this->statistics()['total'];
    }

    public function hasChildRows(): bool
    {
        return $this->statistics()['rows'] !== [];
    }

    private function statistics(): array
    {
        return $this->branchStats ??= app(StatusStats::class)->forNode($this->campaign(), $this->selectedNode());
    }

    private function rowCounts(Node $node): array
    {
        foreach ($this->statistics()['rows'] as $row) {
            if ($row['node']->is($node)) {
                return $row['counts'];
            }
        }

        return [];
    }

    public function reportsUrl(int $node, ?string $status = null): string
    {
        return CampaignReportsList::getUrl(['campaign' => $this->campaignId, 'node' => $node, 'status' => $status]);
    }

    public function table(Table $table): Table
    {
        $columns = [TextColumn::make('name')->label('Организация')];
        foreach ([...array_column(ReportStatus::cases(), 'value'), 'total'] as $status) {
            $columns[] = TextColumn::make($status)->label($status === 'total' ? 'Всего' : ReportStatus::from($status)->label())
                ->state(fn (Node $record): int => $this->rowCounts($record)[$status])
                ->url(fn (Node $record): string => $this->reportsUrl($record->id, $status === 'total' ? null : $status));
        }

        return $table->query(fn () => $this->selectedNode()->children()->getQuery()->withCount('children'))
            ->contentFooter(fn () => view('filament.pages.campaign-stats-total'))
            ->columns($columns)->defaultSort('name')->recordActions([
                Action::make('view')->label('Просмотр')->visible(fn (Node $record): bool => $record->children_count > 0)
                    ->url(fn (Node $record): string => self::getUrl(['campaign' => $this->campaignId, 'node' => $record->id])),
            ]);
    }
}
