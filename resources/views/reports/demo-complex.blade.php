<x-filament-panels::layout>
    @push('styles')
        <style>
            .complex-report { display: grid; gap: 1.5rem; padding-block: 1.5rem; }
            .complex-report h1 { font-size: 1.5rem; font-weight: 600; }
            .complex-report-fields { display: grid; gap: 1rem; }
            .complex-report-field { display: grid; gap: .4rem; }
            .complex-report-event { border: 1px solid #9ca3af; border-radius: .75rem; padding: 1rem; display: grid; gap: 1rem; }
            .complex-report-events { display: grid; gap: 1rem; }
            .complex-report-actions { display: flex; flex-wrap: wrap; gap: .75rem; }
            @media (min-width: 48rem) { .complex-report-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        </style>
    @endpush

    @php
        $value = static function (mixed $candidate): string {
            return is_scalar($candidate) ? (string) $candidate : '';
        };
        $submittedEvents = old('events', $data['events']);
        $events = is_array($submittedEvents) ? array_values(array_filter($submittedEvents, 'is_array')) : [];
    @endphp

    <div class="complex-report">
        <a href="{{ route('filament.app.pages.dashboard') }}">← Личный кабинет</a>
        <h1>{{ $report->reportType->title }}</h1>
        <p>{{ $report->node->name }} · {{ $report->status->label() }}</p>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div role="alert">
                <p>Проверьте поля отчёта. Изменения не сохранены.</p>
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @unless ($canEdit)
            <p>Отчёт доступен только для просмотра.</p>
        @endunless

        <form class="complex-report" method="POST" action="{{ route('demo-complex.save', $report) }}" id="complex-report-form">
            @csrf
            <x-filament::section heading="Контактное лицо">
                <fieldset @disabled(! $canEdit) class="complex-report-fields">
                    @foreach (['full_name' => ['ФИО', 'text', 255], 'position' => ['Должность', 'text', 255], 'phone' => ['Телефон', 'tel', 50], 'email' => ['Электронная почта', 'email', 255]] as $field => [$label, $type, $maxlength])
                        <div class="complex-report-field">
                            <label for="contact-{{ $field }}">{{ $label }} *</label>
                            <x-filament::input.wrapper>
                                <x-filament::input id="contact-{{ $field }}" name="contact[{{ $field }}]" :type="$type" :value="$value(old('contact.'.$field, $data['contact'][$field]))" :maxlength="$maxlength" required />
                            </x-filament::input.wrapper>
                        </div>
                    @endforeach
                </fieldset>
            </x-filament::section>

            <x-filament::section heading="Мероприятия">
                <p>Укажите название, дату и число участников каждого мероприятия. Пустой список допустим.</p>
                <div class="complex-report-events" id="complex-report-events">
                    @foreach ($events as $index => $event)
                        <fieldset class="complex-report-event" @disabled(! $canEdit)>
                            <legend>Мероприятие {{ $index + 1 }}</legend>
                            @foreach (['title' => ['Название', 'text'], 'date' => ['Дата', 'date'], 'participants' => ['Число участников', 'number']] as $field => [$label, $type])
                                <div class="complex-report-field">
                                    <label for="event-{{ $index }}-{{ $field }}">{{ $label }} *</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input id="event-{{ $index }}-{{ $field }}" name="events[{{ $index }}][{{ $field }}]" :type="$type" :value="$value($event[$field] ?? '')" :min="$field === 'participants' ? 0 : null" :max="$field === 'participants' ? 4294967295 : null" :maxlength="$field === 'title' ? 255 : null" :step="$field === 'participants' ? 1 : null" required data-event-field="{{ $field }}" />
                                    </x-filament::input.wrapper>
                                </div>
                            @endforeach
                            @if ($canEdit)
                                <x-filament::button type="button" color="danger" data-remove-event>Удалить мероприятие</x-filament::button>
                            @endif
                        </fieldset>
                    @endforeach
                </div>
                @if ($canEdit)
                    <div class="complex-report-actions">
                        <x-filament::button type="button" color="gray" id="complex-report-add-event">Добавить мероприятие</x-filament::button>
                    </div>
                @endif
            </x-filament::section>

            @if ($canEdit)
                <div class="complex-report-actions">
                    <x-filament::button type="submit">Сохранить</x-filament::button>
                </div>
                <p>Контактное лицо и мероприятия сохраняются вместе.</p>
            @endif
        </form>
    </div>

    @if ($canEdit)
        <template id="complex-report-event-template">
            <fieldset class="complex-report-event">
                <legend>Мероприятие</legend>
                @foreach (['title' => ['Название', 'text'], 'date' => ['Дата', 'date'], 'participants' => ['Число участников', 'number']] as $field => [$label, $type])
                    <div class="complex-report-field">
                        <label>{{ $label }} *</label>
                        <x-filament::input.wrapper>
                            <x-filament::input :type="$type" :min="$field === 'participants' ? 0 : null" :max="$field === 'participants' ? 4294967295 : null" :maxlength="$field === 'title' ? 255 : null" :step="$field === 'participants' ? 1 : null" required data-event-field="{{ $field }}" />
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
                <x-filament::button type="button" color="danger" data-remove-event>Удалить мероприятие</x-filament::button>
            </fieldset>
        </template>
        @push('scripts')
            <script>
                (() => {
                    const events = document.getElementById('complex-report-events');
                    const renumber = () => {
                        [...events.children].forEach((row, index) => {
                            row.querySelector('legend').textContent = `Мероприятие ${index + 1}`;
                            row.querySelectorAll('[data-event-field]').forEach(input => {
                                input.name = `events[${index}][${input.dataset.eventField}]`;
                                input.id = `event-${index}-${input.dataset.eventField}`;
                                input.closest('.complex-report-field').querySelector('label').htmlFor = input.id;
                            });
                        });
                    };
                    document.getElementById('complex-report-add-event').addEventListener('click', () => {
                        events.append(document.getElementById('complex-report-event-template').content.cloneNode(true));
                        renumber();
                        events.lastElementChild.querySelector('input').focus();
                    });
                    events.addEventListener('click', event => {
                        const button = event.target.closest('[data-remove-event]');
                        if (button) {
                            button.closest('fieldset').remove();
                            renumber();
                        }
                    });
                })();
            </script>
        @endpush
    @endif
</x-filament-panels::layout>
