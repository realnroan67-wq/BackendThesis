# Horizon Realty API

Independent Laravel 13 API foundation. The Vue application remains in `../REFront`.
Approved inquiry contracts live in `../REFront/docs/inquiry-api-v1.md` and
`../REFront/docs/inquiry-api-v1.openapi.json`.

## Current boundary

`GET /api/v1/health` executes `SELECT 1` and returns HTTP 200 with database
`connected`, or HTTP 503 with database `unavailable`. Responses contain no
exception details and use `Cache-Control: no-store`. Exceptions are logged locally.
This is a development readiness endpoint; review public exposure before deployment.

Sanctum session authentication, server-controlled roles, property ownership and
public property discovery are implemented. Inquiry creation provides transactional
persistence and idempotency; F1 adds sender history, participant details and the
active-grant provider inbox. F2 adds provider-authorized closing with atomic immutable audit records.
Vue integration for these read endpoints is deferred; production inquiry sending
remains default-disabled pending deployment verification and explicit release.
Registration remains unavailable.

## Existing Windows setup

Verified with PHP 8.5.9, Laravel framework 13.37.0, PostgreSQL 18 and Sanctum 4.3.3.
Run these commands from `C:\REBackend`:

```powershell
.\postgres.ps1 status
# If stopped:
.\postgres.ps1 start
.\php.ps1 artisan migrate
.\php.ps1 artisan migrate:status
.\php.ps1 artisan serve --host=localhost --port=8000
```

Open http://localhost:8000/api/v1/health. Stop the HTTP server with Ctrl+C.
Stop only the project database with `.\postgres.ps1 stop` when finished.
The database is not registered as a startup service; start it after a reboot.

The dedicated cluster in `.local/postgres` listens on `127.0.0.1:5434`.
Existing PostgreSQL services on other ports were left untouched. Development uses
`horizon_realty` / `horizon_app`; tests use `horizon_realty_test` / `horizon_test`.
Both roles have separate generated local passwords and no superuser, database
creation or role creation permissions. PUBLIC database access is revoked, so the
test role cannot connect to the development database.

`php.ps1` uses `C:/php-8.5.9/php.exe` and a project-local extension configuration,
without changing XAMPP or global PHP settings. Override `HORIZON_PHP_BINARY` when
relocating PHP; recreate ignored `.runtime/php/horizon.ini` after changing it.
PHP must have curl, mbstring and openssl enabled; the wrapper adds fileinfo, zip,
pdo_pgsql and pgsql. `HORIZON_PG_BIN` overrides the PostgreSQL binary directory.

## Environments and another workstation

`.env`, `.env.testing`, `.local/`, and `.runtime/` are ignored. Never commit
credentials, application keys, database files or local logs. Safe placeholders are
provided in `.env.example` and `.env.testing.example`. Copy these examples to the
corresponding local files on another workstation, set distinct database passwords,
and generate separate keys:

```powershell
.\php.ps1 C:/ProgramData/ComposerSetup/bin/composer.phar install
.\php.ps1 artisan key:generate
.\php.ps1 artisan key:generate --env=testing
.\php.ps1 artisan config:clear
```

The cluster and secrets are intentionally not portable through Git. Provision
PostgreSQL locally before migrations. For the same project-local layout, use
PostgreSQL 18 `initdb -D C:/REBackend/.local/postgres -U postgres -A scram-sha-256 -W`,
then set `listen_addresses = '127.0.0.1'` and `port = 5434` in its postgresql.conf.
The `.local` parent must exist. Start with `postgres.ps1 start`, then connect using
`psql -h 127.0.0.1 -p 5434 -U postgres -d postgres -W` and provision:

```sql
CREATE ROLE horizon_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password horizon_app
CREATE ROLE horizon_test LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
\password horizon_test
CREATE DATABASE horizon_realty OWNER horizon_app;
CREATE DATABASE horizon_realty_test OWNER horizon_test;
REVOKE CONNECT ON DATABASE horizon_realty FROM PUBLIC;
GRANT CONNECT ON DATABASE horizon_realty TO horizon_app;
REVOKE CONNECT ON DATABASE horizon_realty_test FROM PUBLIC;
GRANT CONNECT ON DATABASE horizon_realty_test TO horizon_test;
```

Use interactive password prompts; put the matching app/test passwords only in
local environment files. Adjust paths and PHP/PostgreSQL binary overrides for
your machine. For an existing database server, provision the same roles/databases
there and update DB_PORT in both local environment files instead of using the
project cluster scripts.

## Verification and test isolation

```powershell
.\php.ps1 artisan config:clear
.\php.ps1 artisan migrate --env=testing
.\php.ps1 artisan test --compact
.\php.ps1 artisan route:list --path=api
.\php.ps1 vendor/bin/pint --dirty --format agent
```

Feature tests refuse to run unless the environment is testing, the driver is
PostgreSQL, the database is `horizon_realty_test`, and the role is `horizon_test`.
Database URLs are disabled in PHPUnit to prevent overriding those safeguards.
Health tests are read-only and do not need RefreshDatabase. A real rejected
connection verifies that the test role cannot access development. A real connection
failure verifies the generic 503 response even with debug enabled. Never point
future destructive test migrations at the development database.

Initial users, cache, jobs and Sanctum token migrations are applied to both databases.
The additive role migration is also applied to both databases. Inquiry and provider-grant infrastructure is applied in Phase C2; Phase D1 adds only POST /api/v1/inquiries.
Frontend validation remains independent: run its npm test, lint, format:check and
build commands from `../REFront`.

## Role authorization checkpoint (Part A)

`App\Enums\UserRole` defines one role per user: administrator, property_provider,
or business_user. The PostgreSQL role column defaults to business_user and rejects
null or unknown values. Existing users receive the business_user default.
The User model casts roles to the enum and excludes role from mass assignment.
Only trusted backend code may assign roles; no public registration or role-change
endpoint is exposed. Future registration must explicitly restrict allowed roles.

Area gates are `access-administration`, `access-provider-area`, and
`access-business-area`. Use `auth:sanctum` followed by `can:<ability>` middleware.
These gates are role eligibility checks only. Property ownership and inquiry
participant policies check stored ownership and active grant records.
There is no administrator bypass or implicit inquiry access. UserPolicy currently
permits viewing one's own profile only; /api/v1/me applies that policy.

Authorization tests use transactions in the isolated test database. Run migrations
with `--env=testing` before running the suite. Test-only routes exercise 401, 403 and
permitted access without exposing placeholder production endpoints. These tests
use actingAs and do not verify login, logout, CSRF or browser cookie behavior.
Separate session authentication tests and Edge checks cover Part B; inquiry sending stays disabled.

## SPA session authentication (Part B)

Use `http://localhost:5173` for Vue and `http://localhost:8000` for Laravel. Database
connections still use `127.0.0.1:5434`. Do not mix browser hostnames. If PowerShell
blocks the helper, invoke `powershell -NoProfile -ExecutionPolicy Bypass -File
.\php.ps1 artisan serve --host=localhost --port=8000` (one command on one line).
The generic `php` and `composer` commands currently select incompatible XAMPP PHP 8.2.

The browser first requests GET /sanctum/csrf-cookie, then POST /login, then GET
/api/v1/me. POST /logout invalidates the session and rotates the CSRF token.
Login accepts email/password only and is limited to five attempts per email/IP
and twenty attempts per IP per minute. No tokens, public registration, default
seeded login accounts, account suspension model or role-changing endpoints exist.
Provision accounts only through separately authorized backend operations.

Local configuration: SESSION_DRIVER=database, host-only SESSION_DOMAIN=null,
SESSION_SECURE_COOKIE=false, SESSION_SAME_SITE=lax,
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000, and
FRONTEND_URL=http://localhost:5173. Both safe environment examples document these
settings. Session tables already exist. Clear configuration after changing origins.
CORS uses one exact origin with credentials; an untrusted browser origin cannot
read responses. CORS is not authorization; CSRF and policies remain required.

For production, explicitly configure the HTTPS frontend URL and API URL, exact
stateful hosts with ports when applicable, SESSION_SECURE_COOKIE=true, and
APP_DEBUG=false. Use compatible same-site domains and a deliberate cookie-domain
policy for subdomains. Never deploy local origins or wildcard credentialed CORS.
Shared deployments also require a shared rate-limit cache/session database.

SessionAuthenticationTest uses real credentials and database session cookies;
it does not use actingAs. Dedicated CSRF cases override only the testing bypass.
Read-only health tests remain unchanged. Edge QA additionally verified invalid
credentials, login, authenticated refresh, logout and signed-out refresh, missing
CSRF rejection and CORS blocking from localhost:5174, using a removed disposable
user in horizon_realty_test. Temporary browser fixtures are removed after QA.
Vue session transport/state tests remain independently runnable in REFront.

## Property ownership (Phase C1)

The properties table stores id, a required indexed provider_id referencing users,
title and timestamps. The foreign key restricts deletion of a referenced user.
Property::provider() and User::properties() represent the relationship. No mock Vue
listing is imported or assigned an invented provider. PropertyFactory creates only
test fixtures; the default seeder does not insert listings or login accounts.

Trusted backend code must associate a current Property Provider when creating a
property. Only title is mass assignable. Saving validates the stored provider role;
direct Eloquent owner reassignment is blocked until a separately approved transfer
workflow exists. Raw SQL/bulk updates bypass model events and must not be used to
implement provider-facing writes. The database foreign key guarantees an existing
user, while provider-role validity is checked by application code.

PropertyPolicy allows providers to create and access only their own management
records. Property::ownedBy($user) scopes management lists to current database
ownership and the stored provider role, including revocation with stale objects.
Administrators and Business Users have no management access. Hard deletion and
ownership transfer abilities are denied to every role pending approved workflows.
This is not the future public-discovery policy, and it grants no inquiry access.
Future update controllers must authorize, lock the property and recheck ownership
inside their write transaction to avoid authorization/write races.

No property API, public listing publication/eligibility workflow, ownership-transfer
API, inquiry table or participant grants are implemented in C1. Test-only routes
exercise middleware and writes without exposing placeholder production endpoints.
Authorization tests use actingAs; existing session tests separately verify login.
Run the new migration in development and testing before the suite, using PHP 8.5:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\php.ps1 artisan migrate
powershell -NoProfile -ExecutionPolicy Bypass -File .\php.ps1 artisan migrate --env=testing
powershell -NoProfile -ExecutionPolicy Bypass -File .\php.ps1 artisan test --compact
```

PostgreSQL remains on port 5434, connectivity tests stay read-only, registration is
unavailable, and Vue inquiry submission remains disabled. Phase C2 implements
historical recipient and active participant grants separately from current ownership.

## Inquiry participant authorization (Phase C2)

Inquiry records now store restrictive property/sender/historical-provider foreign
keys, contract-sized contact snapshots, message, pending/closed status and
timezone-aware timestamps. The provider/status, sender and property lookups are
indexed. InquiryFactory and InquiryProviderGrantFactory produce isolated test
records only; no production seeder creates inquiries.

Inquiry::visibleTo($user) permits only a current Business User's own records or a
current Property Provider's active grants. Inquiry::forProvider($user) scopes the
provider list and status-update eligibility. Both read persisted role/membership
state; cached relations and forged in-memory IDs cannot widen access. InquiryPolicy
uses those same scopes. viewAny is only list eligibility and never an unscoped
query permission. Administrators have no oversight permissions in Approved v1 and
remain denied, even if a grant row exists for their user ID.

Historical provider_id is not an authorization shortcut. Property ownership changes
leave historical recipients and grants unchanged. A new owner receives no prior
contacts or inquiry access. Only an active grant plus the provider role allows
view/updateStatus. Revocation immediately removes read/list/close access, without
removing Business User sender access. Future controllers must recheck policy/grants
within their locked write transaction, rather than caching an authorization result.
The updateStatus policy grants eligibility to close, including closed no-op cases;
it does not implement a status endpoint or authorize arbitrary status values.

PostgreSQL triggers freeze inquiry identities, snapshots and creation time, disallow
hard deletion and reopening, and preserve grant audit history. A unique membership
per inquiry/provider records granted_at, nullable granted_by (system creation),
revoked_at, revoked_by and revocation_reason. A revocation requires a stored actor,
nonempty reason and a time no earlier than granting. Membership/origin fields cannot
be repointed, and a revoked audit cannot be rewritten or reactivated. There is no
regrant, reassignment, oversight or grant-administration API; any future workflow
needs explicit authorization and an approved audit/retention design. Grant rows are
fully guarded against request mass assignment. Raw SQL is also subject to history
constraints, but database credentials are never an application authorization layer.

C2 does not implement request validation against the shared fixtures, property
publication/eligibility, identity derivation during creation, idempotency, atomic
creation of the inquiry plus initial grant, contract responses, or inquiry endpoints.
Those were deferred by C2 and are now implemented by D1 below. InquiryPolicy::create
now permits current Business Users, and the service repeats authorization while locked. Schema/factory persistence in tests is not submission support.
Default seeding remains empty, registration remains unavailable, frontend sending
is disabled, health tests remain read-only and PostgreSQL stays on port 5434.

Apply both additive migrations in each environment using the PHP 8.5 helper before
running the isolated backend suite. Rolling these migrations down drops their tables
and trigger functions; do not use rollback to remove production history. No frontend
files or existing authentication routes changed in this checkpoint.

## Secure inquiry creation (Phase D1)

POST /api/v1/inquiries is the only implemented inquiry endpoint. It uses Sanctum
sessions, CSRF, JSON payloads, a required UUID v4 Idempotency-Key and Business User
authorization. Read/list/status endpoints, grant administration, property APIs,
registration and Vue inquiry submission remain unavailable. The returned Location
is the contracted future read-resource path; its GET endpoint is not implemented yet.

Property eligibility is fail-closed: is_published and accepts_inquiries must both
be true, and the stored owner must currently be a Property Provider. Both flags
are protected from request mass assignment. Existing properties default to false.
There is no publication endpoint or administrator bypass. Trusted future publication
workflows must explicitly authorize changes rather than deriving eligibility from
frontend mock labels.

contracts/ contains pinned, checksum-verified v1 validation and fixture artifacts,
so the backend does not need a Vue checkout at runtime or in tests. Synchronize
these versioned artifacts deliberately when updating the approved contract. Rules,
lengths, patterns and phone digit ranges come from the artifact. Normalization trims
only ASCII space/tab/CR/LF and maps blank optional snapshots to null. Validation
counts Unicode code points and preserves email case. The endpoint bypasses Laravel's
generic trimming/null conversion and rejects unknown fields and non-JSON objects.
Only validated JSON body fields enter the normalized fingerprint; no credentials,
cookies, caller-supplied identities, timestamps, status or grants are accepted.

The service acquires a transaction-scoped advisory lock for user/createInquiry/key,
rechecks and share-locks the sender, then checks the retained result before looking
up property eligibility. An overlapping same-key attempt returns 409
IDEMPOTENCY_IN_PROGRESS with Retry-After: 1. A matching completed attempt returns the
original 201 JSON bytes and Location plus Idempotency-Replayed: true, even after
ownership/availability/status changes. Changed normalized details return 409
IDEMPOTENCY_CONFLICT. Keys are case-normalized UUIDs and isolated per user/operation;
they have no expiry. Database scope uniqueness and immutable stored snapshots protect
successful retry results for the inquiry lifetime. Never automatically retry POST.

For a new attempt, the property is locked FOR UPDATE and its owner is share-locked
before deriving the historical provider. Inquiry, matching provider grant and retry
result are committed together; any failure rolls back all three. No notifications
or other external side effects are dispatched. The HTTP controller returns 201 only
after the service transaction returns. Future transfer/publication writers must use
the same property row locking discipline, with authorization and rechecks inside the
transaction. Future role writers must respect the corresponding user row locks.
A retained result rechecks Business User role and sender scope on every replay.

Resources use an explicit field allowlist, UTC RFC3339 timestamps and JSON-safe
integer checks. Errors use the v1 message/code envelope, with errors on 422 and no
SQL/stack traces even when APP_DEBUG=true. Throttling is thirty attempts per user
per minute, including replays; deploy with a shared cache for multiple app servers.
CORS permits Idempotency-Key and exposes Location, Idempotency-Replayed, Retry-After.
The session cookie is now laravel_session to match Approved OpenAPI v1. Restart
Laravel/clear config and sign in again after this configuration change; old cookie
names do not establish the new session.

Tests exercise real credential-established cookies with CSRF enabled, shared fixtures,
Unicode boundaries, forbidden roles/fields, rollback at grant/result persistence,
replay after changes and long delays, conflicts, user isolation and database uniqueness.
Parallel PHP HTTP-kernel workers use separate connections and disposable schemas
inside horizon_realty_test. Explicit lock signals coordinate duplicate attempts and
both ownership race directions; these are not sequential replay tests. Test-only
schemas are dropped after workers finish. The default DB_SCHEMA remains public;
never point this test workflow at development or production databases.

Use the PHP 8.5 helper for migrations and tests. PostgreSQL remains on port 5434,
health tests stay read-only, and frontend submission stays disabled until D2.

## Public property reads (Phase E1; initial response, expanded by E1.5 below)

`GET /api/v1/properties` and `GET /api/v1/properties/{id}` are public read-only
endpoints. They use a discovery scope, separate from provider-only management
policies: a property must be published and its stored owner must currently have
the Property Provider role. Unpublished, missing, invalid-ID and ineligible-owner
details all return the same 404. Signing in as an owner or Administrator does not
bypass public visibility. No publishing or property-management endpoint was added.

At the E1 checkpoint, the public field allowlist was deliberately small because the
property schema stored only title, ownership and availability flags:

```json
{
    "data": {
        "id": 123,
        "title": "Published business space",
        "accepts_inquiries": true
    }
}
```

IDs are authoritative backend IDs and positive JavaScript-safe integers. Provider
IDs, user/profile data, related inquiries/grants, timestamps and private review
notes are not serialized. E1.5 below adds persisted listing facts. Administrative review state remains unmodeled and is never inferred from publication. Vue integration remains E2.

Publication controls discovery; accepts_inquiries controls whether a new inquiry
may be attempted. A published property may remain browseable with that flag false.
Neither flag means Administratively Reviewed, legal verification or ownership
verification. No review badge/status is inferred. InquiryService still rechecks
publication, availability and the current provider role inside its locked creation
transaction; a public response is a snapshot, not a lasting permission to submit.

The list response uses Laravel's `data`, `links`, and `meta` pagination envelope,
ordered by ascending ID. Supported query parameters are `page` (1–10000, default 1)
and `per_page` (1–50, default 12). Empty and out-of-range result pages return an empty
data array and public-only pagination totals. GET bodies cannot override query
pagination. Unsupported parameters, including search/filter/sort/include, return
422 rather than silently appearing to work; those features need an explicit API
contract before E2 integration.

Errors have `message` and `code`: 404 PROPERTY_NOT_FOUND, 422 VALIDATION_ERROR (with
field errors), 405 METHOD_NOT_ALLOWED, or 500 SERVER_ERROR. Unexpected exceptions
never expose stack traces/SQL, even with debug enabled. Successful reads and errors
use no-store caching so later visibility changes are rechecked. The routes add only
GET/HEAD; they do not publish write operations or public registration.

Run the focused E1 tests, then the full backend suite:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ./php.ps1 artisan test --compact --filter=PropertyDiscoveryTest
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ./php.ps1 artisan test --compact
```

Tests use DatabaseTransactions and the existing isolated PostgreSQL test
connection on port 5434. No migration or dependency change is required for this
minimal read model. Health tests remain unchanged and read-only. Vue property pages,
the mock-to-backend QA mapping and default-disabled/production-disabled inquiry
submission gate are unchanged. The D2 Edge session-expiry message remains unverified.

E1 verification: 40 new discovery tests passed; full backend suite 164 tests / 694 assertions. Pint and Composer validation passed. A read-only HTTP smoke check returned 200 with an empty published collection in the normal development database.

## Persisted listing facts (Phase E1.5 — current response)

The additive `2026_10_18_190229_add_public_listing_fields_to_properties_table`
migration preserves property IDs, ownership, existing publication/inquiry flags
and inquiry history. It reuses `is_published` and `accepts_inquiries`; no duplicate
publication state or administrative review badge is introduced. Unrecorded facts
remain null, with empty feature/image collections. No mock facts are seeded.

Both public property endpoints now use this explicit allowlist (illustration only,
not an actual database record):

```json
{
    "data": {
        "id": 123,
        "title": "Example office",
        "location": "Butuan City",
        "address": null,
        "property_type": "office",
        "transaction_type": "lease",
        "price_amount": "120000.00",
        "price_currency": "PHP",
        "price_period": "month",
        "area_sqm": "420.00",
        "description": "Example public description.",
        "features": ["Flexible interior layout"],
        "images": [],
        "accepts_inquiries": true
    }
}
```

Amounts and areas serialize as two-place decimal strings, never floating-point
JSON numbers. PHP is the persisted platform currency; other currencies are rejected.
A sale amount uses `price_period=total`, a lease amount uses `month`. Both amount and
period may be null for an unknown price; if either exists, both and a matching
transaction type are required. Zero prices are allowed, areas must be positive.
Price precision is NUMERIC(14,2), range 0–999999999999.99; area is NUMERIC(12,2),
range 0.01–9999999999.99. Authoring validation rejects float inputs and more than two
fractional places; raw SQL follows PostgreSQL's numeric scale rounding before
constraints run. NaN/Infinity and values outside the stored ranges are rejected.

Property types are `office`, `retail`, `warehouse`, `commercial-building`;
transaction types are `sale` or `lease`. Location allows 150 Unicode characters,
address/title 255, description 10000. Optional text uses null for unknown facts,
not blank strings. Features are a JSON array of at most 20 nonblank strings, each
at most 150 characters. PostgreSQL checks enforce the important rules even when
writes bypass Eloquent. The title nonblank constraint is NOT VALID for historical
rows: migration never rewrites a legacy blank title, but new/updated rows must have
a nonblank title. Such legacy data needs deliberate review before authoring it.

`PropertyListingValidation` validates complete trusted authoring snapshots, and
Property's saving hook applies it automatically. It grants no authorization.
Ownership, visibility and address approval remain guarded against request mass
assignment. Address itself is also guarded: the public resource returns it only
when trusted code explicitly sets `address_is_public=true`. Clearing an approval
hides the address on the next request. Do not store private information in public
location/description/features. There is still no property authoring or approval API.

`property_images` stores image metadata separately: property foreign key, storage
path, ordering position, nullable caption and protected `is_public` flag (default
false). Position 0–9 plus a per-property unique position bounds all images to ten;
captions allow 200 characters. Only local paths of the form
`properties/{property_id}/{lowercase-UUID-v4}.{jpg|jpeg|png|webp|avif}` are accepted.
External URLs, path traversal, encoded segments, queries and SVG are rejected by
both model validation and database checks. Storage paths are unique. Image metadata
is fully guarded; unapproved image metadata never appears in a public response.

Public images serialize in position order as `{ "url": "/storage/properties/...",
"caption": null }`; image IDs, approval flags and storage metadata are omitted.
Clients resolve these relative paths against the API origin, not the Vue origin.
The collection endpoint eager-loads approved images in one query. This milestone
implements neither uploads nor file existence/content verification. A future
trusted upload/approval workflow must validate actual image files and keep
unapproved files off public storage; a metadata flag alone is not a file ACL.
Test image factories create metadata only, not real upload files.

Existing development records keep their original facts. Populate only verified
listing values through a deliberate authorized data/import process; no automatic
backfill, sample-publication command or production seeder is provided. Descriptive
updates never enable inquiries. The existing public visibility scope and locked
InquiryService eligibility checks are unchanged, as are sender/recipient/grant and
idempotency rules.

Apply the migration with the PHP 8.5 helper in each environment before deploying
code that reads the expanded columns. PostgreSQL remains on port 5434. It has been
applied to local development and the isolated test database. Rollback removes the
new listing/image data, so do not use rollback as a cleanup mechanism for real data.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ./php.ps1 artisan migrate --force --no-interaction
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ./php.ps1 artisan test --compact --filter=PropertyListingSchemaTest
powershell.exe -NoProfile -ExecutionPolicy Bypass -File ./php.ps1 artisan test --compact
```

No Vue files, mock listings or inquiry gates changed. Public registration remains
unavailable; health tests remain unchanged and read-only. Discovery pagination and
unsupported-filter behavior remain as documented in E1; query/filter integration
belongs to E2. The Edge session-expiry message is still unverified.

E1.5 verification: 87 new schema tests passed, alongside the 40 discovery tests; full backend suite 251 tests / 887 assertions. Pint and Composer validation passed. The normal development list endpoint returned HTTP 200 after migration. No frontend files or health tests were changed.

## Public search contract ? E2.2B

GET /api/v1/properties accepts only search, property_type, transaction_type,
max_price, sort, page and per_page. Search is literal case-insensitive substring
matching over public title/location/description and address only when
address_is_public is true. Search is bounded to 150 characters. Unsupported
parameters and invalid values return 422; GET bodies cannot alter the search.

Types follow the persisted listing enums; transactions are sale/lease.
max_price is an exact decimal string (0 through 999999999999.99, at most two
fractional digits), compared directly against PostgreSQL NUMERIC with a bound
string. No PHP or JavaScript floating-point money comparisons are used.
A budget or price sort requires transaction_type, keeping sale totals separate
from monthly leases. Null prices fail active budgets and sort last.

Sort values: recommended (default, ascending ID; no ranking claim), name
(case-insensitive title), price-low and price-high. Every order ends with ID
as a deterministic tie-breaker. All filters apply within publiclyVisible before
pagination, including filtered totals. Pagination remains page 1?10000,
per_page 1?50 (default 12). Generated page links retain validated search criteria.
Public resource allowlists, address/image approvals, inquiry eligibility,
ownership/participant policies, health checks and registration remain unchanged.
Frontend real-property inquiry release remains disabled.

## Inquiry frontend release-readiness audit ? E3

The Vue creation path now uses authoritative API IDs directly with an explicit
default-disabled build flag; no mock mapping remains. Isolated Edge QA verified
a real persisted inquiry and initial active grant, cookie/CSRF authentication,
role restrictions, closed listing availability, session expiry between review and
submit with retained draft/no false success, reauthentication, and same-key
recovery after a forwarding proxy discarded a committed response. The retry
returned the original ID and replay header without another record or grant.

Backend application behavior and authorization were unchanged. The existing
read-only health checks and isolated PostgreSQL port 5434 regression suite remain
intact; registration and Phase F inquiry management endpoints remain unavailable.
QA used a separate test schema, session cookie and localhost ports.

Production inquiry activation is still pending. The local .env.example settings
are not a production deployment configuration. Verify HTTPS frontend/API origins,
exact credentialed CORS/stateful domains, SESSION_SECURE_COOKIE=true, HTTP-only
cookies, CSRF, authorized accounts and persistence/idempotency in the actual
deployment before approving the frontend release flag. Local tests do not
establish a remote environment's TLS/domain/cookie configuration.

## Inquiry management read APIs ? F1 (historical checkpoint)

These authenticated read-only endpoints follow Approved Contract v1:

| Endpoint | Audience and scope |
| --- | --- |
| GET /api/v1/inquiries | Business User, own sender records only |
| GET /api/v1/inquiries/{id} | Sender with Business User role, or provider with a currently active grant |
| GET /api/v1/provider/inquiries | Property Provider, active grants only |

Both lists accept only query parameters page >= 1 (default 1), per_page 1?100
(default 20), and optional status=pending or status=closed. Unsupported identity,
include and sort parameters return 422. GET bodies never override scope or query
criteria. Unauthorized roles receive 403 before list validation. Guests receive
JSON 401 even without an Accept header.

Participant authorization and status filters apply before counting and selecting
pages. Order is created_at DESC, id DESC. Responses have exactly data plus meta
(current_page, per_page, total, last_page); empty totals use last_page=1.
Distant out-of-range pages return an empty page without overflowing SQL OFFSET
arithmetic. Page values must fit PHP's validated integer range.

Detail responses use InquiryPolicy for the specific participant and return exactly
data: Inquiry. Missing, malformed, unsafe and out-of-scope IDs return the same
404 INQUIRY_NOT_FOUND. Administrators have no implicit oversight bypass.
Grant revocation/future grant dates and stored role changes are checked on each
request; cached relations cannot confer access.

The existing InquiryResource explicitly allowlists the contract's inquiry fields.
No grant audit metadata, user records, property relations or retry snapshots are
serialized. All responses and normalized errors use Cache-Control: no-store.
Debug exceptions never expose contact snapshots, stack traces or database details.

Historical provider_id remains immutable and is not an access credential.
Ownership transfer or unpublishing does not remove the sender's authorized
history or silently grant a new owner access. Reads never change status,
timestamps, grants or idempotency records. Public property endpoints still expose
no inquiry contact snapshots.

No migration, public registration, provider status update or Vue change is included.
The PHP 8.5 helper, PostgreSQL port 5434, isolated test database and read-only
health tests remain unchanged. Production activation is a separate release gate.

F1 verification: 42 new read-API tests pass; the full backend suite passes
311 tests / 1,343 assertions on the isolated PostgreSQL test database. Coverage
includes sender/provider scoping before counts and pagination, stable ordering,
status filters, defaults/bounds/out-of-range pages, exact response allowlists,
safe 401/403/404/422/405/500 responses, stored role changes, future/revoked grants,
ownership transfer/unpublishing, no inquiry/grant/retry mutations, and public
property contact privacy. Existing health and creation/concurrency tests pass.
Pint and route inspection passed. The unchanged frontend passes 59 tests,
ESLint, Prettier and production build. No Vue source, release flag, schema or
registration behavior was changed in F1.

## Provider inquiry status updates ? F2

PATCH /api/v1/provider/inquiries/{id}/status accepts only the JSON object
`{"status":"closed"}` with a Sanctum provider session and CSRF protection.
Query parameters, identity overrides, client audit fields and unknown fields are
rejected. Approved v1 supports only pending and closed: pending -> closed is
permitted; closed -> closed is an authorized no-op with unchanged timestamps.
responded, reopening and generic editing are not supported.

C2/v1 grants have no separate read-only capability: a currently active provider
grant plus the Property Provider role permits read/list/close. No new grant type
or permission flag is introduced. Historical recipient, current property ownership
and role alone cannot authorize an update. Business Users and Administrators have
no status-update permission. Missing/out-of-scope inquiries return the same safe
404; unauthenticated users receive 401 and wrong roles 403.

InquiryStatusService locks the stored actor (shared), inquiry (for update), then
the actor's active grant (for update), rechecks InquiryPolicy, and keeps these
locks through commit. A role change/revocation committed before authorization is
observed before any status write. Revocation of an in-flight close's grant waits
for its transaction. Concurrent closes serialize to one transition and one no-op.
Future trusted revocation workflows must use row-level writes/locking on the
same grant; they must never bypass immutable grant history.

The additive inquiry_status_changes table records inquiry_id, previous_status,
new_status, actor_id and changed_at. PostgreSQL allows only pending -> closed
audit entries, at most one per inquiry under v1's non-reopening rules, and rejects
audit updates/deletes. Foreign keys restrict deletion of referenced history.
The service derives the actor, updates status/timestamp and saves the audit
atomically. Failed audit writes roll back the status/timestamp. No-op requests
never insert an audit or invent one for previously closed legacy records.
No historical facts are backfilled.

Responses remain exactly data: Inquiry through the existing resource allowlist;
audit rows/metadata are internal. Resource validation happens before transaction
commit, and success is returned only after commit. Error normalization hides
internal exceptions and uses no-store responses. Public property resources and
inquiry creation/replay behavior remain unchanged.

Apply the additive migration with the PHP 8.5 helper before using F2:
`powershell -NoProfile -ExecutionPolicy Bypass -File .\php.ps1 artisan migrate`.
Do not roll back or delete production audit history as a routine deployment action.
Tests use the isolated PostgreSQL database and disposable concurrency schemas on
port 5434; the read-only health tests are unchanged. Vue/F3 integration,
registration and production submission activation remain outside this milestone.

F2 verification: all 347 backend tests pass with 1,569 assertions, including
36 new status/concurrency tests. The focused status/read suites pass 74 tests,
and all seven real-process concurrency tests pass in disposable PostgreSQL
schemas. They cover both close/revocation orderings, simultaneous closes and
committed role changes, as well as the existing creation/ownership races.
The unchanged read-only health tests and earlier 311-test regression coverage pass.

Pint and route inspection pass. The unchanged frontend passes all 59 tests,
lint, formatting and production build. A serial frontend rerun passed without
the transient Vite dependency-cache rename warning seen in the parallel run.
No Vue source, registration or submission release configuration was changed.
Only the isolated test database and confirmed local development database received
the additive audit migration; no production migration or activation was performed.

## G1.1 server-controlled inquiry creation gate

Creation is now default-off through `config/features.php` and
`INQUIRY_SUBMISSION_ENABLED=false`. The approved contract extension returns
503 / INQUIRY_SUBMISSION_DISABLED to authenticated Business Users while disabled,
including existing-key retries. Guests retain 401, other roles 403. Safe errors
are no-store and carry no replay/Location headers. No creation, grant, retry-result
or submission audit write occurs. Both the Form Request and creation service
check the configuration flag; only explicit true enables creation. Tests opt in
explicitly where they exercise the enabled D1 path and real concurrency workers.
History, detail, provider inbox, status closing, registration and health behavior
are unchanged. Vue's flag is independent and cannot enable Laravel.

Activation is a separate approved release operation: provision the exact backend
flag, rebuild Laravel configuration cache, restart every application instance or
long-lived worker as required, and verify the deployed flag plus direct API and
browser behavior. Enable the Vue build flag only through its separate approved
artifact. Never print cached configuration or secrets into evidence. Roll back
creation availability by setting the backend flag false, rebuilding config and
restarting all instances; publish a Vue artifact with its flag false as well.
Requests already executing or committed are not retroactively cancelled; drain
in-flight traffic when an operational cutoff is needed. Do not delete retry
records or history, and do not roll back database audit migrations to disable
submission. This milestone applies no production environment changes or deployment.
All 15 deployed acceptance checks remain pending.

G1.1 verification: 360 backend tests / 1,630 assertions passed, including 13
release-gate cases / 61 assertions. They cover direct HTTP rejection without
writes, auth/role precedence, service bypass prevention, independent Vue/backend
flags, enabled creation/replay/conflict behavior, blocked existing-key replay,
continued reads/closing and production-style config-cache rebuild behavior.
Pint passed. The normal backend configuration reports the gate false; no local
.env secret or production environment was changed. The shared contract/OpenAPI
update also passes the 75-test frontend suite, lint, formatting and build without
any Vue application behavior changes. Temporary test config-cache files were
removed. PostgreSQL port 5434, PHP helper, health tests and registration remain
unchanged. No HTTPS deployment has been performed.

## R1.2A registration foundation (no onboarding endpoints)

The additive registration migration adds immutable onboarding origin, canonical
email invariants, provider applications, append-only onboarding audit, and an
ID-only notification outbox. It does not overwrite email_verified_at, property
owners, inquiry contact snapshots, grants or status audits. Canonical email
collisions/unsupported legacy values abort rather than merge accounts. Existing
trusted accounts are classified provisioned; new model/database defaults are
unverified self_business, never a publicly selectable Administrator/Provider role.

Run the read-only PHP 8.5 command `artisan registration:preflight` before rollout.
It reports counts only, refuses identity collisions/invalid values and an
unverified last Administrator, and flags unverified legacy access restrictions.
Review that impact and secure recovery before applying changes. Sign-in, own
profile and logout remain available for restricted accounts; business/provider
areas and participant/property scopes now require fresh stored verification and
onboarding eligibility in addition to existing ownership/grants. Verified pending
and rejected self_provider applicants cannot act as Business Users. Approved
Providers still need actual active inquiry grants; Administrators gain no inquiry
or property-management bypass.

`RegistrationAccountFactory` is an unsaved, server-assigned account builder behind
`features.registration_enabled`, default false through REGISTRATION_ENABLED=false.
It assigns business_user + self_business/self_provider and null verification; it
cannot accept client role/origin/approval fields. It is not a public registration
service: R1.2B still needs the pinned full validation/confirmation/common-password
specification, duplicate-safe transactional persistence and durable notification
intents. It neither saves an account nor sends a notification/Registered event.
Do not blindly add Laravel's default Registered listener and send mail before
commit or in parallel with the future outbox workflow.

The pinned horizon hash driver always writes Argon2id and verifies only approved
Argon2id or legacy bcrypt. Successful legacy sign-in rehashes to Argon2id; wrong
password does not rewrite the hash. Algorithm checks stay enabled. Default Argon
cost is memory 65536 KiB, time 4, threads 1; benchmark deployment capacity before
release. Reduced 1024/1/1 costs exist only in isolated test settings and must not
be copied into production. Login email canonicalization preserves mixed-case
legacy sign-in; password bytes are not trimmed, normalized or silently truncated.
Existing bcrypt data is not bulk-rewritten by migration. Argon2id support must
exist on every target runtime. No credential or .env file was changed here.

No HTTP route was added: registration, application submission, email verification,
resend, onboarding summaries, Administrator confirmation/decisions and outbox
workers remain R1.2B/C. Fresh Administrator confirmation is still required before
future decisions; foundation review eligibility alone is not that confirmation.
No Vue source changed. Registration and inquiry creation remain independently off.

Rollout: take a protected backup, freeze conflicting account writes, run the
preflight, review canonicalization/verification impact, and apply the additive
migration with the PHP helper only to an explicitly approved database. Deploy
code and schema together; protected queries assume the new schema. Do not use a
role-only fallback against an unmigrated database. Rebuild configuration caches
and restart relevant long-lived instances, then confirm both flags false.
Do not use down migrations, delete audit history, fabricate verification or change
immutable origins as a routine rollback. Restore/forward repair is an explicit
operational decision; keep backup material protected.

Current application status: after explicit user approval on 2026-10-21, only
2026_10_21_001110_add_registration_foundation.php was applied to local
horizon_realty at 127.0.0.1:5434. The isolated test database is also migrated.
Before the local change, a custom-format pg_dump backup was created with
user/SYSTEM-only ACLs, encrypted with Windows DPAPI CurrentUser, and verified by
archive listing and encryption round-trip hash comparison. The plaintext dump was
removed after verification. This verifies backup integrity, not restoration.

Protected backup:
.local/backups/r12a-20261021-002924-86e44f0f/horizon_realty.dump.dpapi
The accompanying manifest and before/after snapshots remain in the same private
directory. DPAPI decryption requires the originating Windows user context.
Existing records and all non-email values, including verification timestamps,
ownership and inquiry history, match their pre-migration snapshots. Approved
email canonicalization completed and post-migration preflight passes.
Registration and inquiry backend flags remain false. No deployment, public
registration or submission activation occurred; all 15 deployed checks remain
pending.

R1.2A final verification: 406 backend tests / 1,833 assertions passed, including
46 new foundation/migration cases. Coverage includes canonical collisions and
legacy-history preservation, read-only preflight, server-assigned unsaved accounts,
Argon2id/legacy bcrypt compatibility, unverified sign-in/profile/logout, restricted
applicant scopes/services, immutable audit/application data, outbox invariants,
configuration-cache independence and all existing inquiry concurrency tests.
Pint passed; the unchanged Vue application passes 75 tests, lint, formatting and
build. Read-only route inspection confirms no registration/application endpoints.
Both backend flags report false; frontend submission remains default-disabled.
Normal local target horizon_realty at 127.0.0.1:5434 now has the approved
registration-foundation migration. No live .env changes were made. Production
deployment, restore acceptance and activation remain unverified and unapproved.

## Registration and verification API ? R1.2B

Backend implementation adds guest registration, authenticated verification/resend
and own onboarding readiness, following the versioned contracts in contracts/.
Registration remains gated off in the live environment; inquiry submission is
independently off. No Vue changes, provider application/decision routes,
production activation or normal-development account creation occurred.

POST /register returns the exact generic 202 for valid new/duplicate identities
without automatic sign-in. Account and mail intent commit together; duplicate
requests never alter a password, origin, role or verification state. Registered
is not dispatched, avoiding synchronous framework mail. Verification requires the
matching web session and configured-origin absolute signed URL, expires after
60 minutes, and atomically writes one timestamp/audit plus an after-commit
Verified event. Verified provider applicants still have no business/provider
privileges.

After approving a real mail transport/sender, invoke the PHP 8.5 helper with
artisan onboarding:deliver --limit=20 for a bounded batch (1?100). No scheduler or
delivery process is started automatically. Only SMTP is supported in this phase;
log/array/composite transports are refused. SMTP timeout is 10 seconds.
The worker polls committed intents and locks user before outbox. Failure stores
only a safe error code, with five bounded attempts and exponential cooldown.
An exhausted intent stays restricted; investigate the cause before an
authenticated resend creates a new intent. Pending resends coalesce; verified
accounts receive no mail. Transport acceptance is not proof of inbox delivery.
A crash after sending and before commit can repeat an email; signed verification
replay remains safe. No signature/body/password is stored in outbox or error
logs. Exclude verification URL queries from reverse-proxy/access logs before
deployment. Do not use live business data for QA.

Application submission and Administrator review/decisions remain future R1.2C
work. Both release gates stay false. Restore drill and all 15 deployed checks
remain pending.


R1.2B verification: full backend suite passed 459 tests / 2,155 assertions
(53 new API/concurrency cases). After final origin-validation hardening, all
50 endpoint tests were rerun: 295 assertions passed. Real-process PostgreSQL tests
force duplicate registration, verification and resend requests to overlap at
database locks. Pint passes. Frontend regression remains 75 tests with lint,
formatting and build passing; Vue source is unchanged. Contract response schemas
accept documented examples and reject extra fields; cross-repository artifact
hashes agree. Read-only configuration inspection confirms registration_enabled
and inquiry_submission_enabled are false. No live accounts, mail delivery or
worker activation were performed. Real SMTP verification, R1.2C workflows,
restore drill and deployment acceptance remain pending.


## Provider applications and Administrator decisions ? R1.2C

Backend-only implementation now provides:

- POST /api/v1/provider-applications (eligible verified self_provider, registration gate).
- GET /api/v1/admin/provider-applications (bounded, scoped review).
- GET /api/v1/admin/provider-applications/{id} (explicit reviewer contact allowlist).
- POST /api/v1/admin/password-confirmation (current eligible reviewer, regenerated web session).
- PATCH /api/v1/admin/provider-applications/{id}/decision (fresh confirmation, atomic final decision).

The existing foundation schema is reused without a new migration. Application
retries are normalized, unique per applicant and conflict on changed content.
Matching approved self_provider retries can recover their original application
without creating another or changing privileges; unverified self_provider gets
EMAIL_VERIFICATION_REQUIRED. Both new submissions and matching retries remain
blocked by the default-off registration gate.

Review requires verified provisioned Administrator eligibility plus the explicit
review ability. Listing is scoped before pagination, ordered submitted_at DESC,
id DESC, with default 20 and maximum 100 records. Detail alone adds applicant
id/name/email; no audit, decision actor, credential or notification metadata is
serialized. Missing/out-of-scope IDs share APPLICATION_NOT_FOUND.

Password confirmation is bound to current actor, credential fingerprint and
server session, lasts 600 seconds, and never returns a token. Logout clears it.
Decision transactions lock involved users by ascending ID, then application.
They recheck eligibility and confirmation after locking and atomically persist
the role change (approval only), immutable final decision, one audit and one
durable notification intent. Repeated matching decisions preserve original
reviewer/time/reason; opposing decisions or changed rejection reasons conflict.
No-op requests still require authorization and fresh confirmation.

Approval conveys no property ownership or inquiry grant. Rejected/pending users
remain restricted. The existing bounded SMTP-only onboarding:deliver worker now
also handles committed provider-decision intents. No message is sent in the HTTP
decision request; retries retain the same intent. Real sender/SMTP verification
is still pending, and no worker/scheduler was activated.

Both registration and inquiry submission gates remain false. Vue, .env and
.env.testing are unchanged. No live application, approval, role update, local
migration or production deployment was performed. Approved-image delivery,
Nginx validation, the restore drill and all 15 deployed checks remain pending.


R1.2C final local verification (2026-10-23, Asia/Manila): 504 backend tests /
2,491 assertions pass, including 45 new cases. Real-process PostgreSQL HTTP tests
cover duplicate submissions, matching/opposing decisions and Administrator role
revocation while a decision waits. Endpoint cases cover validation, finality,
fresh session-bound confirmation, role/verification/password changes, no
self-review, atomic rollback, throttles, scoped pagination, resource allowlists
and outbox retries. Existing ownership, inquiry and read-only health regressions
remain passing. The unchanged Vue application passes 75 tests, lint, formatting
and build. Pint passes. OpenAPI examples and repository artifact hashes agree.
Both release gates remain false; no live data changes or migration occurred.
SMTP verification and all deployment/restore prerequisites remain pending.


## R1.3A-B frontend integration support

The existing register and verification-notification routes are now included in
CORS paths, fixing cross-origin SPA preflights. Allowed origins/credentials,
release gates, API contracts, PHP helper, PostgreSQL port and health tests remain
unchanged. A preflight regression covers both routes and an untrusted origin.
The complete backend suite passes 505 tests / 2,508 assertions, with Pint passing.
Frontend validation passes 86 tests, lint, formatting and production build.
Controlled browser QA used a separate loopback server, isolated cookie/key and
disposable test schema; its one account/intent/verification audit were confirmed
before the temporary environment was removed. No normal database migration,
mail delivery, deployment or release activation occurred. Both normal gates
remain false. Real SMTP and all deployment/restore prerequisites remain pending.
# BackendThesis
