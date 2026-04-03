# CLAUDE.md — Suggested Improvements (Laravel Modern Style)

## 1. Enforce PHP 8.3+ / Laravel 12 Language Features
Use `readonly` classes for single-responsibility actions and DTOs, backed enums for all status/type columns, and constructor property promotion everywhere — no legacy array bags or string constants.

## 2. Prefer Spatie Laravel-Data DTOs Over Raw Request Arrays
All incoming request data must be cast to a `Spatie\LaravelData\Data` object (e.g. `CompanySearchCriteria`) with validation attributes on constructor properties, keeping controllers thin and validation co-located with the data shape.

## 3. Use Single-Action Classes (Invokable / `execute()`)
Every business operation should live in its own `final readonly` Action class with one public method (`execute`), avoiding fat models and fat controllers.

## 4. Typed Return Types & Strict Types on Every File
Every PHP file must declare `declare(strict_types=1)` and every method must have explicit return types — no `mixed` unless truly unavoidable.

## 5. Scope Queries via Eloquent Local Scopes
Reusable query conditions (e.g. `search()`, `withStatus()`) should be defined as local scopes on the model, not inline `where()` chains in controllers or actions.

## 6. Use PHP Enums for API Filter Values
Separate domain enums (`CompanyStatus`) from API-layer filter enums (`CompanyStatusFilter`) so the API can expose extra options like `all` without polluting domain logic.

## 7. Prefer `final` by Default
Mark classes `final` unless explicitly designed for inheritance — this aligns with the existing codebase pattern and prevents accidental coupling.

## 8. API Versioning & Resource Responses
Controllers live under `Api/V1/` and must return `JsonResource` or `Data` responses — never raw arrays or Eloquent models directly.