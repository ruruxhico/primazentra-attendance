# PrimaZentra Attendance (hris-attendance)

Daily Time Records (DTR) for the PrimaZentra HRIS. Supervisors enter each
day's late minutes, undertime, OT hours and absences, then approve them.
Payroll only counts approved records.

One of three separate apps:

| App | Owns (Firestore) | Exposes |
|---|---|---|
| `primazentra-hris` (main) | `users`, `employees` | `GET /api/v1/employees`, `POST /api/v1/auth/login` |
| `hris-attendance` (this) | `dtr_records`, `period_locks` | `GET /api/v1/dtr?from=&to=[&employee_id=]`, `POST /api/v1/events` |
| `hris-payroll` | `payroll_runs`, `payroll_details` | `GET /api/v1/runs[/{period}]`, `GET /api/v1/employees/{id}/payslips`, `POST /api/v1/events` |

The apps never read each other's collections; they talk over REST with an
`X-Api-Key` header per calling app.

## Keeping the three apps in sync

Every change made in one app shows up in the other two:

1. **Live reads.** Each page fetches the other apps' data when it loads:
   Employees in the main app show this period's DTR and payslips; Attendance
   shows each period's payroll status; Payroll reads employees and DTR.
2. **Change events** (`POST /api/v1/events`, body `{type, source, occurred_at, data}`):

| Event | From → To | Effect |
|---|---|---|
| `employee.changed {employee_id}` | main → payroll | every draft payroll marked out of date |
| `dtr.changed {dates[], employee_ids[]}` | attendance → payroll | drafts for those periods marked out of date |
| `payroll.finalized {period_id, by}` | payroll → attendance | period's DTR locked (finalize fails if this fails) |
| `payroll.reopened {period_id}` | payroll → attendance | period's DTR unlocked |

Out-of-date drafts recompute automatically when opened. `employee.changed`
and `dtr.changed` are best effort; before finalizing, Payroll compares a
fingerprint of its current inputs with the draft's and recomputes if
anything changed, so a lost event cannot produce a wrong final payroll.
Unknown event types are answered with 202 and ignored.

## Setup

1. `composer install --ignore-platform-req=ext-grpc` (Firestore uses REST; the gRPC extension is not needed).
2. Copy the Firebase service-account key to `writable/credentials/firebase_key.json` (git-ignored).
3. Create `.env` (git-ignored):

```ini
CI_ENVIRONMENT = development
app.baseURL = 'http://localhost/hris-attendance/'
app.indexPage = ''
session.cookieName = 'hris_attendance_session'

# Main app: identity + employee master data
HRIS_MAIN_URL = http://localhost/primazentra-hris/
HRIS_MAIN_API_KEY = <the "attendance:" key in the main app's HRIS_API_KEYS>

# Keys accepted on this app's /api/v1/*  (caller:key,caller:key)
HRIS_API_KEYS = payroll:<payroll's HRIS_ATTENDANCE_API_KEY>,main:<main's HRIS_ATTENDANCE_API_KEY>

# Payroll (status display + dtr.changed events)
HRIS_APP_NAME = attendance
HRIS_PAYROLL_URL = http://localhost/hris-payroll/
HRIS_PAYROLL_API_KEY = <the "attendance:" key in payroll's HRIS_API_KEYS>
```

The main app's `.env` needs `HRIS_ATTENDANCE_URL`, `HRIS_PAYROLL_URL`,
`HRIS_ATTENDANCE_API_KEY`, `HRIS_PAYROLL_API_KEY`, `HRIS_APP_NAME = main` and
`HRIS_API_KEYS = attendance:<key>,payroll:<key>`.

## Access

Log in with a PrimaZentra account. Allowed roles: Administrator,
Operations Manager, Project Manager (see `app/Config/Hris.php`).

## DTR rules

- One record per employee per day (document id `{employee_id}_{date}`).
- Minutes are whole numbers ≥ 0; OT hours 0–16; no future dates.
- An absent day cannot also have late, undertime or OT.
- Records are saved as **pending**; approved records are locked.
- When Payroll finalizes a period, all of that period's DTR is locked until Payroll reopens it.
- The 15-minute late grace period is a payroll rule and is applied in `hris-payroll`.

## Tests

`vendor/bin/phpunit`
