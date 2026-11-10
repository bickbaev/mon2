<?php

namespace App\Reports;

use InvalidArgumentException;

class ReportRegistry
{
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
