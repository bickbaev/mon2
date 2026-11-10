<x-filament-panels::page>
    {{ $this->content }}
    <div x-data="{ login: '', password: '' }"
         x-on:user-created-password.window="login = $event.detail.login; password = $event.detail.password; $dispatch('open-modal', { id: 'temporary-password' })"
         x-on:close-modal.window="if ($event.detail.id === 'temporary-password') { login = ''; password = '' }">
        <x-filament::modal id="temporary-password" width="md">
            <x-slot name="heading">Пользователь создан</x-slot>
            <p>Передайте пользователю логин и временный пароль. Пароль показывается один раз.</p>
            <p>Логин: <strong x-text="login"></strong></p>
            <label for="temporary-password-value">Временный пароль</label>
            <x-filament::input.wrapper>
                <x-filament::input id="temporary-password-value" x-bind:value="password" readonly autocomplete="off" />
            </x-filament::input.wrapper>
            <x-slot name="footerActions">
                <x-filament::button x-on:click="password = ''; login = ''; $dispatch('close-modal', { id: 'temporary-password' })">Закрыть</x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>
