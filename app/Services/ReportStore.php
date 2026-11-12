<?php

namespace App\Services;

use App\Enums\FieldType;
use App\Enums\ReportKind;
use App\Enums\ReportStatus;
use App\Models\Campaign;
use App\Models\Node;
use App\Models\Report;
use App\Models\User;
use App\Reports\ReportDefinition;
use App\Reports\ReportRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ReportStore
{
    public function __construct(private ReportRegistry $registry, private ReportFiles $files) {}

    /** @param array<string, mixed> $data */
    public function save(Report $report, array $data, User $actor): void
    {
        $created = [];
        try {
            DB::transaction(function () use ($report, $data, $actor, &$created): void {
                $campaignId = Report::query()->whereKey($report->id)->value('campaign_id');
                $campaign = $campaignId === null ? null : Campaign::query()->lockForUpdate()->findOrFail($campaignId);
                $current = Report::query()->lockForUpdate()->findOrFail($report->id);
                abort_unless($current->campaign_id === $campaignId, 409);
                $current->setRelation('campaign', $campaign);
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                $nodes = Node::query()->whereIn('id', array_filter([$user->node_id, $current->node_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $user->setRelation('node', $nodes->get($user->node_id));
                $current->setRelation('node', $nodes->get($current->node_id));
                Gate::forUser($user)->authorize('update', $current);
                $type = $current->reportType()->lockForUpdate()->firstOrFail();
                $definition = $this->registry->get($type->key);
                if ($definition->kind() === ReportKind::Complex) {
                    throw ValidationException::withMessages(['report' => 'Используйте специальную форму отчёта.']);
                }
                $previous = $this->raw($current, $definition);
                if ($definition->kind() === ReportKind::List) {
                    $rules = ['rows' => ['present', 'array', 'list', 'max:1000', 'min:'.($type->list_allows_empty ? 0 : 1)]];
                    $rules['rows.*'] = ['array'];
                    $validated = Validator::make($data, $rules, $definition->validationMessages(), $definition->validationAttributes())->validate();
                    $rows = [];
                    foreach ($validated['rows'] as $index => $row) {
                        $old = $this->trustedPrevious($definition, $row, $previous);
                        try {
                            $values = $definition->validate($row, ['rules' => $this->fileValidationRules($definition, $row, $old), 'report' => $current, 'actor' => $user]);
                        } catch (ValidationException $exception) {
                            $errors = [];
                            foreach ($exception->errors() as $key => $messages) {
                                $errors['rows.'.$index.'.'.$key] = $messages;
                            }
                            throw ValidationException::withMessages($errors);
                        }
                        $rows[] = $this->serializeRow($definition, $values, $old, $current->id, $created);
                    }
                } else {
                    $rules = $this->fileValidationRules($definition, $data, $previous[0] ?? []);
                    $validated = $definition->validate($data, ['rules' => $rules, 'report' => $current, 'actor' => $user]);
                    $rows = [$this->serializeRow($definition, $validated, $previous[0] ?? [], $current->id, $created)];
                }
                DB::table($definition->table())->where('report_id', $current->id)->delete();
                foreach ($rows as $row) {
                    DB::table($definition->table())->insert(['report_id' => $current->id, ...$row]);
                }
                if (in_array($current->status, [ReportStatus::New, ReportStatus::Rework], true)) {
                    app(ReportStatusService::class)->transition($current, ReportStatus::InProgress, $user);
                }
            });
        } catch (Throwable $exception) {
            $this->files->deleteCreated($created);
            throw $exception;
        }
        $report->refresh();
    }

    /** @return array<string, mixed> */
    public function load(Report $report): array
    {
        $definition = $this->registry->get($report->reportType->key);
        $rows = $this->raw($report, $definition);
        foreach ($rows as &$row) {
            foreach ($definition->fields() as $field) {
                if ($field->type === FieldType::File && isset($row[$field->name])) {
                    $metadata = json_decode($row[$field->name], true);
                    $row[$field->name] = $field->isMultiple ? array_column($metadata, 'path') : ($metadata['path'] ?? null);
                } elseif ($field->type === FieldType::Multiselect && isset($row[$field->name])) {
                    $row[$field->name] = json_decode($row[$field->name], true);
                } elseif ($field->type === FieldType::Checkbox && isset($row[$field->name])) {
                    $row[$field->name] = (bool) $row[$field->name];
                }
            }
            unset($row['id'], $row['report_id']);
        }

        return $definition->kind() === ReportKind::List ? ['rows' => $rows] : ($rows[0] ?? []);
    }

    /** @return list<array<string, mixed>> */
    public function raw(Report $report, ReportDefinition $definition): array
    {
        return DB::table($definition->table())->where('report_id', $report->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
    }

    private function fileValidationRules(ReportDefinition $definition, array $data, array $previous): array
    {
        $rules = $definition->validationRules();
        foreach ($definition->fields() as $field) {
            if ($field->type !== FieldType::File) {
                continue;
            }
            $metadata = json_decode($previous[$field->name] ?? 'null', true);
            $value = $data[$field->name] ?? null;
            if ($field->isMultiple && is_array($value)) {
                $fileRules = $rules[$field->name.'.*'];
                unset($rules[$field->name.'.*']);
                foreach ($value as $index => $upload) {
                    $rules[$field->name.'.'.$index] = $fileRules;
                    if (is_string($upload)) {
                        if (! in_array($upload, array_column($metadata ?? [], 'path'), true)) {
                            throw ValidationException::withMessages([$field->name.'.'.$index => 'Недопустимый файл.']);
                        }
                        $rules[$field->name.'.'.$index] = ['required', 'string', 'distinct'];
                    }
                }
            }
            if (! $field->isMultiple && is_string($value) && $value !== '') {
                if (! is_array($metadata) || ($metadata['path'] ?? null) !== $value) {
                    throw ValidationException::withMessages([$field->name => 'Недопустимый файл.']);
                }
                $rules[$field->name] = [$field->isRequired ? 'required' : 'nullable', 'string'];
            }
        }

        return $rules;
    }

    /** @param list<array<string, mixed>> $previous */
    private function trustedPrevious(ReportDefinition $definition, array $data, array $previous): array
    {
        $result = [];
        foreach ($definition->fields() as $field) {
            if ($field->type !== FieldType::File) {
                continue;
            }
            $files = [];
            foreach ($previous as $row) {
                $metadata = json_decode($row[$field->name] ?? 'null', true);
                if (is_array($metadata)) {
                    $files = [...$files, ...($field->isMultiple ? $metadata : [$metadata])];
                }
            }
            $value = $data[$field->name] ?? null;
            $metadata = $field->isMultiple ? $files : collect($files)->firstWhere('path', is_string($value) ? $value : '');
            $result[$field->name] = json_encode($metadata, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    private function serializeRow(ReportDefinition $definition, array $row, array $previous, int $reportId, array &$created): array
    {
        $result = [];
        foreach ($definition->fields() as $field) {
            $value = $row[$field->name] ?? null;
            if ($field->type === FieldType::File) {
                $old = json_decode($previous[$field->name] ?? 'null', true);
                if ($field->isMultiple) {
                    $metadata = [];
                    foreach ($value ?? [] as $upload) {
                        $existing = collect($old ?? [])->firstWhere('path', is_string($upload) ? $upload : '');
                        $metadata[] = $this->files->store($upload, $existing, $field, $reportId, $created);
                    }
                } else {
                    $metadata = $this->files->store($value, $old, $field, $reportId, $created);
                }
                $value = $metadata === null ? null : json_encode($metadata, JSON_THROW_ON_ERROR);
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $result[$field->name] = $value;
        }

        return $result;
    }
}
