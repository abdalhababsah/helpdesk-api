# Support Helpdesk API

The backend for an internal helpdesk. Employees raise tickets, support agents work a shared queue, and admins manage accounts, categories and reporting.

Built with Laravel 13 and MySQL. The frontend lives in a separate repository.

---

## What you need

- PHP 8.3 or newer (built and tested on 8.4)
- Composer
- MySQL 8 or 9, either your own or the one in `docker-compose.yml`

---

## Setup

**1. Install dependencies**

```bash
composer install
```

**2. Create your environment file**

```bash
cp .env.example .env
php artisan key:generate
```

**3. Start a database**

If you already have MySQL running, create an empty database called `helpdesk` and point `.env` at it.

Otherwise use the included one:

```bash
docker compose up -d
```

That runs MySQL on port **3307**, which is what `.env.example` expects. Port 3307 rather than the usual 3306 so it does not clash with a MySQL you may already have.

**4. Set a signing key**

The API refuses to start without one, and it must be at least 32 characters.

```bash
php artisan tinker --execute="echo 'JWT_SECRET='.bin2hex(random_bytes(32));" >> .env
```

**5. Create the tables and demo data**

```bash
php artisan migrate --seed
```

This creates 8 accounts, 3 categories and 200 tickets with replies, spread across statuses, priorities and dates so the filtering and paging have something real to work on.

**6. Run it**

```bash
php artisan serve --port=8080
```

Check it is up:

```bash
curl http://127.0.0.1:8080/api/health
```

---

## Logins

Password for every account is `Passw0rd!`

| Email | Name | Role |
|---|---|---|
| `admin@example.com` | Amina Admin | admin |
| `sam@example.com` | Sam Support | moderator |
| `priya@example.com` | Priya Agent | moderator |
| `jordan@example.com` | Jordan Employee | user |
| `dana@example.com` | Dana Former | user, **deactivated** |

Three more employees exist so the queue looks like a shared queue rather than one person's inbox.

Dana is deactivated on purpose, so you can see what a blocked login does without having to break a working account first.

---

## Trying it out

Import `docs/helpdesk-api.postman_collection.json` into Postman.

1. Open the **Sign in** folder and run one of the three requests.
2. The token is stored automatically. Every other request will now work.
3. To act as a different role, run a different sign-in request.

Several requests fill in variables as you go, so running **List tickets** then **View a ticket** just works without copying ids by hand.

Access tokens last 10 minutes. If requests start failing with `AUTH_TOKEN_STALE`, run **Refresh session** or sign in again.

---

## The endpoints

Base path is `/api`.

### Signing in

| | | Who |
|---|---|---|
| `POST` | `/auth/login` | Anyone |
| `POST` | `/auth/refresh` | Anyone with the cookie |
| `POST` | `/auth/logout` | Anyone |
| `POST` | `/auth/logout-all` | Signed in |
| `POST` | `/auth/forgot-password` | Anyone |
| `POST` | `/auth/reset-password` | Anyone with a link |
| `GET` | `/auth/me` | Signed in |

### Tickets

| | | Who |
|---|---|---|
| `GET` | `/tickets` | Everyone, but employees see only their own |
| `GET` | `/tickets/summary` | Agents, admins |
| `POST` | `/tickets` | Everyone |
| `GET` | `/tickets/{id}` | The requester, agents, admins |
| `PATCH` | `/tickets/{id}` | Agents, admins |
| `DELETE` | `/tickets/{id}` | Admins |
| `POST` | `/tickets/{id}/comments` | The requester, agents, admins |

### Categories

| | | Who |
|---|---|---|
| `GET` | `/categories` | Everyone |
| `POST` | `/categories` | Admins |
| `PATCH` | `/categories/{id}` | Admins |

### Accounts

| | | Who |
|---|---|---|
| `GET` | `/users` | Admins |
| `GET` | `/users/assignable` | Agents, admins |
| `GET` | `/roles` | Admins |
| `POST` | `/users` | Admins |
| `GET` | `/users/{id}` | Admins |
| `PATCH` | `/users/{id}` | Admins |
| `DELETE` | `/users/{id}` | Admins |
| `POST` | `/users/{id}/password-reset` | Admins |

An admin can change a person's name, email, role and whether the account is active. Changing a role or deactivating signs them out everywhere immediately; correcting a name or email does not, because neither grants anything.

`GET /users/{id}` returns the account with the counts you want before changing it: tickets raised, tickets ever assigned, and how many of those are still open.

**Deleting an account** hides it rather than removing it. The person disappears from every list, cannot sign in, and every session ends at once. Their open tickets go back to the queue unassigned. Their name stays on resolved work and on every comment, because the row is kept. You cannot delete yourself or the last active admin.

**Password reset** is link only. An admin can send someone a one-time link; nobody can type a password for another person. The link expires after 60 minutes, works once, and a newer link retires any older one. Anyone can also ask for a link themselves from the sign-in page.

`/roles` exists because role ids are generated when you seed, so they differ per installation and the frontend cannot hardcode them. It is what fills the role dropdown on the account screens.

### Reporting

| | | Who |
|---|---|---|
| `GET` | `/metrics` | Admins |

---

## Filtering the ticket list

All of it happens in the database, so paging stays correct however you filter.

```
GET /api/tickets
  ?page=2
  &limit=20
  &status=open,in_progress
  &priority=high,urgent
  &category=it-access
  &assignee=me
  &search=vpn
  &overdue=true
  &sortBy=createdAt
  &sortDir=desc
```

| Parameter | Values |
|---|---|
| `page` | 1 or more. Default 1 |
| `limit` | 1 to 100. Default 20 |
| `status` | `open`, `in_progress`, `resolved`, `closed`. Comma separated |
| `priority` | `low`, `medium`, `high`, `urgent`. Comma separated |
| `category` | One or more category slugs |
| `assignee` | `me`, `unassigned`, or a user id |
| `search` | Matches the subject and the description |
| `overdue` | `true` or `false` |
| `sortBy` | `createdAt`, `updatedAt`, `priority`, `status`, `dueAt`, `subject` |
| `sortDir` | `asc` or `desc` |

A parameter the API does not recognise is rejected rather than ignored, so a typo shows up instead of quietly returning everything.

An unknown category slug is rejected too, because an empty result caused by a typo looks exactly like a genuine empty result.

### What comes back

```json
{
  "data": [
    {
      "id": "01m1rr92ah8vpf6rbe4stnz9qw",
      "subject": "Expense reimbursement not received",
      "status": "in_progress",
      "priority": "urgent",
      "category": { "id": "01m1...", "slug": "hr-payroll", "name": "HR - Payroll" },
      "requester": { "id": "01m1...", "name": "Tariq Nasser" },
      "assignee": { "id": "01m1...", "name": "Amina Admin" },
      "dueAt": "2026-09-05T16:21:46+00:00",
      "isOverdue": true,
      "resolvedAt": null,
      "commentCount": 3,
      "createdAt": "2026-09-05T12:21:46+00:00",
      "updatedAt": "2026-09-05T12:21:46+00:00"
    }
  ],
  "pagination": { "page": 1, "limit": 20, "totalItems": 195, "totalPages": 10 }
}
```

The description is left out of the list because nothing in a table shows it. It is still searched, and it is included when you open a single ticket.

---

## When something goes wrong

Every error looks the same:

```json
{
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "The request failed validation.",
    "details": [
      { "field": "limit", "message": "The limit field must not be greater than 100." }
    ]
  }
}
```

`details` only appears on validation errors, and lists one entry per field so a form can show messages next to the right inputs.

| Code | Status | Meaning |
|---|---|---|
| `VALIDATION_FAILED` | 400 | Something in the request was wrong |
| `AUTH_REQUIRED` | 401 | No token, or it is not valid |
| `AUTH_TOKEN_STALE` | 401 | Token is valid but the session ended. Refresh and retry |
| `AUTH_INVALID_CREDENTIALS` | 401 | Wrong email or password |
| `AUTH_REFRESH_INVALID` | 401 | The refresh cookie cannot be used |
| `AUTH_ACCOUNT_DISABLED` | 403 | The account is deactivated |
| `FORBIDDEN` | 403 | Signed in, but not allowed to do this |
| `NOT_FOUND` | 404 | No such thing |
| `CONFLICT` | 409 | Allowed, but not from the current state |
| `INVALID_ASSIGNEE` | 422 | That person cannot be given tickets |
| `RATE_LIMITED` | 429 | Too many attempts |

`AUTH_TOKEN_STALE` is separate from `AUTH_REQUIRED` so the frontend knows to quietly refresh instead of throwing the user back to the login screen.

---

## Who can do what

| | User | Moderator | Admin |
|---|---|---|---|
| Raise a ticket | yes | yes | yes |
| See a ticket | own only | any | any |
| Reply to a ticket | own only | any | any |
| See the shared queue | no | yes | yes |
| Assign a ticket | no | yes | yes |
| Change status or priority | no | yes | yes |
| Delete a ticket | no | no | yes |
| Manage categories | no | no | yes |
| Manage accounts | no | no | yes |
| Delete an account | no | no | yes |
| See reporting | no | no | yes |

Every one of these is checked on the server, not just hidden in the interface. Calling an endpoint directly with the wrong role returns 403.

Permissions are stored in the database (`roles`, `permissions`, `role_permissions`) rather than written into the code, so the table above is data. A test compares what is in the database against the table above and fails if they ever disagree.

---

## Signing in works like this

Two tokens, doing different jobs.

**The access token** lasts 10 minutes and is sent as `Authorization: Bearer ...`. The frontend keeps it in memory, never in `localStorage`, so a script injected into the page cannot read it.

**The refresh token** lasts 30 days and lives in a cookie the browser will not let JavaScript touch. It is only sent to `/api/auth/*`, so it never rides along on a ticket request.

When the access token expires, the frontend calls `/auth/refresh` and gets a new pair. The old refresh token stops working the moment it is used.

**If a refresh token is used twice**, that means it leaked, so every session for that sign-in is ended at once. The person has to sign in again. That is deliberate.

**Deactivating someone or changing their role takes effect immediately**, not when their token happens to expire. A demoted admin loses admin access on their very next request.

> Note for the frontend: a browser does not send cookies on a JSON request unless you ask it to. Your fetch or axios setup needs `credentials: 'include'`, or refresh will never work and people will be signed out every 10 minutes.

---

## Emails

The person who raised a ticket is emailed when:

- the status changes
- someone picks the ticket up

A person is also emailed a one-time link when a password reset is requested, by them or by an admin, and a confirmation once their password has changed.

Every email uses one template in the same palette as the web app, in `resources/views/vendor/mail`. Buttons link to the React app, so `FRONTEND_URL` in `.env` must point at it.

Nobody is emailed about something they did themselves, so an agent updating their own ticket sends nothing. Deactivated accounts are skipped, since they cannot sign in to act on it. Handing a ticket back to the queue sends nothing either: it is not something the requester can do anything about.

**Emails are sent after the change is saved, never during.** An email about a change that then failed is already in someone's inbox and cannot be taken back.

Out of the box `MAIL_MAILER=log`, so messages are written to `storage/logs/laravel.log` and you can see them without configuring a mail server:

```bash
tail -f storage/logs/laravel.log
```

`QUEUE_CONNECTION=sync` is the default so this works straight after cloning. In production set it to `database` and run a worker, so a slow mail server cannot hold up a response:

```bash
php artisan queue:work
```

---

## Deadlines

Every ticket gets a deadline when it is raised, based on its priority:

| Priority | Deadline |
|---|---|
| Urgent | 4 hours |
| High | 24 hours |
| Medium | 72 hours |
| Low | 1 week |

Changing the priority moves the deadline with it. A ticket counts as overdue when the deadline has passed and it is not yet resolved. A ticket resolved late is not overdue, because the work is done.

---

## Running the tests

```bash
composer test          # or: php artisan test
composer lint          # code formatting
composer analyse       # static analysis
```

180 tests. They run against a real MySQL database called `helpdesk_test`, not an in-memory stand-in, because the schema uses MySQL features that other engines do not have. Create it once:

```sql
CREATE DATABASE helpdesk_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
```

The one worth knowing about is `RoleAccessSweepTest`. It calls every endpoint as every role and as a stranger, and checks the exact response each time. That is the test that catches an endpoint which forgot to check permissions at all.

---

## Settings

| Setting | Default | What it does |
|---|---|---|
| `DB_PORT` | `3307` | Matches the included Docker database |
| `DB_COLLATION` | `utf8mb4_0900_ai_ci` | MySQL 8's own default, rather than Laravel's older one |
| `JWT_SECRET` | none | Required. At least 32 characters |
| `JWT_TTL` | `10` | Access token lifetime in minutes |
| `REFRESH_TTL_DAYS` | `30` | Refresh token lifetime |
| `WEB_ORIGIN` | `http://localhost:5173` | Where the frontend runs |
| `AUDIT_RETENTION_DAYS` | `365` | How long the activity log is kept |

---

## Activity log

Every change is recorded: who did it, what it was, and what changed. So are refused attempts, failed sign-ins, and replayed refresh tokens.

The log entry is written in the same step as the change itself, so if the change is rolled back the log entry goes with it. A log that records things that did not happen is worse than no log.

It is trimmed on a schedule rather than growing forever:

```bash
php artisan model:prune --model="App\Models\ActionLog"
```

---

## Not built

Left out on purpose, so the list reads as decisions rather than gaps:

- File attachments
- Automatic escalation when a deadline passes. Deadlines are tracked and shown, but nothing acts on them
- Emails to anyone other than the person who raised the ticket
- Threaded replies. The thread is flat
- Live updates. The queue does not push changes to open browsers
- A screen for editing permissions. The tables support it, but the roles are fixed
- More than one role per person

---

## Where things live

```
app/
  Actions/          One class per thing the system can do
  Authorization/    Who can do what, and the check itself
  Queries/          Reading: the ticket list and the reporting numbers
  Support/          Tokens and other plumbing
  Http/             Routes, controllers, validation, response shapes
  Models/           The database tables as objects
database/
  migrations/       The schema
  seeders/          The demo data
  factories/        Test data
docs/
  spec.md           The full design document and the reasoning behind it
  schema.dbml       The database diagram. Paste into dbdiagram.io
  helpdesk-api.postman_collection.json
tests/              191 tests
```
