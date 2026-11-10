<?php

use App\Filament\Resources\Dictionaries\DictionaryResource;
use App\Filament\Resources\Dictionaries\Pages\CreateDictionary;
use App\Filament\Resources\Dictionaries\Pages\EditDictionary;
use App\Filament\Resources\Dictionaries\RelationManagers\ItemsRelationManager;
use App\Models\Dictionary;
use App\Models\DictionaryItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

test('only admin can create and edit dictionaries', function () {
    $dictionary = Dictionary::factory()->create();
    $this->actingAs(User::factory()->create(['pd_consent_at' => now()]));
    $this->get(DictionaryResource::getUrl())->assertForbidden();
    $this->get(DictionaryResource::getUrl('create'))->assertForbidden();
    $this->get(DictionaryResource::getUrl('edit', ['record' => $dictionary]))->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]));
    Livewire::test(CreateDictionary::class)->fillForm(['key' => 'subjects', 'name' => 'Предметы'])->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateDictionary::class)->fillForm(['key' => 'subjects', 'name' => 'Дубль'])->call('create')->assertHasFormErrors(['key']);
    Livewire::test(EditDictionary::class, ['record' => $dictionary->id])->fillForm(['name' => 'Новое название'])->call('save')->assertHasNoFormErrors();
    expect($dictionary->fresh()->name)->toBe('Новое название');
});

test('items are created edited and deactivated without deletion', function () {
    $dictionary = Dictionary::factory()->create();
    $admin = User::factory()->create(['is_admin' => true, 'pd_consent_at' => now()]);
    $this->actingAs($admin);
    $manager = Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $dictionary, 'pageClass' => EditDictionary::class]);
    $manager->callTableAction('create', data: ['label' => 'История', 'sort' => 2])->assertHasNoTableActionErrors();
    $item = $dictionary->items()->sole();
    $manager->callTableAction('edit', $item, data: ['label' => 'Математика', 'sort' => 1])->assertHasNoTableActionErrors();
    $manager->callTableAction('deactivate', $item)->assertHasNoTableActionErrors();
    expect($item->fresh()->active)->toBeFalse();
    expect($item->fresh()->label)->toBe('Математика');
    expect(Gate::forUser($admin)->allows('delete', $item))->toBeFalse();
    expect(Gate::forUser($admin)->allows('delete', $dictionary))->toBeFalse();
    $manager->assertTableActionDoesNotExist('delete');
});

test('active items are ordered and exclude inactive values while old references remain', function () {
    $dictionary = Dictionary::factory()->create();
    $last = DictionaryItem::factory()->for($dictionary)->create(['sort' => 3]);
    $first = DictionaryItem::factory()->for($dictionary)->create(['sort' => 1]);
    $inactive = DictionaryItem::factory()->for($dictionary)->create(['active' => false]);
    expect($dictionary->activeItems()->modelKeys())->toBe([$first->id, $last->id]);
    expect($dictionary->items()->find($inactive->id)->label)->toBe($inactive->label);
});

test('forged deactivation is denied and blocked admin has no dictionary permissions', function () {
    $item = DictionaryItem::factory()->create();
    $this->actingAs(User::factory()->create(['pd_consent_at' => now()]));
    Livewire::test(ItemsRelationManager::class, ['ownerRecord' => $item->dictionary, 'pageClass' => EditDictionary::class])
        ->assertForbidden();
    expect($item->fresh()->active)->toBeTrue();
    $blocked = User::factory()->create(['is_admin' => true, 'blocked_at' => now()]);
    expect(Gate::forUser($blocked)->allows('update', $item))->toBeFalse();
});
