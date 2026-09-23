# Engineering Decisions

Twelve decisions made while building this, in the format the brief asks for. Each links to the code it produced.

## 1. Authentication — Sanctum SPA cookie auth, not bearer tokens

**Problem.** The React SPA needs to authenticate against the Laravel API. Sanctum supports two modes: issued bearer tokens, or first-party SPA cookie auth.

**Options considered.**
- Bearer tokens stored in memory or `localStorage`.
- Sanctum's SPA cookie mode: an HttpOnly, CSRF-protected session cookie.

**Choice.** Cookie mode. `bootstrap/app.php` calls `$middleware->statefulApi()`; `config/sanctum.php` lists `localhost:5173` as a stateful domain; the frontend calls `GET /sanctum/csrf-cookie` before every mutating request and echoes the `XSRF-TOKEN` cookie back as an `X-XSRF-TOKEN` header (`frontend/src/lib/csrf.ts`).

**Why.** A bearer token has to live somewhere JavaScript can read it, which is exactly what makes it readable by an XSS payload too. An HttpOnly cookie is invisible to JS entirely. Sessions live in the `database` driver, not Redis, deliberately (decision 8) — login must not depend on Redis being up.

**Trade-offs.** Bearer tokens are simpler for a fully decoupled or mobile client and don't need CSRF handling. This is a first-party SPA serving one browser-based client, so the security win outweighs that simplicity. It does mean the frontend must special-case CSRF-cookie priming, and dev requires the API and SPA to be treated as the same site (matching ports/domains in `sanctum.stateful`).

## 2. Interaction idempotency — database constraint is correctness, Redis is contention control

**Problem.** The brief requires that a retried `POST .../interactions` cannot create a duplicate row, and asks for the approach to be documented.

**Options considered.**
- Rely on the client not retrying (no protection).
- A single global unique constraint on the idempotency key.
- A unique constraint scoped to `(created_by, idempotency_key)`, backed by a distributed lock.

**Choice.** The client sends an `Idempotency-Key` header (a UUID). `interactions` has `UNIQUE (created_by, idempotency_key)`. `InteractionService::create()` inserts inside a transaction; on a unique-constraint violation it loads the existing row and compares a SHA-256 fingerprint of `(contact_id, type, timestamp, note)`: identical payload → replay the original resource with `Idempotency-Replayed: true` and 200; different payload reusing the same key → `422 idempotency_key_reused`. A Redis lock (`IdempotencyLockService`, `Cache::lock()`) wraps the attempt so two near-simultaneous retries don't both race the database — but it fails open on any Redis exception (logs and proceeds), because the database constraint alone already guarantees no duplicate is ever persisted.

**Why scoped, not global.** A globally unique key would let one user replay or read back another user's interaction just by resubmitting their key — a real (if narrow) information leak. Scoping to the creator closes that off while keeping the retry-safety property the brief actually asks for.

**Trade-offs.** The fingerprint means a *literal* retry (same request) is safe, but a client that generates a new UUID per logical action and resubmits it with a materially different payload gets a 422, not a merge — the interface makes this loud by design rather than silently overwriting. The Redis lock is pure defense-in-depth: it costs a small amount of complexity for a benefit that only shows up under real concurrent duplicate submissions, which is rare but not impossible with a flaky mobile network retry.

## 3. Concurrent editing — optimistic locking via an atomic conditional UPDATE

**Problem.** Two agents (or an agent and a team lead) can open the same contact at the same time; a naive `PATCH` would let the second save silently clobber the first.

**Options considered.**
- Pessimistic locking (explicit lock/unlock on open/close).
- Last-write-wins (do nothing).
- Optimistic locking with a `version` column, checked via read-compare-write in PHP.
- Optimistic locking with a `version` column, checked via a single atomic `UPDATE ... WHERE version = ?`.

**Choice.** The last one. `contacts.version` starts at 1; every response includes it; `PATCH`/`assign` require the client to send back the `version` it last read. `ContactService::applyVersionedUpdate()` runs `UPDATE contacts SET …, version = version + 1 WHERE id = ? AND version = ?` as one statement. Zero affected rows means either the version was stale or the contact was archived concurrently; a re-read (`withTrashed()`) tells them apart — archived/missing → 404, stale version → `409 version_conflict` with the current server copy attached, so the frontend can offer "reload" without a second round trip.

**Why not read-compare-write.** Comparing the version in PHP after a separate `SELECT` leaves a window between the check and the write where two concurrent requests can both pass the check and both write — the exact race this feature exists to prevent. Folding the check into the `WHERE` clause of the `UPDATE` makes MySQL's own row-level locking do the arbitration atomically; whichever request's `UPDATE` commits first is the one that "wins," and the second one provably sees zero rows affected.

**Why not pessimistic locking.** It needs an explicit unlock, which needs a heartbeat/TTL story for an agent who opens a contact and walks away, and it actively blocks a team lead who needs to reassign a contact an agent has open. Optimistic locking degrades better for a low-contention, mostly-read workload like this one.

**Scope boundary.** Archiving/restoring a contact is a single boolean-ish field, team-lead-only, and doesn't carry the same "two people editing the same fields" risk the brief describes — it isn't version-checked, and that's deliberate rather than an oversight. Adding an interaction also must *not* bump `version`/`updated_at` (see decision 4) — otherwise every new interaction would spuriously invalidate whichever agent is mid-edit on that contact's other fields.

## 4. Sort by "most recent interaction" — denormalized `contacts.last_activity_at`

**Problem.** The contacts list must support sorting by most recent interaction. `interactions` is expected to hold 10M+ rows; computing `MAX(interactions.timestamp)` per contact via a join or correlated subquery on every list request doesn't scale to that, and is close to the exact scenario in the brief's Operational Scenario (see ARCHITECTURE.md).

**Options considered.**
- Join/subquery against `interactions` at query time.
- A denormalized `last_activity_at` column on `contacts`, maintained incrementally.
- A materialized view / scheduled recompute.

**Choice.** A denormalized `NOT NULL` column, defaulting to the contact's own `created_at` at creation. `InteractionService::create()` updates it inside the same transaction as the interaction insert, via a raw `UPDATE contacts SET last_activity_at = GREATEST(last_activity_at, ?) WHERE id = ?` — not through the Eloquent model, so it can't accidentally touch `version`/`updated_at` (decision 3). `GREATEST()` makes a backdated interaction (an agent logging a call after the fact) a correct no-op instead of moving the timestamp backwards. A `contacts:recompute-activity` artisan command exists as a documented recovery path if the column ever drifts (e.g. after a bulk import that bypassed the service layer) — not something run routinely.

**Why NOT NULL, not nullable.** `cursorPaginate()`'s keyset comparison doesn't handle NULLs correctly; a nullable "no activity yet" column would silently break pagination for exactly the contacts without any interactions.

**Trade-offs.** Every interaction insert now costs one extra `UPDATE`, and the column can drift if something writes to `interactions` outside `InteractionService` (the seeder does this deliberately and calls the recompute command afterward). That cost buys an O(1), indexable sort column instead of an aggregate over the largest table in the schema.

## 5. Pagination — cursor (keyset), not offset

**Problem.** The brief explicitly flags 250k+ contacts and asks the design to account for it.

**Options considered.** Offset (`LIMIT/OFFSET` with page numbers) vs. cursor/keyset (`WHERE (sort_col, id) < (?, ?) ORDER BY sort_col, id LIMIT ?`).

**Choice.** Cursor, via Laravel's built-in `cursorPaginate()`. Every list query orders by `<sort_col>, id` with `id` as an explicit tiebreaker — without it, two rows with an identical `last_activity_at` (not unusual at scale) can make keyset pagination skip or repeat a row.

**Why.** Deep `OFFSET` pages degrade linearly with depth because the database still has to walk and discard every skipped row; a keyset seek with the right composite index stays roughly constant regardless of how far into the list you are. A `COUNT(*)` for a "page 1 of N" UI is also expensive at this scale and was dropped along with offset pagination.

**Trade-offs.** No "jump to page 42," and no total count in the response. Acceptable for a search/filter-first agent console — the tests confirm the keyset traversal itself is stable (no duplicate or skipped rows across pages; `tests/Feature/Contacts/FilteringTest.php`) rather than testing page-number semantics that don't exist here.

## 6. Search — MySQL FULLTEXT, boolean mode, word-prefix matching

**Problem.** `LIKE '%term%'` can't use a B-tree index (the leading wildcard defeats it), and at 250k+ rows a full table scan per search is too slow.

**Options considered.** `LIKE` with a leading wildcard, a dedicated search engine (Meilisearch/Elasticsearch via Laravel Scout), MySQL's built-in InnoDB FULLTEXT index.

**Choice.** A single `FULLTEXT(name, email, phone, company)` index, queried in boolean mode with each search word turned into a required prefix match (`+term*`). `docker/mysql/my.cnf` sets `innodb_ft_min_token_size=2` and disables the stopword list — both have to be set *before* the index is created, since they affect how the index is built.

**Why not Scout/a real search engine.** Would add real operational infrastructure (a service to run, index to keep in sync, another thing that can be down) for a feature whose relevance-ranking and typo-tolerance gains aren't worth that cost at this project's scope. FULLTEXT is "good enough" search with zero added infrastructure.

**Documented limitation.** This is prefix matching, not substring matching — `+19995550123*` matches a token that *starts with* those digits, not one that merely *contains* them. A phone number stored as `+19995550123` tokenizes as one word starting with `1`, so searching `9995550123` (a true substring, not a prefix) won't match it; searching the full digit string `19995550123` will. This is tested explicitly (`tests/Feature/Search/ContactSearchTest.php`) as documented, expected behavior, not a bug. Production-scale search with relevance ranking and typo tolerance would move to Scout + a real engine — not implemented here, see ARCHITECTURE.md.

**A testing footnote worth recording.** InnoDB FULLTEXT search on a table that already holds committed data does not reliably see a row inserted earlier in the *same, still-open* transaction (verified empirically against this project's MySQL container — a freshly created, still-empty FULLTEXT table does show same-transaction visibility; the real `contacts` table, already exercised by other tests, does not). Since `RefreshDatabase` wraps every test in exactly that kind of transaction, the search tests use `DatabaseTruncation` instead, isolated to their own file (`tests/Feature/Search/`) so the rest of the suite keeps the faster transaction-rollback isolation.

## 7. Roles as a plain enum column, not a permissions package

**Problem.** Two fixed roles (`agent`, `team_lead`) need backend-enforced authorization.

**Options considered.** Spatie `laravel-permission` (or similar), a hand-rolled `role` column checked via Policies.

**Choice.** A `role` enum column on `users`, cast to a PHP backed enum (`App\Enums\UserRole`), enforced through Laravel Policies (`ContactPolicy`, `InteractionPolicy`, `UserPolicy`) plus one query-scoping method used everywhere a contacts list is built (`Contact::scopeVisibleTo()`).

**Why.** A full RBAC package is built for a world with configurable, growing role sets and per-permission grants. This app has exactly two roles with fixed capabilities defined once, in code. Adding a dependency (and its own migration, cache layer, and mental model) for that is weight the brief's own "don't add abstractions beyond what's needed" spirit argues against. Centralizing the agent-scoping in one scope method means no endpoint can forget to apply it — the failure mode of a hand-rolled approach without that discipline.

**Trade-offs.** If a third role or per-resource custom permissions show up later, this would need to be revisited — the enum approach doesn't scale to that. For two roles, it's less code, and testing authorization is table-driven Policy tests, not permission-config tests.

## 8. Redis — two justified uses, explicit restraint on a third

**Problem.** The brief requires at least one meaningful Redis use, explicitly warns against adding it just to satisfy the requirement, and asks for what it's used for, why, invalidation/expiry, and Redis-down behavior to be documented.

**Uses adopted:**

1. **Rate limiting** — `login` (5/min per email+IP), the general `api` limiter (60/min per user/IP, applied to every route via `throttleApi()`), and `interactions` (30/min per user) — `app/Providers/AppServiceProvider.php`. *Why*: brute-force login protection and abuse throttling are the textbook case for a fast, TTL-based counter — this is what rate limiters are for. *Invalidation*: none needed; each window expires by TTL automatically (Laravel's cache-backed `RateLimiter`). *Redis down*: `FailOpenThrottle` (`app/Http/Middleware/FailOpenThrottle.php`) wraps Laravel's real throttle middleware and only intervenes when the underlying check itself throws a Redis-connectivity error — in that case the request is let through rather than surfaced as a 500. A Redis outage should degrade rate limiting, not take down login.
2. **Idempotency lock** (decision 2) — a short-lived (10s) `Cache::lock()` per `(user, idempotency key)`, released as soon as the request finishes. *Why*: reduces wasted database contention between near-simultaneous retries. *Invalidation*: the lock's own TTL; nothing is stored beyond the lock's lifetime. *Redis down*: fails open (`IdempotencyLockService`) — the database unique constraint is the actual correctness guarantee, so a missing lock only removes an optimization, not a safety property.

**Considered and explicitly rejected: caching the contacts list.** With the indexes in place (decision 5, the index table in ARCHITECTURE.md), the list query is fast without a cache layer, and the cache key space is large and awkward — every combination of filters, sort, cursor, and the requesting user's own role-based scope would need its own key, and every contact/interaction write would need to know which of those keys to invalidate. That's real invalidation complexity purchased for a query that isn't slow. Not caching it is the considered choice, not an oversight — "do not add Redis simply to satisfy the requirement" cuts both ways: it also means not caching things that don't need it just to have a second use case.

**Sessions deliberately not in Redis** — see decision 1: login must survive a Redis outage, so sessions use the `database` driver.

## 9. API conventions and the error envelope

**Problem.** The brief asks for a consistent response structure and centralized error handling, without mandating a specific shape.

**Choice.** `/api/v1` prefix; Form Requests own all validation; API Resources shape every response (`{data}` for a single resource, `{data, meta, links}` for a cursor page — the last two are produced automatically by Laravel when a Resource wraps a `CursorPaginator`). Every failure mode, without exception, resolves to one envelope: `{ "message": string, "code": "snake_case_code", "errors"?: {...}, ...extra }`, produced centrally in `bootstrap/app.php`'s `withExceptions()` — `ApiException` subclasses, `ValidationException` (422), `AuthenticationException` (401), the Symfony `AccessDeniedHttpException` Laravel converts every Policy denial into (403 — note: registering a handler for Laravel's own `AuthorizationException` doesn't work, because the framework's exception handler converts it to this Symfony type *before* custom render callbacks run), `ModelNotFoundException`/`NotFoundHttpException` (404), and `TooManyRequestsHttpException` (429, with `Retry-After` preserved) are all mapped explicitly; anything else reaching an `api/*` route falls through to a generic `HttpExceptionInterface` handler as a last resort, so a JSON client can never receive Laravel's HTML error page.

**Why one envelope for everything.** The frontend's `toApiError()` (`frontend/src/api/baseApi.ts`) has exactly one shape to parse, regardless of which failure occurred. A `code` field (not just an HTTP status) lets the frontend branch on specific, stable failure reasons — `version_conflict`, `idempotency_key_reused`, `forbidden` — without string-matching a human-readable message that might change wording later.

## 10. Frontend state — Redux Toolkit + RTK Query; filters live in the URL, not Redux

**Problem.** The brief asks for deliberate state management and separation of concerns, not just "uses Redux."

**Choice.** All server state (contacts, interactions, agents, the current user) goes through RTK Query — it owns caching, loading/error flags, and tag-based invalidation, so a mutation that changes a contact automatically invalidates the right list/detail queries without manual cache-busting code. The *only* plain Redux slice is `auth` (the current user + whether the initial session check has resolved) — genuinely client-only state with no server representation of its own. Contact list filters (search, status, sort, cursor, …) live in the URL query string via `useSearchParams`, not in Redux.

**Why filters aren't in Redux.** A filter state that's also mirrored to the URL creates two sources of truth that have to be kept in sync — restoring from URL on load, writing to URL on change, and deciding which one wins if they disagree. Making the URL the *only* source of truth removes that whole class of bug, and comes with real product value for free: the filtered view is shareable and survives a page refresh or the back button, matching how a URL-addressable resource is supposed to behave.

**Trade-offs.** Anything that isn't naturally URL-shaped (a multi-step wizard's in-progress state, say) would need a different answer — not a problem this app has, but worth naming as the boundary of this pattern.

## 11. Database indexes

**Problem.** The brief explicitly asks to be able to explain why each non-trivial index exists.

**Choice and reasoning** (full table in ARCHITECTURE.md): every hot query on `contacts` has the shape `WHERE <equality filter(s)> ORDER BY <sort column>, id`, so each composite index puts the equality column(s) first and the sort column second — that lets MySQL satisfy the filter *and* the ordering from one index scan, with no separate filesort step. `contacts(assigned_agent_id, last_activity_at)` and `(assigned_agent_id, created_at)` serve an agent's own default list under either sort; `(status, last_activity_at)` serves a team lead's status filter; the FULLTEXT index serves search (decision 6); `interactions(contact_id, timestamp)` serves the per-contact timeline (ascending is sufficient — InnoDB scans a B-tree backwards for `DESC` just as efficiently as forwards, so no separate descending index is needed); the unique index on `interactions(created_by, idempotency_key)` is the actual correctness mechanism for decision 2, not just a performance index.

**Deliberately not indexed:** `contacts.deleted_at` alone (archived rows are a small minority — low selectivity, no query filters on it in isolation) and `contacts.email` (no requirement to look up a contact by exact email; that's what search is for).

## 12. Interaction idempotency error format & other smaller calls

Bundled here rather than given their own full entries: **error response format** is covered by decision 9. **Redux architecture** is covered by decision 10. **Additional technologies** beyond the required stack, each chosen for a specific, narrow reason: **Pest** (Laravel's current default test framework, already present in the scaffold, more readable `it(...)` syntax than raw PHPUnit); **Larastan** (Laravel-aware static analysis catches a class of bug type hints alone don't — e.g. it correctly flagged that `$user->role === UserRole::Agent` would always evaluate false under its own type inference, which traced back to a real gap: the model's enum casts weren't declared in a way Larastan could see, fixed with explicit `@property` docblocks — see AI_USAGE.md); **Tailwind v4** (the brief says UI polish isn't being evaluated — utility classes are faster to write correctly than hand-rolled CSS for a UI this size, no separate design-system decision needed); **RTK Query** (decision 10); **MSW** (mocks at the network layer rather than mocking RTK Query hooks, so the frontend tests exercise the real request/response code path, including header construction — this is what caught the idempotency-key-reuse behavior actually working end-to-end, not just in isolation); **oxlint**, not ESLint (ships as the default with the current Vite React-TS scaffold, is meaningfully faster, and needed zero additional configuration to get a working, sensible ruleset — adding ESLint + typescript-eslint + the React hooks plugin on top would have been unjustified extra setup for the same outcome).
