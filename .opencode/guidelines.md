# Project Guidelines

## Laravel Best Practices

### Code Generation & Artisan
- Use `php artisan` (or `vendor/bin/testbench`) commands to create new files (migrations, controllers, models, etc.) whenever possible.
- Pass `--no-interaction` to ensure commands run without hanging.
- If creating a generic PHP class, consider if it fits a standard Laravel concept (Service, Action, Job) first.

### Database & Eloquent
- **Relationships:** Always use proper Eloquent relationship methods with return type hints. Prefer relationships over raw joins.
- **Queries:** Avoid `DB::` facade when `Model::query()` can be used.
- **Performance:** Prevent N+1 query problems by using eager loading (`with()`).
- **Migrations:** When modifying columns, ensure all previous attributes are retained.

### Architecture
- **Validation:** Use Form Request classes for validation logic, not inline controller validation.
- **Config:** Access environment variables *only* through configuration files using `config()`. **Never** use `env()` directly in application code.
- **Helpers:** Prefer Laravel's helper functions (e.g., `str()`, `route()`, `collect()`) over raw PHP functions when idiomatic.

### Laravel 12 Context
- This project targets Laravel 12.
- Note that Laravel 12 uses a streamlined application structure. Middleware and exceptions are configured in `bootstrap/app.php`.
- Console commands are automatically discovered.

## Testing (Pest PHP)

### Framework
- All tests must be written using **Pest PHP**.
- Use the functional syntax: `it('does something', function () { ... })`.

### Assertions & Mocking
- **Specific Assertions:** Use specific assertions like `assertForbidden()`, `assertNotFound()`, `assertRedirect()` instead of generic `assertStatus()`.
- **Mocking:** Use `Pest\Laravel\mock` or `$this->mock()` for mocking dependencies.
- **API Calls:** **ALWAYS** mock external Cloudflare API calls. Never allow tests to hit the live Cloudflare API.

### Test Structure
- Organize tests in `tests/Feature` and `tests/Unit`.
- Use `describe()` blocks to group related tests within a file.
- Use `beforeEach()` to set up common state.

## Cloudflare Integration Safety

### API Interaction
- **Mocking:** As strictly noted above, absolutely no live API calls in the test suite.
- **Validation:** All inputs destined for the Cloudflare API (Zone IDs, Email addresses, API Keys) must be strictly validated before being sent.
- **Destructive Actions:** Actions like "Purge Everything" or modifying WAF rules must have safeguards or explicit confirmation steps in the code (e.g., specific flags or prompt confirmations in console commands).

### Error Handling
- Gracefully handle Cloudflare API exceptions. The package should not crash if Cloudflare is down or returns a 500 error; it should catch the exception and report/log it appropriately.
