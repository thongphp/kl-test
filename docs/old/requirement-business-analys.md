# Business Requirements — Resource-Constrained Booking

## 1. Feature Overview

The MVP provides an end-to-end **Booking capability** that allows a customer to request a service appointment for a specific vehicle, service type, dealership, and desired start time.

Before confirming the appointment, the system must verify that both:

- A qualified **Technician**
- A suitable **Service Bay**

are available at the requested dealership for the **entire service duration**.

If both resources are available, the system creates a persistent, confirmed `Appointment` record.

---

## 2. Business Scope

### 2.1 Booking Request

A customer can request an appointment using:

- Customer
- Vehicle
- Service Type
- Dealership
- Desired start time

The service duration is determined by the selected Service Type.

Example:

> A customer requests an Oil Change at Dealership X at 10:00. If the service requires 60 minutes, the required resource window is 10:00–11:00.

The customer does not need to provide the end time.

```text
end_at = start_at + service_type.duration
```

---

## 3. Business Flow

```text
Customer
   |
   v
Request Appointment
   |
   +-- Validate Vehicle
   +-- Validate Service Type
   +-- Validate Dealership
   +-- Validate Desired Time
   |
   v
Determine Service Duration
   |
   v
Find Qualified Technicians
   |
   v
Find Available Service Bays
   |
   v
Find Resources Available for Entire Duration
   |
   +-- No suitable resources --> Reject / Unavailable
   |
   v
Reserve Technician + Service Bay
   |
   v
Create Appointment
   |
   v
Confirmed
```

---

## 4. Domain/Data Model

The MVP should contain the following concepts:

| Entity | Purpose |
|---|---|
| `Customer` | Person making the booking |
| `Vehicle` | Vehicle being serviced |
| `Dealership` | Location where service occurs |
| `ServiceType` | Requested service and its duration |
| `Technician` | Person performing the service |
| `ServiceBay` | Physical service resource |
| `Appointment` | Persistent confirmed booking |
| Technician Qualification | Defines which services a technician can perform |

### Appointment

An `Appointment` should associate:

```text
customer
vehicle
dealership
service_type
technician
service_bay
start_at
end_at
status
```

The end time is calculated from the Service Type duration.

---

# 5. Business Rules

## BR-01 — Vehicle Ownership

The selected vehicle must belong to the requesting customer.

## BR-02 — Dealership

The selected dealership must be valid and active.

## BR-03 — Service Type

The selected Service Type must be valid and active and must have a defined service duration.

## BR-04 — Technician Qualification

The assigned technician must be qualified to perform the requested Service Type.

## BR-05 — Technician Availability

The technician must have no conflicting appointment during the complete requested service period.

## BR-06 — Service Bay Availability

The service bay must have no conflicting appointment during the complete requested service period.

## BR-07 — Same Dealership

The assigned Technician and Service Bay must belong to the requested dealership.

## BR-08 — Full-Duration Availability

Both the Technician and Service Bay must be available for the **entire service duration**.

Checking availability only at the requested start time is not sufficient.

## BR-09 — Atomic Booking

An appointment must not be created with only one resource successfully assigned.

The operation must result in either:

```text
Technician + Service Bay + Appointment
```

or:

```text
Nothing is booked
```

## BR-10 — No Double Booking

Two concurrent booking requests must not be able to successfully book the same Technician or Service Bay for overlapping periods.

---

# 6. Definition of Done

## 6.1 Booking Request

- [ ] Customer can submit a booking request.
- [ ] Request contains Vehicle, Service Type, Dealership, and desired start time.
- [ ] Customer identity is obtained from the authenticated user/session.
- [ ] Vehicle ownership is validated.
- [ ] Service Type is validated.
- [ ] Dealership is validated.
- [ ] Service duration is obtained from the Service Type.
- [ ] `end_at` is calculated automatically.

## 6.2 Technician Availability

- [ ] System finds technicians at the requested dealership.
- [ ] System filters technicians by service qualification.
- [ ] System excludes technicians with overlapping appointments.
- [ ] Only technicians available for the complete service duration can be selected.

## 6.3 Service Bay Availability

- [ ] System finds service bays at the requested dealership.
- [ ] System excludes bays with overlapping appointments.
- [ ] Only service bays available for the complete service duration can be selected.

## 6.4 Appointment Confirmation

- [ ] Appointment is created only when both resources are available.
- [ ] Appointment contains Customer.
- [ ] Appointment contains Vehicle.
- [ ] Appointment contains Dealership.
- [ ] Appointment contains Service Type.
- [ ] Appointment contains Technician.
- [ ] Appointment contains Service Bay.
- [ ] Appointment contains `start_at`.
- [ ] Appointment contains `end_at`.
- [ ] Appointment has a clear status, e.g. `confirmed`.

## 6.5 Failure Handling

- [ ] If no qualified technician is available, booking is rejected.
- [ ] If no service bay is available, booking is rejected.
- [ ] No partial appointment is created on failure.
- [ ] API returns an appropriate validation or business error.

---

# 7. API Requirements

Recommended MVP endpoint:

```http
POST /api/appointments
```

### Request

```json
{
    "vehicle_id": 123,
    "service_type_id": 1,
    "dealership_id": 5,
    "start_at": "2026-10-20T10:00:00"
}
```

### Successful Response

```json
{
    "data": {
        "id": 1001,
        "status": "confirmed",
        "start_at": "2026-10-20T10:00:00",
        "end_at": "2026-10-20T11:00:00",
        "customer": {},
        "vehicle": {},
        "service_type": {},
        "dealership": {},
        "technician": {},
        "service_bay": {}
    }
}
```

### Unavailable Response

Example:

```json
{
    "message": "No qualified technician and service bay are available for the requested time."
}
```

The exact HTTP status code and error response format should follow the API's established error-handling convention.

---

# 8. Automated Test Requirements

## 8.1 Happy Path

- [ ] Creates an appointment when Technician and Service Bay are available.
- [ ] Correct service duration is calculated.
- [ ] A qualified Technician is assigned.
- [ ] An available Service Bay is assigned.
- [ ] Appointment status is `confirmed`.

## 8.2 Technician Constraints

- [ ] Unqualified technician is not assigned.
- [ ] Busy technician is not assigned.
- [ ] Technician from another dealership is not assigned.
- [ ] Technician unavailable during only part of the requested duration is rejected.

## 8.3 Service Bay Constraints

- [ ] Busy service bay is not assigned.
- [ ] Service bay from another dealership is not assigned.
- [ ] Service bay unavailable during only part of the requested duration is rejected.

## 8.4 Booking Conflicts

- [ ] Existing appointment occupying the entire requested period prevents booking.
- [ ] Existing appointment overlapping the requested start prevents booking.
- [ ] Existing appointment overlapping the requested end prevents booking.
- [ ] Back-to-back appointments are allowed when their time boundaries do not overlap.

Example of valid back-to-back appointments:

```text
Existing:   09:00 -------- 10:00
Requested:              10:00 -------- 11:00
```

Example of an invalid overlapping appointment:

```text
Existing:        09:30 -------- 10:30
Requested:       10:00 -------- 11:00
```

## 8.5 Transaction and Concurrency

- [ ] Appointment creation is transactional.
- [ ] Failed resource allocation leaves no partial appointment.
- [ ] Concurrent booking attempts cannot double-book the same Technician.
- [ ] Concurrent booking attempts cannot double-book the same Service Bay.

---

# 9. Non-Functional Requirements

- [ ] Business logic is not buried inside the controller.
- [ ] Availability calculation is independently testable.
- [ ] Appointment creation and resource assignment are performed inside a database transaction.
- [ ] Appropriate database indexes exist for appointment resource/time queries.
- [ ] API request validation is implemented.
- [ ] Authorization prevents customers from booking another customer's vehicle.
- [ ] Feature tests cover the complete booking workflow.
- [ ] The implementation follows the existing Laravel project conventions.

---

# 10. Acceptance Criteria

The primary acceptance criterion is:

> **Given** a customer owns a vehicle and requests a service at a dealership at a specific start time,  
> **when** the system finds a qualified technician and a service bay that are both available for the complete service duration,  
> **then** the system atomically creates a confirmed appointment linking the customer, vehicle, service, dealership, technician, and service bay.

Otherwise:

> **No appointment is created and the customer receives an appropriate availability or validation error.**

---

# 11. Recommended Implementation Order

The feature should be implemented in the following order:

1. Domain/model structure
2. Service Type and service duration
3. Technician qualifications
4. Resource availability engine
5. Appointment transaction
6. API endpoint and validation
7. Feature tests
8. Concurrency and edge-case handling

This keeps the business rules independent from the API/controller layer and makes the booking domain easier to extend.

---

# 12. MVP Success Definition

The feature is considered complete when a customer can successfully execute the following end-to-end scenario:

```text
Authenticate
    |
    v
Select owned Vehicle
    |
    v
Select Service Type
    |
    v
Select Dealership
    |
    v
Request Start Time
    |
    v
System calculates Service End Time
    |
    v
System finds qualified Technician
    |
    v
System finds available Service Bay
    |
    v
System confirms both resources
    |
    v
Create Appointment
    |
    v
Return Confirmed Appointment
```

And when no suitable Technician or Service Bay exists, the system safely rejects the request without creating a partial booking.
