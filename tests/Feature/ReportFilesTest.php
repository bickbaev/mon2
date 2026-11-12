<?php

use App\Enums\ReportKind;
use App\Enums\ReportStatus;
use App\Models\User;
use App\Reports\Demo\DemoList;
use App\Reports\Demo\DemoSimple;
use App\Reports\Field;
use App\Services\ReportStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('private file persists and can be retained only by its own report field', function () {
    Storage::fake('reports');
    [$report, $user] = storedDemoReport('demo_simple');
    $payload = simpleDemoPayload();
    $store = app(ReportStore::class);
    $store->save($report, [...$payload, 'attachment' => UploadedFile::fake()->create('data.pdf', 10, 'application/pdf')], $user);
    $loaded = $store->load($report);
    Storage::disk('reports')->assertExists($loaded['attachment']);
    $store->save($report, [...$payload, 'attachment' => $loaded['attachment']], $user);
    expect($store->load($report)['organization_type'])->toBe($payload['organization_type']);
    expect(fn () => $store->save($report, [...$payload, 'attachment' => '../other.pdf'], $user))->toThrow(ValidationException::class);
    $this->actingAs($user)->get(route('reports.files.download', ['report' => $report, 'field' => 'attachment']))->assertOk()->assertDownload('data.pdf');
    $other = User::factory()->create(['pd_consent_at' => now()]);
    $this->actingAs($other)->get(route('reports.files.download', ['report' => $report, 'field' => 'attachment']))->assertNotFound();
    $this->actingAs($user)->get(route('reports.files.download', ['report' => $report, 'field' => 'missing']))->assertNotFound();
    $report->campaign->update(['closed_at' => now()]);
    $this->get(route('reports.files.download', ['report' => $report, 'field' => 'attachment']))->assertNotFound();
});

test('invalid file causes no data status or storage changes', function (string $filename, int $size, string $mime) {
    Storage::fake('reports');
    [$report, $user] = storedDemoReport('demo_simple');
    $payload = simpleDemoPayload();
    expect(fn () => app(ReportStore::class)->save($report, [...$payload, 'attachment' => UploadedFile::fake()->create($filename, $size, $mime)], $user))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('demo_simple_data', 0);
    expect(Storage::disk('reports')->allFiles())->toBe([]);
    expect($report->fresh()->status)->toBe(ReportStatus::New);
})->with([['code.exe', 1, 'application/x-msdownload'], ['large.pdf', 5121, 'application/pdf']]);

class MultipleFilesDemo extends DemoSimple
{
    public function fields(): array
    {
        $fields = parent::fields();
        $fields[4] = Field::file('attachment', 'Приложения')->files(['pdf'], 5120, true);

        return $fields;
    }
}

class ListFilesDemo extends DemoSimple
{
    public function kind(): ReportKind
    {
        return ReportKind::List;
    }
}

test('multiple uploads can be retained and removed without accepting foreign paths', function () {
    Storage::fake('reports');
    config(['reports.definitions' => [MultipleFilesDemo::class]]);
    [$report, $user] = storedDemoReport('demo_simple');
    $payload = simpleDemoPayload();
    $store = app(ReportStore::class);
    $store->save($report, [...$payload, 'attachment' => [UploadedFile::fake()->create('one.pdf', 1, 'application/pdf'), UploadedFile::fake()->create('two.pdf', 1, 'application/pdf')]], $user);
    $paths = $store->load($report)['attachment'];
    $store->save($report, [...$payload, 'attachment' => [$paths[1]]], $user);
    expect($store->load($report)['attachment'])->toBe([$paths[1]]);
    expect(fn () => $store->save($report, [...$payload, 'attachment' => [$paths[0]]], $user))->toThrow(ValidationException::class);
});

test('list file can be retained while updating the row', function () {
    Storage::fake('reports');
    config(['reports.definitions' => [ListFilesDemo::class]]);
    [$report, $user] = storedDemoReport('demo_simple');
    $payload = simpleDemoPayload();
    $store = app(ReportStore::class);
    $store->save($report, ['rows' => [[...$payload, 'attachment' => UploadedFile::fake()->create('one.pdf', 1, 'application/pdf')]]], $user);
    $loaded = $store->load($report);
    $store->save($report, $loaded, $user);
    expect($store->load($report))->toBe($loaded);
});

class AttachmentRowsDemo extends DemoList
{
    public function fields(): array
    {
        return [Field::file('title', 'Файл')->required()->files(['pdf'], 5120), Field::date('date', 'Дата')->required(), Field::number('participants', 'Участники')->required()->min(0)];
    }
}

test('list attachment ownership survives reordering and deleting earlier rows', function () {
    Storage::fake('reports');
    config(['reports.definitions' => [AttachmentRowsDemo::class]]);
    [$report, $user] = storedDemoReport();
    $store = app(ReportStore::class);
    $store->save($report, ['rows' => [
        ['title' => UploadedFile::fake()->create('one.pdf', 1, 'application/pdf'), 'date' => '2026-11-12', 'participants' => 1],
        ['title' => UploadedFile::fake()->create('two.pdf', 1, 'application/pdf'), 'date' => '2026-11-13', 'participants' => 2],
    ]], $user);
    $rows = $store->load($report)['rows'];
    $store->save($report, ['rows' => [$rows[1], $rows[0]]], $user);
    expect($store->load($report)['rows'])->toBe([$rows[1], $rows[0]]);
    $store->save($report, ['rows' => [$rows[0]]], $user);
    expect($store->load($report)['rows'])->toBe([$rows[0]]);
});
