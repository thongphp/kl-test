# Implementation Plan — Resource-Constrained Appointment Booking MVP

## 1. Purpose

This document converts the frozen MVP technical requirements into a practical implementation checklist.

The goal is to provide a step-by-step implementation plan that can be used during real development, while keeping the MVP intentionally simple.

### Core MVP rule

> Book a ServiceType for a Vehicle at a Dealership when one active Technician and one active Service Bay belonging to that Dealership are both available for the entire duration defined by the ServiceType.

The booking must be atomic and must prevent overlapping resource reservations under concurrent requests.

---

## 2. Frozen MVP Decisions

These decisions should not be changed during implementation without explicitly revising the requirements.

- API version: `/api/v1`
- Authentication: Laravel Sanctum
- Customer identity: authenticated user
- `ServiceType`: independent first-class model
- ServiceType duration: stored on `ServiceType`
- Dealership service catalog: all active ServiceTypes
- Technician qualification: not modeled
- Service Bay capability: not modeled
- Resource selection: any active Technician + Service Bay at the selected Dealership
- Resource selection: deterministic, e.g. lowest ID
- Appointment interval: `[start_at, end_at)`
- Back-to-back appointments: allowed
- Time input: ISO-8601 with explicit offset
- Time storage: UTC
- Operating hours: out of MVP
- Appointment statuses: `confirmed`, `cancelled`
- Cancellation: explicit `/cancel` endpoint
- Public availability endpoint: not included
- Database: authoritative source of truth
- Redis: first-layer advisory availability index and dealership booking lock; never authoritative
- Redis availability check: first layer before authoritative DB availability selection
- Redis booking lock: `booking:dealership:{dealership_id}`
- Redis lock does not include customer ID
- Redis unavailable: fall back to authoritative DB path
- Redis lock contention: `409 BOOKING_IN_PROGRESS`
- DB transaction: required for booking
- DB dealership row lock: authoritative serialization boundary
- Idempotency: `Idempotency-Key`, retained for 24 hours
- Idempotency key scope: `(customer_id, key)`
- Idempotency fingerprint fields: `vehicle_id`, `dealership_id`, `service_type_id`, `start_at`
- Same customer + same key + same fingerprint: replay the original appointment result with `200 OK`
- Same customer + same key + different fingerprint: `422`
- Expired idempotency records do not block key reuse
- Expired idempotency records are handled on use; no scheduled cleanup is required for MVP
- Idempotency record is created/completed inside the booking transaction
- Concurrent same-key requests use the existing dealership serialization plus the database uniqueness constraint
- New booking: `201 Created`
- Idempotent retry: `200 OK`
- Cancellation: `200 OK`
- Resource conflict: `409 Conflict`
- Validation/business input errors: `422`
- List/detail: included
- Pagination: 20 default / 100 maximum
- Filters: `status`, `vehicle_id`
- Ordering: `start_at DESC`
- Responses: Laravel API Resources
- IDs: UUID
- Soft deletes: where appropriate
- Production database: PostgreSQL or MySQL
- Development/test database: SQLite is acceptable

### Explicitly excluded from MVP

Do not implement these unless requirements are intentionally changed:

- Technician qualifications
- Service Bay capabilities
- Dealership-specific service catalogs
- Advanced technician/bay optimization
- Skill levels
- Equipment requirements
- Service packages/bundles
- Recurring appointments
- Advanced scheduling
- Operating-hours validation

---

# 3. Target Architecture

The application should use:

```text
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
    +--> IdempotencyService
    |
    v
Eloquent Models
    |
    v
Database
```

## Responsibilities

### Controller

HTTP-level orchestration only.

Must NOT contain:

- booking rules
- availability logic
- overlap logic
- transaction logic
- resource selection

### FormRequest

Responsible for:

- request validation
- request authorization where appropriate
- validating API input shape

The client must not submit:

```text
customer_id
technician_id
service_bay_id
end_at
```

### Action

Represents the application use case.

Primary action:

```text
CreateAppointmentAction
```

It receives validated input, builds the DTO, and delegates to the booking service.

### AppointmentBookingService

The orchestration/application service.

Responsible for the complete booking workflow:

1. Start transaction.
2. Resolve/validate customer and vehicle.
3. Validate dealership.
4. Validate ServiceType.
5. Calculate `end_at`.
6. Find available Technician.
7. Find available Service Bay.
8. Create Appointment.
9. Complete idempotency state when applicable.
10. Commit transaction.
11. Update Redis availability after commit.

The transaction belongs here because the whole operation must succeed or fail together.

### Smaller domain services

Instead of one large `ResourceAvailabilityService`, split responsibilities:

- `CustomerService`
- `DealershipService`
- `ServiceTypeService`
- `TechnicianService`
- `ServiceBayService`
- `AppointmentService`
- `IdempotencyService`

These services should have focused responsibilities and be independently testable.

---

# 4. DTO Strategy

Use immutable DTOs at meaningful Action/Service boundaries.

Do not create DTOs mechanically for every Eloquent model.

Recommended DTO structure:

```text
app/
├── DTOs/
│   ├── Customer/
│   │   └── CustomerContextData.php
│   ├── Vehicle/
│   │   └── VehicleData.php
│   ├── Dealership/
│   │   └── DealershipData.php
│   ├── ServiceType/
│   │   └── ServiceTypeData.php
│   ├── Technician/
│   │   └── TechnicianData.php
│   ├── ServiceBay/
│   │   └── ServiceBayData.php
│   └── Appointment/
│       ├── CreateAppointmentData.php
│       ├── AppointmentPeriodData.php
│       └── CancelAppointmentData.php
```

The primary booking DTO contains:

```text
customerId
vehicleId
dealershipId
serviceTypeId
startAt
```

It must NOT contain:

```text
technicianId
serviceBayId
endAt
```

Those values are derived by the application.

---

# 5. Implementation Phases

## Current Implementation Status

> Updated after Phase 0 through Phase 8 were completed and verified.

### Overall

- **Current phase:** Phase 9 — Production Hardening
- **Phase 0 status:** ✅ Done
- **Phase 1 status:** ✅ Done
- **Phase 2 status:** ✅ Done
- **Phase 3 status:** ✅ Done
- **Phase 4 status:** ✅ Done
- **Phase 5 status:** ✅ Done
- **Phase 6 status:** ✅ Done
- **Phase 7 status:** ✅ Done
- **Phase 8 status:** ✅ Done (parallel concurrency tests moved to Phase 9)
- **Next phase:** Phase 9 — Production Hardening

### Phase status

- [x] **Phase 0 — Project Baseline** — Done
- [x] **Phase 1 — Domain Models & Database** — Done
- [x] **Phase 2 — Authentication & Ownership** — Done
- [x] **Phase 3 — ServiceType & Core Booking Domain** — Done
- [x] **Phase 4 — Transaction & Concurrency Protection** — Done
- [x] **Phase 5 — Idempotency** — Done
- [x] **Phase 6 — API Resources & Endpoints** — Done
- [x] **Phase 7 — Error Handling & API Contract** — Done
- [x] **Phase 8 — Complete Automated Test Suite** — Done
- [ ] **Phase 9 — Production Hardening** — Not started

---

## Phase 0 — Project Baseline

### Goal

Ensure the Laravel project is stable before implementing business logic.

### Status

✅ **Done.** Phase 0 was merged to `main`, and the full test suite passed locally.

### Checklist

- [x] Verify Laravel installation.
- [x] Verify Composer configuration.
- [x] Verify application boots successfully.
- [x] Configure `.env`.
- [x] Configure database connection.
- [x] Verify migrations run.
- [x] Configure Pest.
- [x] Verify the existing test suite passes.
- [x] Configure API routing.
- [x] Establish `/api/v1` route convention.
- [x] Establish UUID convention.
- [x] Establish soft-delete convention.
- [x] Establish coding/namespace conventions.

### Testing

- [x] Application boot test.
- [x] Database connection test.
- [x] Migration test.
- [x] Basic API health test.
- [x] Baseline Pest suite passes.

### Exit criteria

The project starts cleanly and the baseline test suite passes.

---

# Phase 1 — Domain Models & Database

## Goal

Create the MVP domain model and database structure.

### Status

✅ **Done.** Phase 1 was implemented, migrated, seeded, and verified successfully.

### Models

- [x] Customer
- [x] Vehicle
- [x] Dealership
- [x] Technician
- [x] ServiceBay
- [x] ServiceType
- [x] Appointment
- [x] IdempotencyKey

### Migrations

- [x] UUID primary keys.
- [x] Foreign keys.
- [x] Required indexes.
- [x] Soft-delete columns where appropriate.
- [x] Appointment timestamps.
- [x] ServiceType duration/status.
- [x] Appointment status.
- [x] Idempotency key fields.

### Appointment indexes

At minimum:

```text
appointments(technician_id, start_at, end_at)
appointments(service_bay_id, start_at, end_at)
```

Also index foreign keys and common lookup fields.

### Relationships

Verify:

```text
Customer
    hasMany Vehicle
    hasMany Appointment

Vehicle
    belongsTo Customer
    hasMany Appointment

Dealership
    hasMany Technician
    hasMany ServiceBay
    hasMany Appointment

ServiceType
    hasMany Appointment

Technician
    belongsTo Dealership
    hasMany Appointment

ServiceBay
    belongsTo Dealership
    hasMany Appointment

Appointment
    belongsTo Customer
    belongsTo Vehicle
    belongsTo Dealership
    belongsTo ServiceType
    belongsTo Technician
    belongsTo ServiceBay
```

### Checklist

- [x] Create migrations.
- [x] Create models.
- [x] Configure UUIDs.
- [x] Configure casts.
- [x] Configure soft deletes.
- [x] Configure relationships.
- [x] Add factories.
- [x] Add seeders for development.
- [x] Run migrations from clean database.
- [x] Run rollback/re-migration.
- [x] Verify indexes.

### Testing

- [x] Model creation.
- [x] Relationship tests.
- [x] UUID generation.
- [x] Soft-deleted resources are excluded from normal queries.
- [x] Factory data is valid.
- [x] Clean migration/re-migration succeeds.

### Verification

- [x] Run migrations from a clean database.
- [x] Run rollback/re-migration.
- [x] Verify indexes.
- [x] Run the complete Pest suite and confirm all Phase 1 tests pass.

### Exit criteria

All domain models and relationships exist and the database can be recreated from scratch.

---

# Phase 2 — Authentication & Ownership

## Goal

Implement customer authentication and ownership boundaries.

### Status

✅ **Done.**

### Checklist

- [x] Install/configure Laravel Sanctum.
- [x] Authenticate API requests through the `auth:sanctum` middleware.
- [x] Resolve customer from the authenticated user/context.
- [x] Never accept `customer_id` from booking requests.
- [x] Verify vehicle belongs to authenticated customer through an ownership policy.
- [x] Scope appointment access to the authenticated customer through an ownership policy.
- [x] Implement reusable ownership policies.
- [x] Protect customer-owned resources where endpoints exist.

### Policies

- [x] `VehiclePolicy` verifies that the authenticated Customer owns the Vehicle.
- [x] `AppointmentPolicy` verifies that the authenticated Customer owns the Appointment.
- [x] Ownership checks use the authenticated Customer identity and do not trust a client-supplied `customer_id`.

### Testing

- [x] Unauthenticated customer API request is rejected by `auth:sanctum`.
- [x] Authenticated customer is resolved from the Sanctum user context.
- [x] Authenticated customer can access their own Vehicle through `VehiclePolicy`.
- [x] Customer cannot access another customer's Vehicle through `VehiclePolicy`.
- [x] Authenticated customer can view and cancel their own Appointment through `AppointmentPolicy`.
- [x] Customer cannot view or cancel another customer's Appointment through `AppointmentPolicy`.

### Verification

- [x] Run `php artisan migrate:fresh --seed`.
- [x] Run `php artisan test`.

### Exit criteria

Authentication and reusable ownership policies are in place.

---

# Phase 3 — ServiceType & Core Booking Domain

## Goal

Implement the basic booking flow without concurrency hardening yet.

### Status

✅ **Done.**

### Verification

- [x] Run `php artisan migrate:fresh --seed`.
- [x] Run `php artisan test`.
- [x] Verify Phase 3 booking, validation, resource selection, and overlap tests pass.

### ServiceType

- [x] Create ServiceType model.
- [x] Add `duration`.
- [x] Add active/inactive status.
- [x] Validate positive duration during booking.
- [x] Do not add capability/qualification pivots.

### Services

```text
CustomerService
DealershipService
ServiceTypeService
TechnicianService
ServiceBayService
AppointmentService
```

### Responsibilities

- [x] CustomerService finds the vehicle, verifies availability, and verifies ownership.
- [x] DealershipService finds the dealership and verifies it is active.
- [x] ServiceTypeService finds the ServiceType, verifies it is active, and verifies positive duration.
- [x] TechnicianService finds active technicians, applies overlap rules, and selects deterministically.
- [x] ServiceBayService finds active bays, applies overlap rules, and selects deterministically.
- [x] AppointmentService creates, loads, and cancels appointments.
- [x] AppointmentBookingService orchestrates the booking flow.

### Overlap rule

```text
existing.start_at < requested.end_at
AND
existing.end_at > requested.start_at
```

Back-to-back appointments must be allowed.

### Checklist

- [x] Implement DTOs.
- [x] Implement FormRequest.
- [x] Implement CreateAppointmentAction.
- [x] Implement AppointmentBookingService.
- [x] Implement smaller services.
- [x] Calculate `end_at` from ServiceType duration.
- [x] Find technician.
- [x] Find service bay.
- [x] Create appointment.
- [x] Use `confirmed` status.
- [x] Exclude cancelled appointments from blocking.
- [x] Exclude soft-deleted resources.
- [x] Add `POST /api/v1/appointments`.

### Testing

#### Happy path

- [x] Valid booking succeeds.
- [x] Correct customer, vehicle, dealership, and ServiceType are assigned.
- [x] Technician and Service Bay are assigned.
- [x] `end_at` is calculated from duration.
- [x] Appointment status is `confirmed`.

#### Validation

- [x] Unknown/inactive vehicle.
- [x] Vehicle owned by another customer.
- [x] Unknown/inactive dealership.
- [x] Unknown/inactive ServiceType.
- [x] Invalid duration.
- [x] Invalid/missing start time.
- [x] Client-supplied `end_at` cannot override server calculation.

#### Overlap

For both Technician and Service Bay:

- [x] Exact overlap rejected.
- [x] Requested start inside existing appointment rejected.
- [x] Requested end inside existing appointment rejected.
- [x] Existing appointment completely inside requested period rejected.
- [x] Requested appointment completely inside existing period rejected.
- [x] Back-to-back before existing appointment allowed.
- [x] Back-to-back after existing appointment allowed.

### Exit criteria

A valid request creates one complete appointment with server-derived duration and resources.

---

# Phase 4 — Transaction & Concurrency Protection

## Goal

Make booking safe under concurrent requests while keeping the database as the authoritative source of truth.

### Status

✅ **Done.** Phase 4 was implemented and the complete local test suite was run successfully.

### Agreed booking protection flow

```text
Booking Request
      |
      v
1. Redis dealership lock
   booking:dealership:{dealership_id}
      |
      v
2. Database transaction
   - lock dealership row
   - validate customer / vehicle
   - validate ServiceType
   - calculate end_at
   - Redis availability index lookup
   - authoritative DB availability check
   - create Appointment
      |
      v
   COMMIT
      |
      v
3. Update Redis availability index
```

Redis is an optimization/coordination layer only. A Redis result must never be treated as proof that a resource is available.

### Transaction boundary

```text
BEGIN

Lock dealership row
Validate ownership
Validate dealership
Validate ServiceType
Calculate end_at
Check Redis availability candidates
Check authoritative DB availability
Select technician
Select service bay
Create appointment

COMMIT
```

Any failure rolls back the transaction.

### Redis dealership lock

The lock key is:

```text
booking:dealership:{dealership_id}
```

Rules:

- [x] Lock is dealership-scoped.
- [x] Customer ID is not part of the lock key.
- [x] Different dealerships do not unnecessarily block each other.
- [x] Lock contention maps to `409 BOOKING_IN_PROGRESS`.
- [x] Redis lock is never authoritative.
- [x] Redis lock failure/unavailability falls back to the database locking path where applicable.

### Redis availability index

- [x] Redis is consulted first for advisory candidate resource IDs.
- [x] Final overlap checks remain authoritative database queries.
- [x] Redis is updated only after successful DB commit.
- [x] Redis unavailability falls back to the database-only authoritative path.

### Implementation

- [x] Transaction ownership in `AppointmentBookingService`.
- [x] Dealership-level Redis booking lock.
- [x] Authoritative database dealership row locking.
- [x] Redis technician availability index.
- [x] Redis service-bay availability index.
- [x] Advisory Redis candidate filtering.
- [x] Authoritative final overlap checks.
- [x] Post-commit Redis availability indexing.
- [x] Redis lock release.
- [x] DB fallback when Redis is unavailable.
- [x] `BOOKING_IN_PROGRESS` conflict mapping.

### Testing

- [x] Transaction rollback after appointment persistence failure.
- [x] Failure after resource selection leaves no appointment.
- [x] Redis dealership lock contention returns `409 BOOKING_IN_PROGRESS`.
- [x] Redis availability failure falls back to the database.
- [x] Successful booking updates Redis availability after commit.
- [x] Existing overlap rules continue to protect resources.
- [x] Full local test suite passes.

### Exit criteria

The booking transaction is atomic, dealership locking is implemented, Redis is advisory only, DB remains authoritative, and concurrency protection is in place.

---

# Phase 5 — Idempotency

## Goal

Prevent duplicate bookings caused by client retries.

### Status

✅ **Done.** Phase 5 was implemented and verified. The complete test suite passes.

### Endpoint

```http
POST /api/v1/appointments
Idempotency-Key: <key>
```

When an `Idempotency-Key` is supplied, the booking flow applies customer-scoped idempotency.

### Idempotency rules

- [x] Same customer + same key + same request fingerprint returns the original appointment result.
- [x] Same customer + same key + same request returns `200 OK` on retry.
- [x] The first successful request returns `201 Created`.
- [x] Same customer + same key + different request fingerprint returns `422`.
- [x] Different customers may use the same key independently.
- [x] Different keys create separate booking attempts.
- [x] Keys are retained for 24 hours.
- [x] Expired records do not block key reuse.
- [x] Expired records are handled when the key is reused; no scheduled cleanup is required for MVP.
- [x] Unique constraint on `(customer_id, key)`.
- [x] Successful idempotency state is completed inside the same booking transaction.
- [x] Failed booking does not leave an incorrect successful idempotency record.
- [x] Concurrent same-key requests are protected by the existing dealership serialization plus the database uniqueness constraint.

### Request fingerprint

The fingerprint is deterministic and contains only documented appointment-creation request data:

```text
vehicle_id
dealership_id
service_type_id
start_at
```

No server-generated fields are included in the fingerprint.

### Replay behavior

An idempotent retry:

1. Finds the customer-scoped idempotency record.
2. Verifies the request fingerprint.
3. Loads the associated appointment.
4. Returns the same appointment API representation with `200 OK`.

The initial successful booking returns the same persisted appointment representation with `201 Created`.

### IdempotencyService

Responsibilities:

- [x] Generate the deterministic request fingerprint.
- [x] Detect an existing customer-scoped key.
- [x] Compare request fingerprints.
- [x] Detect replayable successful records.
- [x] Create idempotency state inside the booking transaction.
- [x] Complete idempotency state with the created appointment.
- [x] Handle expiration and key reuse.
- [x] Preserve the Phase 4 transaction/concurrency mechanisms.

### Testing

- [x] First request creates exactly one appointment and returns `201`.
- [x] Same key + same request returns the same appointment.
- [x] Idempotent retry returns `200`.
- [x] Same key + different request returns `422`.
- [x] Same key is customer-scoped.
- [x] Expired key can be reused.
- [x] Failed booking leaves no incorrect successful idempotency record.
- [x] Failed booking can reuse the same key.
- [x] Database uniqueness for `(customer_id, key)` is enforced.
- [x] Fingerprint contains exactly the agreed request fields.
- [x] Concurrent duplicate protection is covered by the existing dealership serialization and DB uniqueness mechanism.
- [x] Existing Phase 4 tests continue to pass.
- [x] Full local test suite passes.

### Verification

The implementation was verified by running:

```bash
php artisan migrate:fresh --seed
php artisan test
```

Both completed successfully.

### Exit criteria

Client retries cannot accidentally create duplicate appointments, successful retries replay the original appointment with `200`, mismatched reuse returns `422`, and failed transactions do not leave incorrect successful idempotency state.

---

# Phase 6 — API Resources, List, Detail & Cancellation

## Goal

Complete the customer-facing appointment API.

## Status

✅ **Done.** Phase 6 was implemented and verified successfully.

## API endpoints

```http
POST /api/v1/appointments
GET  /api/v1/appointments
GET  /api/v1/appointments/{appointment}
POST /api/v1/appointments/{appointment}/cancel
```

## AppointmentResource

All API responses use Laravel API Resources.

No appointment endpoint returns raw Eloquent models.

Exposed fields are: `id`, `status`, `start_at`, `end_at`, `vehicle`, `dealership`, `service_type`, `technician`, and `service_bay`.

### List

Rules implemented:

- [x] Pagination enabled.
- [x] Default `per_page = 20`.
- [x] Maximum `per_page = 100`.
- [x] Client can control `page`.
- [x] Client can control `per_page`.
- [x] Default ordering `start_at DESC`.
- [x] No arbitrary client sorting.
- [x] Filter by `status`.
- [x] Filter by `vehicle_id`.
- [x] Cancelled appointments remain visible.
- [x] Always scope to authenticated customer.

### Cancellation

Endpoint:

```http
POST /api/v1/appointments/{appointment}/cancel
```

Allowed transition:

```text
confirmed -> cancelled
```

Rules implemented:

- [x] Customer must own appointment.
- [x] Appointment must not have started.
- [x] Already cancelled cannot be cancelled again.
- [x] No minimum cancellation notice.
- [x] Rebooking creates a new appointment.
- [x] Cancelled appointment does not block resources.
- [x] Cancellation uses transaction.
- [x] Cancellation uses appropriate dealership locking.
- [x] Success returns `200`.
- [x] Response uses AppointmentResource.

### Testing

#### API response

- [x] `201` for new booking.
- [x] `200` for idempotent retry.
- [x] `200` for cancellation.
- [x] Correct resource JSON structure.
- [x] No internal fields exposed.
- [x] Related resource summaries are correct.

#### List

- [x] Customer only sees own appointments.
- [x] Pagination default is 20.
- [x] Maximum page size is 100.
- [x] `status` filter works.
- [x] `vehicle_id` filter works.
- [x] Ordering is `start_at DESC`.
- [x] Cancelled appointments remain visible.

#### Cancellation

- [x] Confirmed appointment can be cancelled.
- [x] Started appointment cannot be cancelled.
- [x] Already cancelled appointment cannot be cancelled.
- [x] Other customer's appointment cannot be cancelled.
- [x] Cancelled appointment no longer blocks resources.
- [x] Correct `409 APPOINTMENT_CANNOT_BE_CANCELLED` response.

### Verification

- [x] Run `php artisan migrate:fresh --seed`.
- [x] Run `php artisan test`.
- [x] Confirm the Phase 6 lifecycle tests pass.

### Exit criteria

The complete appointment lifecycle is available through the API.

---
# Phase 7 — Error Handling & API Contract

## Goal

Make error behavior consistent and predictable.

### Status

✅ **Done.** Phase 7 was implemented and the complete local test suite passed successfully.

### HTTP mapping

| Situation | HTTP | Code |
|---|---:|---|
| Malformed request | 400 | `BAD_REQUEST` |
| Unauthenticated | 401 | Laravel standard |
| Forbidden | 403 | `FORBIDDEN` |
| Resource not found/hidden | 404 | Laravel standard |
| Resource/state conflict | 409 | Business conflict |
| Validation/business input | 422 | Business validation |

### Business conflict codes

```text
NO_RESOURCE_AVAILABLE
BOOKING_IN_PROGRESS
APPOINTMENT_CANNOT_BE_CANCELLED
```

All business conflict exceptions return HTTP `409`.

### Business validation codes

```text
DEALERSHIP_INACTIVE
SERVICE_TYPE_INACTIVE
SERVICE_TYPE_INVALID_DURATION
IDEMPOTENCY_KEY_MISMATCH
```

These business validation errors return HTTP `422`.

### Vehicle ownership behavior

A vehicle belonging to another customer is currently treated as a business validation failure and returns HTTP `422`.

This preserves the existing Phase 3 ownership behavior and is intentionally not changed to `404` in this phase.

### Checklist

- [x] Define domain/business exceptions.
- [x] Define consistent error codes.
- [x] Map exceptions to HTTP status.
- [x] Preserve Laravel validation format.
- [x] Do not reveal specific technician/bay availability details.
- [x] Keep the existing vehicle ownership behavior: another customer's vehicle returns `422`.
- [x] Add API error tests.
- [x] Map authorization failures to `403 FORBIDDEN` (render mapping for `AccessDeniedHttpException` on `api/*`).
- [x] Reject malformed JSON bodies with `400 BAD_REQUEST` (`RejectMalformedJson` API middleware, runs before authentication).

### Testing

- [x] Every documented business exception has correct status.
- [x] Every business exception has correct code.
- [x] Validation errors use Laravel format.
- [x] Unauthenticated requests retain Laravel `401` behavior.
- [x] Ownership behavior is covered.
- [x] No sensitive resource availability information leaks.
- [x] No internal exception/trace details leak in API error responses.
- [x] Complete local Pest test suite passes.

### Verification

The implementation was verified by running:

```bash
php artisan migrate:fresh --seed
php artisan test
```

The complete test suite passed successfully.

### Exit criteria

API consumers can reliably distinguish validation errors, authorization failures, and booking conflicts. Phase 7 is complete.

---

# Phase 8 — Complete Automated Test Suite

## Goal

Create confidence that the MVP works as a complete system.

Use Pest for unit and feature/integration tests.

## Status

✅ **Done.** The complete suite passes locally on SQLite. True parallel-request tests need MySQL/PostgreSQL and are tracked in Phase 9 (they exist as `->todo()` placeholders).

## Test layout

All tests share one lazily-resolved `booking` dataset (`tests/Datasets/Booking.php`); shared helpers live in `tests/Pest.php`.

| File | Covers |
|---|---|
| `BookingModelsTest` | UUIDs, soft deletes, factories, active flag, status, relationships |
| `CreateAppointmentTest` | Success, `end_at`/UTC, validation, ownership, business-rule codes |
| `ResourceAllocationTest` | Technician/bay selection, busy-skip, overlap per resource type, cancelled not blocking |
| `AppointmentLifecycleTest` | List, filters, pagination, detail, cancellation, rebooking |
| `IdempotencyTest` | Replay, mismatch, scoping, retention/expiry, rollback, uniqueness, fingerprint |
| `BookingReliabilityTest` | Rollback, Redis lock/index/fallback, DB authority, sequential double-booking |
| `ApiErrorContractTest` | 401/404/400, validation format, no information leaks |

## Test categories

### Domain/model tests

- [x] Relationships.
- [x] UUID behavior.
- [x] Soft-delete behavior.
- [x] Status behavior.
- [x] Factory validity.

### Customer/ownership tests

- [x] Own vehicle accepted.
- [x] Other customer's vehicle rejected.
- [x] Own appointment visible.
- [x] Other customer's appointment hidden.
- [x] Own appointment cancellable.
- [x] Other customer's appointment not cancellable.

### ServiceType tests

- [x] Active ServiceType accepted.
- [x] Inactive ServiceType rejected.
- [x] Positive duration accepted.
- [x] Invalid duration rejected.
- [x] Duration determines `end_at`.

### Dealership tests

- [x] Active dealership accepted.
- [x] Inactive dealership rejected.
- [x] Resources must belong to dealership.

### Technician tests

- [x] Active technician selected.
- [x] Inactive technician ignored.
- [x] Technician from another dealership ignored.
- [x] Busy technician ignored.
- [x] Deterministic selection.

### Service Bay tests

- [x] Active bay selected.
- [x] Inactive bay ignored.
- [x] Bay from another dealership ignored.
- [x] Busy bay ignored.
- [x] Deterministic selection.

### Overlap tests

For both resource types (each interval is run once with only the technician contended and once with only the bay contended):

```text
Existing:   10:00 - 11:00
```

Test:

- [x] 09:30 - 10:30 → conflict.
- [x] 10:30 - 11:30 → conflict.
- [x] 10:15 - 10:45 → conflict.
- [x] 09:00 - 12:00 → conflict.
- [x] 10:00 - 11:00 → conflict.
- [x] 09:00 - 10:00 → allowed.
- [x] 11:00 - 12:00 → allowed.

### Transaction tests

- [x] Technician unavailable → rollback.
- [x] Bay unavailable → rollback.
- [x] Appointment creation failure → rollback.
- [x] No partial resources/appointment remain.

### Idempotency tests

- [x] Same key/same request.
- [x] Same key/different request.
- [x] Different customer scope.
- [x] Expired key.
- [x] Concurrent same-key protection.
- [x] Failed booking leaves no successful idempotency state.
- [x] First request returns `201`.
- [x] Retry returns `200`.

### Cancellation tests

- [x] Confirmed → cancelled.
- [x] Started appointment rejected.
- [x] Already cancelled rejected.
- [x] Cancelled no longer blocks resources.
- [x] Rebooking creates a separate appointment.

### API tests

- [x] Authentication.
- [x] Create.
- [x] List.
- [x] Detail.
- [x] Cancel.
- [x] Validation errors.
- [x] Conflict errors.
- [x] Resource response structure.
- [x] Pagination.
- [x] Filters.

### Concurrency tests

- [x] Two overlapping requests from different customers cannot both reserve the same technician (sequential).
- [x] Two overlapping requests from different customers cannot both reserve the same bay (sequential).
- [x] Same dealership contention behaves correctly (`409 BOOKING_IN_PROGRESS`, and a retry succeeds once the lock is free).
- [x] Different dealerships do not unnecessarily conflict.
- [x] Lock failure maps correctly when Redis locking is enabled.
- [x] Redis availability is indexed only after commit and never after a rollback.
- [x] DB remains the final authority (a stale Redis candidate cannot cause a double booking).

True parallel-request tests are moved to Phase 9 (see "Parallel concurrency tests").

### Exit criteria

The complete automated suite passes locally and covers all critical business rules and the sequential/deterministic concurrency rules. Parallel-request tests are tracked in Phase 9.

---

# Phase 9 — Production Hardening

## Goal

Prepare the implementation for PostgreSQL/MySQL and production-like behavior.

### Database

- [ ] Run test suite against production-like relational database.
- [ ] Verify transaction semantics.
- [ ] Verify locking semantics.
- [ ] Verify indexes.
- [ ] Verify timezone behavior.
- [ ] Verify UUID behavior.
- [ ] Verify soft-delete queries.

### Timezone

- [ ] API accepts ISO-8601 timestamps with offset.
- [ ] Validate `start_at` is in the future.
- [ ] Convert/store appointment times in UTC.
- [ ] Store dealership timezone.
- [ ] Display appointments in dealership timezone.
- [ ] Service duration remains timezone-independent.
- [ ] Do not implement operating-hours validation in MVP.

### Parallel concurrency tests

Move the `->todo()` placeholders in `BookingReliabilityTest` and `IdempotencyTest` to real tests. They require MySQL/PostgreSQL and a non-transactional DB trait, because SQLite ignores `lockForUpdate()` and `RefreshDatabase` wraps each test in a transaction.

- [ ] Two parallel requests cannot both reserve the same technician.
- [ ] Two parallel requests cannot both reserve the same service bay.
- [ ] Parallel bookings for the same dealership are serialised.
- [ ] Parallel requests with the same idempotency key create exactly one appointment.

### Redis

If Redis is enabled:

- [ ] Use dealership-level booking lock.
- [ ] Keep DB as source of truth.
- [ ] Handle Redis unavailable.
- [ ] Handle lock timeout/failure.
- [ ] Never rely on Redis alone to determine availability.
- [ ] Add monitoring/logging for lock contention.

### Idempotency cleanup

- [ ] Add scheduled cleanup for expired keys only if the retention strategy later requires it.
- [ ] Verify cleanup does not remove active keys.
- [ ] Monitor idempotency table growth.

### Observability

- [ ] Log booking failures.
- [ ] Log concurrency conflicts.
- [ ] Log idempotency mismatches.
- [ ] Log cancellation conflicts.
- [ ] Avoid logging sensitive customer data unnecessarily.

### Exit criteria

The implementation behaves correctly on the intended production database and under realistic concurrency.

---

# 6. Recommended Implementation Order

Use this order during actual coding:

```text
Phase 0  Project baseline
   ↓
Phase 1  Domain models + migrations
   ↓
Phase 2  Sanctum + ownership
   ↓
Phase 3  ServiceType + booking services
   ↓
Phase 4  Transaction + concurrency
   ↓
Phase 5  Idempotency
   ↓
Phase 6  API Resources + list/detail/cancel
   ↓
Phase 7  Error handling
   ↓
Phase 8  Complete Pest suite
   ↓
Phase 9  Production hardening
```

Do not jump directly to Redis/concurrency before the basic booking flow is correct and covered by tests.

---

# 7. Suggested Application Structure

A practical Laravel structure:

```text
app/
├── Actions/
│   └── Appointments/
│       ├── CreateAppointmentAction.php
│       └── CancelAppointmentAction.php
│
├── DTOs/
│   ├── Customer/
│   ├── Vehicle/
│   ├── Dealership/
│   ├── ServiceType/
│   ├── Technician/
│   ├── ServiceBay/
│   └── Appointment/
│
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   │           └── AppointmentController.php
│   ├── Requests/
│   │   └── Appointments/
│   │       ├── CreateAppointmentRequest.php
│   │       └── CancelAppointmentRequest.php
│   └── Resources/
│       └── AppointmentResource.php
│
├── Models/
│   ├── Customer.php
│   ├── Vehicle.php
│   ├── Dealership.php
│   ├── Technician.php
│   ├── ServiceBay.php
│   ├── ServiceType.php
│   ├── Appointment.php
│   └── IdempotencyKey.php
│
└── Services/
    ├── CustomerService.php
    ├── DealershipService.php
    ├── ServiceTypeService.php
    ├── TechnicianService.php
    ├── ServiceBayService.php
    ├── AppointmentService.php
    ├── AppointmentBookingService.php
    └── IdempotencyService.php
```

This structure keeps the booking orchestration separate from individual resource responsibilities without creating an unnecessarily complicated domain framework.

---

# 8. Definition of Done

The MVP is ready when all of the following are true:

## Functional

- [x] Customer can authenticate.
- [x] Customer can book an owned vehicle.
- [x] Customer can select dealership.
- [x] Customer can select ServiceType.
- [x] Customer can select start time.
- [x] Server calculates duration/end time.
- [x] Server selects technician.
- [x] Server selects service bay.
- [x] Booking is atomic.
- [x] Overlap rules work.
- [x] Back-to-back appointments work.
- [x] Customer can list appointments.
- [x] Customer can retrieve an appointment.
- [x] Customer can cancel an appointment.
- [x] Cancelled appointments no longer block resources.
- [x] Idempotency prevents duplicate bookings.

## Security/authorization

- [x] Customer identity comes from authentication.
- [x] Customer cannot book another customer's vehicle.
- [x] Customer cannot access another customer's appointment.
- [x] Customer cannot cancel another customer's appointment.

## Reliability

- [x] Transaction rollback works.
- [x] Concurrent booking protection is implemented.
- [x] Database is authoritative.
- [x] Idempotency works under retries and customer-scoped key reuse.
- [ ] Production database semantics are verified.

## API

- [x] API uses `/api/v1`.
- [x] API Resources are used.
- [x] HTTP status codes are correct.
- [x] Error codes are consistent.
- [x] Pagination works.
- [x] Filters work.
- [ ] Timezone behavior is correct (future-date validation and dealership timezone, Phase 9).

## Testing

- [x] Unit/feature tests for implemented phases pass.
- [x] Ownership tests pass.
- [x] Overlap tests pass.
- [x] Transaction tests pass.
- [x] Idempotency tests pass.
- [x] Cancellation tests for the complete API lifecycle.
- [ ] Complete concurrency stress tests (parallel requests, Phase 9).
- [ ] Production-like DB tests.

---

# 9. Implementation Guardrails

During implementation, follow these rules:

1. **Do not add capability matrices to the MVP.**
2. **Do not allow the client to choose Technician or Service Bay.**
3. **Do not allow the client to choose `end_at`.**
4. **Do not trust `customer_id` from the request.**
5. **Do not put booking logic in controllers.**
6. **Do not put the complete booking transaction inside individual sub-services.**
7. **The transaction belongs to `AppointmentBookingService`.**
8. **Use Redis only as the first-layer advisory availability/locking mechanism; the database remains authoritative.**
9. **Do not return raw Eloquent models from API endpoints once the API Resource phase is implemented.**
10. **Do not silently introduce future-domain requirements.**
11. **Keep DTOs focused on service/action boundaries.**
12. **Prefer small, focused services over one monolithic availability service.**
13. **Every new business rule should have an automated test.**
14. **Any change to the frozen MVP decisions should be documented before implementation.**

---

# 10. Final Implementation Principle

The architecture should remain simple but correctly handle the hard part of this MVP:

```text
Simple domain
    +
Small focused services
    +
Immutable DTOs at boundaries
    +
Atomic database transaction
    +
Correct overlap detection
    +
Concurrency protection
    +
Idempotency
    +
Strong automated tests
```

The objective is not to build a generalized scheduling platform.

The objective is to build a reliable MVP that can safely answer:

> Can this customer book this vehicle at this dealership for this ServiceType at this time, and can the system reserve exactly one available technician and one available service bay without double booking them?
