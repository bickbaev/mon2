<?php

namespace App\Reports;

use App\Enums\FieldType;
use App\Models\Dictionary;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;

class ReportFormSchema
{
    /** @return array<\Filament\Forms\Components\Field> */
    public function fields(ReportDefinition $definition): array
    {
        $components = [];
        foreach ($definition->fields() as $field) {
            $component = match ($field->type) {
                FieldType::Number => TextInput::make($field->name)->numeric(),
                FieldType::Text => $field->isMultiline ? Textarea::make($field->name) : TextInput::make($field->name),
                FieldType::Radio => Radio::make($field->name)->options($this->options($field)),
                FieldType::Select => Select::make($field->name)->options($this->options($field))->searchable(),
                FieldType::Multiselect => Select::make($field->name)->multiple()->options($this->options($field))->searchable(),
                FieldType::Checkbox => Checkbox::make($field->name),
                FieldType::Date => DatePicker::make($field->name)->native()->format('Y-m-d'),
                FieldType::Time => TimePicker::make($field->name)->seconds(false)->format('H:i'),
                FieldType::Datetime => DateTimePicker::make($field->name)->format('Y-m-d H:i:s'),
                FieldType::File => FileUpload::make($field->name)->disk('reports')->visibility('private')
                    ->multiple($field->isMultiple)->storeFiles(false)->maxSize($field->maxKb)
                    ->previewable(false)->fetchFileInformation(false),
            };
            $component->label($field->label)->required($field->isRequired)->validationMessages($definition->validationMessages());
            if ($field->type !== FieldType::File) {
                $component->rules($field->toRules());
            }
            $components[] = $component;
        }

        return $components;
    }

    private function options(Field $field): array
    {
        if ($field->dictionaryKey === null) {
            return $field->choices ?? [];
        }

        return Dictionary::query()->where('key', $field->dictionaryKey)->first()?->activeItems()->pluck('label', 'id')->all() ?? [];
    }
}
