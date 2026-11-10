<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        abort_unless((string) $request->user()->id === $id, 403);
        $user = DB::transaction(function () use ($id, $hash) {
            $user = User::lockForUpdate()->findOrFail($id);
            abort_unless(! $user->isBlocked() && $user->pd_consent_at && $user->pending_email
                && hash_equals(sha1($user->pending_email), $hash), 403);
            $user->forceFill([
                'email' => $user->pending_email,
                'pending_email' => null,
                'email_verified_at' => now(),
            ])->save();

            return $user;
        });
        event(new Verified($user));

        return redirect('/cabinet');
    }
}
