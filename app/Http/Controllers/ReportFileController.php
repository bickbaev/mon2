<?php

namespace App\Http\Controllers;

use App\Enums\FieldType;
use App\Models\Report;
use App\Reports\ReportRegistry;
use App\Services\ReportStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportFileController extends Controller
{
    public function __invoke(Report $report, string $field, int $row = 0, int $index = 0): StreamedResponse
    {
        abort_unless(Gate::forUser(auth()->user()->fresh())->allows('view', $report), 404);
        $definition = app(ReportRegistry::class)->get($report->reportType->key);
        $fileField = collect($definition->fields())->first(fn ($candidate): bool => $candidate->name === $field && $candidate->type === FieldType::File);
        abort_unless($fileField, 404);
        $data = app(ReportStore::class)->raw($report, $definition);
        $metadata = json_decode($data[$row][$field] ?? 'null', true);
        if ($fileField->isMultiple) {
            $metadata = $metadata[$index] ?? null;
        }
        abort_unless(is_array($metadata) && isset($metadata['path'], $metadata['name']) && Storage::disk('reports')->exists($metadata['path']), 404);

        return Storage::disk('reports')->download($metadata['path'], $metadata['name']);
    }
}
