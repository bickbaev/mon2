<?php

use App\Reports\Field;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

function fieldPasses(Field $field, mixed $value): bool
{
    return Validator::make([$field->name => $value], $field->toValidationRules())->passes();
}

test('required numeric bounds reject missing and out of range values', function () {
    $field = Field::number('count', 'Количество')->required()->min(1)->max(10);
    foreach ([null, '', 'hello', 0, 11] as $value) {
        expect(fieldPasses($field, $value))->toBeFalse();
    }
    foreach ([1, 5.5, 10] as $value) {
        expect(fieldPasses($field, $value))->toBeTrue();
    }
});

test('text lengths nullable fields and custom validation work', function () {
    $field = Field::text('name', 'Имя')->multiline()->maxLength(3);
    expect(fieldPasses($field, null))->toBeTrue();
    expect(fieldPasses($field, 'abcd'))->toBeFalse();
    expect(fieldPasses($field, 'abc'))->toBeTrue();
    $field->rules([function (string $attribute, mixed $value, Closure $fail): void {
        if ($value === 'abc') {
            $fail('Запрещённое значение');
        }
    }]);
    expect(fieldPasses($field, 'abc'))->toBeFalse();
    expect(fieldPasses(Field::text('code', 'Код')->rules(['regex:/^A/']), 'BCD'))->toBeFalse();
});

test('choice fields validate scalar and each distinct array member', function () {
    foreach ([Field::select('v', 'Выбор'), Field::radio('v', 'Выбор')] as $field) {
        $field->options([0 => 'Ноль', 'yes' => 'Да']);
        expect(fieldPasses($field, '0'))->toBeTrue();
        expect(fieldPasses($field, 0))->toBeTrue();
        expect(fieldPasses($field, ['yes']))->toBeFalse();
        expect(fieldPasses($field, 'no'))->toBeFalse();
    }
    $field = Field::multiselect('v', 'Выбор')->options(['a' => 'А', 'b' => 'Б']);
    expect(fieldPasses($field, ['a', 'b']))->toBeTrue();
    expect(fieldPasses($field, ['a', 'bad']))->toBeFalse();
    expect(fieldPasses($field, ['a', 'a']))->toBeFalse();
    expect(fieldPasses($field, 'a'))->toBeFalse();
});

test('boolean and temporal formats are validated', function (string $type, mixed $valid, mixed $invalid) {
    $field = Field::$type('v', 'Значение')->required();
    expect(fieldPasses($field, $valid))->toBeTrue();
    expect(fieldPasses($field, $invalid))->toBeFalse();
})->with([
    'checkbox' => ['checkbox', false, 'yes'],
    'date' => ['date', '2026-11-10', '2026-02-30'],
    'time' => ['time', '23:59', '24:00'],
    'datetime' => ['datetime', '2026-11-10 12:30:00', '2026-11-10'],
]);

test('date boundaries are strict', function () {
    $field = Field::date('v', 'Дата')->after('2026-01-01')->before('2026-12-31');
    expect(fieldPasses($field, '2026-06-01'))->toBeTrue();
    expect(fieldPasses($field, '2026-01-01'))->toBeFalse();
    expect(fieldPasses($field, '2026-12-31'))->toBeFalse();
});

test('files validate contents size and every member of multiple uploads', function () {
    $file = UploadedFile::fake()->createWithContent('note.txt', 'hello');
    $wrong = UploadedFile::fake()->createWithContent('document.pdf', '%PDF-1.4 content');
    $large = UploadedFile::fake()->create('large.txt', 3, 'text/plain');
    $field = Field::file('v', 'Файл')->files(['txt'], 2)->required();
    expect(fieldPasses($field, $file))->toBeTrue();
    expect(fieldPasses($field, $large))->toBeFalse();
    expect(fieldPasses($field, $wrong))->toBeFalse();
    $multiple = Field::file('v', 'Файлы')->files(['txt'], 2, true);
    expect(fieldPasses($multiple, [$file]))->toBeTrue();
    expect(fieldPasses($multiple, [$file, $wrong]))->toBeFalse();
});

test('custom closure can be passed directly', function () {
    $field = Field::text('v', 'Текст')->rules(function (string $attribute, mixed $value, Closure $fail): void {
        $fail('Нет');
    });
    expect(fieldPasses($field, 'text'))->toBeFalse();
});
