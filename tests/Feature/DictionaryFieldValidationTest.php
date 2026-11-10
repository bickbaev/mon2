<?php

use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Reports\Field;
use Illuminate\Support\Facades\Validator;

test('dictionary choices accept only currently active ids of the correct dictionary', function () {
    $dictionary = Dictionary::factory()->create(['key' => 'subjects']);
    $active = DictionaryItem::factory()->for($dictionary)->create();
    $inactive = DictionaryItem::factory()->for($dictionary)->create(['active' => false]);
    $foreign = DictionaryItem::factory()->create();
    $field = Field::select('subject', 'Предмет')->dictionary('subjects')->required();
    $passes = fn ($value) => Validator::make(['subject' => $value], $field->toValidationRules())->passes();
    expect($passes($active->id))->toBeTrue();
    expect($passes($inactive->id))->toBeFalse();
    expect($passes($foreign->id))->toBeFalse();
    $active->update(['active' => false]);
    expect($passes($active->id))->toBeFalse();
    $multiple = Field::multiselect('subjects', 'Предметы')->dictionary('subjects');
    expect(Validator::make(['subjects' => [$foreign->id, $inactive->id]], $multiple->toValidationRules())->fails())->toBeTrue();
    $unknown = Field::select('subject', 'Предмет')->dictionary('unknown');
    expect(Validator::make(['subject' => $foreign->id], $unknown->toValidationRules())->fails())->toBeTrue();
});

test('dictionary and inline options cannot be combined', function () {
    expect(fn () => Field::select('v', 'Выбор')->options(['a' => 'А'])->dictionary('subjects'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Field::select('v', 'Выбор')->dictionary('subjects')->options(['a' => 'А']))->toThrow(InvalidArgumentException::class);
});
