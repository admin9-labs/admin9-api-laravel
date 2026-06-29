---
name: admin9-laravel-quality-review
description: "Project-specific Laravel 13 middle/back-office quality workflow for Admin9 API Laravel. Use when the user asks to review, audit, score, benchmark, optimize, refactor, fix, verify, or improve the current Laravel admin/backend project quality against a fixed standard. Covers read-only quality reviews, P0/P1/P2/P3 finding classification, evidence-based remediation planning, safe Laravel best-practice fixes, and post-fix verification."
---

# Admin9 Laravel Quality Review

Use this project skill to keep Laravel 13 quality reviews and follow-up fixes stable across different sessions. It defines a fixed rubric, severity rules, evidence requirements, and mode boundaries for the Admin9 API Laravel project.

## Required reference

Read `references/review-standard-v1.md` before every review, remediation plan, fix, or verification pass. Treat it as the stable scoring and severity contract for this project.

Also use the project `laravel-best-practices` skill for Laravel code changes, refactors, or implementation reviews.

## Mode selection

Choose exactly one mode from the user's wording:

1. **Review mode** — user asks whether the project is mature, compliant, high quality, risky, or asks for audit / review / score.
   - Stay read-only.
   - Do not edit files, create docs, run migrations, or commit.
   - Use the fixed rubric and output evidence-backed findings.

2. **Optimization planning mode** — user asks how to improve, optimize, clean up, or prepare remediation, but does not ask to execute.
   - Stay read-only.
   - Convert review findings into a phased plan.
   - Include expected tests and risk controls.

3. **Fix mode** — user explicitly asks to fix, optimize, refactor, or implement improvements.
   - Before editing, bind the work to specific findings or a clearly scoped target.
   - Prefer small reversible changes and existing Laravel conventions.
   - Add or update tests for behavior changes.
   - Run targeted tests, then Pint for PHP changes.

4. **Verification mode** — user asks whether a fix is complete, whether quality improved, or asks for re-review after changes.
   - Re-check only the impacted rubric areas unless the user asks for a full review.
   - Compare before/after findings where evidence is available.
   - Do not claim global maturity from a narrow verification.

## Official-doc discipline

For Laravel behavior, syntax, or best-practice claims, use Laravel Boost `search-docs` first when available. Scope queries to the relevant package, usually `laravel/framework`, and keep queries topic-based, such as `form request validation`, `authorization policies`, `eloquent resources`, `queues timeouts`, or `database testing`.

## Evidence rules

- Every finding must cite concrete repository evidence using `path:line-line`.
- Do not report a missing pattern until the relevant directories/files have been searched.
- Label unsupported areas as `Unknown`, not as defects.
- Separate direct evidence from inference.
- Prefer current project evidence over generic Laravel opinions.
- Keep unrelated dirty work untouched.

## Review workflow

1. Read `references/review-standard-v1.md`.
2. Capture project shape: `composer.json`, `routes/`, `app/Http`, `app/Models`, `app/Policies`, `app/Providers`, `database/migrations`, `database/factories`, `database/seeders`, `tests/`, `config/`, and `bootstrap/app.php`.
3. Inspect route and controller boundaries before judging API quality.
4. Inspect representative write paths before judging validation, authorization, and transactions.
5. Inspect representative list/read paths before judging Eloquent performance.
6. Inspect tests before judging maturity.
7. Score only what the repository evidence supports.

## Review output contract

Use this structure for full reviews:

```markdown
## 总体结论
- 成熟度评分：X/100
- 结论：成熟 / 基本可用 / 有明显生产风险 / 原型级
- 置信度：High / Medium / Low

## 分项评分
| 维度 | 分数 | 证据摘要 |
|---|---:|---|

## P0/P1/P2/P3 问题清单
### P0
- 无 / ...

### P1
- 问题：...
  - Evidence: `path:line-line`
  - Impact: ...
  - Recommendation: ...

## 做得好的地方
- Evidence: `path:line-line` — ...

## 优先改进路线
1. ...

## Unknown / 限制
- ...
```

## Fix workflow

When executing fixes:

1. Restate the selected findings and write scope.
2. Check sibling patterns before editing.
3. Use Artisan generators for new Laravel classes when appropriate.
4. Keep migrations, controllers, requests, policies, resources, and tests aligned.
5. Run the narrowest relevant tests first.
6. Run `vendor/bin/pint --dirty --format agent` after PHP changes.
7. Report changed files, verification commands, and remaining risks.

## Scoring stability rules

- Use the same `references/review-standard-v1.md` weights every time unless the user explicitly asks to revise the standard.
- Do not change category weights mid-review.
- Do not compare scores from different rubric versions without noting the version.
- If evidence is incomplete, lower confidence instead of inventing a score.
