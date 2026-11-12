<?php

namespace App\Services;

use App\Reports\Field;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportFiles
{
    /** @return array<string, mixed>|null */
    public function store(mixed $value, mixed $previous, Field $field, int $reportId, array &$created): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && is_array($previous) && ($previous['path'] ?? null) === $value) {
            return $previous;
        }
        if (! $value instanceof UploadedFile) {
            throw ValidationException::withMessages([$field->name => 'Недопустимый файл.']);
        }
        $path = $value->store((string) $reportId, 'reports');
        $created[] = $path;

        return ['path' => $path, 'name' => basename($value->getClientOriginalName()), 'mime' => $value->getMimeType(), 'size' => $value->getSize()];
    }

    public function deleteCreated(array $paths): void
    {
        if ($paths !== []) {
            Storage::disk('reports')->delete($paths);
        }
    }
}
