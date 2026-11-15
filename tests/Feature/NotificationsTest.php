<?php

use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\User;
use App\Notifications\DeadlineReminderNotification;
use App\Notifications\NewReportNotification;
use App\Notifications\ReportReturnedNotification;
use App\Services\CampaignService;
use App\Services\DeadlineReminders;
use App\Services\NodeSelection;
use App\Services\ReportRecipients;
use App\Services\ReportStatusService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

test('report recipients include only verified unblocked users of the assigned node', function () {
    [, , $district, $school] = campaignContext();
    $eligible = User::factory()->count(2)->create(['node_id' => $school->id]);
    User::factory()->unverified()->create(['node_id' => $school->id]);
    User::factory()->create(['node_id' => $school->id, 'blocked_at' => now()]);
    User::factory()->create(['node_id' => $school->id, 'email' => null]);
    User::factory()->create(['node_id' => $district->id]);

    expect(app(ReportRecipients::class)->for($school)->modelKeys())->toBe($eligible->modelKeys());
});

test('starting a collection notifies all eligible users and adding the same participant does not duplicate mail', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [$actor, , , $school, $branch] = campaignContext();
    $recipients = User::factory()->count(2)->create(['node_id' => $school->id]);
    $unverified = User::factory()->unverified()->create(['node_id' => $school->id]);
    $blocked = User::factory()->create(['node_id' => $school->id, 'blocked_at' => now()]);
    $branchUser = User::factory()->create(['node_id' => $branch->id]);
    Notification::fake();
    $service = app(CampaignService::class);

    $campaign = $service->start($actor, campaignData(), new NodeSelection([$school->id]));
    $service->addNode($actor, $campaign, $branch);
    $service->addNode($actor, $campaign, $branch);

    foreach ($recipients as $recipient) {
        Notification::assertSentTo($recipient, NewReportNotification::class, fn ($notification, $channels): bool => $notification->reportId === $campaign->reports()->where('node_id', $school->id)->value('id') && $channels === ['mail']);
    }
    Notification::assertNotSentTo([$unverified, $blocked], NewReportNotification::class);
    Notification::assertSentToTimes($branchUser, NewReportNotification::class, 1);
    Notification::assertCount(3);
});

test('a collection without eligible recipients still starts and creates all reports', function () {
    [$actor, , , $school] = campaignContext();
    Notification::fake();
    $campaign = app(CampaignService::class)->start($actor, campaignData(), new NodeSelection([$school->id]));

    expect($campaign->reports()->count())->toBe(1);
    Notification::assertNothingSent();
});

test('template launches send the same new report notification once', function () {
    $this->travelTo(now()->setDate(2026, 11, 16));
    [$actor, , $district, $school] = campaignContext();
    $recipient = User::factory()->create(['node_id' => $school->id]);
    $template = CampaignTemplate::factory()->create(['creator_id' => $actor->id, 'creator_node_id' => $district->id]);
    $template->nodes()->attach($school);
    Notification::fake();

    $this->artisan('campaigns:spawn')->assertSuccessful();
    $this->artisan('campaigns:spawn')->assertSuccessful();

    Notification::assertSentToTimes($recipient, NewReportNotification::class, 1);
});

test('rollback sends no new report mail and discards queued assignments', function () {
    [$actor, , , $school] = campaignContext();
    User::factory()->create(['node_id' => $school->id]);
    Notification::fake();
    $data = campaignData();

    expect(fn () => DB::transaction(function () use ($actor, $school, $data): void {
        app(CampaignService::class)->start($actor, $data, new NodeSelection([$school->id]));
        Notification::assertNothingSent();
        throw new RuntimeException('Откат');
    }))->toThrow(RuntimeException::class, 'Откат');

    expect(Campaign::count())->toBe(0);
    Notification::assertNothingSent();
});

test('rework notifies the owner node for campaign and base reports but accepting sends nothing', function (bool $base) {
    [, $root, , $school] = campaignContext();
    $type = ReportType::factory()->create(['is_base' => $base]);
    $campaign = $base ? null : Campaign::factory()->create(['report_type_id' => $type->id]);
    $report = Report::factory()->create(['report_type_id' => $type->id, 'campaign_id' => $campaign?->id, 'node_id' => $school->id, 'status' => ReportStatus::InProgress]);
    $owner = User::factory()->create(['node_id' => $school->id]);
    $reviewer = User::factory()->create(['node_id' => $root->id]);
    Notification::fake();

    app(ReportStatusService::class)->transition($report, ReportStatus::Accepted, $reviewer);
    Notification::assertNothingSent();
    if ($campaign !== null) {
        $campaign->update(['creator_id' => $reviewer->id]);
    }
    app(ReportStatusService::class)->transition($report, ReportStatus::Rework, $reviewer);

    Notification::assertSentToTimes($owner, ReportReturnedNotification::class, 1);
})->with([true, false]);

test('reminders only target new open reports on the exact configured day and run once', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [, , , $school, $branch] = campaignContext();
    $recipient = User::factory()->create(['node_id' => $school->id]);
    User::factory()->create(['node_id' => $branch->id]);
    $due = Campaign::factory()->create(['ends_at' => '2026-11-18', 'closes_at' => '2026-11-20', 'remind_days_before' => 3]);
    $new = Report::factory()->create(['campaign_id' => $due->id, 'report_type_id' => $due->report_type_id, 'node_id' => $school->id, 'status' => ReportStatus::New]);
    Report::factory()->create(['campaign_id' => $due->id, 'report_type_id' => $due->report_type_id, 'node_id' => $branch->id, 'status' => ReportStatus::InProgress]);
    foreach ([
        ['ends_at' => '2026-11-17'],
        ['ends_at' => '2026-11-19'],
        ['ends_at' => '2026-11-18', 'closed_at' => now()],
        ['ends_at' => '2026-11-15', 'closes_at' => '2026-11-15', 'remind_days_before' => 0],
        ['ends_at' => '2026-11-18', 'remind_days_before' => null],
    ] as $settings) {
        $campaign = Campaign::factory()->create(array_replace(['remind_days_before' => 3], $settings));
        Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'node_id' => $school->id]);
    }
    Notification::fake();

    $this->artisan('campaigns:remind')->assertSuccessful();
    $this->artisan('campaigns:remind')->assertSuccessful();

    Notification::assertSentTo($recipient, DeadlineReminderNotification::class, fn ($notification): bool => $notification->reportId === $new->id);
    Notification::assertCount(1);
    expect($new->fresh()->deadline_reminded_at)->not->toBeNull();
});

test('notifications are queued and configured channels can be disabled', function () {
    [$actor, , , $school] = campaignContext();
    $recipient = User::factory()->create(['node_id' => $school->id]);
    Queue::fake([SendQueuedNotifications::class]);

    app(CampaignService::class)->start($actor, campaignData(), new NodeSelection([$school->id]));

    Queue::assertPushed(SendQueuedNotifications::class, fn ($job): bool => $job->notification instanceof NewReportNotification && $job->notification->afterCommit === true);
    config(['report-notifications.channels' => []]);
    $notification = new NewReportNotification(Report::firstOrFail());
    expect($notification->via($recipient))->toBe([]);
});

test('queued new report mail rechecks current recipient and report eligibility', function (string $change, int $messages) {
    [$actor, , , $school, , $foreign] = campaignContext();
    $recipient = User::factory()->create(['node_id' => $school->id]);
    Queue::fake([SendQueuedNotifications::class]);
    $campaign = app(CampaignService::class)->start($actor, campaignData(), new NodeSelection([$school->id]));
    $payload = serialize(Queue::pushed(SendQueuedNotifications::class)->first());
    match ($change) {
        'blocked' => $recipient->forceFill(['blocked_at' => now()])->save(),
        'unverified' => $recipient->forceFill(['email_verified_at' => null])->save(),
        'moved' => $recipient->forceFill(['node_id' => $foreign->id])->save(),
        'closed' => $campaign->update(['closed_at' => now()]),
        'deleted' => $campaign->reports()->delete(),
        default => null,
    };

    unserialize($payload)->handle(app(ChannelManager::class));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount($messages);
})->with([
    ['blocked', 0], ['unverified', 0], ['moved', 0], ['closed', 0], ['deleted', 0], ['unchanged', 1],
]);

test('queued rework and deadline mail skip obsolete status or deadline', function (string $kind) {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [, , , $school] = campaignContext();
    $recipient = User::factory()->create(['node_id' => $school->id]);
    $campaign = Campaign::factory()->create(['ends_at' => '2026-11-18', 'remind_days_before' => 3]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'node_id' => $school->id, 'status' => $kind === 'rework' ? ReportStatus::Rework : ReportStatus::New]);
    Queue::fake([SendQueuedNotifications::class]);
    $recipient->notify($kind === 'rework' ? new ReportReturnedNotification($report) : new DeadlineReminderNotification($report));
    $payload = serialize(Queue::pushed(SendQueuedNotifications::class)->first());
    if ($kind === 'deadline') {
        $campaign->update(['ends_at' => '2026-11-19']);
    } else {
        $report->update(['status' => ReportStatus::InProgress]);
    }

    unserialize($payload)->handle(app(ChannelManager::class));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
})->with(['rework', 'deadline']);

test('mail contains report title period and a usable report link with escaped user text', function () {
    $report = Report::factory()->create();
    $report->reportType->update(['title' => 'Отчёт <script>alert(1)</script>']);
    $recipient = User::factory()->create(['node_id' => $report->node_id]);
    $notification = new NewReportNotification($report);

    $mail = $notification->toMail($recipient);
    $html = (string) $mail->render();

    expect($html)->toContain('Отчёт')->not->toContain('<script>alert(1)</script>');
    expect($mail->actionUrl)->toContain('/cabinet/reports/'.$report->id);
    expect($html)->toContain($report->campaign->ends_at->format('d.m.Y'));
});

test('database reminder jobs stay invisible to workers before commit and rollback leaves the report retryable', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [, , , $school] = campaignContext();
    User::factory()->create(['node_id' => $school->id]);
    $campaign = Campaign::factory()->create(['ends_at' => '2026-11-18', 'remind_days_before' => 3]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'node_id' => $school->id]);
    config(['queue.default' => 'database']);
    config(['database.connections.reminder_observer' => config('database.connections.'.config('database.default'))]);
    $reminders = app(DeadlineReminders::class);

    expect(fn () => DB::transaction(function () use ($reminders, $campaign): void {
        expect($reminders->sendForCampaign($campaign))->toBe(1);
        expect(DB::table('jobs')->count())->toBe(1);
        expect(DB::connection('reminder_observer')->table('jobs')->count())->toBe(0);
        throw new RuntimeException('Откат напоминания');
    }))->toThrow(RuntimeException::class, 'Откат напоминания');

    expect($report->fresh()->deadline_reminded_at)->toBeNull();
    expect(DB::table('jobs')->count())->toBe(0);
    DB::transaction(function () use ($reminders, $campaign): void {
        expect($reminders->sendForCampaign($campaign))->toBe(1);
        expect(DB::table('jobs')->count())->toBe(1);
        expect(DB::connection('reminder_observer')->table('jobs')->count())->toBe(0);
    });
    expect(DB::table('jobs')->count())->toBe(1);
    expect($report->fresh()->deadline_reminded_at)->not->toBeNull();
    expect($reminders->sendForCampaign($campaign))->toBe(0);
    expect(DB::table('jobs')->count())->toBe(1);
    DB::purge('reminder_observer');
});

test('rework rolled back inside an outer transaction sends no mail', function () {
    [, $root, , $school] = campaignContext();
    $report = Report::factory()->create(['node_id' => $school->id, 'status' => ReportStatus::InProgress]);
    User::factory()->create(['node_id' => $school->id]);
    $reviewer = User::factory()->create(['node_id' => $root->id]);
    Notification::fake();

    expect(fn () => DB::transaction(function () use ($report, $reviewer): void {
        app(ReportStatusService::class)->transition($report, ReportStatus::Rework, $reviewer);
        throw new RuntimeException('Откат доработки');
    }))->toThrow(RuntimeException::class, 'Откат доработки');

    expect($report->fresh()->status)->toBe(ReportStatus::InProgress);
    Notification::assertNothingSent();
});

test('deadline mail rechecks status date and channels while waiting in the queue', function (string $change, int $expectedMessages) {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [, , , $school] = campaignContext();
    $recipient = User::factory()->create(['node_id' => $school->id]);
    $campaign = Campaign::factory()->create(['ends_at' => '2026-11-18', 'remind_days_before' => 3]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'node_id' => $school->id]);
    Queue::fake([SendQueuedNotifications::class]);
    $recipient->notify(new DeadlineReminderNotification($report));
    $payload = serialize(Queue::pushed(SendQueuedNotifications::class)->first());
    match ($change) {
        'status' => $report->update(['status' => ReportStatus::InProgress]),
        'day' => $this->travel(1)->days(),
        'setting' => $campaign->update(['remind_days_before' => null]),
        'channel' => config(['report-notifications.channels' => []]),
        default => null,
    };

    unserialize($payload)->handle(app(ChannelManager::class));

    expect(Mail::mailer('array')->getSymfonyTransport()->messages())->toHaveCount($expectedMessages);
})->with([['status', 0], ['day', 0], ['setting', 0], ['channel', 0], ['unchanged', 1]]);

test('failed enqueue rolls back reminder marker and all recipients jobs so retry is safe', function () {
    $this->travelTo(now()->setDate(2026, 11, 15));
    [, , , $school] = campaignContext();
    User::factory()->count(2)->create(['node_id' => $school->id]);
    $campaign = Campaign::factory()->create(['ends_at' => '2026-11-18', 'remind_days_before' => 3]);
    $report = Report::factory()->create(['campaign_id' => $campaign->id, 'report_type_id' => $campaign->report_type_id, 'node_id' => $school->id]);
    config(['queue.default' => 'database']);
    $attempts = 0;
    Event::listen(JobQueueing::class, function ($event) use (&$attempts): void {
        if ($event->job instanceof SendQueuedNotifications && $event->job->notification instanceof DeadlineReminderNotification && ++$attempts === 2) {
            throw new RuntimeException('Очередь недоступна');
        }
    });
    $reminders = app(DeadlineReminders::class);

    expect(fn () => $reminders->sendForCampaign($campaign))->toThrow(RuntimeException::class, 'Очередь недоступна');

    expect($report->fresh()->deadline_reminded_at)->toBeNull();
    expect(DB::table('jobs')->count())->toBe(0);
    expect($reminders->sendForCampaign($campaign))->toBe(1);
    expect(DB::table('jobs')->count())->toBe(2);
});
