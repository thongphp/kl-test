# Keyloop MVP — System Design

## 1. Context

Keyloop is a Laravel-based MVP for customer vehicle service appointments.

The MVP provides an API-first booking flow where an authenticated customer selects a vehicle, dealership, service type, and desired start time. The application derives the service duration and selects one available Technician and one available Service Bay.

The domain is intentionally simple:

- `ServiceType` is independent.
- `ServiceType` owns duration.
- All active ServiceTypes are available at all active Dealerships.
- All active Technicians and Service Bays at a Dealership can perform all active ServiceTypes.
- No capability/qualification matrices are modeled in the MVP.

The main technical challenge is safe resource allocation under concurrency.

## 2. Goals

- Provide a REST API for appointment booking.
- Authenticate customers with Laravel Sanctum.
- Keep controllers thin.
- Keep business logic in Actions and focused Services.
- Use immutable DTOs at application boundaries.
- Use UUID identifiers.
- Use SoftDeletes where appropriate.
- Calculate appointment duration server-side.
- Prevent overlapping Technician and Service Bay reservations.
- Make booking atomic.
- Use Redis as a first-layer coordination/advisory mechanism while keeping the database authoritative.
- Maintain strong automated test coverage.

## 3. Current Tech Stack

| Area | Technology |
|---|---|
| Runtime | PHP 8.5.x |
| Framework | Laravel 13.x |
| API | Laravel REST API |
| Authentication | Laravel Sanctum |
| Database | SQLite for development/tests |
| Production database | PostgreSQL or MySQL |
| Cache/coordination | Redis |
| API documentation | Dedoc Scramble / OpenAPI |
| Testing | Pest 4 / PHPUnit 12 |
| ORM | Laravel Eloquent |
| Identifiers | UUID |
| Deletion strategy | SoftDeletes |
| Dependency management | Composer |
| Test-only final-class mocking | `nunomaduro/mock-final-classes` |

## 4. High-Level Architecture

```text
HTTP Request
    |
    v
Controller
    |
    v
FormRequest
    |
    v
Action
    |
    v
AppointmentBookingService
    |
    +--> CustomerService
    +--> DealershipService
    +--> ServiceTypeService
    +--> TechnicianService
    +--> ServiceBayService
    +--> AppointmentService
    +--> RedisBookingLockService
    +--> RedisAvailabilityService
    |
    v
Eloquent Models
    |
    v
Database

Supporting infrastructure:
    Redis
```

### Controller

Controllers are HTTP orchestration only.

They must not contain:

- booking rules;
- availability logic;
- overlap logic;
- resource selection;
- transaction logic;
- Redis coordination.

### FormRequest

FormRequests own HTTP validation and authorization.

The booking request accepts only customer-controlled input:

```text
vehicle_id
dealership_id
service_type_id
start_at
```

The client cannot provide:

```text
customer_id
technician_id
service_bay_id
end_at
```

### Action

`CreateAppointmentAction` represents the booking use case.

It receives validated input, constructs `CreateAppointmentData`, and delegates to `AppointmentBookingService`.

### AppointmentBookingService

This is the booking orchestration service and owns the transaction boundary.

It coordinates:

- customer/vehicle validation;
- dealership validation;
- ServiceType validation;
- duration calculation;
- Redis lock;
- Redis availability candidates;
- authoritative DB resource checks;
- appointment creation;
- post-commit Redis indexing.

## 5. Booking Flow and Concurrency Protection

```text
Booking Request
      |
      v
Redis dealership lock
booking:dealership:{dealership_id}
      |
      v
DB transaction
      |
      +--> lock dealership row
      |
      +--> validate customer / vehicle
      |
      +--> validate dealership
      |
      +--> validate ServiceType
      |
      +--> calculate end_at
      |
      +--> consult Redis availability index
      |
      +--> authoritative DB overlap checks
      |
      +--> select Technician
      |
      +--> select Service Bay
      |
      +--> create Appointment
      |
      v
COMMIT
      |
      v
Update Redis availability index
      |
      v
Release Redis lock
```

### Why two layers?

Redis provides fast coordination and candidate filtering.

The database provides the authoritative booking decision.

Therefore:

```text
Redis = first layer
Database = final authority
```

A Redis result can never replace the database overlap check.

## 6. Redis Booking Lock

The lock key is:

```text
booking:dealership:{dealership_id}
```

The lock is intentionally scoped to the dealership.

It does not include customer ID.

This means:

```text
Dealership A booking -> Dealership A lock
Dealership B booking -> Dealership B lock
```

Bookings at different dealerships do not unnecessarily block one another.

If the lock cannot be acquired because another booking currently holds it:

```text
409 BOOKING_IN_PROGRESS
```

If Redis itself is unavailable, the booking continues through the authoritative database locking path.

Redis lock failure does not make Redis authoritative.

## 7. Redis Availability Index

Redis maintains advisory availability information for Technicians, Service Bays, and appointments.

Conceptual keys:

```text
booking:availability:dealership:{dealership_id}:technicians
booking:availability:dealership:{dealership_id}:service-bays
booking:availability:technician:{technician_id}
booking:availability:service-bay:{service_bay_id}
booking:availability:appointment:{appointment_id}
```

The index is used to produce candidate resource IDs.

The authoritative resource services still query the database to apply:

```text
existing.start_at < requested.end_at
AND
existing.end_at > requested.start_at
```

Redis is updated only after a successful database commit.

If Redis indexing fails after commit, the appointment remains valid because the database already established the authoritative state.

## 8. Database Transaction

The booking transaction contains the complete authoritative operation:

```text
BEGIN

Lock dealership row
Validate customer and vehicle
Validate dealership
Validate ServiceType
Calculate end_at
Get Redis candidate resources
Check authoritative DB availability
Create Appointment

COMMIT
```

On any exception:

```text
ROLLBACK
```

This guarantees that a failed booking cannot leave a partial Appointment.

The dealership row lock is the authoritative serialization boundary for booking requests targeting the same dealership.

## 9. Resource Selection

`TechnicianService` and `ServiceBayService` have focused responsibilities.

Both select only resources that:

- belong to the requested dealership;
- are active;
- are not blocked by an overlapping confirmed appointment.

Selection is deterministic, using ID ordering.

The MVP does not model technician qualification or service-bay capability.

## 10. Domain Model

```text
Customer
    |
    +---- Vehicle
    |
    +---- Appointment
              |
              +---- Dealership
              |       |
              |       +---- Technician
              |       +---- ServiceBay
              |
              +---- ServiceType
```

Appointment stores:

```text
customer_id
vehicle_id
dealership_id
service_type_id
technician_id
service_bay_id
start_at
end_at
status
```

`dealership_id` is stored explicitly even though Technician and ServiceBay also belong to a Dealership.

## 11. Time and Overlap

Service duration is owned by `ServiceType`:

```text
end_at = start_at + service_type.duration
```

Appointment intervals are half-open:

```text
[start_at, end_at)
```

Back-to-back appointments are valid.

Example:

```text
Existing:   09:00 -------- 10:00
Requested:              10:00 -------- 11:00
```

Conflict rule:

```text
existing.start_at < requested.end_at
AND
existing.end_at > requested.start_at
```

API timestamps use ISO-8601 with an explicit offset and appointment times are stored in UTC.

## 12. Authentication and Ownership

Laravel Sanctum authenticates customers.

The authenticated user determines customer identity.

Customers may:

- create appointments only for their own vehicles;
- list only their own appointments;
- retrieve only their own appointments;
- cancel only their own appointments.

The client never supplies `customer_id` as an authoritative value.

## 13. API

Booking:

```http
POST /api/v1/appointments
```

Future/next-phase customer endpoints:

```http
GET /api/v1/appointments
GET /api/v1/appointments/{appointment}
POST /api/v1/appointments/{appointment}/cancel
```

Responses use Laravel API Resources.

## 14. Error Semantics

Relevant booking conflicts include:

```text
409 NO_RESOURCE_AVAILABLE
409 BOOKING_IN_PROGRESS
```

Validation/business input failures use:

```text
422
```

The API must not expose internal resource availability details.

## 15. Idempotency

Idempotency is a separate phase.

The booking endpoint will support:

```text
Idempotency-Key
```

with 24-hour retention.

The idempotency mechanism must integrate with the existing booking transaction and concurrency model.

## 16. Testing Strategy

Feature tests exercise the HTTP booking workflow.

Phase 4 specifically verifies:

- transaction rollback after persistence failure;
- no partial booking state;
- Redis lock contention;
- `BOOKING_IN_PROGRESS`;
- Redis availability fallback to DB;
- post-commit Redis indexing;
- existing overlap behavior.

The test environment uses:

```text
Pest 4
PHPUnit 12
nunomaduro/mock-final-classes
```

The final-class mocking package is development-only and does not participate in production runtime behavior.

Explicit multi-process concurrency/race testing remains part of the later complete test and production-hardening work.

## 17. Database

Development/test database:

```text
SQLite
```

Production database:

```text
PostgreSQL or MySQL
```

Recommended appointment indexes:

```text
appointments(technician_id, start_at, end_at)
appointments(service_bay_id, start_at, end_at)
```

The booking implementation must not rely on SQLite-specific locking behavior.

## 18. Redis Failure Model

Redis is supporting infrastructure.

### Redis available

Use:

```text
Redis lock
    +
Redis availability candidates
    +
DB authoritative check
```

### Redis unavailable

Use:

```text
DB lock
    +
DB authoritative availability check
```

### Redis disagrees with DB

Use:

```text
DB result
```

The database always wins.

## 19. Project Structure

```text
app/
├── Actions/
├── DTOs/
├── Exceptions/
├── Http/
│   ├── Controllers/
│   └── Requests/
├── Models/
└── Services/
    ├── CustomerService.php
    ├── DealershipService.php
    ├── ServiceTypeService.php
    ├── TechnicianService.php
    ├── ServiceBayService.php
    ├── AppointmentService.php
    ├── AppointmentBookingService.php
    ├── RedisBookingLockService.php
    └── RedisAvailabilityService.php

routes/
└── api.php

tests/
├── Feature/
└── Unit/

docs/
├── SYSTEM-DESIGN.md
├── mvp-tech-final.md
└── plan.md
```

## 20. Development Principles

1. Keep controllers thin.
2. Validate HTTP input in FormRequests.
3. Put application use cases in Actions.
4. Put reusable business logic in focused Services.
5. Keep the complete booking transaction in `AppointmentBookingService`.
6. Use the database as the authoritative source of truth.
7. Use Redis only as a first-layer lock/index.
8. Never trust Redis availability as final truth.
9. Use immutable DTOs at application boundaries.
10. Prefer Eloquent relationships over raw SQL.
11. Keep API contracts stable and documented.
12. Avoid abstractions without a concrete reason.
13. Add automated tests for new business rules.

## 21. Current MVP Status

Completed:

- Project baseline.
- Domain models and migrations.
- Sanctum authentication and ownership.
- ServiceType and core booking domain.
- Appointment resource selection.
- Server-derived appointment duration.
- Half-open overlap handling.
- Database transaction boundary.
- Dealership-level Redis booking lock.
- Database dealership row locking.
- Redis advisory availability index.
- Redis failure fallback.
- Post-commit Redis index update.
- Phase 4 rollback and contention tests.

Next:

- Phase 5 — Idempotency.
- Phase 6 — API Resources, list/detail, and cancellation.
- Phase 7 — Error handling and API contract.
- Phase 8 — Complete automated test suite.
- Phase 9 — Production hardening.

## 22. Architectural Decision Summary

```text
Simple domain
    +
Focused Services
    +
Immutable DTOs
    +
Redis first-layer coordination
    +
Authoritative database transaction
    +
Dealership database locking
    +
Correct overlap detection
    +
Idempotency
    +
Strong automated tests
```

The objective is not to build a generalized scheduling platform.

The objective is to reliably answer:

> Can this customer book this vehicle at this dealership for this ServiceType at this time, and can the system reserve exactly one available Technician and one available Service Bay without double booking them?
