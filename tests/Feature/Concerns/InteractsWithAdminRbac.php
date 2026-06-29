<?php

namespace Tests\Feature\Concerns;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

trait InteractsWithAdminRbac
{
    protected function adminTokenFor(User $user): string
    {
        $response = $this->postJson('/api/admin/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $token = $response->json('data.access_token');
        $this->assertIsString($token);

        return $token;
    }

    /**
     * @param  array<int, string>  $permissionNames
     */
    protected function managerTokenFor(array $permissionNames): string
    {
        $user = User::factory()->create(['email' => fake()->unique()->safeEmail()]);

        foreach ($permissionNames as $permissionName) {
            $user->givePermissionTo($this->createAdminPermission($permissionName));
        }

        return $this->adminTokenFor($user);
    }

    protected function createSuperAdmin(string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole(Role::findOrCreate('super-admin', 'admin'));

        return $user;
    }

    /**
     * @param  array{display_name?: string, group?: string, description?: ?string, sort?: int, is_system?: bool, is_active?: bool}  $attributes
     */
    protected function createPermission(string $name, array $attributes = [], string $guardName = 'admin'): Permission
    {
        return $this->createAdminPermission($name, $attributes, $guardName);
    }

    /**
     * @param  array{display_name?: string, group?: string, description?: ?string, sort?: int, is_system?: bool, is_active?: bool}  $attributes
     */
    protected function createAdminPermission(string $name, array $attributes = [], string $guardName = 'admin'): Permission
    {
        $permission = Permission::findOrCreate($name, $guardName);

        DB::table('permissions')->where('id', $permission->id)->update([
            'display_name' => $attributes['display_name'] ?? str($name)->replace('.', ' ')->title()->toString(),
            'group' => $attributes['group'] ?? str($name)->beforeLast('.')->toString(),
            'description' => $attributes['description'] ?? null,
            'sort' => $attributes['sort'] ?? 0,
            'is_system' => $attributes['is_system'] ?? false,
            'is_active' => $attributes['is_active'] ?? true,
            'updated_at' => now(),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $permission->refresh();
    }
}
