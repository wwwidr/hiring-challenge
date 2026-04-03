# TICKET-001: Add Company Search API Endpoint

## Problem
We need an API endpoint that allows searching companies by name, with pagination and filtering by status.

## Requirements
- GET `/api/v1/companies/search`
- Query params: `q` (search term, required), `status` (optional: active/inactive/all, default: all), `per_page` (optional, default: 15, max: 100)
- Response: paginated JSON with id, name, status, created_at
- Must validate input (q min 2 chars, per_page within range)
- Must return 422 for invalid input with clear error messages
- Must have Feature test covering: happy path, validation errors, empty results, pagination, status filter

## Files to Create/Modify
- `app/Http/Controllers/Api/V1/CompanySearchController.php`
- `routes/api.php`
- `tests/Feature/Api/V1/CompanySearchTest.php`

## Hints
- Company model already exists at `app/Modules/Company/Models/Company.php`
- Use Laravel's `when()` for conditional query building
- Follow the existing controller pattern in `app/Http/Controllers/Api/V1/`
