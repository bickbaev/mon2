<?php

use App\Enums\NodeType;
use App\Filament\Pages\BaseReports;
use App\Models\User;
use App\Services\NodeService;
use Filament\Facades\Filament;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function panelNavigationUser(?NodeType $type): User
{
    $node = null;
    if ($type !== null) {
        $service = app(NodeService::class);
        $node = $service->create(null, NodeType::Authority, 'Регион');
        if ($type === NodeType::Organization) {
            $node = $service->create($node, NodeType::Organization, 'Школа');
        }
    }

    return User::factory()->create([
        'node_id' => $node?->id, 'is_admin' => $type === null, 'pd_consent_at' => now(),
    ]);
}

test('topbar global search is absent for every role', function (?NodeType $type) {
    $this->actingAs(panelNavigationUser($type));

    $this->get('/cabinet/profile')->assertOk()->assertDontSee('fi-global-search', false);
})->with([
    'administrator' => [null],
    'authority' => [NodeType::Authority],
    'organization' => [NodeType::Organization],
]);

test('organization navigation has reports instead of dashboard', function () {
    $this->actingAs(panelNavigationUser(NodeType::Organization));

    $this->get('/cabinet/profile')->assertOk()
        ->assertDontSeeText(__('filament-panels::pages/dashboard.title'))
        ->assertSeeText('Назначенные отчёты');
});

test('organization opening cabinet is redirected to assigned reports', function () {
    $this->actingAs(panelNavigationUser(NodeType::Organization));

    $this->get('/cabinet')->assertRedirect(BaseReports::getUrl(panel: 'app'));
    $this->get(BaseReports::getUrl(panel: 'app'))->assertOk();
});

test('dashboard remains available for authorities and administrator', function (?NodeType $type) {
    $this->actingAs(panelNavigationUser($type));

    $this->get('/cabinet/profile')->assertOk()->assertSeeText(__('filament-panels::pages/dashboard.title'));
    $this->get('/cabinet')->assertOk();
})->with([
    'administrator' => [null],
    'authority' => [NodeType::Authority],
]);
