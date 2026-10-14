# Technical Requirements — Resource-Constrained Appointment Booking MVP

## 1. Purpose

This document defines the final technical requirements and architecture for the MVP appointment-booking feature.

The MVP intentionally keeps the domain model simple:

- `ServiceType` is an independent first-class model.
- A `ServiceType` owns its service duration.
- Every active ServiceType is assumed to be available at every active Dealership.
- Every Technician and Service Bay at a Dealership is assumed capable of performing every ServiceType.
- No Technician-to-ServiceType qualification matrix is required for the MVP.
- No ServiceBay-to-ServiceType capability matrix is required for the MVP.
- No Dealership-to-ServiceType pivot table is required for the MVP.

The primary technical challenge is resource availability and concurrency, not capability matching.

## 2. Booking Concept

A customer requests:

1. Vehicle
2. Dealership
3. ServiceType
4. Start time

The system derives:

- `end_at` from `ServiceType.duration`;
- one active Technician;
- one active Service Bay.

The customer never chooses the Technician, Service Bay, or `end_at`.

The appointment interval is `[start_at, end_at)`, so back-to-back appointments are allowed.

## 3. Domain Model

```text
Customer
  ├── Vehicles
  └── Appointments

Dealership
  ├── Technicians
  ├── ServiceBays
  └── Appointments

ServiceType
  └── Appointments

Appointment
  ├── Customer
  ├── Vehicle
  ├── Dealership
  ├── ServiceType
  ├── Technician
  └── ServiceBay
```

`ServiceType` is independent. It does not belong to a Technician, Service Bay, or Dealership.

For the MVP:

```text
ServiceType = what service is requested
Technician  = who performs the service
ServiceBay  = where the service is performed
Dealership  = where the resources belong
```

No capability/qualification matrices are required.

## 4. Appointment API

```http
POST /api/v1/appointments
```

Request:

```json
{
  "vehicle_id": "uuid",
  "dealership_id": "uuid",
  "service_type_id": "uuid",
  "start_at": "2026-10-20T10:00:00+07:00"
}
```

The authenticated customer is derived from Sanctum authentication.

The client must not provide:

```text
customer_id
technician_id
service_bay_id
end_at
```

## 5. Booking Architecture

```text
HTTP Request
    |
    v
FormRequest
    |
    v
CreateAppointmentAction
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
Eloquent Models / Database
```

Controllers remain HTTP orchestration only.

They must not contain:

- availability logic;
- overlap logic;
- resource selection;
- transaction logic;
- Redis coordination.

## 6. Booking Flow — Phase 4

Phase 4 establishes two protection layers.

```text
Booking Request
      |
      v
1. Redis first layer
   ├── Dealership booking lock
   │   booking:dealership:{dealership_id}
   │
   └── Advisory availability index
      |
      v
2. Authoritative DB transaction
   ├── Lock dealership row
   ├── Validate customer/vehicle
   ├── Validate dealership
   ├── Validate ServiceType
   ├── Calculate end_at
   ├── Use Redis candidates when available
   ├── Perform DB overlap checks
   ├── Select Technician
   ├── Select Service Bay
   └── Create Appointment
      |
      v
   COMMIT
      |
      v
3. Update Redis availability index
```

Redis is intentionally not authoritative.

If Redis says a resource is available, the database must still confirm it.

If Redis says a resource is unavailable, the application may exclude it from the candidate set, but the final booking decision remains with the database.

If Redis is unavailable, the database-only path is used.

## 7. Transaction and Atomicity

Appointment creation must be atomic.

```text
BEGIN

Lock dealership row
Validate ownership
Validate dealership
Validate ServiceType
Calculate end_at
Find authoritative available Technician
Find authoritative available ServiceBay
Create Appointment

COMMIT
```

On failure:

```text
ROLLBACK
```

No partial appointment may remain.

The database is the source of truth for committed booking state.

## 8. Concurrency Protection

### Dealership Redis lock

The Redis lock key is:

```text
booking:dealership:{dealership_id}
```

Rules:

- The lock is scoped to a dealership.
- Customer ID is not included in the lock key.
- Different dealerships do not unnecessarily block each other.
- Lock contention returns:

```text
409 BOOKING_IN_PROGRESS
```

- Redis lock failure does not make Redis authoritative.
- Redis unavailability falls back to the DB locking path.

### Database lock

The booking transaction locks the selected dealership row before authoritative resource checks.

This provides the authoritative serialization boundary for booking requests targeting the same dealership.

### Availability

The database performs the final overlap check:

```text
existing.start_at < requested.end_at
AND
existing.end_at > requested.start_at
```

Cancelled appointments do not block resources.

## 9. Redis Availability Index

Redis maintains advisory indexes for:

```text
booking:availability:dealership:{dealership_id}:technicians
booking:availability:dealership:{dealership_id}:service-bays
booking:availability:technician:{technician_id}
booking:availability:service-bay:{service_bay_id}
booking:availability:appointment:{appointment_id}
```

The index is used to narrow candidates before the authoritative database check.

Redis is updated only after a successful database commit.

A Redis indexing failure must not roll back an already committed appointment.

## 10. Resource Selection

Technician and Service Bay selection remains deterministic.

The MVP selects the first available active resource ordered by ID.

Both must:

- belong to the requested Dealership;
- be active;
- have no conflicting confirmed Appointment for the complete period.

No technician qualification or bay capability matching is performed.

## 11. Duration and Time

`ServiceType.duration` is authoritative:

```text
end_at = start_at + service_type.duration
```

API input uses ISO-8601 timestamps with an explicit offset.

Appointment timestamps are stored in UTC.

Operating-hours validation is outside the MVP.

## 12. Statuses

MVP appointment statuses:

```text
confirmed
cancelled
```

`confirmed` appointments block resources.

`cancelled` appointments do not.

## 13. Database

Development/test:

```text
SQLite
```

Production:

```text
PostgreSQL or MySQL
```

The design must not depend on SQLite-specific booking behavior.

Recommended appointment indexes include:

```text
appointments(technician_id, start_at, end_at)
appointments(service_bay_id, start_at, end_at)
```

## 14. API Contract

New booking:

```text
201 Created
```

Resource conflict:

```text
409 Conflict
```

Lock contention:

```text
409 BOOKING_IN_PROGRESS
```

Validation/business input errors:

```text
422
```

Responses use Laravel API Resources.

## 15. Authentication and Ownership

Laravel Sanctum authenticates customers.

Customer identity comes from the authenticated user.

Customers may only create appointments for their own vehicles.

Appointment reads/cancellation are scoped to the authenticated customer.

## 16. Idempotency

`POST /api/v1/appointments` will support:

```text
Idempotency-Key
```

with a 24-hour retention period.

Idempotency is a separate implementation phase and is not part of Phase 4.

## 17. Testing

Phase 4 tests cover:

- transaction rollback after persistence failure;
- no partial appointment after failure;
- Redis lock contention;
- `409 BOOKING_IN_PROGRESS`;
- Redis availability failure and DB fallback;
- successful booking and Redis index update after commit;
- existing overlap behavior.

The test suite uses the development-only package:

```text
nunomaduro/mock-final-classes
```

to permit mocking final classes in tests.

Explicit multi-process/race stress testing remains part of the later complete concurrency/production verification work.

## 18. Scope Exclusions

The MVP does not include:

- technician qualifications;
- service bay capabilities;
- dealership-specific service catalogs;
- advanced technician/bay optimization;
- skill levels;
- equipment requirements;
- service packages/bundles;
- recurring appointments;
- advanced scheduling;
- operating-hours validation;
- public availability endpoint.

## 19. Final Architectural Decision Summary

| Decision | Final MVP Choice |
|---|---|
| API version | `/api/v1` |
| Authentication | Laravel Sanctum |
| Customer identity | Authenticated user |
| ServiceType | Independent first-class model |
| ServiceType duration | Stored on ServiceType |
| Dealership service catalog | All active ServiceTypes |
| Technician qualification | Not modeled |
| Service Bay capability | Not modeled |
| Resource selection | Any active technician + bay at selected dealership |
| Selection | Deterministic, lowest ID |
| Interval | `[start_at, end_at)` |
| Back-to-back | Allowed |
| Time input | ISO-8601 with explicit offset |
| Storage | UTC |
| Operating hours | Out of MVP |
| Statuses | `confirmed`, `cancelled` |
| Public availability endpoint | Not included |
| Redis availability | Advisory first-layer index |
| Redis booking lock | `booking:dealership:{dealership_id}` |
| Customer ID in lock | No |
| Redis lock failure | `409 BOOKING_IN_PROGRESS` |
| Redis unavailable | DB fallback |
| Redis disagreement | DB wins |
| Database authority | Yes |
| Database transaction | Required |
| Database dealership lock | Required authoritative serialization |
| Idempotency | 24-hour `Idempotency-Key` |
| New booking | `201 Created` |
| Idempotent retry | `200 OK` |
| Resource conflict | `409 Conflict` |
| Validation | `422 Unprocessable Content` |
| Responses | Laravel API Resources |
| IDs | UUID |
| Deletes | Soft deletes where appropriate |
| Production DB | PostgreSQL/MySQL |
| Dev/test DB | SQLite acceptable |
| Test-only final-class mocking | `nunomaduro/mock-final-classes` |
