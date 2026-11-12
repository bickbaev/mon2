<?php

use App\Enums\ReportKind;
use App\Models\ReportType;
use App\Reports\Demo\DemoList;
use App\Reports\Demo\DemoSimple;
use App\Reports\Field;
use App\Reports\ReportDefinition;
use App\Reports\ReportRegistry;
use App\Services\ReportStore;
use Illuminate\Validation\ValidationException;

class RegistrySimpleDefinition extends ReportDefinition
{
    public function key(): string
    {
        return 'simple';
    }

    public function title(): string
    {
        return 'Простой';
    }

    public function kind(): ReportKind
    {
        return ReportKind::Simple;
    }

    public function table(): string
    {
        return 'simple_data';
    }

    public function fields(): array
    {
        return [Field::number('count', 'Количество')->required()->min(1)];
    }
}

class RegistryComplexDefinition extends RegistrySimpleDefinition
{
    public function key(): string
    {
        return 'complex';
    }

    public function kind(): ReportKind
    {
        return ReportKind::Complex;
    }
}

class RegistryDuplicateFields extends RegistrySimpleDefinition
{
    public function fields(): array
    {
        return [Field::text('same', 'Первое'), Field::text('same', 'Второе')];
    }
}

test('registry resolves definitions and validation excludes unchecked data', function () {
    config(['reports.definitions' => [RegistrySimpleDefinition::class]]);
    $registry = app(ReportRegistry::class);
    expect(array_keys($registry->all()))->toBe(['simple']);
    expect($registry->get('simple')->validate(['count' => 2, 'unexpected' => 'secret']))->toBe(['count' => 2]);
    expect(fn () => $registry->get('missing'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $registry->get('simple')->validate(['count' => 0]))->toThrow(ValidationException::class);
});

test('invalid definitions are rejected', function (array $definitions) {
    config(['reports.definitions' => $definitions]);
    expect(fn () => app(ReportRegistry::class)->all())->toThrow(InvalidArgumentException::class);
})->with([
    'duplicate keys' => [[RegistrySimpleDefinition::class, RegistrySimpleDefinition::class]],
    'invalid class' => [[stdClass::class]],
    'duplicate fields' => [[RegistryDuplicateFields::class]],
]);

test('sync creates definitions and preserves admin settings on repeated runs', function () {
    config(['reports.definitions' => [RegistrySimpleDefinition::class]]);
    $this->artisan('reports:sync')->assertSuccessful();
    $type = ReportType::where('key', 'simple')->sole();
    $type->update(['title' => 'Старое', 'is_base' => true, 'is_required' => true, 'auto_create' => true, 'excel_enabled' => false, 'list_allows_empty' => false, 'active' => false]);
    $orphan = ReportType::factory()->create();
    $this->artisan('reports:sync')->assertSuccessful();
    $type->refresh();
    expect($type->title)->toBe('Простой');
    expect([$type->is_base, $type->is_required, $type->auto_create, $type->excel_enabled, $type->list_allows_empty, $type->active])->toBe([true, true, true, false, false, false]);
    expect($orphan->fresh())->not->toBeNull();
});

test('complex types cannot be base and always disable excel', function () {
    $complex = ReportType::factory()->create(['kind' => ReportKind::Complex, 'excel_enabled' => true]);
    expect($complex->fresh()->excel_enabled)->toBeFalse();
    expect(fn () => $complex->update(['is_base' => true]))->toThrow(DomainException::class);
});

test('sync changes kind safely and rolls back the whole batch on conflict', function () {
    config(['reports.definitions' => [RegistrySimpleDefinition::class, RegistryComplexDefinition::class]]);
    $existing = ReportType::factory()->create(['key' => 'complex', 'kind' => ReportKind::Simple, 'is_base' => true]);
    $this->artisan('reports:sync')->assertFailed();
    expect(ReportType::where('key', 'simple')->exists())->toBeFalse();
    expect($existing->fresh()->kind)->toBe(ReportKind::Simple);
    $existing->update(['is_base' => false]);
    $this->artisan('reports:sync')->assertSuccessful();
    expect($existing->fresh()->kind)->toBe(ReportKind::Complex);
    expect($existing->fresh()->excel_enabled)->toBeFalse();
});

class UnsafeChangedDefinition extends RegistrySimpleDefinition
{
    public function fields(): array
    {
        return [];
    }
}

test('sync rejects removal of fields after saved data without changing metadata', function () {
    config(['reports.definitions' => [DemoList::class]]);
    [$report, $user] = storedDemoReport();
    app(ReportStore::class)->save($report, ['rows' => [['title' => 'Test', 'date' => '2026-11-12', 'participants' => 1]]], $user);
    $type = $report->reportType;
    $definition = new class extends DemoList
    {
        public function fields(): array
        {
            return [Field::text('title', 'Название')];
        }
    };
    expect(fn () => app(ReportRegistry::class)->assertSafeChange($type, $definition))->toThrow(DomainException::class);
});

test('saved file field cannot change from single to multiple uploads', function () {
    config(['reports.definitions' => [DemoSimple::class]]);
    [$report, $user] = storedDemoReport('demo_simple');
    app(ReportStore::class)->save($report, simpleDemoPayload(), $user);
    $definition = new class extends DemoSimple
    {
        public function fields(): array
        {
            $fields = parent::fields();
            $fields[4]->isMultiple = true;

            return $fields;
        }
    };
    expect(fn () => app(ReportRegistry::class)->assertSafeChange($report->reportType, $definition))->toThrow(DomainException::class);
});
