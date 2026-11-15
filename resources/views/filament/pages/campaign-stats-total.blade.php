<td class="fi-ta-cell">
    <div class="fi-ta-text">Итого по выбранной ветке</div>
</td>
@foreach ($this->totals() as $status => $count)
    <td class="fi-ta-cell">
        <div class="fi-ta-text">
            <x-filament::link :href="$this->reportsUrl($this->nodeId, $status === 'total' ? null : $status)">
                {{ $count }}
            </x-filament::link>
        </div>
    </td>
@endforeach
<td class="fi-ta-cell"></td>
