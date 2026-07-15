# Admin9 API Laravel

Laravel 13 / PHP 8.3 backend for the Admin9 middle/back-office API.

## Local development

```bash
composer setup
composer dev
```

`composer dev` runs the HTTP server, queue listener, scheduler worker, log tailing, and Vite dev server together for local feedback.

## Initial admin access

`composer setup` creates the schema but does not seed application data. In a local or testing environment, create the initial RBAC data and development administrator with:

```bash
php artisan db:seed
```

The local-only development credentials are `admin@example.com` / `password`. They are deliberately never created in staging, production, or any other non-local environment. For those environments, inject a unique `ADMIN_BOOTSTRAP_EMAIL` and `ADMIN_BOOTSTRAP_PASSWORD` through the hosting platform's secret manager before running `php artisan db:seed --force`.

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

`composer docs:api` exports the generated OpenAPI document to `docs/api.json`; `composer docs:api:check` also fails if the committed document is stale.

## Production run checklist

This checklist is intentionally command/process oriented and does not contain secrets. Inject production secrets through the hosting platform or encrypted environment workflow, not through committed files.

1. **Prepare dependencies and assets**
   - Install PHP dependencies with optimized autoloading.
   - Build frontend assets if the deployment serves the bundled Vite assets.
2. **Configure environment**
   - Inject `JWT_SECRET` through the hosting platform or encrypted environment workflow before running API traffic.
   - Generate a JWT secret with `php artisan jwt:secret` when preparing a new environment.
3. **Migrate database**
   - Run `php artisan migrate --force` during deployment.
   - Treat deployed migrations as immutable; add forward migrations for schema changes.
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
   - The project schedules only built-in operations commands: failed-job pruning, queue-batch pruning, and queue backlog monitoring.
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
