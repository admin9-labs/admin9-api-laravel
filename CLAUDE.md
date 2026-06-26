# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

`admin9-api-laravel` is an **API-first** Laravel 13 / PHP 8.3 backend (the `admin9` product's API). It is not a full-stack app — there are no Blade controllers or session-based auth flows of note. The stack is tuned for serving JSON: a unified response layer, per-request tracing, and a swapped exception handler. Default DB is SQLite; MySQL/MariaDB/Postgres configs are present and ready.

## Commands

```bash
composer setup      # Full bootstrap: install, .env, key, migrate, npm install + build
composer dev        # Concurrent: php artisan serve + queue:listen + pail (logs) + vite (all --kill-others)
composer test       # Clears config then runs `php artisan test` (PHPUnit, in-memory SQLite)

# Single test
php artisan test --filter=AddContextTest
php artisan test --filter='test_it_exposes_request_id_to_success_responses'

# Lint / formatting — Pint with the `laravel` preset (see pint.json)
composer pint        # formats the whole repo (--parallel)
./vendor/bin/pint --dirty   # only changed files

# IDE helper (regenerates _ide_helper.php, .phpstorm.meta.php, _ide_helper_models.php)
composer ide-helper

# Boost MCP is wired up via .mcp.json (`php artisan boost:mcp`); use the `search-docs` tool for exact Laravel API.
```

Tests run on **PHPUnit** (not Pest) against in-memory SQLite (`DB_DATABASE=:memory:` in `phpunit.xml`). There is no `tests/Pest.php`.

## Architecture

### Unified JSON response contract (mitoop/laravel-api-response)

All API responses go through `Mitoop\Http\JsonResponder`. The base `App\Http\Controllers\Controller` applies the `RespondsWithJson` trait, so controllers call `$this->success(...)`, `$this->error(...)`, `$this->deny(...)` directly — do not `return response()->json(...)`.

Response envelope (HTTP status is **always 200**, the real status lives in the JSON `code`):

```json
// success
{ "success": true,  "code": 0,   "message": "...", "data": {...}, "request_id": "uuid" }
// error (e.g. 404, validation) — `errors` present only on ValidationException
{ "success": false, "code": 404, "message": "...", "data": {}, "errors": {}, "request_id": "uuid" }
// deny (auth) — code is -1
{ "success": false, "code": -1,  "message": "Unauthenticated", "data": {}, "errors": {}, "request_id": "uuid" }
```

Paginated success payloads add a `meta` block: page-based gives `{pagination:'page', page, page_size, has_more, total}`; cursor-based gives `{pagination:'cursor', next_cursor, page_size, has_more}`.

### Exception handling is replaced, not extended

`App\Providers\AppServiceProvider` binds `ExceptionHandler::class => Mitoop\Http\Exceptions\Handler::class` as a singleton — Laravel's own handler is swapped out, not subclassed. Exception → JSON mapping (in the Mitoop handler):

- `ClientSafeException` → `error()` with its message + code (the only exception whose message is safe to expose to clients — throw this for expected business errors).
- `AuthenticationException` → `deny('Unauthenticated')`.
- `ValidationException` → first flattened error message + full `errors` bag.
- `HttpException` (incl. `ModelNotFoundException` mapped to 404) → message + status code in `code`.
- Other throwables → generic message unless `APP_DEBUG`, which adds `class/code/message/file` to `errors`.

In `bootstrap/app.php`, `shouldRenderJsonWhen` forces JSON rendering for `api/*` requests. Don't add a second error-rendering path — extend via `ClientSafeException` or the existing Mitoop handler.

### Request tracing (AddContext middleware)

`App\Http\Middleware\AddContext` is registered as a **global** middleware (in `bootstrap/app.php`) specifically so that **unmatched `api/*` routes (404s) still get a `request_id`**. For every `api/*` request it:

1. Generates a UUID7 `request_id`.
2. Stores it in Laravel `Context` (`request_id`, `url`) — so it flows into logs and queued jobs.
3. Pushes `extra: ['request_id' => ...]` into the JsonResponder, merging `request_id` into every API JSON body.
4. Sets the `X-Request-Id` response header.

Web (`/` non-api) responses are intentionally left untouched. The exception `respond()` hook re-applies `X-Request-Id` to error responses too. The contract is covered by `tests/Feature/AddContextTest.php`.

### Models use the HasModelDefaults trait

`App\Models\Traits\HasModelDefaults` sets two project-wide conventions — **every new Eloquent model should `use` it**:

- `serializeDate()` → `Y-m-d H:i:s` (deterministic serialization, no timezone/ISO-8601 drift).
- `getPerPage()` → page size driven by the `?page_size` query param, clamped to 1–100, default 15. Don't override `$perPage`; let the trait handle pagination sizing.

Models configure fillable/hidden via PHP 8.3 attributes (`#[Fillable([...])]`, `#[Hidden([...])]`) and casts via the `casts()` method — see `App\Models\User` as the reference.

### Routing

`bootstrap/app.php` loads **two** API route files under the `api` stack: `routes/api.php` (wrapped in `throttle:30,1`) and `routes/admin.php` (prefix `/admin`). Add admin-facing endpoints to `admin.php`, public/external ones to `api.php`. `routes/web.php` and `routes/console.php` are essentially empty stubs.

## Conventions

- **Comments are written in Chinese** (see `AddContext.php`, the tests). Match this when adding comments/docblocks — explain *why*, since per the project's style rule comments are reserved for intent/config, not narration.
- Follow the `laravel-best-practices` skill (`.claude/skills/laravel-best-practices/`) for any Laravel-pattern decision; it prioritizes consistency-with-existing-code over theoretical optimality — check sibling files first.
- Code style is enforced by Pint (`laravel` preset); run `composer pint` before committing. IDE-helper artifacts (`_ide_helper.php`, `_ide_helper_models.php`, `.phpstorm.meta.php`) are gitignored and regenerated on demand.
- Prefer Laravel helpers (`Str`, `Arr`, `$request->string()`, etc.) over raw PHP.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- phpunit/phpunit (PHPUNIT) - v12

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
