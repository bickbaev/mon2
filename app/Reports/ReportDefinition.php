<?php

namespace App\Reports;

use App\Enums\ReportKind;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

abstract class ReportDefinition
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function kind(): ReportKind;

    abstract public function table(): string;

    /** @return list<Field> */
    abstract public function fields(): array;

    /** @return array<string, list<mixed>> */
    public function validationRules(): array
    {
        $rules = [];
        foreach ($this->fields() as $field) {
            if (! $field instanceof Field || isset($rules[$field->name])) {
                throw new InvalidArgumentException('Некорректное или повторяющееся поле отчёта.');
            }
            $rules = [...$rules, ...$field->toValidationRules()];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function validate(array $data, array $context = []): array
    {
        return Validator::make($data, $this->validationRules())->validate();
    }
}
