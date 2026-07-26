<?php

namespace App\Actions\Admin;

use App\Models\Member;
use App\Models\User;
use App\Support\Audit\SecurityActivityRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageMember
{
    public function __construct(private SecurityActivityRecorder $activityRecorder) {}

    /**
     * @param  array{name: string, email?: ?string, mobile?: ?string, password: string, is_active?: bool}  $attributes
     */
    public function create(array $attributes, User $actor): Member
    {
        try {
            return DB::transaction(function () use ($actor, $attributes): Member {
                $member = Member::query()->create($attributes);
                $this->activityRecorder->record($member, $actor, 'admin', 'member_created');

                return $member;
            });
        } catch (QueryException $exception) {
            $this->throwIdentityConflict($exception, $attributes);
        }
    }

    /**
     * @param  array{name?: string, email?: ?string, mobile?: ?string}  $attributes
     */
    public function update(Member $member, array $attributes, User $actor): Member
    {
        try {
            return DB::transaction(function () use ($actor, $attributes, $member): Member {
                $lockedMember = $this->lock($member);
                $lockedMember->fill($attributes);

                if ($lockedMember->email === null && $lockedMember->mobile === null) {
                    throw ValidationException::withMessages([
                        'email' => ['An email address or mobile number is required.'],
                        'mobile' => ['An email address or mobile number is required.'],
                    ]);
                }

                if ($lockedMember->isDirty(['email', 'mobile'])) {
                    $lockedMember->forceFill([
                        'auth_version' => $lockedMember->auth_version + 1,
                    ]);
                }

                $lockedMember->save();
                $this->activityRecorder->record($lockedMember, $actor, 'admin', 'member_updated');

                return $lockedMember;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $this->throwIdentityConflict($exception, $attributes, $member);
        }
    }

    public function updateStatus(Member $member, bool $isActive, User $actor): Member
    {
        return DB::transaction(function () use ($actor, $isActive, $member): Member {
            $lockedMember = $this->lock($member);

            if ($lockedMember->is_active !== $isActive) {
                $lockedMember->forceFill([
                    'is_active' => $isActive,
                    'auth_version' => $lockedMember->auth_version + 1,
                ])->save();
            }

            $this->activityRecorder->record($lockedMember, $actor, 'admin', 'member_status_updated');

            return $lockedMember;
        }, attempts: 3);
    }

    public function resetPassword(Member $member, string $password, User $actor): Member
    {
        return DB::transaction(function () use ($actor, $member, $password): Member {
            $lockedMember = $this->lock($member);
            $lockedMember->forceFill([
                'password' => $password,
                'auth_version' => $lockedMember->auth_version + 1,
            ])->save();
            $this->activityRecorder->record($lockedMember, $actor, 'admin', 'member_password_reset');

            return $lockedMember;
        }, attempts: 3);
    }

    public function invalidateSessions(Member $member, User $actor): Member
    {
        return DB::transaction(function () use ($actor, $member): Member {
            $lockedMember = $this->lock($member);
            $lockedMember->increment('auth_version');
            $this->activityRecorder->record($lockedMember, $actor, 'admin', 'member_sessions_invalidated');

            return $lockedMember;
        }, attempts: 3);
    }

    private function lock(Member $member): Member
    {
        return Member::query()->lockForUpdate()->findOrFail($member->getKey());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function throwIdentityConflict(QueryException $exception, array $attributes, ?Member $member = null): never
    {
        $errors = collect(['email', 'mobile'])
            ->filter(fn (string $field): bool => isset($attributes[$field]))
            ->filter(function (string $field) use ($attributes, $member): bool {
                return Member::query()
                    ->where($field, $attributes[$field])
                    ->when($member !== null, fn ($query) => $query->whereKeyNot($member->getKey()))
                    ->exists();
            })
            ->mapWithKeys(fn (string $field): array => [
                $field => ["The {$field} has already been taken."],
            ])
            ->all();

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        throw $exception;
    }
}
