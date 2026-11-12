<?php

use App\Filament\Pages\ReportForm;
use App\Models\User;
use App\Services\ReportStore;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('owner opens and saves list report through generated form', function () {
    [$report, $user] = storedDemoReport();
    $this->actingAs($user)->get(ReportForm::getUrl(['report' => $report->id]))->assertOk()->assertSee('Добавить запись');
    Livewire::test(ReportForm::class, ['report' => $report->id])
        ->fillForm(['rows' => [['title' => 'Праздник', 'date' => '2026-11-12', 'participants' => 20]]])
        ->call('save')->assertHasNoFormErrors();
    $this->assertDatabaseHas('demo_event_rows', ['report_id' => $report->id, 'title' => 'Праздник']);
});

test('form rejects foreign report and rechecks privilege on save', function () {
    [$report, $user] = storedDemoReport();
    $foreign = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($foreign)->get(ReportForm::getUrl(['report' => $report->id]))->assertNotFound();
    $this->actingAs($user);
    $page = Livewire::test(ReportForm::class, ['report' => $report->id])->fillForm(['rows' => []]);
    $user->forceFill(['node_id' => null])->save();
    $page->call('save')->assertForbidden();
    $this->assertDatabaseCount('demo_event_rows', 0);
});

test('simple form stores uploaded file and can save again with retained file', function () {
    Storage::fake('reports');
    [$report, $user] = storedDemoReport('demo_simple');
    $payload = simpleDemoPayload();
    $this->actingAs($user);
    Livewire::test(ReportForm::class, ['report' => $report->id])
        ->fillForm([...$payload, 'attachment' => UploadedFile::fake()->create('one.pdf', 1, 'application/pdf')])
        ->call('save')->assertHasNoFormErrors();
    $loaded = app(ReportStore::class)->load($report);
    Storage::disk('reports')->assertExists($loaded['attachment']);
    Livewire::test(ReportForm::class, ['report' => $report->id])->call('save')->assertHasNoFormErrors();
});

test('required field errors in simple form have readable russian labels', function () {
    [$report, $user] = storedDemoReport('demo_simple');
    $this->actingAs($user);
    Livewire::test(ReportForm::class, ['report' => $report->id])->fillForm(['description' => ''])->call('save')
        ->assertHasFormErrors(['description' => 'required'])->assertSee('Заполните поле');
});
