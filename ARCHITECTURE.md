# Architecture Notes

What I'd review or change before this deploys at the brief's six-month production scenario (250,000 contacts, 10 million interactions, 100 concurrently active agents, ~30 req/s peak), followed by a walkthrough of the specific operational incident the brief describes. This is reasoning, not a build list — see [DECISIONS.md](DECISIONS.md) for what's actually implemented today and why, and README's "Known limitations" for what's explicitly out of scope.

## Database indexes

Every index that exists today and why (also in DECISIONS.md #11, repeated here because the brief asks for this reasoning in both places):

| Index | Table | Serves | Why this shape |
|---|---|---|---|
| `(assigned_agent_id, last_activity_at)` | contacts | An agent's own list, sorted by recent activity — the single most common query | Equality column first, sort column second → one index-ordered scan, no filesort |
| `(assigned_agent_id, created_at)` | contacts | Same, sorted by creation date | Second sort option needs its own composite |
| `(status, last_activity_at)` | contacts | Team lead filtering by status | Same shape, different equality column |
| `(last_activity_at)`, `(created_at)` | contacts | Team lead's unfiltered list, either sort | Plain keyset seek, no filter to combine with |
| `FULLTEXT(name, email, phone, company)` | contacts | Search | `LIKE '%x%'` can't use a B-tree; see DECISIONS.md #6 |
| `(contact_id, timestamp)` | interactions | The per-contact timeline, newest-first, keyset-paginated | At 10M rows this is what keeps the detail page's timeline query O(page size), not O(contact's total interaction count) |
| `UNIQUE (created_by, idempotency_key)` | interactions | Retry safety | The correctness mechanism for DECISIONS.md #2, not just performance |

**What I'd add before the 250k/10M mark specifically:** nothing new is *structurally* required — the indexes above were designed with that scale in mind from the start, not retrofitted. What I would do is verify it with real `EXPLAIN` output against a representative data volume rather than trust the reasoning alone; the seeders (`database/seeders/`) are written against Eloquent factories and could be pointed at much larger counts for that kind of test, though the seeded dataset shipped with this submission is intentionally small (README "Seed credentials") for fast local setup. Table/index bloat from `interactions` growing past 10M rows would eventually justify partitioning by month on `timestamp`, but I wouldn't add that speculatively — I'd add it when `SHOW TABLE STATUS` and query latency actually show it's needed.

## Query behaviour

The list endpoint's query shape (`WHERE <equality filters> ORDER BY <sort col>, id LIMIT n`) is deliberately built so every filter combination the frontend can produce has a matching index prefix. The one query that *doesn't* fit this pattern well is combining full-text search with a sort other than relevance — MySQL can use the FULLTEXT index for the `WHERE` or a B-tree index for the `ORDER BY`, not both in one query, so a search-plus-sort-by-recent-activity request degrades to filesorting the FULLTEXT match results. At today's scale this is invisible; at 250k rows with a broad search term, it's the first thing I'd profile.

## Pagination strategy

Already cursor-based (DECISIONS.md #5). At review time before a scale deploy, I'd specifically re-verify the keyset tiebreaker (`id`) is holding up under concurrent writes — the current test suite proves no duplicate/skipped rows across pages for a static dataset (`ContactsListPage`/`FilteringTest`), but doesn't test pagination against a list that's being concurrently written to during traversal. That's a realistic scenario at 100 concurrent agents and worth a dedicated test before launch.

## Caching

Deliberately not caching the contacts list today (DECISIONS.md #8) — the indexes make it fast enough that the invalidation complexity isn't worth it yet. At 30 req/s peak, I'd first check whether that holds by looking at actual p95 query latency (see Observability below) before reaching for a cache. If it doesn't hold, the first thing I'd cache is *not* the full filtered/sorted list (too many key combinations) but narrower, high-value pieces: the active-agent list for the assignment dropdown (changes rarely, read very often), and per-contact detail pages (already scoped to a single, stable cache key: the contact ID) with invalidation tied to the same events that already invalidate RTK Query's tags on the frontend — `Contact:{id}` on update/assign/archive/interaction-add.

## Queues

Nothing is queued today — every request does its work synchronously (`QUEUE_CONNECTION=sync`). At this scale, I'd move two things to a queue first: (1) the interaction-creation side-effects if they grow beyond the single `last_activity_at` update — e.g. if a notification, an audit-log entry, or a webhook gets added later, none of those should hold up the agent's request; (2) anything bulk — a CSV export of contacts, a bulk reassignment — which doesn't exist yet but is a predictable ask once a team lead is managing hundreds of agents' worth of contacts. Nothing here demands a queue *today*; the sync path is simpler and correct at current scope, and I'd rather add the queue when the first genuinely slow/bulk operation shows up than build the infrastructure speculatively.

## Application scaling

The app is already stateless (sessions in MySQL, not sticky in-process memory), so horizontal scaling is just running more `app` (PHP-FPM) containers behind a load balancer — nothing in the code assumes a single instance. Before that: move from PHP-FPM to a persistent-worker runtime (Laravel Octane, via Swoole or RoadRunner) if request latency at 30 req/s ever becomes bottlenecked on PHP bootstrap cost rather than actual work, since Octane keeps the framework booted between requests. I wouldn't add Octane speculatively — it changes some assumptions (no per-request memory reset, singletons persist) that need real review before adopting, not before there's a measured reason to.

## Database scaling

At 10M interactions / 250k contacts with 100 concurrent agents, a single primary is very likely still fine for writes (this is a modest write volume — each agent action is one or two rows). The first real lever is a **read replica** for the list/search/detail queries (the overwhelming majority of traffic for a support console), with writes staying on the primary — Laravel supports this natively via `config/database.php`'s read/write connection split, not currently configured since one instance is enough for this scope. I'd also review `innodb_buffer_pool_size` against actual working-set size once real data volume exists — the `contacts` table plus its indexes should comfortably fit in memory at 250k rows; `interactions` at 10M rows plus its indexes might not, which is exactly the kind of thing that turns into the Operational Scenario below if missed.

## Observability

The most significant gap today: there's no structured logging, no request tracing, no metrics dashboard. Before a production deploy I'd add, in priority order: (1) MySQL's slow query log turned on with a threshold tuned to catch anything above ~100ms, because that's the fastest way to catch exactly the kind of regression in the Operational Scenario below; (2) per-route p50/p95/p99 latency (something like Laravel Telescope in staging, or a proper APM — Datadog/New Relic-class tool — in production); (3) structured JSON logs with a request ID correlating a frontend action to its backend log lines, since "agent reports the app was slow" is useless to debug without being able to find the specific slow request; (4) a Redis and MySQL connection-health dashboard, since both are now load-bearing (rate limiting/idempotency locking, and literally everything).

## Failure handling

Already covers two real failure modes explicitly (see DECISIONS.md #8): Redis being unreachable degrades rate limiting and the idempotency lock to fail-open, rather than 500ing the whole app. Not yet covered: MySQL being unreachable is currently an unhandled 500 — at this scale I'd add a maintenance-mode-style circuit breaker that returns a clear "temporarily unavailable" response rather than Laravel's generic error page, plus alerting tied to the observability work above so a DB outage pages someone instead of being discovered from a support ticket. I'd also add automated MySQL backups with a tested restore procedure before trusting this with 250k real customer contacts — currently out of scope for a local Docker dev setup, but non-negotiable before real production data lands in it.

---

## Redis usage — the four required points

(Referenced from DECISIONS.md #8, restated here together since the brief asks for it as its own checklist.)

1. **What it's used for:** (a) rate limiting on login, general API writes, and interaction creation; (b) a short-lived distributed lock during idempotent interaction creation.
2. **Why that use case:** both are naturally TTL-based, high-frequency, low-value-per-entry operations — exactly what Redis is fast at and what would be wasteful to put through MySQL. Neither stores anything that needs to survive a restart or be queried later.
3. **When data is invalidated/expires:** rate-limit windows expire automatically via their configured TTL (Laravel's `RateLimiter`, no manual cleanup). The idempotency lock has a 10-second TTL and is explicitly released in a `finally` block as soon as the request finishes — the TTL exists purely as a safety net against a crashed request leaking a held lock.
4. **What happens if Redis becomes unavailable:** both fail open. `FailOpenThrottle` catches a Redis-connectivity failure specifically and lets the request proceed unthrottled rather than 500ing; the idempotency lock service catches the same class of failure and proceeds without the lock, relying on the database's own unique constraint as the actual correctness guarantee (DECISIONS.md #2). Verified for real, not just in tests: `docker compose stop redis`, then both `POST /auth/login` and `POST /contacts/{id}/interactions` (including a duplicate-key retry) were exercised against the live stack and behaved correctly — see AI_USAGE.md for how this was checked.

---

## Operational scenario

> Agents report that the Contacts page became very slow after the interaction table grew to several million rows. Individual contact pages still load normally.

**What I'd inspect first.** The fact that the *list* page is slow but *individual contact pages* are fine is the single most useful piece of information in the report — it immediately narrows the search away from anything that touches one contact's interactions (that path is provably fine) and toward whatever the list query does that scales with the *total* size of `interactions`, not a single contact's slice of it. First move: turn on the MySQL slow query log (if not already on) and pull `EXPLAIN` for the exact query the Contacts list endpoint is running, captured from a production-like data volume, not the dev seed.

**Metrics/logs I'd want.** Per-route p95/p99 latency isolated to the list endpoint specifically (to confirm it's the query, not something else in the request cycle like an N+1 in serialization); the slow query log entries for that endpoint, with `rows_examined` vs `rows_sent` — a huge gap between those two numbers is the signature of exactly this kind of regression; `SHOW ENGINE INNODB STATUS` or the buffer pool hit ratio, to rule out the working set no longer fitting in memory now that `interactions` is large; and a diff of the actual query being run today against what the code intends to run (an ORM change or a forgotten eager-load can silently swap a fast query for a slow one).

**Likely causes, roughly in order of probability given this codebase specifically:**
1. The `contacts:recompute-activity` command (or some other bulk operation) was run, or a bug bypassed `InteractionService`, leaving some contacts' `last_activity_at` stale or NULL in a way that broke the sort's index usage — for instance, if the column's NOT NULL invariant was violated, `cursorPaginate()`'s keyset comparison degrades badly.
2. The `assigned_agent_id, last_activity_at` (or equivalent) composite index was dropped, never applied in this environment, or MySQL's optimizer is choosing a different, worse index — `EXPLAIN` will show a filesort or a full index scan where an index range scan is expected.
3. A regression reintroduced a join or correlated subquery against `interactions` for the "sort by most recent interaction" feature — i.e., someone "fixed" a bug by going back to computing `MAX(interactions.timestamp)` live instead of trusting the denormalized column, which is precisely the mistake this design (DECISIONS.md #4) exists to avoid, and precisely the trap this scenario is testing for.
4. Less likely but worth ruling out: an N+1 query on the list endpoint's agent eager-load has regressed (the `FilteringTest` N+1 guard test exists specifically to catch this, but a change that bypasses `ContactService::list()` wouldn't be caught by it).

**How I'd verify.** Reproduce against a copy of production-scale data (or the optional scale-seeder), not the small dev seed — this class of bug is often invisible below a few hundred thousand rows and only shows up once the planner's row-count estimates cross a threshold. Run `EXPLAIN ANALYZE` on the actual list query and check `rows_examined`: if it's anywhere near the total `interactions` row count for a query that should only ever touch `contacts`, that confirms cause #3. If the `contacts` table itself is being scanned instead of index-seeked, that points to #1 or #2 — `SHOW INDEX FROM contacts` and a check of `last_activity_at`'s actual NULL count settle it.

**Potential fixes**, matched to the cause: for #1, run `contacts:recompute-activity` to repair the column, and add a NOT NULL constraint violation to the deploy checklist if it isn't already enforced at the DB level (it is, per the migration — so this would mean something bypassed Eloquent entirely, which itself is the real bug to fix). For #2, add the missing index (`CREATE INDEX ... ALGORITHM=INPLACE` to avoid locking the table during the fix) or force the optimizer's hand with an index hint as a stopgap while the real index is rebuilt. For #3, revert to the denormalized-column query — this is the "put the fire out" fix — and treat the regression as a signal to add a regression test asserting the list query never joins `interactions`. For #4, restore the eager-load.

**Risks of those fixes.** Adding an index on a live, multi-million-row `interactions` table can itself cause a long-held metadata lock if done carelessly — needs `ALGORITHM=INPLACE, LOCK=NONE` (supported for a plain secondary index add on InnoDB) and ideally done during a low-traffic window regardless, with a rollback plan if it runs long. Running `contacts:recompute-activity` against the full table is a heavy write operation (it touches every row) — doing it during peak hours would add load to a database already under stress from the original problem; better to schedule it for a quiet period unless the drift is actively causing incorrect sort order right now. Reverting a query change is low-risk by comparison, but only if it's actually a revert and not a new fix layered on top of a codebase whose git history doesn't obviously show what changed — which is itself an argument for the observability gaps above being closed *before* this kind of incident happens, not during it.
