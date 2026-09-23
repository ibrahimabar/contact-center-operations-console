# Contact Center Operations Console

An internal tool for customer-support agents and team leads to manage contacts and their interaction history. Built with Laravel 13 / PHP 8.4, MySQL 8, Redis 7, React 19 / Redux Toolkit / TypeScript, and Docker.

See also: [ARCHITECTURE.md](ARCHITECTURE.md) (scale/production reasoning, the operational-incident walkthrough), [DECISIONS.md](DECISIONS.md) (engineering decisions with trade-offs), [AI_USAGE.md](AI_USAGE.md).

## Prerequisites

- Docker and Docker Compose (that's it — no local PHP, Composer, Node, or npm required; everything runs in containers)

## Setup

```bash
cp .env.example .env
docker compose up --build
```

That's the whole setup. The `app` container's entrypoint (`docker/php/entrypoint.sh`) automatically, on every start:

1. Waits for MySQL to accept connections
2. Runs `composer install` if `backend/vendor` is missing
3. Copies `backend/.env.example` → `backend/.env` if missing, generates `APP_KEY` if empty
4. Runs `php artisan migrate --force`
5. Seeds the database if it's empty (`SEED_ON_START=true` by default — see [Seed credentials](#seed-credentials) below)

The `frontend` container runs `npm install && npm run dev` on every start, so `frontend/node_modules` is always in sync with `package.json` without a rebuild.

Once everything is up:

| Service | URL |
|---|---|
| Frontend (React SPA) | http://localhost:5173 |
| Backend API | http://localhost:8000/api/v1 |
| MySQL (host access, e.g. a GUI client) | `localhost:3307` |
| Redis (host access) | `localhost:6380` |

MySQL and Redis are on non-default host ports (3307, 6380) specifically so this stack doesn't collide with a developer's own local MySQL/Redis, if any. Container-to-container traffic always uses the default ports and the Docker Compose service names (`mysql`, `redis`).

## Environment configuration

Two `.env` files, both with a committed `.env.example` to copy from:

- **Root `.env`** — Docker Compose variables only: host port mappings, MySQL root/app credentials, `SEED_ON_START`.
- **`backend/.env`** — the Laravel app's own configuration (DB/Redis connection details pointed at the Compose service names, Sanctum stateful domains, cache/session/queue drivers). Auto-created from `backend/.env.example` by the entrypoint if missing; edit it directly for any local override (it's gitignored).
- **`frontend/.env`** — just `VITE_API_BASE_URL` (defaults to `http://localhost:8000`).

Notable non-default choices (each justified in [DECISIONS.md](DECISIONS.md)):
- `SESSION_DRIVER=database`, not `redis` — login must survive a Redis outage.
- `CACHE_STORE=redis` — backs rate limiting and the interaction-idempotency lock, the app's two Redis uses.
- `QUEUE_CONNECTION=sync` — no background jobs are needed at this scope (see ARCHITECTURE.md "Queues").

## Commands

Run via `docker compose exec app <command>` (backend) or `docker compose exec frontend <command>` (frontend):

```bash
# Backend
docker compose exec app php artisan migrate:fresh --seed   # reset + reseed the database
docker compose exec app php artisan tinker                 # REPL
docker compose exec app ./vendor/bin/pint                   # auto-fix code style
docker compose exec app ./vendor/bin/phpstan analyse        # static analysis (Larastan)
docker compose exec app php artisan contacts:recompute-activity  # recompute last_activity_at from scratch (see DECISIONS.md #4)

# Frontend
docker compose exec frontend npm run lint       # oxlint
docker compose exec frontend npm run typecheck  # tsc --noEmit
docker compose exec frontend npm run build      # production build → frontend/dist
```

## Test commands

```bash
docker compose exec app php artisan test          # backend: Pest, 51 tests, real MySQL + Redis (no mocking — see DECISIONS.md)
docker compose exec frontend npm run test          # frontend: Vitest + Testing Library + MSW
```

Both are also run in CI (`.gitlab-ci.yml`) against fresh, isolated databases.

## Seed credentials

All seeded users share the password **`password`**. Dev-only — never used outside this seeded, local-only dataset.

| Email | Role | Notes |
|---|---|---|
| `teamlead@example.com` | Team Lead | Full access: all contacts, reassignment, archive/restore |
| `agent1@example.com` … `agent6@example.com` | Agent | Each has their own assigned contacts only |
| `inactive.agent@example.com` | Agent (deactivated) | For testing the login-rejected-for-inactive-user path |

Seed data: ~500 contacts (spread across the 6 agents plus an unassigned queue for the team lead, ~3% pre-archived to demonstrate the restore flow) and ~5,000 interactions with realistic, varied timestamps. This is a small demonstration dataset, not a scale test — see ARCHITECTURE.md for how the design accounts for the brief's 250k-contact / 10M-interaction production scenario without needing that much data loaded locally.

## Assumptions

The brief left a few specifics for the implementer to decide; each is also called out where relevant in the code:

- **Who can create a contact:** both roles. An agent-created contact is always assigned to that agent (any `assigned_agent_id` they submit is silently ignored, not rejected); a team lead may assign it to anyone, or leave it unassigned.
- **"Delete or archive":** implemented as archive (soft delete) with a restore action, not a hard delete — interaction history is never destroyed.
- **Unauthorized access to a specific contact returns 403, not 404.** For an internal tool, existence-hiding wasn't judged worth the UX cost of "why can't I find this contact"; the brief's authorization requirement is satisfied either way.
- **Interaction `timestamp` may be backdated** (an agent logging a call after the fact) but not more than 5 minutes into the future (clock-skew allowance).

## Known limitations

- **The GitLab CI pipeline hasn't been run against a live GitLab instance.** It's been validated with [`gitlab-ci-local`](https://github.com/firecow/gitlab-ci-local) — every job (`backend:install`, `backend:lint`, `backend:test` with real `mysql`/`redis` services, and the full `frontend:*` chain including a production build) has actually executed successfully in Docker, not just been schema-checked — but a real GitLab runner may still surface environment differences this couldn't catch.
- **Search is prefix-matching, not substring or typo-tolerant** (MySQL FULLTEXT boolean mode) — see DECISIONS.md #6 for what a production-scale search engine would add.
- **No live demonstration of the 250k-contact/10M-interaction scale** — the schema/index design accounts for it (ARCHITECTURE.md), but the seeded dataset is intentionally small for fast local setup.
- **No password reset / email verification flow** — out of scope for an internal tool seeded with known credentials; `password_reset_tokens` table exists (Laravel default) but is unused.
- **No file attachments on interactions** — not in the brief's minimum interaction fields.
