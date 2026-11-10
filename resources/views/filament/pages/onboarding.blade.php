<x-filament-panels::page>
    @if (! $this->hasConsent)
        <form wire:submit="acceptConsent" class="space-y-4">
            <p>Для работы в системе необходимо согласие на обработку персональных данных, затем подтверждение электронной почты.</p>
            <label class="flex items-center gap-2">
                <x-filament::input.checkbox wire:model="consent" />
                <span>Я согласен на обработку моих персональных данных для работы в системе сбора данных образовательных организаций.</span>
            </label>
            @error('consent') <p role="alert">{{ $message }}</p> @enderror
            <x-filament::button type="submit">Согласиться и продолжить</x-filament::button>
        </form>
    @else
        <form wire:submit="sendVerification" class="space-y-4">
            <p>Укажите почту и перейдите по ссылке из письма. До подтверждения доступ к системе закрыт.</p>
            <label for="onboarding-email">Электронная почта</label>
            <x-filament::input.wrapper>
                <x-filament::input id="onboarding-email" type="email" wire:model="email" required autocomplete="email" />
            </x-filament::input.wrapper>
            @error('email') <p role="alert">{{ $message }}</p> @enderror
            <x-filament::button type="submit" wire:loading.attr="disabled">Отправить письмо подтверждения</x-filament::button>
            @if ($verificationSent)
                <p role="status">Письмо отправлено. Проверьте почту и перейдите по ссылке подтверждения.</p>
            @endif
        </form>
    @endif
</x-filament-panels::page>
