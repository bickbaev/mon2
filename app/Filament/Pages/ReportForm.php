<?php

namespace App\Filament\Pages;

use App\Enums\FieldType;
use App\Enums\ReportKind;
use App\Models\Report;
use App\Reports\ReportFormSchema;
use App\Reports\ReportRegistry;
use App\Services\ReportStore;
use Filament\Forms\Components\Repeater;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ReportForm extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'reports/{report}';

    protected string $view = 'filament.pages.report-form';

    #[Locked]
    public int $reportId;

    public ?array $data = [];

    public function mount(int $report): void
    {
        $this->reportId = $report;
        $record = $this->record();
        $definition = app(ReportRegistry::class)->get($record->reportType->key);
        if ($definition->kind() === ReportKind::Complex) {
            $this->redirect($definition->url($record));

            return;
        }
        $this->form->fill(app(ReportStore::class)->load($record));
    }

    public function form(Schema $schema): Schema
    {
        $record = $this->record();
        $definition = app(ReportRegistry::class)->get($record->reportType->key);
        $components = app(ReportFormSchema::class)->fields($definition);
        if ($definition->kind() === ReportKind::List) {
            $components = [Repeater::make('rows')->label('Записи')->schema($components)->defaultItems(0)
                ->addActionLabel('Добавить запись')->maxItems(1000)->minItems($record->reportType->list_allows_empty ? 0 : 1)];
        }

        return $schema->components($components)->statePath('data')->disabled(! $this->canEdit());
    }

    public function save(): void
    {
        $record = Report::query()->findOrFail($this->reportId);
        Gate::forUser(auth()->user()->fresh())->authorize('update', $record);
        $data = $this->form->getState();
        try {
            app(ReportStore::class)->save($record, $data, auth()->user()->fresh());
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
        $this->form->fill(app(ReportStore::class)->load($record));
        Notification::make()->title('Отчёт сохранён')->success()->send();
    }

    public function record(): Report
    {
        $record = Report::query()->with(['reportType', 'node', 'campaign'])->findOrFail($this->reportId);
        abort_unless(Gate::forUser(auth()->user()->fresh())->allows('view', $record), 404);

        return $record;
    }

    public function canEdit(): bool
    {
        return Gate::forUser(auth()->user()->fresh())->allows('update', $this->record());
    }

    public function getTitle(): string
    {
        return $this->record()->reportType->title;
    }

    public function getSubheading(): string
    {
        $report = $this->record();

        return $report->node->name.' · '.$report->status->label();
    }

    public function downloads(): array
    {
        $report = $this->record();
        $definition = app(ReportRegistry::class)->get($report->reportType->key);
        $links = [];
        foreach (app(ReportStore::class)->raw($report, $definition) as $rowIndex => $row) {
            foreach ($definition->fields() as $field) {
                if ($field->type !== FieldType::File || empty($row[$field->name])) {
                    continue;
                }
                $metadata = json_decode($row[$field->name], true);
                foreach ($field->isMultiple ? $metadata : [$metadata] as $index => $file) {
                    $links[] = ['name' => $file['name'], 'url' => route('reports.files.download', ['report' => $report, 'field' => $field->name, 'row' => $rowIndex, 'index' => $index])];
                }
            }
        }

        return $links;
    }
}
