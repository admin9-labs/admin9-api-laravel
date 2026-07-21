# Laravel 13 中后台审查检查清单 v1

Use this file to choose review scope, gather evidence, and classify supported risk for Admin9 API Laravel work. It is a project-local checklist, not a scoring rubric, maturity model, certification standard, Laravel official convention, or third-party package convention.

## Contents

- [Checklist boundaries](#checklist-boundaries)
- [Evidence checklist](#evidence-checklist)
- [Review scope checklist](#review-scope-checklist)
- [Risk classification](#risk-classification)
- [Coverage and finding format](#coverage-and-finding-format)

## Checklist boundaries

- Use each item as an inspection prompt, not as a mandatory architecture prescription.
- Report only risks supported by concrete evidence and explain the impact.
- Record missing or insufficient evidence as `Unknown`; do not treat it as a defect.
- Do not total checked items, assign weights, calculate scores, define maturity bands, or use checklist coverage as a pass/fail result.
- Do not judge architecture quality by whether the repository has `Services`, `Repositories`, Actions, DDD layers, or another preferred directory layout.
- Evaluate boundaries by behavior, coupling, cohesion, change cost, testability, and demonstrated risk in this repository.
- Do not call a personal preference a best practice. Identify the applicable Laravel official convention, third-party package convention, or explicit project design source.

## Evidence checklist

Keep different kinds of support explicit in each finding:

| Evidence kind | What qualifies | How to report it |
|---|---|---|
| Repository fact | Current code, config, migration, route, test, dependency lockfile, schema, or command output | Cite `path:line` or `path:line-line`; quote command output only when needed |
| Laravel official convention | Version-matched Laravel documentation | Name the topic/source; use Boost `search-docs` first when available |
| Third-party convention | Documentation or source matching the installed package version | Name the package, version, and source |
| Project design | Explicit user requirement, repository documentation, or a consistent local decision | Cite the requirement or repository path |
| Reviewer inference | Reasoning derived from cited evidence | Label it as inference and explain the causal link |
| Unknown | Missing, inaccessible, stale, conflicting, or insufficient evidence | State what could not be established and what evidence would resolve it |

Use direct current-repository evidence first for claims about current behavior. Use explicit project sources for intended design, official Laravel documentation for framework conventions, and version-matched package sources for third-party conventions. A generic opinion does not outrank concrete project evidence.

Confidence can be `High`, `Medium`, or `Low`, but it describes evidence strength only:

- **High**: multiple direct artifacts or an authoritative source plus matching repository evidence support the claim.
- **Medium**: direct evidence supports the claim, but inspection coverage is partial.
- **Low**: evidence is narrow and the conclusion relies substantially on inference.

Confidence is not a score and does not indicate repository maturity.

## Review scope checklist

Select the areas relevant to the user's requested scope. For each selected area, record `Reviewed`, `Not applicable`, or `Unknown` and cite the evidence used.

### Project context and boundaries

- Confirm installed PHP, Laravel, and package versions from current dependency files.
- Inspect route, controller, request, resource, policy, model, job, listener, and provider boundaries that participate in the reviewed flow.
- Look for unnecessary coupling, unclear ownership, duplicated rules, or framework layers that obscure behavior.
- Judge extraction or layering by demonstrated complexity and change pressure, not by a required Service/Repository/DDD shape.

### Routes and API contracts

- Inspect route grouping, middleware, names, parameter binding, and endpoint ownership.
- Check request/response shape, status codes, pagination, and error behavior against project contracts.
- Treat API versioning and Eloquent Resources as contextual design choices unless a project or compatibility requirement makes them necessary.

### Authentication, authorization, and security

- Trace authentication and server-side authorization for sensitive actions.
- Check input trust boundaries, mass assignment, sensitive-field exposure, file handling, configuration safety, secret handling, and applicable throttling.
- Verify security findings through reachable code paths; do not report theoretical vulnerabilities without showing exposure and impact.

### Data model and migrations

- Inspect schema constraints, foreign keys, indexes, nullability, casts, relationships, and write invariants relevant to the flow.
- Check migrations for focused intent, safe rollout assumptions, and reversibility where the deployment path requires it.
- Tie index findings to actual query predicates/orderings and plausible data volume rather than generic indexing preferences.

### Eloquent and performance

- Trace representative list/detail queries for N+1 behavior, unbounded reads, unnecessary payloads, and pagination or batch-processing needs.
- Check eager loading, selected columns, scopes, chunks, cursors, or caching only where access patterns justify them.
- Treat performance risk as `Unknown` when traffic, volume, query plans, or runtime evidence is required but unavailable.

### Validation and error handling

- Trace validation, authorization, normalized input, domain invariants, exceptions, and API error responses through representative write paths.
- Evaluate Form Requests or other abstractions by consistency and behavior, not by their mere presence.
- Distinguish malformed input, authorization failure, business conflict, and system failure when the API contract depends on it.

### Queues, cache, and scheduling

- Inspect slow or retryable side effects for queue suitability, idempotency, timeout, retry/backoff, and failed-job behavior.
- Inspect cache invalidation, lock scope, and stale-data consequences where caching or concurrency is present.
- Inspect scheduled work for overlap, duplicate side effects, and deployment/runtime assumptions.
- Mark the area `Not applicable` when the reviewed scope has no such behavior.

### Tests

- Inspect relevant feature and unit tests, factories, fakes, and database isolation.
- Look for happy paths, authorization failures, validation failures, important edge cases, and regression coverage for the reviewed risk.
- Do not claim a missing test until the relevant test locations and naming variants have been searched.
- A test count or directory presence alone does not establish quality.

### Configuration, deployment, and observability

- Check env/config separation, config-cache compatibility, logs with actionable context, and required queue/scheduler process assumptions.
- Review health checks, failure diagnostics, and deployment-sensitive behavior when they are in scope.
- Keep infrastructure conclusions `Unknown` when deployment configuration or runtime evidence is outside the repository.

### Maintainability and local consistency

- Inspect naming, type declarations, duplication, method complexity, magic strings, dead paths, and consistency with nearby code.
- Prefer existing useful Laravel and repository primitives before introducing abstractions.
- Recommend extraction only when it clarifies ownership, reduces meaningful duplication, improves testability, or addresses concrete change risk.
- Separate style polish from behavior, security, correctness, and operational risk.

## Risk classification

P0-P3 labels prioritize supported findings. They are not points, grades, maturity bands, or proof that the repository passes or fails the checklist.

### P0 - immediate blocker

Use P0 only when direct evidence shows an immediate critical condition, such as:

- An exploitable security vulnerability on a reachable path.
- A sensitive administrative action without effective server-side authentication or authorization.
- Likely data loss or corruption during normal operation.
- Broken production boot, routing, migration, or core workflow.
- A committed secret or direct sensitive-data exposure.

### P1 - high risk

Use P1 when evidence shows serious near-term correctness, security, or operational risk, such as:

- A systemic authorization gap affecting important resources.
- A core write path broadly trusts unvalidated input.
- Missing constraints or concurrency controls can violate critical data invariants.
- A demonstrable high-use N+1 or unbounded query creates material production risk.
- Queue, job, or scheduled behavior can duplicate critical side effects.
- A core path has a regression-prone behavior gap with no relevant test protection.

### P2 - material but non-urgent risk

Use P2 when evidence shows a maintainability, consistency, performance, or secondary correctness problem with meaningful impact, such as:

- Conflicting boundaries or duplication make likely changes unsafe or expensive.
- A non-critical API path has inconsistent response or error behavior.
- A secondary flow lacks important failure or edge-case coverage.
- A query or model issue is likely to degrade with evidenced usage patterns.
- A verified framework or package convention mismatch creates concrete maintenance or runtime risk.

### P3 - low risk or polish

Use P3 for localized cleanup with limited impact, such as:

- Naming or style inconsistency.
- Minor duplication without current correctness risk.
- A low-risk readability improvement.
- Non-blocking convention alignment.

When severity is uncertain, state the uncertainty and evidence needed instead of inflating the priority. Do not derive an overall verdict from the number of findings in each severity.

## Coverage and finding format

For a full-repository review, consider at least these surfaces when present and relevant:

- `composer.json` and dependency lockfiles
- `routes/web.php`, `routes/api.php`, and `routes/admin.php`
- `bootstrap/app.php` and `config/`
- `app/Http/Controllers`, `app/Http/Requests`, and `app/Http/Resources`
- `app/Models`, `app/Policies`, and `app/Providers`
- `app/Jobs`, `app/Listeners`, `app/Console`, and scheduled commands
- `database/migrations`, `database/factories`, and `database/seeders`
- `tests/Feature` and `tests/Unit`

Use `php artisan route:list --except-vendor` when route evidence is needed. Follow the selected mode's mutation boundary when running commands.

Record coverage without grading it:

```markdown
| Area | Status | Evidence or limitation |
|---|---|---|
| Authorization | Reviewed | `routes/admin.php:...`, `app/Policies/...` |
| Queue behavior | Not applicable | No queued side effect in the reviewed flow |
| Production runtime | Unknown | Deployment configuration was unavailable |
```

Use this finding format and omit source labels that genuinely do not apply:

```markdown
- Finding: short, testable title
  - Severity: P0/P1/P2/P3
  - Confidence: High/Medium/Low
  - Repository fact: `path:line-line` - direct evidence
  - Laravel official convention: verified source or not applicable
  - Third-party convention: verified package source or not applicable
  - Project design: explicit source or not established
  - Reviewer inference: why the evidence creates risk
  - Impact: user, business, security, operational, or engineering consequence
  - Recommendation: smallest useful remediation
```

For unsupported areas, report `Unknown` separately rather than manufacturing a finding.
