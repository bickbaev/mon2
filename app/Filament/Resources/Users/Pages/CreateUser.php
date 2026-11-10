<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected static bool $canCreateAnother = false;

    protected string $view = 'filament.resources.users.create-user';

    public function create(bool $another = false): void
    {
        $this->authorizeAccess();
        if ($this->isCreating) {
            return;
        }
        $this->isCreating = true;
        try {
            $data = $this->form->getState();
            $password = Str::password(16, symbols: false);
            $user = UserResource::persistUser($data, password: $password);
            $this->form->fill();
            $this->rememberData();
            $this->getCreatedNotification()?->send();
            $payload = json_encode(['login' => $user->login, 'password' => $password], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $this->js('window.dispatchEvent(new CustomEvent("user-created-password", {detail: '.$payload.'}));');
        } finally {
            $this->isCreating = false;
        }
    }
}
