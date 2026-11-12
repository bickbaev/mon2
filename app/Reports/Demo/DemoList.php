<?php

namespace App\Reports\Demo;

use App\Enums\ReportKind;
use App\Reports\Field;
use App\Reports\ReportDefinition;

class DemoList extends ReportDefinition
{
    public function key(): string
    {
        return 'demo_list';
    }

    public function title(): string
    {
        return 'Демонстрационный: мероприятия';
    }

    public function kind(): ReportKind
    {
        return ReportKind::List;
    }

    public function table(): string
    {
        return 'demo_event_rows';
    }

    public function fields(): array
    {
        return [
            Field::text('title', 'Название мероприятия')->required()->maxLength(255),
            Field::date('date', 'Дата')->required(),
            Field::number('participants', 'Количество участников')->required()->min(0)->max(4294967295)->rules(['integer']),
        ];
    }
}
