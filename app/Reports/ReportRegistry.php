<?php

namespace App\Reports;

use App\Models\ReportType;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class ReportRegistry
{
    public function snapshot(ReportDefinition $definition): array
    {
        $fields = [];
        foreach ($definition->fields() as $field) {
            $fields[$field->name] = ['type' => $field->type->value, 'required' => $field->isRequired, 'multiple' => $field->isMultiple];
        }

        return ['table' => $definition->table(), 'kind' => $definition->kind()->value, 'fields' => $fields];
    }

    public function assertSafeChange(ReportType $type, ReportDefinition $definition): void
    {
        $previous = json_decode($type->getRawOriginal('definition_snapshot') ?? 'null', true);
        if ($previous === null || ! Schema::hasTable($previous['table'])) {
            return;
        }
        $hasData = DB::table($previous['table'])->whereIn('report_id', DB::table('reports')->select('id')->where('report_type_id', $type->id))->exists();
        if (! $hasData) {
            return;
        }
        $next = $this->snapshot($definition);
        if ($previous['table'] !== $next['table'] || $previous['kind'] !== $next['kind']) {
            throw new DomainException('Нельзя менять таблицу или тип отчёта с сохранёнными данными.');
        }
        foreach ($previous['fields'] as $name => $field) {
            if (! isset($next['fields'][$name]) || $next['fields'][$name]['type'] !== $field['type'] || (! $field['required'] && $next['fields'][$name]['required']) || ($next['fields'][$name]['multiple'] !== ($field['multiple'] ?? false))) {
                throw new DomainException('Нельзя удалять поля, менять их тип или делать существующие поля обязательными после сохранения данных.');
            }
        }
    }

    /** @return array<string, ReportDefinition> */
    public function all(): array
    {
        $definitions = [];
        foreach (config('reports.definitions', []) as $class) {
            if (! is_string($class) || ! is_subclass_of($class, ReportDefinition::class)) {
                throw new InvalidArgumentException('Класс должен наследовать ReportDefinition.');
            }
            $definition = app($class);
            $key = $definition->key();
            if ($key === '' || isset($definitions[$key])) {
                throw new InvalidArgumentException('Пустой или повторяющийся ключ вида отчёта: '.$key);
            }
            $definition->validationRules();
            $definitions[$key] = $definition;
        }

        return $definitions;
    }

    public function get(string $key): ReportDefinition
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException('Неизвестный вид отчёта: '.$key);
    }
}
