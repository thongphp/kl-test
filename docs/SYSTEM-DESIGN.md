# Keyloop MVP — System Design

## 1. Context

Keyloop is a Laravel-based MVP for managing customers, vehicles, service bays, qualified employees, and appointments.

The MVP is API-first and is intended to provide a clean foundation for future business workflows such as vehicle test drives and appointment scheduling.

The application is currently designed as a single Laravel application with a REST API, relational persistence through Eloquent, Redis for caching, and OpenAPI documentation.

## 2. Goals

- Provide a REST API for the MVP domain.
- Support customer authentication.
- Keep business logic outside controllers.
- Separate application actions from domain/service logic.
- Use immutable DTOs at Action/Service boundaries.
- Use UUIDs as model identifiers.
- Use SoftDeletes for domain models.
- Provide API documentation through OpenAPI/Swagger-compatible documentation.
- Maintain high automated-test coverage for Actions and Services.
- Keep the architecture simple enough for an MVP while allowing future growth.

## 3. Current Tech Stack

| Area | Technology |
|---|---|
| Runtime | PHP 8.5.x |
| Framework | Laravel 13.x |
| API | Laravel REST API |
| Authentication | Laravel Sanctum |
| Database | SQLite |
| Cache | Redis |
| API documentation | Dedoc Scramble / OpenAPI |
| Testing | Pest 4 / PHPUnit 12 |
| ORM | Laravel Eloquent |
| Identifiers | UUID |
| Deletion strategy | SoftDeletes |
| Dependency management | Composer |

Composer targets PHP `^8.5`, meaning PHP 8.5 or newer within the PHP 8.x-compatible Composer constraint.

## 4. Architecture

The application follows a layered application architecture:

```text
HTTP Request
    |
    v
Controller
    |
    +--> FormRequest
    |
    v
Action
    |
    v
Service
    |
    v
Eloquent Model
    |
    v
Database
```

Supporting infrastructure:

```text
                    +----------------+
                    |   REST API     |
                    +-------+--------+
                            |
                       Controllers
                            |
                         Actions
                            |
                         Services
                       /          \
                      /            \
                Eloquent          Redis
                   |
                SQLite
```

### Controller

Controllers are intentionally thin.

Responsibilities:

- Receive HTTP requests.
- Inject FormRequests for validation.
- Inject Actions.
- Convert validated request data into Action input/DTOs.
- Return API responses.

Controllers should not contain business rules or direct database queries.

### FormRequest

FormRequests own HTTP input validation and authorization concerns.

They should validate request-specific input before an Action is executed.

### Action

Actions represent application-level use cases.

Examples:

- Login a customer.
- Create an appointment.
- Assign a qualified employee.

Actions receive validated input and coordinate Services.

Actions should not directly query the database.

### Service

Services contain reusable business/domain logic.

Services are the only application-layer components, apart from Eloquent Models themselves, that should interact with the persistence layer.

Services may:

- Query Eloquent models.
- Create/update/delete models.
- Apply business rules.
- Interact with infrastructure such as cache where appropriate.

### DTO

DTOs provide explicit boundaries between Services and Actions.

DTOs should be immutable and represent structured application data rather than HTTP-specific request objects.

## 5. Domain Model

The current MVP contains these core entities:

### Customer

Represents a customer who can authenticate with email/password.

Key information:

- UUID
- First name
- Last name
- Email
- Password
- Timestamps
- Soft deletion

### Vehicle

Represents a vehicle associated with a customer.

Key information includes:

- UUID
- Name
- Brand
- Color
- Unique PIN
- Vehicle type
- Timestamps
- Soft deletion

### Service Bay

Represents a physical/service location where an appointment can take place.

Key information:

- UUID
- Name
- Location
- Timestamps
- Soft deletion

### Qualified Employee

Represents an employee qualified to perform a service.

Key information:

- UUID
- Name
- Unique EIN
- Timestamps
- Soft deletion

### Appointment

Represents a scheduled service interaction.

It connects:

- Customer
- Vehicle
- Service Bay
- Qualified Employee

It also contains:

- Start time
- End time
- Service type

Example service type:

- `test-drive`

## 6. Authentication

Customer authentication is provided through Laravel Sanctum.

Current endpoint:

```text
POST /api/auth/login
```

Example request:

```json
{
  "email": "customer@example.com",
  "password": "password"
}
```

Successful response:

```json
{
  "data": {
    "customer": {
      "id": "UUID",
      "first_name": "John",
      "last_name": "Doe",
      "email": "customer@example.com"
    },
    "token": "sanctum-token"
  }
}
```

Authentication responsibilities are separated as follows:

```text
AuthController
    |
LoginCustomerRequest
    |
LoginCustomerAction
    |
CustomerAuthenticationService
    |
Customer
```

## 7. API Design

API routes live under:

```text
routes/api.php
```

API responses should use a predictable JSON structure.

Successful resource/use-case responses should generally expose data through a `data` property.

Validation errors should use Laravel's standard JSON validation response format.

Authentication/authorization errors should use appropriate HTTP status codes.

## 8. Database

SQLite is the current MVP database because it keeps local development and automated testing lightweight.

Redis is the target cache backend.

All domain models should use:

- UUID primary keys.
- Timestamps.
- SoftDeletes.

Relationships should be represented using Eloquent relationships rather than manually constructed SQL.

Database access belongs behind the Service boundary.

## 9. Caching

Redis is used as the cache backend.

Caching should be introduced only where it provides a clear performance or consistency benefit.

Services should own cache-related business decisions rather than controllers.

Cache invalidation must be considered whenever cached domain data is mutated.

## 10. API Documentation

OpenAPI documentation is provided through Dedoc Scramble.

All public API endpoints should be documented.

Documentation should describe:

- HTTP method.
- URI.
- Authentication requirements.
- Request parameters/body.
- Validation rules.
- Response structure.
- HTTP status codes.
- Error responses.

## 11. Testing Strategy

Testing uses Pest 4 with PHPUnit 12 underneath.

### Unit tests

Actions and Services should have comprehensive unit coverage.

Target:

- Actions: 100%
- Services: 100%

### Integration / Feature tests

API endpoints should be tested through HTTP requests.

Important scenarios include:

- Successful authentication.
- Invalid credentials.
- Validation failures.
- Authentication/authorization failures.
- Successful domain operations.
- Domain-level business-rule failures.

Tests should use an isolated database state.

## 12. Project Structure

Important application directories:

```text
app/
├── Actions/
├── DTOs/
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Models/
└── Services/

routes/
└── api.php

tests/
├── Feature/
└── Unit/

docs/
└── SYSTEM-DESIGN.md
```

The exact directory structure may grow as additional use cases are introduced.

## 13. Development Principles

1. Keep controllers thin.
2. Validate HTTP input in FormRequests.
3. Put application use cases in Actions.
4. Put reusable business logic in Services.
5. Keep database interaction behind the Service boundary.
6. Use immutable DTOs between application layers.
7. Prefer Eloquent relationships over raw SQL.
8. Keep API contracts stable and documented.
9. Write tests with the use case in mind.
10. Avoid adding abstractions without a concrete reason.

## 14. Current MVP Status

Implemented foundation:

- Laravel 13.x application.
- PHP 8.5 Composer target.
- Customer model and authentication flow.
- Sanctum token authentication.
- Vehicle model.
- Service Bay model.
- Qualified Employee model.
- Appointment model.
- UUID-based domain models.
- SoftDeletes.
- Action/Service/DTO structure.
- API routes.
- OpenAPI documentation dependency.
- Pest 4 testing setup.
- Feature and unit test structure.

## 15. Known Limitations / Follow-up Work

The following areas should be reviewed as development continues:

- Complete API coverage for all domain entities.
- Complete authorization rules.
- Add factories and seeders for all domain models.
- Expand appointment business rules.
- Define and enforce appointment conflict rules.
- Add Redis-backed caching where useful.
- Review Sanctum token lifecycle/revocation.
- Ensure every model participating in the application follows the UUID and SoftDeletes conventions.
- Generate and verify the OpenAPI documentation.
- Add CI for PHP 8.5 and automated tests.
- Keep `composer.lock` synchronized with the Composer manifest after dependency installation/update.

## 16. Architectural Direction

The MVP should remain a modular Laravel monolith unless scale or team boundaries justify introducing separate services.

Future modules can be added using the same pattern:

```text
Controller
    -> FormRequest
    -> Action
    -> DTO
    -> Service
    -> Eloquent Model
```

This keeps the API layer independent from business rules while retaining Laravel's productivity and ecosystem.
