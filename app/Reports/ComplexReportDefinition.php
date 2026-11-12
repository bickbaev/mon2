<?php

namespace App\Reports;

use App\Enums\ReportKind;
use App\Models\Report;

abstract class ComplexReportDefinition extends ReportDefinition
{
    final public function kind(): ReportKind
    {
        return ReportKind::Complex;
    }

    /** @return list<Field> */
    final public function fields(): array
    {
        return [];
    }

    abstract public function routes(): void;

    public function url(Report $report): string
    {
        return url('/cabinet/complex/'.$this->key().'/'.$report->id);
    }
}
