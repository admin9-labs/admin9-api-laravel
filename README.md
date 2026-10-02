# Admin9 API Laravel

Laravel 13 / PHP 8.3 backend for the Admin9 middle/back-office API.

## Local development

```bash
composer setup
composer dev
```

`composer dev` runs the HTTP server at `http://localhost:8000`, queue listener, scheduler worker, log tailing, and Vite dev server together for local feedback. When using Herd or Valet, you may override `APP_URL` in your local `.env`, for example with `http://admin9-api-laravel.test`.

## Local sample accounts

`composer setup` creates the schema but does not seed application data. In a local or testing environment, create the initial RBAC data and local sample accounts with:

```bash
php artisan db:seed
```

The local-only administrator credentials are `admin@admin9.dev` / `password`. The local-only member credentials are `member@admin9.dev` / `Member-password-123`. Re-running the seeder preserves existing records for both identities. These sample identities are deliberately never created in staging, production, or any other non-local environment. Member accounts in non-local environments must be created through the application workflow.

## Test and formatting

```bash
composer check
```

Use the narrower commands below while debugging a specific failure:

```bash
composer test
composer docs:api
composer docs:api:check
vendor/bin/pint --dirty --format agent
php artisan route:list --except-vendor
```

`composer docs:api` exports the generated OpenAPI document to `docs/api.json`; `composer docs:api:check` also fails if the committed document is stale. The same export additionally writes `docs/admin-api.json` and `docs/client-api.json`, retaining the combined document for existing consumers. Public system settings are included in both partitions; routes, operation IDs, and authentication rules are unchanged.

New member logins issue a JWT with a persisted `sid`. The current logout revokes that session and its refreshed tokens; `DELETE /api/auth/sessions` invalidates every session and legacy JWT for the member and records a security audit event. Revoked, expired, foreign, or malformed sessions are rejected on protected requests and refresh. Administrator JWT behavior is unchanged. Revoked and expired sessions and expired refresh results are pruned every five minutes by the scheduler.

Upgrade compatibility: previously issued JWTs without `sid` retain the existing signature, guard/provider, authentication-version, expiry, and blacklist checks. Their first successful refresh creates a session; new logins always use sessions. A malformed or explicit null `sid` is rejected rather than treated as a legacy token. Session refresh follows `JWT_REFRESH_IAT`; account-level credential and session invalidation still apply through `auth_version`.

Member refresh supports a fixed 30-second recovery window from the first committed result. A retry returns the same replacement JWT, with `expires_in` calculated from its actual remaining lifetime. Retrying does not extend the recovery window, JWT expiry, or session expiry. Logout, password/admin invalidation, revoked/expired sessions, and successor rotation stop recovery. Admin refresh remains single-use. Cache revocation failures return retryable `503` responses; a prepared result survives for recovery within the window.

If a revocation outage outlasts that window, an expired, incomplete result can be replaced only after verifying that its source JWT remains unrevoked and its account and session are still valid. Successfully consumed sources cannot restart their recovery window.

Security tradeoff: anyone holding the old bearer, including someone who stole it, can retrieve the same successor during this window. This improves lost-response reliability; it does not detect bearer theft or bind refresh to a separate secret. Protected endpoints still reject the revoked source JWT. Recovery tokens are encrypted at rest, omitted from model serialization, and can be removed after expiry with `model:prune --model='App\Models\MemberTokenRefresh'`. Keep the shared persistent blacklist cache and revocation checks enabled; pruning recovery rows does not clear the source blacklist entry.

## Production run checklist

This checklist is intentionally command/process oriented and does not contain secrets. Inject production secrets through the hosting platform or encrypted environment workflow, not through committed files.

1. **Prepare dependencies and assets**
   - Install PHP dependencies with optimized autoloading.
   - Build frontend assets if the deployment serves the bundled Vite assets.
2. **Configure environment**
   - Set `APP_ENV=production` and `APP_DEBUG=false`; do not deploy the local sample environment values.
   - Inject `APP_KEY`, database credentials, and other environment-specific values through the hosting platform or encrypted environment workflow.
   - Inject `JWT_SECRET` through the hosting platform or encrypted environment workflow before running API traffic.
   - Generate a JWT secret with `php artisan jwt:secret` when preparing a new environment.
   - Keep `JWT_BLACKLIST_ENABLED=true` and `JWT_SHOW_BLACKLIST_EXCEPTION=true`. Disabling either bypasses token revocation checks; the latter is not merely a logging option. Use a shared persistent cache supporting atomic locks for revocation and refresh coordination across processes. Keep `JWT_BLACKLIST_GRACE_PERIOD=0` for immediate source-token revocation.
3. **Initialize the database and administrator**
   - Run `php artisan migrate --force` during deployment.
   - Run `php artisan db:seed --force` after migrations to create the required roles, permissions, and menus. This does not create an administrator outside local or testing environments.
   - On the first production deployment, run `php artisan admin:create` from a trusted interactive terminal after seeding. It creates the first super administrator and displays the generated temporary password once.
   - The file API stores public assets on the `public` disk. Run `php artisan storage:link --force` and configure `FILES_URL` for the served storage URL. Changing `FILESYSTEM_DISK` does not change the file API's disk.
   - Deploy file-directory and permission migrations before the grouped file picker. Existing files remain ungrouped; moving files preserves their paths and URLs. Seed the new move button through the existing menu provisioning flow and explicitly grant `system.file.update` to custom roles that need it. The permission data migration retains existing permissions, menu bindings, and grants on rollback; rolling back the directory schema discards group assignments, not file bytes.
   - Re-running `AdminRbacSeeder` restores built-in permission definitions and enabled states, and replaces the reserved `system-admin` role's permissions with the built-in set. Use a separate role for project-specific grants.
   - Treat deployed migrations as immutable; add forward migrations for schema changes.
   - Before upgrading an existing installation, back up the database and uploaded files and rehearse restoration in an isolated environment. Historical media migrations remove the old table; rolling back its schema does not restore its records. Some other migrations deliberately refuse a lossy rollback.
4. **Cache framework metadata**
   - Run `php artisan config:cache` after production environment variables are present.
   - Run `php artisan route:cache` during deployment and refresh it whenever routes change.
   - Optional for rendered views: `php artisan view:cache`.
5. **Queue worker**
   - Run a supervised queue worker such as `php artisan queue:work --queue=default --tries=3 --timeout=60`.
   - Run `php artisan queue:restart` during each deployment so long-lived workers reload code safely.
   - Ensure the configured cache store is available before relying on `queue:restart` signals.
6. **Scheduler**
   - Run the scheduler continuously with one of Laravel's supported production patterns, for example a cron entry that runs `php artisan schedule:run` every minute or a supervised `php artisan schedule:work` process.
   - The project schedules failed-job pruning, queue-batch pruning, queue backlog monitoring, interrupted file-deletion recovery, and member authentication pruning. Deletion recovery and authentication pruning run every five minutes; review scheduler failure logs if either stops succeeding.
7. **Health check**
   - Point load balancers and uptime checks at `GET /up`.
   - A non-200 response from `/up` means the Laravel application did not boot cleanly.
8. **Logging and operations visibility**
   - Keep `LOG_CHANNEL` routed to the production log sink.
   - Preserve context fields emitted by the API middleware, including request IDs, for incident correlation.
   - Monitor scheduler and queue operation warnings from the channels configured under `logging.operations`.
9. **Post-deploy smoke checks**
   - `php artisan route:list --except-vendor`
   - `php artisan schedule:list`
   - `curl --fail https://<host>/up`

## Migration index convention

New project migrations that add application indexes should use explicit index names so rollback and cross-database diagnostics stay stable. Do not rename historical deployed indexes in place; add a forward migration when a production index needs to change.
