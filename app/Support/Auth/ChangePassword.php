<?php

namespace App\Support\Auth;

use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ChangePassword
{
    public function __construct(private SecurityActivityRecorder $activityRecorder) {}

    public function handle(Model&Authenticatable $account, string $currentPassword, string $password, string $guard): void
    {
        DB::transaction(function () use ($account, $currentPassword, $password, $guard): void {
            /** @var Model&Authenticatable $lockedAccount */
            $lockedAccount = $account->newQuery()->lockForUpdate()->findOrFail($account->getKey());

            if (! Hash::check($currentPassword, (string) $lockedAccount->getAuthPassword())) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }

            $lockedAccount->forceFill([
                'password' => $password,
                'auth_version' => (int) $lockedAccount->getAttribute('auth_version') + 1,
            ])->save();

            $this->activityRecorder->record($lockedAccount, $lockedAccount, $guard, 'password_changed');
        });
    }
}
