# kl-test MVP changes

This ZIP contains only files that are new or modified for the Customer Login MVP.

## Included

- Laravel API routing
- Customer, Vehicle, ServiceBay, QualifiedEmployee and Appointment UUID/SoftDelete models
- Migrations for those entities and Sanctum personal access tokens
- Customer login endpoint: `POST /api/auth/login`
- Immutable DTOs
- Service + Action authentication flow
- FormRequest validation
- Pest 4 unit/feature tests
- Scramble OpenAPI dependency

## Apply

Extract this ZIP into the root of your existing `kl-test` checkout, preserving paths.

Then run:

```bash
composer update
php artisan migrate
php artisan test
```

Scramble exposes `/docs/api` and `/docs/api.json` after dependencies are installed.

Note: `composer.lock` is intentionally not included because this package was prepared from the repository source without modifying the lockfile.
