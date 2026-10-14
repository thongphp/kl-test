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

The primary technical challenge is therefore resource availability and concurrency, not capability matching.

## 2. MVP Business Concept

The customer requests a service appointment for:

1. A specific vehicle.
2. A specific dealership.
3. A specific service type.
4. A requested start time.

The system determines the service duration from the selected ServiceType and calculates the appointment end time.

The system must then find:

- one available Technician belonging to the dealership; and
- one available Service Bay belonging to the dealership.

Both resources must be available for the entire appointment duration.

If both resources are available, the system creates the Appointment atomically.

If either resource cannot be allocated, the booking fails and no partial appointment/resource allocation is persisted.

## 3. Domain Model

### 3.1 Customer

Represents the customer requesting service.

Relationships:

```text
Customer
  ├── Vehicles
  └── Appointments
```

### 3.2 Vehicle

Represents a vehicle owned/managed by a Customer.

Relationships:

```text
Vehicle
  ├── Customer
  └── Appointments
```

Business rule:

> A customer may only create an appointment for a vehicle belonging to that customer.

### 3.3 Dealership

Represents the location where the appointment will be performed.

A Dealership owns:

- Service Bays
- Technicians
- Appointments

MVP assumption:

> All active ServiceTypes are available at every active Dealership.

No `dealership_service_types` table is required.

### 3.4 ServiceType

`ServiceType` is an independent first-class model. It does not belong to a Technician or Service Bay.

It represents what work the customer wants performed.

Recommended fields:

```text
ServiceType
-----------
id
name
duration
status
created_at
updated_at
deleted_at
```

The duration is authoritative for appointment calculation:

```text
end_at = start_at + service_type.duration
```

The client must not supply or control `end_at`.

MVP assumption:

> Every Technician and every Service Bay at a Dealership can perform every active ServiceType.

Therefore the MVP does not require:

```text
technician_service_types
service_bay_service_types
dealership_service_types
```

### 3.5 Technician

Represents an employee who can perform service work.

For the MVP:

> Every active Technician belonging to a Dealership can perform every active ServiceType.

Availability is determined by existing appointments.

### 3.6 ServiceBay

Represents a physical service bay at a Dealership.

For the MVP:

> Every active ServiceBay belonging to a Dealership can be used for every active ServiceType.

Bay capability matching is intentionally excluded.

### 3.7 Appointment

Represents a confirmed reservation of:

- Customer
- Vehicle
- Dealership
- ServiceType
- Technician
- ServiceBay
- Time period

Recommended fields:

```text
Appointment
-----------
id
customer_id
vehicle_id
dealership_id
service_type_id
technician_id
service_bay_id
start_at
end_at
status
created_at
updated_at
deleted_at
```

`dealership_id` is explicitly stored even though Technician and ServiceBay also belong to a Dealership. This makes the appointment location explicit and simplifies querying, reporting, authorization, and future requirements.

## 4. Entity Relationships

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

There is intentionally no direct ownership relationship:

```text
ServiceType → Technician
ServiceType → ServiceBay
```

Instead:

```text
ServiceType = What service is requested?
Technician  = Who performs the service?
ServiceBay  = Where the service is performed?
Dealership  = Where the resources belong?
```

For the MVP, all technicians and bays are assumed capable of all service types.

## 5. Appointment Creation API

Recommended endpoint:

```http
POST /api/appointments
```

Request:

```json
{
  "vehicle_id": "uuid",
  "dealership_id": "uuid",
  "service_type_id": "uuid",
  "start_at": "2026-10-20T10:00:00"
}
```

The authenticated customer is derived from the authenticated user/context and must not be trusted from the request body.

The client does not provide:

```text
customer_id
technician_id
service_bay_id
end_at
```

These are determined by the application.

## 6. Appointment Creation Flow

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
    +--> Validate Customer / Vehicle
    |
    +--> Validate Dealership
    |
    +--> Validate ServiceType
    |
    +--> Calculate end_at
    |
    +--> Find available Technician
    |
    +--> Find available ServiceBay
    |
    +--> Create Appointment
    |
    v
Response
```

Controllers should remain thin and delegate booking logic to the Action.

## 7. Validation Rules

Before booking, verify:

### Customer / Vehicle

- Vehicle exists.
- Vehicle is active.
- Vehicle belongs to the authenticated customer.

### Dealership

- Dealership exists.
- Dealership is active.

### ServiceType

- ServiceType exists.
- ServiceType is active.
- ServiceType has a valid positive duration.

### Start Time

- `start_at` is valid.
- `start_at` is acceptable according to application scheduling rules.
- The system calculates `end_at`.

### Resources

The selected Technician and Service Bay must:

- belong to the requested Dealership;
- be active;
- have no conflicting blocking Appointment during the requested period.

## 8. Duration and Time Calculation

The ServiceType owns the authoritative duration.

Example:

```text
ServiceType.duration = 60 minutes
start_at = 10:00
end_at   = 11:00
```

The resulting appointment interval is:

```text
[start_at, end_at)
```

The start is inclusive and the end is exclusive for overlap purposes.

## 9. Appointment Overlap Rule

Two appointments conflict when:

```text
existing.start_at < requested.end_at
AND
existing.end_at > requested.start_at
```

Back-to-back appointments are allowed:

```text
Existing:   09:00 → 10:00
Requested:  10:00 → 11:00
```

## 10. Resource Availability

A Technician is available when:

- the Technician belongs to the requested Dealership;
- the Technician is active;
- no blocking Appointment overlaps the requested period.

A Service Bay is available when:

- the Service Bay belongs to the requested Dealership;
- the Service Bay is active;
- no blocking Appointment overlaps the requested period.

A deterministic selection strategy is recommended, such as:

```text
ORDER BY id
```

and selecting the first available resource.

## 11. Appointment Status

Recommended MVP statuses:

```text
confirmed
cancelled
```

Future statuses may include:

```text
pending
completed
no_show
```

Only statuses that actually reserve resources participate in availability checks.

For the MVP, `confirmed` blocks resources and `cancelled` does not.

## 12. Transaction and Atomicity

Appointment creation must be atomic:

```text
BEGIN TRANSACTION

    Validate vehicle ownership
    Load ServiceType
    Calculate end_at
    Find available Technician
    Find available ServiceBay
    Create Appointment

COMMIT
```

If any step fails:

```text
ROLLBACK
```

No partial appointment or resource allocation may remain.

The operation is:

```text
SUCCESS = Technician + ServiceBay + Appointment
FAILURE = none of them
```

## 13. Concurrency Requirement

Concurrency is the most important technical concern.

Two requests can simultaneously observe the same resource as available. Simple application-level availability checks are therefore not sufficient by themselves.

The implementation must use appropriate database transaction/locking semantics for the production database.

The database is the authoritative source of truth for resource availability.

Redis must not be used as the authoritative booking lock or availability source.

## 14. Database Recommendation

SQLite is acceptable for:

- local development;
- automated tests;
- early MVP experimentation.

The booking design must not depend on SQLite-specific behavior.

Production should use a transactional relational database such as PostgreSQL or MySQL.

## 15. Database Indexes

Recommended indexes:

```text
appointments(
    technician_id,
    start_at,
    end_at
)
```

```text
appointments(
    service_bay_id,
    start_at,
    end_at
)
```

Also index foreign keys and common lookup fields according to the final migrations.

The following tables are intentionally not required:

```text
technician_service_types
service_bay_service_types
dealership_service_types
```

## 16. Application Architecture

The existing architecture remains appropriate:

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
Service
    |
    v
Eloquent Model
    |
    v
Database
```

### Controller

Responsible for HTTP-level orchestration only.

It should not contain availability, overlap, transaction, or resource-selection logic.

### FormRequest

Responsible for request validation and authorization.

### Action

Represents the application use case:

```text
CreateAppointmentAction
```

It receives validated input and delegates the booking operation.

### Service

Recommended service:

```text
AppointmentBookingService
```

Responsibilities include:

- calculating appointment duration;
- selecting resources;
- checking availability;
- performing the booking transaction;
- creating the Appointment.

A dedicated availability component can be introduced if needed:

```text
AppointmentBookingService
        |
        +-- ResourceAvailabilityService
                |
                +-- Technician availability
                +-- Service Bay availability
```

## 17. DTO

An immutable DTO is recommended at the Action/Service boundary.

Example:

```text
CreateAppointmentData

customerId
vehicleId
dealershipId
serviceTypeId
startAt
```

It does not need:

```text
technicianId
serviceBayId
endAt
```

because these are derived by the booking service.

## 18. Eloquent Relationships

Recommended relationships:

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

## 19. UUID and Soft Delete

Continue the existing project conventions:

- UUID identifiers.
- Soft deletes where appropriate.
- Eloquent relationships.
- API-first design.
- FormRequest validation.
- Automated tests.

Soft-deleted resources must not be selected for new appointments.

## 20. Redis

Redis is not part of the authoritative booking mechanism.

For the MVP:

```text
Database = source of truth
Redis     = optional supporting infrastructure
```

Redis may later be used for caching, queues, or non-authoritative performance optimization.

## 21. Testing Requirements

Automated tests should cover:

### Happy Path

- Customer owns vehicle.
- Dealership is active.
- ServiceType is active.
- Technician is available.
- Service Bay is available.
- Appointment is created.
- `end_at` is calculated correctly.

### Validation

- Unknown vehicle rejected.
- Vehicle belonging to another customer rejected.
- Unknown dealership rejected.
- Inactive dealership rejected.
- Unknown ServiceType rejected.
- Inactive ServiceType rejected.
- Invalid duration rejected.
- Invalid start time rejected.

### Technician Availability

- Busy Technician is not selected.
- Overlap at the beginning is rejected.
- Overlap at the end is rejected.
- Full containment conflict is rejected.
- Back-to-back appointment is allowed.

### Service Bay Availability

Test the same overlap cases for Service Bays.

### Resource Selection

- Technician from another Dealership is never selected.
- Service Bay from another Dealership is never selected.
- An available resource is selected when another is busy.
- Resource selection is deterministic.

### Transaction

If no Technician is available, no Appointment is created.

If no Service Bay is available, no Appointment is created.

No partial booking state is allowed.

### Concurrency

Concurrent booking attempts must not successfully reserve the same Technician and Service Bay for overlapping periods.

## 22. MVP Scope Exclusions

The following are deliberately excluded:

- Technician qualifications.
- Service Bay capabilities.
- Dealership-specific service catalogs.
- Advanced technician/bay optimization.
- Skill levels.
- Equipment requirements.
- Service packages/bundles.
- Recurring appointments.

Specifically, the MVP does not require:

```text
technician_service_types
service_bay_service_types
dealership_service_types
```

## 23. Future Extension Path

The simplified MVP preserves a clean extension path.

If dealerships eventually offer different services:

```text
dealership_service_types
```

can be added.

If technicians have different qualifications:

```text
technician_service_types
```

can be added.

If bays have different capabilities:

```text
service_bay_service_types
```

can be added.

The architectural principle remains:

> ServiceType stays independent. Resources gain relationships to ServiceType only when the business requires capability restrictions.

## 24. Final MVP Design

```text
                    ServiceType
                        |
                        | duration
                        v
Customer ---> Vehicle ---> Appointment <--- Dealership
                              |
                 +------------+------------+
                 |                         |
                 v                         v
            Technician                ServiceBay
```

Booking request:

```text
Customer
   |
   +-- Vehicle
   +-- Dealership
   +-- ServiceType
   +-- Start Time
```

System derives:

```text
End Time
Technician
Service Bay
```

Final booking rule:

> Book a ServiceType for a Vehicle at a Dealership when one active Technician and one active Service Bay belonging to that Dealership are both available for the entire duration defined by the ServiceType.

The system must create the booking atomically and prevent overlapping resource reservations under concurrent requests.

## 25. Architectural Decision Summary

| Decision | MVP Choice |
|---|---|
| ServiceType ownership | Independent model |
| ServiceType duration | Stored on ServiceType |
| Dealership service catalog | All active ServiceTypes |
| Technician qualification | Not modeled |
| Service Bay capability | Not modeled |
| Technician selection | Any active technician in dealership |
| Service Bay selection | Any active bay in dealership |
| Appointment end time | Calculated server-side |
| Availability source | Database |
| Booking atomicity | Database transaction |
| Concurrency | Must prevent double booking |
| Redis | Not authoritative |
| Database | SQLite for development/tests; PostgreSQL/MySQL recommended for production |
| IDs | UUID |
| Deletes | Soft deletes where appropriate |
| Architecture | Controller → FormRequest → Action → Service → Eloquent |
| Advanced scheduling | Out of MVP scope |
