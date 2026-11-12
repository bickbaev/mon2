<x-filament-panels::page>
    @if (! $this->canEdit())
        <p>Отчёт доступен только для просмотра. Проверьте его статус и заполнение обязательных базовых отчётов организации.</p>
    @endif
    <form wire:submit="save">
        {{ $this->form }}
        @if ($this->canEdit())
            <x-filament::button type="submit" wire:loading.attr="disabled">Сохранить</x-filament::button>
        @endif
    </form>
    @foreach ($this->downloads() as $file)
        <x-filament::link :href="$file['url']">{{ $file['name'] }}</x-filament::link>
    @endforeach
</x-filament-panels::page>
