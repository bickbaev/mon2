<x-filament-panels::page>
    {{ $this->table }}
    @unless ($this->hasChildRows())
        <x-filament::section heading="Итого по выбранной ветке">
        @foreach ($this->totals() as $status => $count)
            <p>
                <x-filament::link :href="$this->reportsUrl($this->nodeId, $status === 'total' ? null : $status)">
                    {{ $status === 'total' ? 'Всего' : \App\Enums\ReportStatus::from($status)->label() }}: {{ $count }}
                </x-filament::link>
            </p>
        @endforeach
        </x-filament::section>
    @endunless
</x-filament-panels::page>
