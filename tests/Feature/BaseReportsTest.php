<?php

use App\Enums\NodeType;
use App\Enums\ReportKind;
use App\Enums\ReportStatus;
use App\Events\NodeCreated;
use App\Filament\Pages\BaseReports;
use App\Filament\Resources\ReportTypes\Pages\EditReportType;
use App\Filament\Resources\ReportTypes\Pages\ListReportTypes;
use App\Filament\Resources\ReportTypes\ReportTypeResource;
use App\Models\Campaign;
use App\Models\Node;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\BaseReportAssignment;
use App\Services\BaseReportGate;
use App\Services\NodeService;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function baseReportNodes(): array
{
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $district = $service->create($root, NodeType::Authority, 'Район');
    $organization = $service->create($district, NodeType::Organization, 'Школа');
    $branch = $service->create($organization, NodeType::Branch, 'Филиал');
    $foreign = $service->create($root, NodeType::Organization, 'Другая школа');

    return [$root, $district, $organization, $branch, $foreign];
}

test('new organizations receive automatic base reports exactly once', function () {
    $type = ReportType::factory()->base()->create(['auto_create' => true]);
    ReportType::factory()->base()->create(['auto_create' => false]);
    ReportType::factory()->base()->create(['auto_create' => true, 'active' => false]);
    [$root, $district, $organization, $branch, $foreign] = baseReportNodes();
    event(new NodeCreated($organization));

    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
    expect(Report::query()->count())->toBe(2);
});

test('enabling automatic assignment or making an automatic type base backfills organizations idempotently', function (bool $initialBase) {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->create(['is_base' => $initialBase, 'auto_create' => ! $initialBase]);
    $type->update(['auto_create' => true, 'is_base' => true]);
    $type->update(['title' => 'Новое название']);
    app(BaseReportAssignment::class)->backfill($type);

    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
})->with([true, false]);

test('only assigned required unsaved base reports block organization campaign edits', function (ReportStatus $status) {
    [, , $organization, $branch] = baseReportNodes();
    $type = ReportType::factory()->base()->create(['is_required' => true]);
    $base = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => null, 'node_id' => $organization->id, 'status' => $status]);
    $campaign = Report::factory()->create(['node_id' => $organization->id]);
    $owner = User::factory()->create(['node_id' => $organization->id]);
    $admin = User::factory()->create(['is_admin' => true]);

    expect(app(BaseReportGate::class)->blocks($organization))->toBe($status === ReportStatus::New);
    expect(Gate::forUser($owner)->allows('edit', $campaign))->toBe($status !== ReportStatus::New);
    expect(Gate::forUser($admin)->allows('edit', $campaign))->toBeTrue();
    expect(Gate::forUser($owner)->allows('edit', $base))->toBe(in_array($status, [ReportStatus::New, ReportStatus::InProgress, ReportStatus::Rework], true));
    expect(app(BaseReportGate::class)->blocks($branch))->toBeFalse();
})->with(ReportStatus::cases());

test('unassigned optional reports authorities and branches do not block', function () {
    [$root, , $organization, $branch] = baseReportNodes();
    ReportType::factory()->base()->create(['is_required' => true]);
    $optional = ReportType::factory()->base()->create();
    Report::factory()->create(['report_type_id' => $optional->id, 'campaign_id' => null, 'node_id' => $organization->id]);

    foreach ([$root, $organization, $branch] as $node) {
        expect(app(BaseReportGate::class)->blocks($node))->toBeFalse();
    }
});

test('admin manually assigns selected organizations when automatic assignment is off', function () {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $service = app(BaseReportAssignment::class);
    $service->assign($type, [$organization->id], $admin);
    $service->assign($type, [$organization->id], $admin);

    expect(Report::query()->pluck('node_id')->all())->toBe([$organization->id]);
    expect(Report::query()->where('node_id', $foreign->id)->exists())->toBeFalse();
});

test('manual assignment rechecks stale actor and type and rejects non organizations', function () {
    [$root, , $organization, $branch] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $service = app(BaseReportAssignment::class);
    User::query()->whereKey($admin)->update(['is_admin' => false]);
    expect(fn () => $service->assign($type, [$organization->id], $admin))->toThrow(AuthorizationException::class);
    User::query()->whereKey($admin)->update(['is_admin' => true, 'blocked_at' => now()]);
    expect(fn () => $service->assign($type, [$organization->id], $admin))->toThrow(AuthorizationException::class);
    User::query()->whereKey($admin)->update(['blocked_at' => null]);
    expect(fn () => $service->assign($type, [$organization->id, $branch->id], $admin))->toThrow(ValidationException::class);
    expect(Report::query()->count())->toBe(0);
    ReportType::query()->whereKey($type)->update(['is_base' => false]);
    expect(fn () => $service->assign($type, [$organization->id], $admin))->toThrow(ValidationException::class);
    expect(Report::query()->count())->toBe(0);
});

test('assigned base reports prevent disabling their type or base flag', function (string $attribute) {
    [, , $organization] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => null, 'node_id' => $organization->id]);
    expect(fn () => $type->update([$attribute => false]))->toThrow(DomainException::class);
    expect($type->fresh()->{$attribute})->toBeTrue();
})->with(['is_base', 'active']);

test('campaign type with existing campaigns cannot become base', function () {
    $campaign = Campaign::factory()->create();
    expect(fn () => $campaign->reportType->update(['is_base' => true]))->toThrow(DomainException::class);
    expect($campaign->reportType->fresh()->is_base)->toBeFalse();
});

test('base report table is branch scoped and supports status filtering and superior review', function () {
    [, $district, $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    $local = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => null, 'node_id' => $organization->id, 'status' => ReportStatus::InProgress]);
    $other = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => null, 'node_id' => $foreign->id]);
    $actor = User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    Livewire::test(BaseReports::class)->assertCanSeeTableRecords([$local])->assertCanNotSeeTableRecords([$other])
        ->filterTable('status', ReportStatus::New->value)->assertCanNotSeeTableRecords([$local])
        ->resetTableFilters()->callTableAction('accept', $local)->assertHasNoTableActionErrors();
    expect($local->fresh()->status)->toBe(ReportStatus::Accepted);
    Livewire::test(BaseReports::class)->callTableAction('rework', $local)->assertHasNoTableActionErrors();
    expect($local->fresh()->status)->toBe(ReportStatus::Rework);
    $this->actingAs(User::factory()->create(['node_id' => $organization->id, 'pd_consent_at' => now()]));
    Livewire::test(BaseReports::class)->assertCanSeeTableRecords([$local])->assertTableActionHidden('accept', $local);
});

test('base report table scope rechecks stale user branch and blocked status', function () {
    [, $district, $organization] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    $report = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => null, 'node_id' => $organization->id]);
    $actor = User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    $page = Livewire::test(BaseReports::class)->assertCanSeeTableRecords([$report]);
    User::query()->whereKey($actor)->update(['node_id' => null]);
    $page->call('$refresh')->assertForbidden();
    User::query()->whereKey($actor)->update(['blocked_at' => now()]);
    expect(BaseReports::canAccess())->toBeFalse();
});

test('new automatic base type immediately backfills existing organizations', function () {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->base()->create(['auto_create' => true]);

    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
});

test('manual assignment of all organizations is idempotent and rechecks automatic flag', function () {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    $actor = User::factory()->create(['is_admin' => true]);
    $service = app(BaseReportAssignment::class);
    $service->assign($type, null, $actor);
    $service->assign($type, null, $actor);
    expect(Report::query()->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
    ReportType::query()->whereKey($type)->update(['auto_create' => true]);
    expect(fn () => $service->assign($type, null, $actor))->toThrow(ValidationException::class);
});

test('type settings reject a stale administrators privileges and ignore code controlled fields', function () {
    $type = ReportType::factory()->create();
    $actor = User::factory()->create(['is_admin' => true]);
    $service = app(BaseReportAssignment::class);
    $service->updateSettings($type, ['key' => 'forged', 'kind' => 'complex', 'is_required' => true], $actor);
    expect($type->fresh()->key)->toBe($type->key);
    expect($type->fresh()->is_required)->toBeTrue();
    User::query()->whereKey($actor)->update(['is_admin' => false]);
    expect(fn () => $service->updateSettings($type, ['is_base' => true], $actor))->toThrow(AuthorizationException::class);
    expect($type->fresh()->is_base)->toBeFalse();
});

test('only admin manages registered type settings and can assign organizations in the resource', function () {
    [, , $organization] = baseReportNodes();
    $this->artisan('reports:sync')->assertSuccessful();
    $type = ReportType::query()->where('key', 'demo_simple')->firstOrFail();
    $actor = User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    Livewire::test(EditReportType::class, ['record' => $type->id])->fillForm(['is_base' => true, 'is_required' => true])->call('save')->assertHasNoFormErrors();
    Livewire::test(ListReportTypes::class)->callTableAction('assign', $type, data: ['organizations' => [$organization->id]])->assertHasNoTableActionErrors();
    expect(Report::query()->where('report_type_id', $type->id)->where('node_id', $organization->id)->exists())->toBeTrue();
    expect(Gate::forUser($actor)->allows('create', ReportType::class))->toBeFalse();
    $this->actingAs(User::factory()->create(['pd_consent_at' => now()]));
    $this->get(ReportTypeResource::getUrl())->assertForbidden();
});

test('an open type settings form cannot save after administrative rights are revoked', function () {
    $this->artisan('reports:sync')->assertSuccessful();
    $type = ReportType::query()->where('key', 'demo_simple')->firstOrFail();
    $actor = User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]);
    $this->actingAs($actor);
    $page = Livewire::test(EditReportType::class, ['record' => $type->id])->fillForm(['is_base' => true]);
    User::query()->whereKey($actor)->update(['is_admin' => false]);
    $page->call('save')->assertNotFound();
    expect($type->fresh()->is_base)->toBeFalse();
});

test('review actions cannot target a report outside the viewers branch', function () {
    [, $district, , , $foreign] = baseReportNodes();
    $type = ReportType::factory()->base()->create();
    $report = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => null, 'node_id' => $foreign->id, 'status' => ReportStatus::InProgress]);
    $this->actingAs(User::factory()->create(['node_id' => $district->id, 'pd_consent_at' => now()]));
    $page = Livewire::test(BaseReports::class);
    expect(fn () => $page->callTableAction('accept', $report))->toThrow(ActionNotResolvableException::class);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
});

test('failed automatic backfill rolls back type flag updates and partial assignments and allows retry', function (bool $initialBase) {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->create(['is_base' => $initialBase, 'auto_create' => ! $initialBase]);
    $this->mock(BaseReportAssignment::class)->shouldReceive('backfill')->once()
        ->andReturnUsing(function (ReportType $current) use ($organization): void {
            Report::factory()->create(['report_type_id' => $current->id, 'campaign_id' => null, 'node_id' => $organization->id]);
            throw new RuntimeException('Назначение прервано.');
        });
    $type->fill(['is_base' => true, 'auto_create' => true]);

    expect(fn () => $type->save())->toThrow(RuntimeException::class, 'Назначение прервано.');
    expect($type->fresh()->is_base)->toBe($initialBase);
    expect($type->fresh()->auto_create)->toBe(! $initialBase);
    expect(Report::query()->count())->toBe(0);

    $this->forgetMock(BaseReportAssignment::class);
    $type->save();
    expect($type->fresh()->is_base)->toBeTrue();
    expect($type->fresh()->auto_create)->toBeTrue();
    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
})->with([true, false]);

test('failed backfill rolls back a new type and allows saving the same model again', function () {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::factory()->base()->make(['auto_create' => true]);
    $this->mock(BaseReportAssignment::class)->shouldReceive('backfill')->once()
        ->andReturnUsing(function (ReportType $current) use ($organization): void {
            Report::factory()->create(['report_type_id' => $current->id, 'campaign_id' => null, 'node_id' => $organization->id]);
            throw new RuntimeException('Назначение прервано.');
        });

    expect(fn () => $type->save())->toThrow(RuntimeException::class, 'Назначение прервано.');
    expect(ReportType::query()->where('key', $type->key)->exists())->toBeFalse();
    expect(Report::query()->count())->toBe(0);
    expect($type->exists)->toBeFalse();

    $this->forgetMock(BaseReportAssignment::class);
    $type->save();
    expect($type->fresh()->auto_create)->toBeTrue();
    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
});

test('failed automatic assignment rolls back new organization and reports and allows retry', function () {
    $service = app(NodeService::class);
    $root = $service->create(null, NodeType::Authority, 'Регион');
    $type = ReportType::factory()->base()->create(['auto_create' => true]);
    $assignment = app(BaseReportAssignment::class);
    $this->mock(BaseReportAssignment::class)->shouldReceive('forNewNode')->once()
        ->andReturnUsing(function (Node $node) use ($assignment): void {
            $assignment->forNewNode($node);
            throw new RuntimeException('Назначение прервано.');
        });

    expect(fn () => $service->create($root, NodeType::Organization, 'Новая школа'))->toThrow(RuntimeException::class, 'Назначение прервано.');
    expect(Node::query()->where('name', 'Новая школа')->exists())->toBeFalse();
    expect(Report::query()->count())->toBe(0);
    expect($root->fresh())->not->toBeNull();

    $this->forgetMock(BaseReportAssignment::class);
    $organization = $service->create($root, NodeType::Organization, 'Новая школа');
    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id]);
});

test('automatic type creation uses database active default when caller omits optional flags', function () {
    [, , $organization, , $foreign] = baseReportNodes();
    $type = ReportType::query()->create(['key' => 'automatic_defaults', 'title' => 'Автоматический', 'kind' => ReportKind::Simple, 'is_base' => true, 'auto_create' => true]);
    expect(Report::query()->where('report_type_id', $type->id)->pluck('node_id')->all())->toBe([$organization->id, $foreign->id]);
});
