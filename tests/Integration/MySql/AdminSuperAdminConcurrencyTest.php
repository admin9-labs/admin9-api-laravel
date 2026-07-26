<?php

namespace Tests\Integration\MySql;

use App\Http\Requests\Admin\UpdateMenuRequest;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use App\Support\Admin\ReservedAdminRole;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\Feature\Concerns\InteractsWithAdminRbac;
use Tests\Support\MySqlConcurrencyDatabaseGuard;
use Tests\TestCase;

#[Group('mysql-concurrency')]
class AdminSuperAdminConcurrencyTest extends TestCase
{
    use InteractsWithAdminRbac;

    private const PROCESS_TIMEOUT_SECONDS = 20;

    private const SYNCHRONIZATION_TIMEOUT_SECONDS = 10;

    /**
     * @var array{database: string, version: string, version_comment: string}
     */
    private array $databaseMetadata;

    private static bool $environmentReported = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseMetadata = MySqlConcurrencyDatabaseGuard::assertSafe();
        $this->reportEnvironment();
    }

    public function test_concurrent_disables_preserve_an_active_super_admin(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.user.update');

            $firstSuperAdmin = $this->createSuperAdmin("disable-first-{$round}@example.com");
            $secondSuperAdmin = $this->createSuperAdmin("disable-second-{$round}@example.com");
            $firstToken = $this->tokenFor($firstSuperAdmin);
            $secondToken = $this->tokenFor($secondSuperAdmin);

            $results = $this->raceRequests(
                scenario: "disable-round-{$round}",
                targetIds: [$firstSuperAdmin->id, $secondSuperAdmin->id],
                requests: [
                    $this->request('PATCH', "/api/admin/users/{$secondSuperAdmin->id}", $firstToken, ['is_active' => false]),
                    $this->request('PATCH', "/api/admin/users/{$firstSuperAdmin->id}", $secondToken, ['is_active' => false]),
                ],
            );

            $this->assertSerializedOutcome($results, 'last active super-admin cannot be disabled');
            $this->assertCount(1, $this->activeSuperAdminIds());
            $this->assertSame(1, User::query()
                ->whereKey([$firstSuperAdmin->id, $secondSuperAdmin->id])
                ->where('is_active', true)
                ->count());
        }
    }

    public function test_concurrent_deletes_preserve_an_active_super_admin(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.user.delete');

            $firstSuperAdmin = $this->createSuperAdmin("delete-first-{$round}@example.com");
            $secondSuperAdmin = $this->createSuperAdmin("delete-second-{$round}@example.com");
            $firstToken = $this->tokenFor($firstSuperAdmin);
            $secondToken = $this->tokenFor($secondSuperAdmin);

            $results = $this->raceRequests(
                scenario: "delete-round-{$round}",
                targetIds: [$firstSuperAdmin->id, $secondSuperAdmin->id],
                requests: [
                    $this->request('DELETE', "/api/admin/users/{$secondSuperAdmin->id}", $firstToken),
                    $this->request('DELETE', "/api/admin/users/{$firstSuperAdmin->id}", $secondToken),
                ],
            );

            $this->assertSerializedOutcome($results, 'last active super-admin cannot be deleted');
            $this->assertCount(1, $this->activeSuperAdminIds());
            $this->assertSame(1, User::query()
                ->whereKey([$firstSuperAdmin->id, $secondSuperAdmin->id])
                ->count());
        }
    }

    public function test_concurrent_role_removals_preserve_an_active_super_admin(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.user.assign-role');

            $firstSuperAdmin = $this->createSuperAdmin("role-first-{$round}@example.com");
            $secondSuperAdmin = $this->createSuperAdmin("role-second-{$round}@example.com");
            $firstToken = $this->tokenFor($firstSuperAdmin);
            $secondToken = $this->tokenFor($secondSuperAdmin);

            $results = $this->raceRequests(
                scenario: "role-round-{$round}",
                targetIds: [$firstSuperAdmin->id, $secondSuperAdmin->id],
                requests: [
                    $this->request('PUT', "/api/admin/users/{$secondSuperAdmin->id}/roles", $firstToken, ['roles' => []]),
                    $this->request('PUT', "/api/admin/users/{$firstSuperAdmin->id}/roles", $secondToken, ['roles' => []]),
                ],
            );

            $this->assertSerializedOutcome($results, 'last active super-admin cannot lose the super-admin role');
            $this->assertCount(1, $this->activeSuperAdminIds());
            $this->assertSame(2, User::query()
                ->whereKey([$firstSuperAdmin->id, $secondSuperAdmin->id])
                ->where('is_active', true)
                ->count());
        }
    }

    public function test_permission_deletion_serializes_concurrent_role_creation(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.permission.delete');
            $this->createPermission('system.role.create');

            $permission = $this->createPermission("dynamic.concurrent.create.{$round}");
            $roleName = "concurrent-created-role-{$round}";
            $directUser = User::factory()->create(['email' => "concurrent-create-direct-{$round}@example.com"]);
            $manager = User::factory()->create(['email' => "concurrent-create-manager-{$round}@example.com"]);
            $manager->givePermissionTo(['system.permission.delete', 'system.role.create']);
            $token = $this->tokenFor($manager);

            $results = $this->racePermissionDeletionWithRoleWrite(
                scenario: "permission-role-create-round-{$round}",
                permission: $permission,
                token: $token,
                roleRequest: $this->request('POST', '/api/admin/roles', $token, [
                    'name' => $roleName,
                    'permissions' => [$permission->name],
                ]),
                directUser: $directUser,
            );

            $this->assertPermissionDeletionConflict($results, "role-create-round-{$round}");
            $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
            $this->assertDatabaseMissing('roles', [
                'name' => $roleName,
                'guard_name' => 'admin',
            ]);
            $this->assertDatabaseMissing('role_has_permissions', [
                'permission_id' => $permission->id,
            ]);
            $this->assertDatabaseMissing('model_has_permissions', [
                'permission_id' => $permission->id,
                'model_id' => $directUser->id,
                'model_type' => User::class,
            ]);
        }
    }

    public function test_permission_deletion_serializes_concurrent_role_update(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.permission.delete');
            $this->createPermission('system.role.update');

            $permission = $this->createPermission("dynamic.concurrent.update.{$round}");
            $retainedPermission = $this->createPermission("dynamic.concurrent.update.retained.{$round}");
            $originalRoleName = "concurrent-original-role-{$round}";
            $updatedRoleName = "concurrent-updated-role-{$round}";
            $role = Role::findOrCreate($originalRoleName, 'admin');
            $role->givePermissionTo($retainedPermission);
            $directUser = User::factory()->create(['email' => "concurrent-update-direct-{$round}@example.com"]);
            $manager = User::factory()->create(['email' => "concurrent-update-manager-{$round}@example.com"]);
            $manager->givePermissionTo(['system.permission.delete', 'system.role.update']);
            $token = $this->tokenFor($manager);

            $results = $this->racePermissionDeletionWithRoleWrite(
                scenario: "permission-role-update-round-{$round}",
                permission: $permission,
                token: $token,
                roleRequest: $this->request('PATCH', "/api/admin/roles/{$role->id}", $token, [
                    'name' => $updatedRoleName,
                    'permissions' => [$permission->name],
                ]),
                directUser: $directUser,
            );

            $this->assertPermissionDeletionConflict($results, "role-update-round-{$round}");
            $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
            $this->assertDatabaseHas('roles', [
                'id' => $role->id,
                'name' => $originalRoleName,
                'guard_name' => 'admin',
            ]);
            $this->assertDatabaseMissing('roles', [
                'id' => $role->id,
                'name' => $updatedRoleName,
            ]);
            $this->assertDatabaseMissing('role_has_permissions', [
                'permission_id' => $permission->id,
                'role_id' => $role->id,
            ]);
            $this->assertDatabaseHas('role_has_permissions', [
                'permission_id' => $retainedPermission->id,
                'role_id' => $role->id,
            ]);
            $this->assertDatabaseMissing('model_has_permissions', [
                'permission_id' => $permission->id,
                'model_id' => $directUser->id,
                'model_type' => User::class,
            ]);
        }
    }

    public function test_permission_deletion_serializes_concurrent_role_permission_sync_and_direct_user_assignment(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.permission.delete');
            $this->createPermission('system.role.update');

            $permission = $this->createPermission("dynamic.concurrent.sync.{$round}");
            $retainedPermission = $this->createPermission("dynamic.concurrent.sync.retained.{$round}");
            $role = Role::findOrCreate("concurrent-sync-role-{$round}", 'admin');
            $role->givePermissionTo($retainedPermission);
            $directUser = User::factory()->create(['email' => "concurrent-sync-direct-{$round}@example.com"]);
            $manager = User::factory()->create(['email' => "concurrent-sync-manager-{$round}@example.com"]);
            $manager->givePermissionTo(['system.permission.delete', 'system.role.update']);
            $token = $this->tokenFor($manager);

            $results = $this->racePermissionDeletionWithRoleWrite(
                scenario: "permission-role-sync-round-{$round}",
                permission: $permission,
                token: $token,
                roleRequest: $this->request(
                    'PUT',
                    "/api/admin/roles/{$role->id}/permissions",
                    $token,
                    ['permissions' => [$permission->name]],
                ),
                directUser: $directUser,
            );

            $this->assertPermissionDeletionConflict($results, "role-sync-round-{$round}");
            $this->assertDatabaseMissing('permissions', ['id' => $permission->id]);
            $this->assertDatabaseMissing('role_has_permissions', [
                'permission_id' => $permission->id,
                'role_id' => $role->id,
            ]);
            $this->assertDatabaseHas('role_has_permissions', [
                'permission_id' => $retainedPermission->id,
                'role_id' => $role->id,
            ]);
            $this->assertDatabaseMissing('model_has_permissions', [
                'permission_id' => $permission->id,
                'model_id' => $directUser->id,
                'model_type' => User::class,
            ]);
        }
    }

    public function test_concurrent_menu_parent_updates_cannot_commit_a_cycle(): void
    {
        foreach (range(1, $this->rounds()) as $round) {
            $this->resetDatabase();
            $this->createPermission('system.menu.update');

            $firstMenu = Menu::factory()->create(['code' => "concurrent-menu-first-{$round}"]);
            $secondMenu = Menu::factory()->create(['code' => "concurrent-menu-second-{$round}"]);
            $manager = User::factory()->create(['email' => "concurrent-menu-manager-{$round}@example.com"]);
            $manager->givePermissionTo('system.menu.update');
            $token = $this->tokenFor($manager);

            $results = $this->raceMenuParentRequests(
                scenario: "menu-parent-round-{$round}",
                targetIds: [$firstMenu->id, $secondMenu->id],
                requests: [
                    $this->request('PATCH', "/api/admin/menus/{$firstMenu->id}", $token, [
                        'parent_id' => $secondMenu->id,
                    ]),
                    $this->request('PATCH', "/api/admin/menus/{$secondMenu->id}", $token, [
                        'parent_id' => $firstMenu->id,
                    ]),
                ],
            );

            $this->assertSerializedOutcome(
                $results,
                strtolower(UpdateMenuRequest::DESCENDANT_PARENT_MESSAGE),
            );
            $rejection = collect($results)->firstWhere('status', 422);
            $this->assertIsArray($rejection);
            $this->assertSame(
                [UpdateMenuRequest::DESCENDANT_PARENT_MESSAGE],
                $rejection['body']['errors']['parent_id'] ?? null,
            );

            $firstParentId = $firstMenu->refresh()->parent_id;
            $secondParentId = $secondMenu->refresh()->parent_id;

            $this->assertTrue(
                ($firstParentId === $secondMenu->id && $secondParentId === null)
                || ($firstParentId === null && $secondParentId === $firstMenu->id),
            );
        }
    }

    /**
     * @param  array{method: string, uri: string, token: string, payload: array<string, mixed>}  $roleRequest
     * @return array<int, array{status: int, body: array<string, mixed>, connection_id: int}>
     */
    private function racePermissionDeletionWithRoleWrite(
        string $scenario,
        Permission $permission,
        string $token,
        array $roleRequest,
        User $directUser,
    ): array {
        $temporaryDirectory = $this->createTemporaryDirectory($scenario);
        $pausedFile = $temporaryDirectory.'/permission-delete-paused';
        $releaseFile = $temporaryDirectory.'/permission-delete-release';
        $processes = [];

        try {
            $deleteReadyFile = $temporaryDirectory.'/delete-worker.json';
            $deleteProcess = $this->startRequestProcess(
                $this->request('DELETE', "/api/admin/permissions/{$permission->id}", $token),
                $deleteReadyFile,
                [
                    'MYSQL_CONCURRENCY_PERMISSION_DELETE_ID' => (string) $permission->id,
                    'MYSQL_CONCURRENCY_PERMISSION_DELETE_PAUSED_FILE' => $pausedFile,
                    'MYSQL_CONCURRENCY_PERMISSION_DELETE_RELEASE_FILE' => $releaseFile,
                ],
            );
            $processes[] = ['process' => $deleteProcess, 'ready_file' => $deleteReadyFile];
            $deleteConnectionId = $this->waitForReadyWorkers($processes)[0];
            $this->waitForBarrierFile($pausedFile, $deleteProcess);

            $roleReadyFile = $temporaryDirectory.'/role-write-worker.json';
            $roleWriteProcess = $this->startRequestProcess($roleRequest, $roleReadyFile);
            $directReadyFile = $temporaryDirectory.'/direct-assignment-worker.json';
            $directAssignmentProcess = $this->startDirectPermissionAssignmentProcess(
                $directUser->id,
                $permission->id,
                $directReadyFile,
            );
            $assignmentProcesses = [
                ['process' => $roleWriteProcess, 'ready_file' => $roleReadyFile],
                ['process' => $directAssignmentProcess, 'ready_file' => $directReadyFile],
            ];
            $processes = [...$processes, ...$assignmentProcesses];

            $assignmentConnectionIds = $this->waitForReadyWorkers($assignmentProcesses);
            $parentConnectionId = $this->connectionId(DB::connection());
            $this->assertCount(4, array_unique([
                $parentConnectionId,
                $deleteConnectionId,
                ...$assignmentConnectionIds,
            ]));
            $this->waitForLockWaits(DB::connection(), $assignmentConnectionIds, $assignmentProcesses);
            $this->assertSame(
                [$deleteConnectionId, $deleteConnectionId],
                $this->permissionBlockingConnectionIds(DB::connection(), $assignmentConnectionIds),
            );

            $this->assertNotFalse(file_put_contents($releaseFile, 'release', LOCK_EX));

            return $this->waitForResults($processes);
        } finally {
            if (! is_file($releaseFile)) {
                file_put_contents($releaseFile, 'release', LOCK_EX);
            }

            $this->stopProcesses($processes);
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    /**
     * @param  array<int, array{status: int, body: array<string, mixed>, connection_id: int}>  $results
     */
    private function assertPermissionDeletionConflict(array $results, string $scenario): void
    {
        $this->assertCount(3, $results);
        [$deleteResult, $roleWriteResult, $directAssignmentResult] = $results;
        $resultDiagnostics = json_encode([
            'scenario' => $scenario,
            'delete' => $deleteResult,
            'role_write' => $roleWriteResult,
            'direct_assignment' => $directAssignmentResult,
        ], JSON_THROW_ON_ERROR);

        $this->assertSame(200, $deleteResult['status'], $resultDiagnostics);
        $this->assertTrue($deleteResult['body']['success'] ?? false, $resultDiagnostics);
        $this->assertSame('deleted', $deleteResult['body']['message'] ?? null, $resultDiagnostics);
        $this->assertSame(422, $roleWriteResult['status'], $resultDiagnostics);
        $this->assertFalse($roleWriteResult['body']['success'] ?? true, $resultDiagnostics);
        $this->assertSame(422, $roleWriteResult['body']['code'] ?? null, $resultDiagnostics);
        $this->assertSame(
            'One or more selected permissions no longer exist.',
            $roleWriteResult['body']['message'] ?? null,
            $resultDiagnostics,
        );
        $this->assertSame(409, $directAssignmentResult['status'], $resultDiagnostics);
        $this->assertFalse($directAssignmentResult['body']['success'] ?? true, $resultDiagnostics);
        $this->assertSame('23000', $directAssignmentResult['body']['sql_state'] ?? null, $resultDiagnostics);
        $this->assertSame(1452, $directAssignmentResult['body']['driver_code'] ?? null, $resultDiagnostics);
    }

    private function resetDatabase(): void
    {
        $this->artisan('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  array<int, int>  $targetIds
     * @param  array<int, array{method: string, uri: string, token: string, payload: array<string, mixed>}>  $requests
     * @return array<int, array{status: int, body: array<string, mixed>, connection_id: int}>
     */
    private function raceRequests(string $scenario, array $targetIds, array $requests): array
    {
        $connection = DB::connection();
        $temporaryDirectory = $this->createTemporaryDirectory($scenario);
        $processes = [];

        $connection->beginTransaction();

        try {
            $this->assertSame($this->sortedIntegers($targetIds), User::query()
                ->whereKey($targetIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

            $lockedRole = Role::query()
                ->where('name', ReservedAdminRole::SUPER_ADMIN)
                ->where('guard_name', 'admin')
                ->lockForUpdate()
                ->first(['id']);

            $this->assertInstanceOf(Role::class, $lockedRole);

            $parentConnectionId = $this->connectionId($connection);

            foreach ($requests as $index => $request) {
                $readyFile = $temporaryDirectory.'/worker-'.($index + 1).'.json';
                $process = $this->startRequestProcess($request, $readyFile);
                $processes[] = [
                    'process' => $process,
                    'ready_file' => $readyFile,
                ];
            }

            $workerConnectionIds = $this->waitForReadyWorkers($processes);
            $this->assertCount(3, array_unique([$parentConnectionId, ...$workerConnectionIds]));
            $this->waitForLockWaits($connection, $workerConnectionIds, $processes);

            $connection->commit();

            return $this->waitForResults($processes);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            $this->stopProcesses($processes);
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    /**
     * @param  array<int, int>  $targetIds
     * @param  array<int, array{method: string, uri: string, token: string, payload: array<string, mixed>}>  $requests
     * @return array<int, array{status: int, body: array<string, mixed>, connection_id: int}>
     */
    private function raceMenuParentRequests(string $scenario, array $targetIds, array $requests): array
    {
        $connection = DB::connection();
        $temporaryDirectory = $this->createTemporaryDirectory($scenario);
        $processes = [];

        $connection->beginTransaction();

        try {
            $this->assertSame($this->sortedIntegers($targetIds), Menu::query()
                ->whereKey($targetIds)
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());

            Menu::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $parentConnectionId = $this->connectionId($connection);

            foreach ($requests as $index => $request) {
                $readyFile = $temporaryDirectory.'/worker-'.($index + 1).'.json';
                $process = $this->startRequestProcess($request, $readyFile);
                $processes[] = [
                    'process' => $process,
                    'ready_file' => $readyFile,
                ];
            }

            $workerConnectionIds = $this->waitForReadyWorkers($processes);
            $this->assertCount(3, array_unique([$parentConnectionId, ...$workerConnectionIds]));
            $this->waitForLockWaits($connection, $workerConnectionIds, $processes);
            $this->assertSame(
                2,
                count(array_filter(
                    $this->blockingConnectionIds($connection, 'menus', $workerConnectionIds),
                    static fn (int $blockingConnectionId): bool => $blockingConnectionId === $parentConnectionId,
                )),
            );

            $connection->commit();

            return $this->waitForResults($processes);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }

            $this->stopProcesses($processes);
            $this->removeTemporaryDirectory($temporaryDirectory);
        }
    }

    /**
     * @param  array{method: string, uri: string, token: string, payload: array<string, mixed>}  $request
     * @param  array<string, string>  $environmentOverrides
     */
    private function startRequestProcess(array $request, string $readyFile, array $environmentOverrides = []): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/mysql-concurrency-request.php')],
            base_path(),
            [...$this->workerEnvironment($request, $readyFile), ...$environmentOverrides],
        );
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->start();

        return $process;
    }

    private function startDirectPermissionAssignmentProcess(int $userId, int $permissionId, string $readyFile): Process
    {
        $process = new Process(
            [PHP_BINARY, base_path('tests/Support/mysql-concurrency-direct-permission.php')],
            base_path(),
            [
                ...$this->workerEnvironment($this->request('POST', '/', 'unused'), $readyFile),
                'MYSQL_CONCURRENCY_PERMISSION_ID' => (string) $permissionId,
                'MYSQL_CONCURRENCY_USER_ID' => (string) $userId,
            ],
        );
        $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);
        $process->start();

        return $process;
    }

    private function waitForBarrierFile(string $path, Process $process): void
    {
        $deadline = microtime(true) + self::SYNCHRONIZATION_TIMEOUT_SECONDS;

        do {
            if (is_file($path)) {
                return;
            }

            if (! $process->isRunning()) {
                $this->fail('MySQL concurrency worker exited before reaching the permission deletion barrier: '.$this->processDiagnostics($process));
            }
        } while ($this->pollUntil($deadline));

        $this->fail('Timed out waiting for the permission deletion barrier.');
    }

    /**
     * @param  array<int, array{process: Process, ready_file: string}>  $processes
     * @return array<int, int>
     */
    private function waitForReadyWorkers(array $processes): array
    {
        $deadline = microtime(true) + self::SYNCHRONIZATION_TIMEOUT_SECONDS;

        do {
            $connectionIds = [];

            foreach ($processes as $worker) {
                if (! is_file($worker['ready_file'])) {
                    if (! $worker['process']->isRunning()) {
                        $this->fail('MySQL concurrency worker exited before reaching the barrier: '.$this->processDiagnostics($worker['process']));
                    }

                    continue 2;
                }

                $ready = json_decode((string) file_get_contents($worker['ready_file']), true);

                if (! is_array($ready) || ! is_int($ready['connection_id'] ?? null)) {
                    continue 2;
                }

                $connectionIds[] = $ready['connection_id'];
            }

            return $connectionIds;
        } while ($this->pollUntil($deadline));

        $this->fail('Timed out waiting for MySQL concurrency workers to reach the request barrier.');
    }

    /**
     * @param  array<int, int>  $workerConnectionIds
     * @param  array<int, array{process: Process, ready_file: string}>  $processes
     */
    private function waitForLockWaits(Connection $connection, array $workerConnectionIds, array $processes): void
    {
        $deadline = microtime(true) + self::SYNCHRONIZATION_TIMEOUT_SECONDS;

        do {
            foreach ($processes as $worker) {
                if (! $worker['process']->isRunning()) {
                    $this->fail('MySQL concurrency worker exited before entering lock wait: '.$this->processDiagnostics($worker['process']));
                }
            }

            $waitingConnectionIds = $this->lockWaitingConnectionIds($connection, $workerConnectionIds);

            if ($waitingConnectionIds === $this->sortedIntegers($workerConnectionIds)) {
                foreach ($processes as $worker) {
                    $this->assertTrue($worker['process']->isRunning(), 'A worker completed before the lock holder released its transaction.');
                }

                return;
            }
        } while ($this->pollUntil($deadline));

        $processList = $connection->select(
            'select id, command, time, state, info from information_schema.processlist where id in (?, ?)',
            $workerConnectionIds,
        );

        $this->fail('Workers did not enter MySQL lock wait before timeout: '.json_encode([
            'processlist' => $processList,
            'processes' => array_map(
                fn (array $worker): string => $this->processDiagnostics($worker['process']),
                $processes,
            ),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<int, int>  $workerConnectionIds
     * @return array<int, int>
     */
    private function lockWaitingConnectionIds(Connection $connection, array $workerConnectionIds): array
    {
        $rows = $connection->select(
            <<<'SQL'
                select distinct requesting_thread.processlist_id as connection_id
                from performance_schema.data_lock_waits as lock_wait
                inner join performance_schema.threads as requesting_thread
                    on requesting_thread.thread_id = lock_wait.requesting_thread_id
                where requesting_thread.processlist_id in (?, ?)
                SQL,
            $workerConnectionIds,
        );

        return $this->sortedIntegers(array_map(
            fn (object $row): int => (int) $row->connection_id,
            $rows,
        ));
    }

    /**
     * @param  array<int, int>  $workerConnectionIds
     * @return array<int, int>
     */
    private function permissionBlockingConnectionIds(Connection $connection, array $workerConnectionIds): array
    {
        return $this->blockingConnectionIds($connection, 'permissions', $workerConnectionIds);
    }

    /**
     * @param  array<int, int>  $workerConnectionIds
     * @return array<int, int>
     */
    private function blockingConnectionIds(Connection $connection, string $table, array $workerConnectionIds): array
    {
        $rows = $connection->select(
            <<<'SQL'
                select distinct
                    requesting_thread.processlist_id as requesting_connection_id,
                    blocking_thread.processlist_id as connection_id
                from performance_schema.data_lock_waits as lock_wait
                inner join performance_schema.data_locks as requesting_lock
                    on requesting_lock.engine_lock_id = lock_wait.requesting_engine_lock_id
                inner join performance_schema.threads as requesting_thread
                    on requesting_thread.thread_id = lock_wait.requesting_thread_id
                inner join performance_schema.threads as blocking_thread
                    on blocking_thread.thread_id = lock_wait.blocking_thread_id
                where requesting_lock.object_schema = ?
                    and requesting_lock.object_name = ?
                    and requesting_lock.index_name = 'PRIMARY'
                    and requesting_thread.processlist_id in (?, ?)
                order by requesting_connection_id
                SQL,
            [$this->databaseMetadata['database'], $table, ...$workerConnectionIds],
        );

        return array_map(
            fn (object $row): int => (int) $row->connection_id,
            $rows,
        );
    }

    /**
     * @param  array<int, array{process: Process, ready_file: string}>  $processes
     * @return array<int, array{status: int, body: array<string, mixed>, connection_id: int}>
     */
    private function waitForResults(array $processes): array
    {
        $results = [];

        foreach ($processes as $worker) {
            $process = $worker['process'];

            try {
                $process->wait();
            } catch (ProcessTimedOutException $exception) {
                $this->fail($exception->getMessage().' '.$this->processDiagnostics($process));
            }

            $this->assertSame(0, $process->getExitCode(), $this->processDiagnostics($process));
            $result = json_decode(trim($process->getOutput()), true);
            $this->assertIsArray($result, $this->processDiagnostics($process));
            $this->assertIsInt($result['status'] ?? null);
            $this->assertIsArray($result['body'] ?? null);
            $this->assertIsInt($result['connection_id'] ?? null);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * @param  array<int, array{status: int, body: array<string, mixed>, connection_id: int}>  $results
     */
    private function assertSerializedOutcome(array $results, string $expectedValidationMessage): void
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        $this->assertSame([200, 422], $statuses);

        $rejection = collect($results)->firstWhere('status', 422);
        $this->assertIsArray($rejection);
        $this->assertFalse($rejection['body']['success'] ?? null);
        $this->assertSame(422, $rejection['body']['code'] ?? null);
        $this->assertStringContainsString(
            $expectedValidationMessage,
            strtolower(json_encode($rejection['body'], JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * @return array<int, int>
     */
    private function activeSuperAdminIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query
                ->where('name', ReservedAdminRole::SUPER_ADMIN)
                ->where('guard_name', 'admin')
            )
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{method: string, uri: string, token: string, payload: array<string, mixed>}
     */
    private function request(string $method, string $uri, string $token, array $payload = []): array
    {
        return compact('method', 'uri', 'token', 'payload');
    }

    /**
     * @param  array{method: string, uri: string, token: string, payload: array<string, mixed>}  $request
     * @return array<string, string>
     */
    private function workerEnvironment(array $request, string $readyFile): array
    {
        $database = config('database.connections.mysql');

        if (! is_array($database)) {
            throw new RuntimeException('The mysql database connection is not configured.');
        }

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'APP_URL' => 'http://localhost',
            'BCRYPT_ROUNDS' => '4',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => (string) $database['database'],
            'DB_HOST' => (string) $database['host'],
            'DB_PASSWORD' => (string) $database['password'],
            'DB_PORT' => (string) $database['port'],
            'DB_SOCKET' => (string) $database['unix_socket'],
            'DB_URL' => '',
            'DB_USERNAME' => (string) $database['username'],
            'JWT_SECRET' => (string) config('jwt.secret'),
            'LOG_CHANNEL' => 'stderr',
            'MAIL_MAILER' => 'array',
            'MYSQL_CONCURRENCY_DATABASE' => $this->databaseMetadata['database'],
            'MYSQL_CONCURRENCY_METHOD' => $request['method'],
            'MYSQL_CONCURRENCY_PAYLOAD' => json_encode($request['payload'], JSON_THROW_ON_ERROR),
            'MYSQL_CONCURRENCY_READY_FILE' => $readyFile,
            'MYSQL_CONCURRENCY_TOKEN' => $request['token'],
            'MYSQL_CONCURRENCY_URI' => $request['uri'],
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
    }

    private function connectionId(Connection $connection): int
    {
        $row = $connection->selectOne('select connection_id() as connection_id');

        return (int) $row->connection_id;
    }

    private function tokenFor(User $user): string
    {
        $guard = Auth::guard('admin');
        $this->assertInstanceOf(JWTGuard::class, $guard);
        $token = $guard->login($user);
        $this->assertIsString($token);
        Auth::forgetGuards();

        return $token;
    }

    private function rounds(): int
    {
        $configuredRounds = $_SERVER['MYSQL_CONCURRENCY_ROUNDS']
            ?? $_ENV['MYSQL_CONCURRENCY_ROUNDS']
            ?? getenv('MYSQL_CONCURRENCY_ROUNDS')
            ?: 3;
        $rounds = filter_var($configuredRounds, FILTER_VALIDATE_INT);

        if (! is_int($rounds) || $rounds < 2 || $rounds > 10) {
            throw new RuntimeException('MYSQL_CONCURRENCY_ROUNDS must be an integer between 2 and 10.');
        }

        return $rounds;
    }

    private function reportEnvironment(): void
    {
        if (self::$environmentReported) {
            return;
        }

        fwrite(STDOUT, sprintf(
            "\nMySQL concurrency: server=%s (%s), database=%s, rounds=%d\n",
            $this->databaseMetadata['version'],
            $this->databaseMetadata['version_comment'],
            $this->databaseMetadata['database'],
            $this->rounds(),
        ));
        self::$environmentReported = true;
    }

    private function createTemporaryDirectory(string $scenario): string
    {
        $path = sys_get_temp_dir().'/admin9-mysql-concurrency-'.$scenario.'-'.bin2hex(random_bytes(6));

        if (! mkdir($path, 0700) && ! is_dir($path)) {
            throw new RuntimeException("Unable to create temporary directory [{$path}].");
        }

        return $path;
    }

    private function removeTemporaryDirectory(string $path): void
    {
        foreach (glob($path.'/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }

    /**
     * @param  array<int, array{process: Process, ready_file: string}>  $processes
     */
    private function stopProcesses(array $processes): void
    {
        foreach ($processes as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(1);
            }
        }
    }

    private function processDiagnostics(Process $process): string
    {
        return json_encode([
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ], JSON_THROW_ON_ERROR);
    }

    private function pollUntil(float $deadline): bool
    {
        if (microtime(true) >= $deadline) {
            return false;
        }

        usleep(10_000);

        return true;
    }

    /**
     * @param  array<int, int>  $values
     * @return array<int, int>
     */
    private function sortedIntegers(array $values): array
    {
        $values = array_map(fn (mixed $value): int => (int) $value, $values);
        sort($values);

        return $values;
    }
}
