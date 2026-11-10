<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;

class UserPasswordTokenRepository extends DatabaseTokenRepository
{
    /** The existing email column stores an account-specific opaque key, not a delivery address. */
    protected function userKey(CanResetPassword $user): string
    {
        return hash('sha256', $user->getAuthIdentifier().'|'.$user->getEmailForPasswordReset());
    }

    public function create(CanResetPassword $user): string
    {
        $this->deleteExisting($user);
        $token = $this->createNewToken();
        $this->getTable()->insert($this->getPayload($this->userKey($user), $token));

        return $token;
    }

    protected function deleteExisting(CanResetPassword $user): int
    {
        return $this->getTable()->where('email', $this->userKey($user))->delete();
    }

    public function exists(CanResetPassword $user, #[\SensitiveParameter] $token): bool
    {
        $record = (array) $this->getTable()->where('email', $this->userKey($user))->first();

        return $record && ! $this->tokenExpired($record['created_at'])
            && $this->hasher->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPassword $user): bool
    {
        $record = (array) $this->getTable()->where('email', $this->userKey($user))->first();

        return $record && $this->tokenRecentlyCreated($record['created_at']);
    }
}
