# Laravel 13 中后台质量评审标准 v1

Use this fixed standard for Admin9 API Laravel quality reviews, optimization plans, fixes, and verification passes.

## Maturity bands

| Score | Band | Meaning |
|---:|---|---|
| 90-100 | 成熟 | 可作为稳定中后台基座，只有局部优化项 |
| 80-89 | 基本成熟 | 可生产使用，但仍有明确质量缺口 |
| 70-79 | 可用但风险明显 | 核心功能可跑，工程化、测试、安全或性能短板明显 |
| 60-69 | 高风险 | 存在系统性缺口，生产使用需要集中治理 |
| <60 | 原型级 | 主要是功能堆叠，缺少成熟中后台保障 |

## Fixed 100-point rubric

| Category | Weight | What to inspect |
|---|---:|---|
| Laravel 架构与约定 | 12 | Directory conventions, dependency injection, thin controllers, Actions/Services/Jobs where useful, no self-made framework layer without need |
| 路由与 API 契约 | 10 | Route organization, middleware groups, versioning if needed, response shape, pagination, error statuses, Resource usage |
| 权限与安全 | 15 | Authentication, roles/permissions, Policy/Gate/Middleware, authorization on sensitive actions, mass assignment, sensitive field exposure, upload and config safety, throttling |
| 数据建模与 Migration | 12 | Table design, constraints, foreign keys, indexes, casts, fillable/guarded, relationships, reversible and focused migrations |
| Eloquent 与性能 | 10 | N+1 prevention, eager loading, list query scalability, selected columns, pagination, scopes, chunk/cursor usage for large data |
| 验证与错误处理 | 8 | Form Request usage, validated/safe input, business vs system exceptions, consistent JSON errors and status codes |
| 队列、缓存、定时任务 | 7 | Queue use for slow work, job timeout/retry/backoff/failed handling, cache/locks, scheduler overlap protection |
| 测试质量 | 12 | Feature/Unit tests, auth/authorization/validation/failure paths, factories, fakes, core workflow regression coverage |
| 配置、部署与可观测性 | 8 | env/config hygiene, config cache readiness, logs with context, queue/scheduler deployment concerns, production diagnostics |
| 可维护性与代码风格 | 6 | Pint compatibility, type declarations, naming, duplication, method complexity, magic strings, local consistency |

## Severity rules

### P0 — immediate blocker

Classify as P0 only when repository evidence shows one of these:

- Clear exploitable security vulnerability.
- Sensitive admin action lacks server-side authentication or authorization.
- Data loss / corruption risk in normal operation.
- Production boot, routing, migration, or core admin workflow is broken.
- Secret exposure in committed files.

### P1 — high priority

Classify as P1 when evidence shows one of these:

- Systemic authorization gap on important resources.
- Core write operations lack validation or use untrusted input broadly.
- Critical data tables lack constraints/indexes that protect correctness or expected backend list usage.
- Core business path lacks tests and is likely to regress.
- Obvious N+1 or unbounded query on high-use admin list/detail flows.
- Queue/job/scheduler behavior can duplicate critical side effects.

### P2 — medium priority

Classify as P2 when evidence shows one of these:

- Laravel best-practice drift that hurts maintainability or future changes.
- Inconsistent API response or error format in non-critical paths.
- Partial test gaps for secondary flows.
- Query or data-model issues that are likely to degrade but not immediately critical.
- Repeated controller/service complexity that should be extracted.

### P3 — low priority

Classify as P3 for polish and local cleanup:

- Naming/style/documentation inconsistencies.
- Minor duplication.
- Low-risk refactors.
- Non-blocking convention improvements.

## Evidence and confidence

Use these labels in reviews:

- **Evidence**: Direct code, config, migration, test, route, or command output with `path:line-line`.
- **Inference**: Reasoned conclusion from evidence. Explain why.
- **Unknown**: Repository evidence was not enough to decide.

Confidence levels:

- **High**: Multiple direct artifacts support the conclusion.
- **Medium**: Direct evidence supports the conclusion, but coverage is partial.
- **Low**: Evidence is weak, representative, or incomplete.

## Minimum review surface

For a full review, inspect at least:

- `composer.json`
- `routes/web.php`, `routes/api.php`, `routes/admin.php` if present
- `bootstrap/app.php`
- `config/`
- `app/Http/Controllers`
- `app/Http/Requests`
- `app/Http/Resources`
- `app/Models`
- `app/Policies`
- `app/Providers`
- `app/Jobs`, `app/Console`, or scheduled commands if present
- `database/migrations`
- `database/factories`
- `database/seeders`
- `tests/Feature`
- `tests/Unit`

Use `php artisan route:list --except-vendor` when route evidence is needed. Running tests is allowed in fix/verification mode; for read-only review mode, prefer existing tests and only run commands that do not modify project state.

## Standard finding format

```markdown
- 问题：short finding title
  - Severity: P0/P1/P2/P3
  - Category: one rubric category
  - Evidence: `path:line-line` — direct evidence
  - Inference: why this matters
  - Impact: user/business/engineering risk
  - Recommendation: smallest useful remediation
```

## Standard plan format

```markdown
## 优化/修复路线
1. Phase 1 — stop P0/P1 risk
   - Findings: ...
   - Files likely touched: ...
   - Tests: ...
2. Phase 2 — improve Laravel convention and maintainability
3. Phase 3 — harden performance, observability, and regression coverage
```
