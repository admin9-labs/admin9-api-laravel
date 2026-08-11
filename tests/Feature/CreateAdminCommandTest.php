<?php

namespace Tests\Feature;

use App\Console\Commands\CreateAdmin;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use App\Support\Security\PasswordPolicy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_creates_an_active_super_administrator(): void
    {
        $this->createSuperAdminRole();

        [$exitCode, $output] = $this->runInteractiveCommand('Root Admin', 'root@example.com');

        $user = User::query()->where('email', 'root@example.com')->firstOrFail();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame('Root Admin', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->hasRole(ReservedAdminRole::SUPER_ADMIN, 'admin'));
        $this->assertStringContainsString('Super administrator created.', $output);
        $this->assertStringContainsString('Email: root@example.com', $output);
    }

    public function test_command_hashes_the_generated_password_and_displays_it_once(): void
    {
        $this->createSuperAdminRole();

        [$exitCode, $output] = $this->runInteractiveCommand('Root Admin', 'root@example.com');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame(1, preg_match('/Temporary password: (\S+)/', $output, $matches));

        $password = $matches[1];
        $user = User::query()->where('email', 'root@example.com')->firstOrFail();

        $this->assertSame(16, strlen($password));
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{16}\z/', $password);
        $this->assertNotSame($password, $user->password);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertFalse(Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()])->fails());
        $this->assertSame(1, substr_count($output, $password));
    }

    public function test_command_rejects_an_existing_email_without_elevating_the_user(): void
    {
        $this->createSuperAdminRole();
        $user = User::factory()->create(['email' => 'root@example.com']);

        [$exitCode, $output] = $this->runInteractiveCommand('Root Admin', 'root@example.com');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertFalse($user->refresh()->hasRole(ReservedAdminRole::SUPER_ADMIN, 'admin'));
        $this->assertStringContainsString('A user with this email already exists.', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
    }

    public function test_command_rejects_when_a_super_administrator_already_exists(): void
    {
        $role = $this->createSuperAdminRole();
        $existingAdmin = User::factory()->create();
        $existingAdmin->assignRole($role);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(1, User::query()->count());
        $this->assertStringContainsString('A super administrator already exists.', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
    }

    public function test_command_rejects_when_the_super_admin_role_is_missing(): void
    {
        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);
        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, User::query()->count());
        $this->assertStringContainsString('Run php artisan db:seed --force first.', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
    }

    public function test_command_rejects_non_interactive_execution(): void
    {
        $this->createSuperAdminRole();
        $tester = $this->commandTester();

        $exitCode = $tester->execute([], ['interactive' => false]);
        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, User::query()->count());
        $this->assertStringContainsString('requires an interactive terminal', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
    }

    public function test_command_rejects_interactive_input_without_a_real_terminal(): void
    {
        $this->createSuperAdminRole();
        $tester = $this->commandTester(terminalAvailable: false);

        $exitCode = $tester->execute([], ['interactive' => true]);
        $output = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, User::query()->count());
        $this->assertStringContainsString('requires an interactive terminal', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
    }

    public function test_command_reports_creation_failure_without_disclosing_details_or_password(): void
    {
        $this->createSuperAdminRole();
        Exceptions::fake();
        $exception = new RuntimeException('Simulated database write failure.');
        Event::listen('eloquent.creating: '.User::class, function () use ($exception): never {
            throw $exception;
        });

        [$exitCode, $output] = $this->runInteractiveCommand('Root Admin', 'root@example.com');

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertSame(0, User::query()->count());
        $this->assertStringContainsString('Unable to create the super administrator.', $output);
        $this->assertStringNotContainsString('Temporary password:', $output);
        $this->assertStringNotContainsString('Simulated database write failure.', $output);
        $this->assertStringNotContainsString(RuntimeException::class, $output);
        $this->assertStringNotContainsString(basename(__FILE__), $output);
        Exceptions::assertReported(fn (RuntimeException $reported): bool => $reported === $exception);
        Exceptions::assertReportedCount(1);
    }

    private function createSuperAdminRole(): Role
    {
        return Role::findOrCreate(ReservedAdminRole::SUPER_ADMIN, 'admin');
    }

    /**
     * @return array{int, string}
     */
    private function runInteractiveCommand(string $name, string $email): array
    {
        $tester = $this->commandTester();
        $tester->setInputs([$name, $email]);
        $exitCode = $tester->execute([]);

        return [$exitCode, $tester->getDisplay()];
    }

    private function commandTester(bool $terminalAvailable = true): CommandTester
    {
        $registeredCommand = Artisan::all()['admin:create'];
        $this->assertInstanceOf(CreateAdmin::class, $registeredCommand);

        $command = new TestCreateAdmin($terminalAvailable);
        $command->setLaravel($this->app);
        $command->setName('admin:create');

        return new CommandTester($command);
    }
}

class TestCreateAdmin extends CreateAdmin
{
    public function __construct(private readonly bool $terminalAvailable)
    {
        parent::__construct();
    }

    protected function isInteractiveTerminal(): bool
    {
        return $this->terminalAvailable;
    }
}
