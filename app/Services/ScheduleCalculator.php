<?php

namespace App\Services;

use App\Enums\Periodicity;
use App\Models\CampaignTemplate;
use Carbon\CarbonImmutable;

class ScheduleCalculator
{
    public function nextStart(CampaignTemplate $template, CarbonImmutable $after): CarbonImmutable
    {
        $after = $after->startOfDay();
        if ($template->periodicity === Periodicity::Weekly) {
            $days = ($template->start_weekday - $after->dayOfWeekIso + 7) % 7;

            return $after->addDays($days === 0 ? 7 : $days);
        }
        if ($template->periodicity === Periodicity::Monthly) {
            $month = $after->startOfMonth();
            $candidate = $month->day(min($template->start_day, $month->daysInMonth));
            if ($candidate->lessThanOrEqualTo($after)) {
                $month = $month->addMonth();
                $candidate = $month->day(min($template->start_day, $month->daysInMonth));
            }

            return $candidate;
        }
        $month = $after->startOfYear()->month($template->start_month);
        $candidate = $month->day(min($template->start_day, $month->daysInMonth));
        if ($candidate->lessThanOrEqualTo($after)) {
            $month = $month->addYear();
            $candidate = $month->day(min($template->start_day, $month->daysInMonth));
        }

        return $candidate;
    }
}
