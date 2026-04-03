# CLAUDE.md — Respaid Hiring Challenge

## Rules
- Primary branch is `main` (not `master`). Always branch from `main`, target PRs to `main`.
- Branch naming: `feat/TICKET-ID-short-desc` or `fix/TICKET-ID-short-desc`
- PR title must include `[TICKET-ID]`
- All code must have tests (unit in `tests/Unit/`, feature in `tests/Feature/`)
- Run `php artisan config:clear` before tests
- Run `php artisan test --parallel`

## Conventions
- Module-based structure: Models in `app/Modules/`, Controllers in `app/Http/Controllers/`
- Use queued jobs for heavy operations
- Use transactions for multi-step DB operations
- Log important actions with context
- Never use negative words in user-facing text (rejected, denied, failed, unable)
- Use `when()` for conditional query building
- Follow existing code patterns — read before writing

## PR Template

```markdown
## [TICKET-ID] Title

### Summary
- What changed and why

### Test plan
- [ ] How to verify this works
```

## Before Submitting
1. Run tests: `php artisan config:clear && php artisan test --parallel`
2. Check your diff: `git diff main...HEAD`
3. Verify no hardcoded IDs, no missing migrations, no untested code paths
4. Make sure all new files follow the module-based structure
5. Ensure terminal sequences (`cancelled`, `recovered`) never receive notifications — this is a core business invariant
