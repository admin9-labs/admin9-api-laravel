---
name: admin9-laravel-quality-review
description: "Explicit-invocation repository checklist for Admin9 API Laravel quality work. Use only when the user invokes `$admin9-laravel-quality-review` and requests a review, remediation plan, fix, or verification pass grounded in project evidence, P0-P3 risk, and Unknowns. Do not use it as a maturity score, pass/fail standard, Laravel-official convention, third-party package convention, or general architecture authority."
---

# Admin9 Laravel Quality Review

Use this project skill as a reusable inspection checklist for Laravel 13 reviews and follow-up work. It helps gather evidence consistently and triage risk; it does not score the repository, certify maturity, or decide that one architecture is universally correct.

## Authority boundary

- Treat the checklist as project-local process guidance only.
- Do not present checklist items as Laravel official guidance, third-party package requirements, industry consensus, or proof of architecture quality.
- Do not infer maturity from the presence or absence of `Services`, `Repositories`, Actions, DDD layers, or any other directory pattern.
- Do not present personal preferences as best practices. Tie a convention claim to version-matched documentation, an explicit project decision, or concrete risk evidence.
- Limit conclusions to supported findings, observed strengths, and `Unknown` areas. Do not derive a score, maturity band, or pass/fail verdict from checklist coverage.

## Required reference

Read `references/review-standard-v1.md` before every review, remediation plan, fix, or verification pass. Use it only as the review-scope, evidence, and risk checklist.

Also use the project `laravel-best-practices` skill whenever reviewing or changing Laravel PHP code. That skill remains guidance rather than a substitute for version-matched official or package documentation.

## Mode selection

Choose exactly one mode from the user's request:

1. **Read-only review mode**
   - Do not edit files, create documentation, run migrations, or commit.
   - Inspect the requested scope and report evidence-backed risks, strengths, and `Unknown` areas.
   - Do not assign scores, maturity bands, or checklist-based approval status.
2. **Optimization planning mode**
   - Stay read-only.
   - Convert supported findings into a phased plan with expected tests and risk controls.
3. **Fix mode**
   - Edit only when the user explicitly requests implementation.
   - Bind changes to specific findings or a clearly scoped target.
   - Prefer small, reversible changes that match verified repository and Laravel conventions.
   - Add or update tests when behavior changes.
4. **Verification mode**
   - Re-check impacted findings unless the user requests a full review.
   - Compare before-and-after evidence when available.
   - Do not generalize narrow verification into repository-wide quality claims.

## Documentation discipline

For Laravel behavior, syntax, or convention claims, use Laravel Boost `search-docs` first when available. Scope it to the relevant installed package, usually `laravel/framework`, and use topic-based queries such as `form request validation`, `authorization policies`, `eloquent resources`, `queue timeouts`, or `database testing`.

For third-party behavior or conventions, use documentation or source that matches the installed package version. If the appropriate source cannot be checked, mark the claim `Unknown` or clearly label it as reviewer inference.

## Evidence discipline

Use the source closest to each claim and keep these labels distinct:

- **Repository fact**: current code, config, migration, route, test, dependency lockfile, schema, or command output. Cite source files as `path:line` or `path:line-line`.
- **Laravel official convention**: version-matched Laravel documentation, verified with `search-docs` when available.
- **Third-party convention**: version-matched package documentation or source.
- **Project design**: explicit user requirements, repository documentation, or a consistent local decision supported by concrete paths.
- **Reviewer inference**: a reasoned conclusion from cited facts; explain the reasoning and do not phrase it as authority.
- **Unknown**: evidence is missing, inaccessible, stale, or too narrow to decide.

Prioritize direct current-repository evidence, then the explicit project design source relevant to the decision, then version-matched framework or package sources. Use reviewer inference last. Do not report an absent pattern until the relevant locations have been searched, and do not turn incomplete coverage into a defect.

## Review workflow

1. Read `references/review-standard-v1.md`.
2. Confirm the requested scope, mode, current diff, and dirty-work boundaries.
3. Inventory only the repository surfaces needed for the requested review; use the reference's broader surface for a full-repository review.
4. Inspect route and controller boundaries before evaluating API behavior.
5. Inspect representative write paths before evaluating validation, authorization, transactions, and side effects.
6. Inspect representative read/list paths before evaluating Eloquent performance.
7. Inspect relevant tests before claiming a regression gap.
8. Verify framework and package convention claims against the appropriate authority.
9. Classify supported risks as P0-P3, without converting severity counts into a score or maturity verdict.
10. Record uninspected or unsupported areas as `Unknown`.

## Review output contract

Use this structure for a full review, adapting detail to the requested scope:

```markdown
## Scope and mode
- Mode: Read-only review
- Reviewed: ...
- Not reviewed / unavailable: ...

## Risk summary
- P0: ...
- P1: ...
- P2: ...
- P3: ...

## Findings
- Finding: ...
  - Severity: P0/P1/P2/P3
  - Repository fact: `path:line-line` - ...
  - Convention or project design source: Laravel official / third-party / project design / none
  - Reviewer inference: ...
  - Impact: ...
  - Recommendation: ...

## Observed strengths
- Repository fact: `path:line-line` - ...

## Checklist coverage
| Area | Status | Evidence or limitation |
|---|---|---|
| ... | Reviewed / Not applicable / Unknown | ... |

## Unknown / limitations
- ...

## Prioritized next steps
1. ...
```

Do not add an overall numeric score, category weights, maturity band, or checklist-derived pass/fail verdict.

## Fix workflow

When executing fixes:

1. Restate the selected findings and allowed write scope.
2. Check sibling patterns and current documentation before editing.
3. Use Artisan generators for new Laravel classes when appropriate.
4. Keep migrations, controllers, requests, policies, resources, and tests aligned where the change crosses those boundaries.
5. Run the narrowest relevant tests first.
6. Run `vendor/bin/pint --dirty --format agent` after PHP changes.
7. Report changed files, verification commands, and remaining P0-P2 risks or `Unknown` areas.
