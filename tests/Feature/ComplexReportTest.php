<?php

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Services\ComplexReportStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/** @return array{Report, User} */
function complexDemoReport(): array
{
    test()->artisan('reports:sync')->assertSuccessful();
    $type = ReportType::query()->where('key', 'demo_complex')->sole();
    $campaign = Campaign::factory()->create(['report_type_id' => $type->id]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $type->id]);
    $user = User::factory()->create(['node_id' => $report->node_id, 'pd_consent_at' => now()]);

    return [$report, $user];
}

/** @return array{contact: array{full_name: string, position: string, phone: string, email: string}, events: list<array{title: string, date: string, participants: int}>} */
function complexDemoPayload(): array
{
    return [
        'contact' => ['full_name' => 'Иван Иванов', 'position' => 'Директор', 'phone' => '+7 900 123-45-67', 'email' => 'ivan@example.test'],
        'events' => [['title' => 'Конференция', 'date' => '2026-11-12', 'participants' => 0]],
    ];
}

test('complex report route requires authentication', function () {
    $this->get('/cabinet/complex/demo_complex/1')->assertRedirect('/cabinet/login');
    $this->post('/cabinet/complex/demo_complex/1', complexDemoPayload())->assertRedirect('/cabinet/login');
});

test('complex report saves both sections and replaces events with an empty list', function () {
    [$report, $user] = complexDemoReport();
    $url = route('demo-complex.show', $report);
    $this->actingAs($user)->get($url)->assertOk()->assertSee('Контактное лицо')->assertSee('Мероприятия');
    $this->post(route('demo-complex.save', $report), complexDemoPayload())->assertRedirect($url)->assertSessionHasNoErrors();
    $this->assertDatabaseHas('complex_demo_contacts', ['report_id' => $report->id, 'full_name' => 'Иван Иванов']);
    $this->assertDatabaseHas('complex_demo_events', ['report_id' => $report->id, 'title' => 'Конференция', 'participants' => 0]);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    $this->post(route('demo-complex.save', $report), [...complexDemoPayload(), 'events' => []])->assertRedirect($url)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('complex_demo_contacts', 1);
    $this->assertDatabaseCount('complex_demo_events', 0);
    $this->get($url)->assertOk()->assertSee('Иван Иванов');
});

test('complex report validation preserves both sections and status', function (string $field, mixed $value, ?string $errorField = null) {
    [$report, $user] = complexDemoReport();
    app(ComplexReportStore::class)->save($report, complexDemoPayload(), $user);
    $report->update(['status' => ReportStatus::Rework]);
    $payload = complexDemoPayload();
    $payload['contact']['full_name'] = 'Замена';
    data_set($payload, $field, $value);
    $this->actingAs($user)->post(route('demo-complex.save', $report), $payload)->assertInvalid([$errorField ?? $field]);
    $this->assertDatabaseHas('complex_demo_contacts', ['report_id' => $report->id, 'full_name' => 'Иван Иванов']);
    $this->assertDatabaseHas('complex_demo_events', ['report_id' => $report->id, 'title' => 'Конференция']);
    expect($report->fresh()->status)->toBe(ReportStatus::Rework);
})->with([
    'required full name' => ['contact.full_name', ''],
    'required position' => ['contact.position', ''],
    'required phone' => ['contact.phone', ''],
    'required email' => ['contact.email', ''],
    'invalid email' => ['contact.email', 'invalid'],
    'required title' => ['events.0.title', ''],
    'invalid date' => ['events.0.date', '2026-02-30'],
    'negative participants' => ['events.0.participants', -1],
    'fractional participants' => ['events.0.participants', 1.5],
    'unexpected contact key' => ['contact.report_id', 999, 'contact'],
    'unexpected event key' => ['events.0.report_id', 999, 'events.0'],
]);

test('invalid first complex save writes neither section nor status', function () {
    [$report, $user] = complexDemoReport();
    $this->actingAs($user)->post(route('demo-complex.save', $report), ['contact' => [], 'events' => []])
        ->assertInvalid([
            'contact.full_name' => 'Поле «ФИО» обязательно.',
            'contact.position' => 'Поле «Должность» обязательно.',
            'contact.phone' => 'Поле «Телефон» обязательно.',
            'contact.email' => 'Поле «Электронная почта» обязательно.',
        ]);
    $this->assertDatabaseCount('complex_demo_contacts', 0);
    $this->assertDatabaseCount('complex_demo_events', 0);
    expect($report->fresh()->status)->toBe(ReportStatus::New);
});

test('complex save rolls back both sections if status transition fails', function () {
    [$report, $user] = complexDemoReport();
    Report::updating(function (Report $current): void {
        if ($current->isDirty('status')) {
            throw new RuntimeException('Transition failed');
        }
    });
    try {
        expect(fn () => app(ComplexReportStore::class)->save($report, complexDemoPayload(), $user))->toThrow(RuntimeException::class, 'Transition failed');
        $this->assertDatabaseCount('complex_demo_contacts', 0);
        $this->assertDatabaseCount('complex_demo_events', 0);
        expect($report->fresh()->status)->toBe(ReportStatus::New);
    } finally {
        Report::flushEventListeners();
        Report::clearBootedModels();
    }
});

test('complex report hides foreign branches and mismatched report types', function () {
    [$report, $owner] = complexDemoReport();
    $foreign = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($foreign)->get(route('demo-complex.show', $report))->assertNotFound();
    $this->post(route('demo-complex.save', $report), complexDemoPayload())->assertNotFound();
    $simpleType = ReportType::query()->where('key', 'demo_simple')->sole();
    $report->campaign->update(['report_type_id' => $simpleType->id]);
    $report->update(['report_type_id' => $simpleType->id]);
    $this->actingAs($owner)->get(route('demo-complex.show', $report))->assertNotFound();
    $this->post(route('demo-complex.save', $report), complexDemoPayload())->assertNotFound();
    $this->assertDatabaseCount('complex_demo_contacts', 0);
});

test('complex save rechecks persisted access and status despite stale models', function (string $change) {
    [$report, $user] = complexDemoReport();
    $report->load('campaign', 'node');
    $user->load('node');
    match ($change) {
        'blocked user' => DB::table('users')->where('id', $user->id)->update(['blocked_at' => now()]),
        'closed campaign' => DB::table('campaigns')->where('id', $report->campaign_id)->update(['closed_at' => now()]),
        'changed node' => DB::table('users')->where('id', $user->id)->update(['node_id' => null]),
        'locked report' => DB::table('reports')->where('id', $report->id)->update(['status' => ReportStatus::Blocked->value]),
    };
    expect(fn () => app(ComplexReportStore::class)->save($report, complexDemoPayload(), $user))->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('complex_demo_contacts', 0);
    $this->assertDatabaseCount('complex_demo_events', 0);
})->with(['blocked user', 'closed campaign', 'changed node', 'locked report']);

test('complex report uses rework transition and remains available to its campaign', function () {
    [$report, $user] = complexDemoReport();
    $report->update(['status' => ReportStatus::Rework]);
    app(ComplexReportStore::class)->save($report, complexDemoPayload(), $user);
    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    expect($report->campaign->reports()->where('status', ReportStatus::InProgress)->count())->toBe(1);
});

test('complex form escapes stored text and hides save from read only users', function () {
    [$report, $user] = complexDemoReport();
    $payload = complexDemoPayload();
    $payload['contact']['full_name'] = '<script>alert("contact")</script>';
    $payload['events'][0]['title'] = '<script>alert("event")</script>';
    app(ComplexReportStore::class)->save($report, $payload, $user);
    $report->update(['status' => ReportStatus::Blocked]);
    $this->actingAs($user)->get(route('demo-complex.show', $report))->assertOk()
        ->assertSee($payload['contact']['full_name'])->assertSee($payload['events'][0]['title'])
        ->assertDontSee($payload['contact']['full_name'], false)->assertDontSee($payload['events'][0]['title'], false)
        ->assertDontSee('Сохранить');
    $this->post(route('demo-complex.save', $report), complexDemoPayload())->assertForbidden();
});

test('complex routes require completed onboarding', function () {
    [$report, $user] = complexDemoReport();
    $user->forceFill(['email_verified_at' => null])->save();
    $this->actingAs($user)->get(route('demo-complex.show', $report))->assertRedirect('/cabinet/onboarding');
    $this->post(route('demo-complex.save', $report), complexDemoPayload())->assertRedirect('/cabinet/onboarding');
    $this->assertDatabaseCount('complex_demo_contacts', 0);
});

test('complex form treats a submitted form without event inputs as an empty list', function () {
    [$report, $user] = complexDemoReport();
    app(ComplexReportStore::class)->save($report, complexDemoPayload(), $user);
    $this->actingAs($user)->post(route('demo-complex.save', $report), ['contact' => complexDemoPayload()['contact']])
        ->assertRedirect(route('demo-complex.show', $report))->assertSessionHasNoErrors();
    $this->assertDatabaseCount('complex_demo_contacts', 1);
    $this->assertDatabaseCount('complex_demo_events', 0);
});
