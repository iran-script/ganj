# Laravel Feature Testing

These rules apply to Laravel feature tests in this repository. The project currently uses Laravel 13, PHPUnit 12, PostgreSQL/PostGIS, and Predis.

## Database isolation

- Run tests with `APP_ENV=testing` and a dedicated, disposable PostgreSQL/PostGIS database.
- Before running tests, verify the application's effective `DB_HOST`, `DB_DATABASE`, and `APP_ENV`. Query `current_database()` through the same connection the test process uses and confirm that it is the dedicated test database.
- Never point `RefreshDatabase`, `DatabaseMigrations`, or any write-capable test at a VPS, production database, shared staging database, or a copy containing real user data.
- Do not run `migrate:fresh`, `db:wipe`, seeders, or destructive cleanup against an operational database.
- Keep test-only PostgreSQL and Redis services on a private test network with no published ports. Do not reuse production service endpoints or credentials.

## Authentication and authorization coverage

- For each protected endpoint, test the unauthenticated response and its error code.
- Test an authorized actor's successful operation and the relevant role or ownership boundary.
- Test a signed-in actor who lacks the required role, permission, or resource ownership. Assert the API's documented 403 or 404 behavior and verify that the operation did not change protected data.
- For personal resources such as payment history, prove that a user can see their own records and cannot see another user's records.
- Preserve security assertions. Do not remove, skip, weaken, or rewrite an assertion merely to make a suite pass; first establish the intended API scope and authorization contract.

## API and route contracts

- Use the existing feature-test base class, factories, and domain fixtures. Assert response status, error code, JSON shape, and relevant database effects.
- Keep route-contract assertions scoped to the route group, names, or controller owned by that contract. A contract for games, sessions, or scenarios must not prohibit an unrelated authenticated route elsewhere in the API.
- Continue to assert that each route in the contract has the documented authentication middleware and OpenAPI security declaration.
- When adding a route, add regression coverage for authentication, ownership or role authorization, and the intended success path.

## External dependencies

- Exercise real Redis behavior in tests that cover Redis streams or queue integration. Provide a disposable Redis service on the private test network rather than connecting to production Redis or replacing the behavior with a mock.
- Use the project's configured Predis client in the test environment (`REDIS_CLIENT=predis`). Keep test service addresses and settings scoped to the test process; do not change production configuration to make tests pass.
- Mock only an external boundary when the existing test architecture already treats it as a boundary and the behavior under test does not depend on the integration itself.

## Running tests

- Run the narrowest affected PHPUnit files first, then the complete Feature suite for release validation:
  - `php vendor/bin/phpunit tests/Feature/ExampleTest.php`
  - `php vendor/bin/phpunit tests/Feature`
- Do not claim release readiness unless the required suite finishes with zero failures and zero errors. Record the test, assertion, failure, error, and skipped counts.
- Run PHP lint on changed PHP files and format changed PHP files with Laravel Pint.
