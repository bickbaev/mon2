<?php

namespace App\Filament\Pages;

use App\Notifications\VerifyEmailNotification;
use Filament\Auth\Pages\EditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class Profile extends EditProfile
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')->label('Электронная почта')->email()->required()->maxLength(255)
            ->helperText('Новый адрес начнёт действовать после подтверждения. До этого используется прежний.')
            ->live(debounce: 500);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $data = Arr::only($data, ['name', 'email', 'password']);
        $newEmail = $data['email'];
        unset($data['email']);
        if (isset($data['password'])) {
            $data['remember_token'] = Str::random(60);
        }
        if ($newEmail !== $record->email) {
            $data['pending_email'] = $newEmail;
        }
        $record->forceFill($data)->save();
        if ($newEmail !== $record->email) {
            Notification::route('mail', $newEmail)->notify(new VerifyEmailNotification($record->id, $newEmail));
        }

        return $record;
    }
}
