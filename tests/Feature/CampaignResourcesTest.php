<?php

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Resources\Campaigns\Pages\CreateCampaign;
use App\Filament\Resources\Campaigns\Pages\ListCampaigns;
use App\Filament\Resources\CampaignTemplates\CampaignTemplateResource;
use App\Filament\Resources\CampaignTemplates\Pages\CreateCampaignTemplate;
use App\Filament\Resources\CampaignTemplates\Pages\EditCampaignTemplate;
use App\Filament\Resources\CampaignTemplates\Pages\ListCampaignTemplates;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\User;
use App\Services\CampaignService;
use App\Services\CampaignTemplateService;
use App\Services\NodeSelection;
use App\Services\NodeService;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('campaign form starts a collection and management actions persist changes', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [$actor, , , $school, $branch] = campaignContext();
    $actor->forceFill(['pd_consent_at' => now()])->save();
    $this->actingAs($actor);
    Livewire::test(CreateCampaign::class)
        ->fillForm(campaignData(['nodeIds' => [$school->id]]))->call('create')->assertHasNoFormErrors();
    $campaign = Campaign::sole();
    expect($campaign->reports()->pluck('node_id')->all())->toBe([$school->id]);
    Livewire::test(ListCampaigns::class)->callTableAction('addNode', $campaign, data: ['node_id' => $branch->id])->assertHasNoTableActionErrors();
    Livewire::test(ListCampaigns::class)->callTableAction('blockAll', $campaign)->assertHasNoTableActionErrors();
    expect($campaign->reports()->where('status', 'blocked')->count())->toBe(2);
    Livewire::test(ListCampaigns::class)->callTableAction('close', $campaign)->assertHasNoTableActionErrors();
    expect($campaign->fresh()->closed_at)->not->toBeNull();
});

test('empty campaign form selection returns a visible validation error', function () {
    [$actor] = campaignContext();
    $this->actingAs($actor);
    Livewire::test(CreateCampaign::class)->fillForm(campaignData())->call('create')->assertHasFormErrors(['nodeIds']);
    expect(Campaign::count())->toBe(0);
});

test('campaign and template pages scope records and reject users without launch permission', function () {
    [$actor, , , $school, , $foreign] = campaignContext();
    $campaign = app(CampaignService::class)->start($actor, campaignData(), new NodeSelection([$school->id]));
    $template = app(CampaignTemplateService::class)->create($actor, array_merge(campaignData(), ['periodicity' => 'weekly', 'start_weekday' => 1, 'duration_days' => 5]), new NodeSelection([$school->id]));
    $stranger = User::factory()->create(['node_id' => $foreign->id, 'pd_consent_at' => now()]);
    $this->actingAs($stranger);
    Livewire::test(ListCampaigns::class)->assertCanNotSeeTableRecords([$campaign]);
    Livewire::test(ListCampaignTemplates::class)->assertCanNotSeeTableRecords([$template]);
    $this->get(CampaignResource::getUrl('create'))->assertForbidden();
    $this->get(CampaignTemplateResource::getUrl('create'))->assertForbidden();
    $this->get(CampaignTemplateResource::getUrl('edit', ['record' => $template]))->assertNotFound();
});

test('template form creates edits and adds participants for later collections', function () {
    [$actor, , , $school, $branch] = campaignContext();
    $this->actingAs($actor);
    Livewire::test(CreateCampaignTemplate::class)->fillForm(array_merge(campaignData(), [
        'periodicity' => 'monthly', 'start_day' => 31, 'duration_days' => 5,
        'close_after_days' => null, 'nodeIds' => [$school->id],
    ]))->call('create')->assertHasNoFormErrors();
    $template = CampaignTemplate::sole();
    Livewire::test(EditCampaignTemplate::class, ['record' => $template->id])->fillForm(['active' => false, 'duration_days' => 10])->call('save')->assertHasNoFormErrors();
    expect($template->fresh()->active)->toBeFalse();
    expect($template->fresh()->duration_days)->toBe(10);
    Livewire::test(ListCampaignTemplates::class)->callTableAction('addNode', $template, data: ['node_id' => $branch->id])->assertHasNoTableActionErrors();
    expect($template->nodes()->count())->toBe(2);
});

test('participant modal shows an error when chosen node leaves the owners branch', function (bool $isTemplate) {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [$actor, $root, , $school, $branch] = campaignContext();
    $record = $isTemplate
        ? app(CampaignTemplateService::class)->create($actor, array_merge(campaignData(), ['periodicity' => 'weekly', 'start_weekday' => 1, 'duration_days' => 5]), new NodeSelection([$school->id]))
        : app(CampaignService::class)->start($actor, campaignData(), new NodeSelection([$school->id]));
    $superior = User::factory()->create(['node_id' => $root->id]);
    $this->actingAs($superior);
    $page = Livewire::test($isTemplate ? ListCampaignTemplates::class : ListCampaigns::class)->mountTableAction('addNode', $record)->setTableActionData(['node_id' => $branch->id]);
    $serviceClass = $isTemplate ? CampaignTemplateService::class : CampaignService::class;
    $service = app($serviceClass);
    $this->mock($serviceClass)->shouldReceive('addNode')->once()->andReturnUsing(function (...$arguments) use ($service, $school, $root) {
        app(NodeService::class)->move($school, $root);

        return $service->addNode(...$arguments);
    });
    $page->callMountedTableAction()->assertHasTableActionErrors(['node_id']);
    expect($isTemplate ? $record->nodes()->count() : $record->reports()->count())->toBe(1);
})->with([true, false]);
