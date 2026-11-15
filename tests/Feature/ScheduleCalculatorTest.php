<?php

use App\Models\CampaignTemplate;
use App\Services\ScheduleCalculator;
use Carbon\CarbonImmutable;

test('next start follows weekly monthly and yearly calendars strictly after given day', function (array $settings, string $after, string $expected) {
    $template = new CampaignTemplate;
    $template->forceFill($settings);
    expect(app(ScheduleCalculator::class)->nextStart($template, CarbonImmutable::parse($after))->toDateString())->toBe($expected);
})->with([
    [['periodicity' => 'weekly', 'start_weekday' => 1], '2026-11-15', '2026-11-16'],
    [['periodicity' => 'weekly', 'start_weekday' => 1], '2026-11-16', '2026-11-23'],
    [['periodicity' => 'monthly', 'start_day' => 31], '2026-02-01', '2026-02-28'],
    [['periodicity' => 'monthly', 'start_day' => 31], '2026-02-28', '2026-03-31'],
    [['periodicity' => 'monthly', 'start_day' => 31], '2026-12-31', '2027-01-31'],
    [['periodicity' => 'yearly', 'start_day' => 29, 'start_month' => 2], '2027-01-01', '2027-02-28'],
    [['periodicity' => 'yearly', 'start_day' => 29, 'start_month' => 2], '2028-01-01', '2028-02-29'],
    [['periodicity' => 'yearly', 'start_day' => 29, 'start_month' => 2], '2028-02-29', '2029-02-28'],
]);
