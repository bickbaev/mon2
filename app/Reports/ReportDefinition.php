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

    /** @return array<string, string> */
    public function validationMessages(): array
    {
        return [
            'required' => 'Заполните поле «:attribute».',
            'present' => 'Укажите поле «:attribute».',
            'numeric' => 'Поле «:attribute» должно содержать число.',
            'integer' => 'Поле «:attribute» должно содержать целое число.',
            'min' => 'Значение поля «:attribute» меньше допустимого минимума :min.',
            'max' => 'Значение поля «:attribute» превышает допустимый максимум :max.',
            'string' => 'Поле «:attribute» должно содержать текст.',
            'date_format' => 'Укажите корректную дату или время в поле «:attribute».',
            'exists' => 'Выберите действующий вариант в поле «:attribute».',
            'in' => 'Выберите допустимый вариант в поле «:attribute».',
            'file' => 'Загрузите файл в поле «:attribute».',
            'mimes' => 'Недопустимый тип файла. Разрешены: :values.',
            'array' => 'Поле «:attribute» должно содержать список.',
            'list' => 'Поле «:attribute» должно содержать последовательный список.',
            'distinct' => 'Поле «:attribute» содержит повторяющиеся значения.',
        ];
    }

    /** @return array<string, string> */
    public function validationAttributes(): array
    {
        $attributes = ['rows' => 'Записи'];
        foreach ($this->fields() as $field) {
            $attributes[$field->name] = $field->label;
            $attributes['rows.*.'.$field->name] = $field->label;
        }

        return $attributes;
    }

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
        return Validator::make($data, $context['rules'] ?? $this->validationRules(), $this->validationMessages(), $this->validationAttributes())->validate();
    }
}
