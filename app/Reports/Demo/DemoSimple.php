<?php

namespace App\Reports\Demo;

use App\Enums\ReportKind;
use App\Reports\Field;
use App\Reports\ReportDefinition;

class DemoSimple extends ReportDefinition
{
    public function key(): string
    {
        return 'demo_simple';
    }

    public function title(): string
    {
        return 'Демонстрационный: сведения об организации';
    }

    public function kind(): ReportKind
    {
        return ReportKind::Simple;
    }

    public function table(): string
    {
        return 'demo_simple_data';
    }

    public function fields(): array
    {
        return [
            Field::number('count', 'Количество обучающихся')->required()->min(0)->max(4294967295)->rules(['integer']),
            Field::text('description', 'Описание')->required()->maxLength(2000)->multiline(),
            Field::radio('organization_type', 'Тип организации')->required()->dictionary('organization_types'),
            Field::date('date', 'Дата сведений')->required(),
            Field::file('attachment', 'Приложение')->files(['pdf', 'jpg', 'png'], 5120),
        ];
    }
}
