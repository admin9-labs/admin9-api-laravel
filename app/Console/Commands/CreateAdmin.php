<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Security\PasswordPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[Signature('admin:create')]
#[Description('Create the first super administrator with a temporary password')]
class CreateAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->input->isInteractive() || ! $this->isInteractiveTerminal()) {
            $this->error('The admin:create command requires an interactive terminal.');

            return self::FAILURE;
        }

        if (! $this->superAdminRoleExists()) {
            $this->error('The super-admin role does not exist. Run php artisan db:seed --force first.');

            return self::FAILURE;
        }

        if ($this->superAdminExists()) {
            $this->error('A super administrator already exists.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Administrator name'));
        $email = trim((string) $this->ask('Administrator email'));
        $validator = Validator::make(
            ['name' => $name, 'email' => $email],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
            ],
        );

        if ($validator->fails()) {
            $this->error('Invalid administrator details: '.$validator->errors()->first());

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($name, $email): array {
                $role = Role::query()
                    ->where('name', ReservedAdminRole::SUPER_ADMIN)
                    ->where('guard_name', 'admin')
                    ->lockForUpdate()
                    ->first();

                if (! $role instanceof Role) {
                    return ['error' => 'missing_role'];
                }

                if ($role->users()->exists()) {
                    return ['error' => 'existing_super_admin'];
                }

                if (User::query()->where('email', $email)->lockForUpdate()->exists()) {
                    return ['error' => 'existing_email'];
                }

                $password = Str::password(32, spaces: false);
                Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()])->validate();

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'is_active' => true,
                ]);
                $user->assignRole($role);

                return ['error' => null, 'password' => $password];
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Unable to create the super administrator.');

            return self::FAILURE;
        }

        if ($result['error'] !== null) {
            $message = match ($result['error']) {
                'missing_role' => 'The super-admin role does not exist. Run php artisan db:seed --force first.',
                'existing_super_admin' => 'A super administrator already exists.',
                'existing_email' => 'A user with this email already exists.',
            };
            $this->error($message);

            return self::FAILURE;
        }

        $this->info('Super administrator created.');
        $this->output->writeln('Email: '.$email, OutputInterface::OUTPUT_RAW);
        $this->output->writeln('Temporary password: '.$result['password'], OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function superAdminRoleExists(): bool
    {
        return Role::query()
            ->where('name', ReservedAdminRole::SUPER_ADMIN)
            ->where('guard_name', 'admin')
            ->exists();
    }

    protected function isInteractiveTerminal(): bool
    {
        return defined('STDIN')
            && defined('STDOUT')
            && is_resource(STDIN)
            && is_resource(STDOUT)
            && stream_isatty(STDIN)
            && stream_isatty(STDOUT);
    }

    private function superAdminExists(): bool
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query
                ->where('name', ReservedAdminRole::SUPER_ADMIN)
                ->where('guard_name', 'admin'))
            ->exists();
    }
}
