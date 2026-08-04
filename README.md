# Parlour

> **A communication & approval portal for freelance web developers and their clients.**

[![CI](https://github.com/moriyama-dev/parlour/actions/workflows/ci.yml/badge.svg)](https://github.com/moriyama-dev/parlour/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-8.3%20|%208.4-777BB4?style=flat&logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat&logo=laravel&logoColor=white)
![Vue](https://img.shields.io/badge/Vue-3-4FC08D?style=flat&logo=vuedotjs&logoColor=white)

🔗 **Live site:** **[parlour.takumi.ca](https://parlour.takumi.ca/)**

> ℹ️ **This repository contains the actual source code powering the live application at
> [parlour.takumi.ca](https://parlour.takumi.ca/).** What you see here is exactly what runs in
> production — only secrets (credentials, API keys) and real client data are excluded. Everything
> else, including the database schema, API design, and business logic, is fully open.

---

## What is Parlour?

Existing tools like HoneyBook, Dubsado, and Bonsai are too generic for the realities of web
development work. Parlour is purpose-built around the freelance dev workflow — staging reviews,
production deploy approvals, dependency-update sign-offs — and turns the back-and-forth with
clients into a single, structured portal.

It serves three goals at once:

- **Portfolio** — demonstrates a full-stack Laravel build: API design, authentication,
  real-time messaging, notifications, and PWA support, all implemented from scratch.
- **Client demo** — a working product that shows prospective clients what can be built.
- **Real tool** — actually usable for managing live freelance engagements.

The name *Parlour* (a room for receiving guests) reflects the tone: a place to host clients,
not just a place to work.

## Features

- 🔐 **Token-based auth** with Laravel Sanctum (SPA flow, multi-device)
- 👥 **Companies & clients** with a many-to-many primary-contact model
- ✅ **Task approval workflow** tailored to dev work (design review, staging review,
  deploy approval, dependency updates)
- 🧾 **Append-only approval log** — records are immutable (no UPDATE/DELETE) for a
  tamper-evident audit trail
- 💬 **Real-time messaging** via Laravel Reverb (WebSockets)
- 📄 **Invoicing** with line items, tax, and status tracking
- 🔔 **Dual notifications** — in-app plus PWA web push
- 📎 **Polymorphic attachments** shared across tasks and messages

## Tech Stack

| Layer        | Technology                          |
| ------------ | ----------------------------------- |
| Backend      | Laravel 13 (API) on PHP 8.3+        |
| Auth         | Laravel Sanctum (SPA tokens)        |
| Frontend     | Vue 3 + Vite (Composition API)      |
| State / Routing | Pinia + Vue Router               |
| Styling      | Tailwind CSS                        |
| Database     | MySQL                               |
| Real-time    | Laravel Reverb (WebSockets)         |
| Web Push     | minishlink/web-push (VAPID)         |
| Email        | Laravel Mailable                    |

## Repository Layout

```
.
├── api/    # Laravel 13 backend (REST API, auth, real-time, notifications)
└── web/    # Vue 3 + Vite single-page application
```

## Getting Started

### Backend (`api/`)

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend (`web/`)

```bash
cd web
npm install
npm run dev
```

The SPA proxies `/api` requests to the Laravel backend during development.

## Tests & CI

```bash
cd api
php artisan test          # PHPUnit, SQLite in-memory — no local DB needed
vendor/bin/pint --test    # code style
```

Every push and pull request runs the suite on **PHP 8.3 and 8.4**, checks style with Pint, and
builds the Vue SPA — see [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

The feature tests cover the parts of the portal that are expensive to get wrong:

| Area | What is pinned down |
| --- | --- |
| `AuthTest` | Sanctum token issuance, 401 on bad credentials, validation, password never serialised |
| `RoleAccessTest` | The developer/client split — clients cannot reach company management routes |
| `ProjectAccessTest` | A client of company A cannot read or approve company B's project by guessing an id, and a task id from another project does not resolve |
| `TaskApprovalTest` | Only clients can approve or reject, rejection requires a reason, and **the approval log is append-only** — a later decision never overwrites an earlier one |
| `EnsureRoleTest` | The role middleware in isolation, including the guest case |

The append-only guarantee is the one worth calling out: the approval history is what a client and
a developer point at months later when they disagree about what was signed off, so it is tested
rather than assumed.

## Security & Open-Source Notes

The guiding principle for this public repository: **code and schema are open; secrets and real
client data are not.**

- All secrets live in a single untracked `.env` file (see `api/.env.example` for the
  required keys).
- Real client data is kept out of version control.
- Application code, migrations, and logic are fully published — the implementation *is* the
  portfolio.

### Authorization model

Two independent checks, both enforced server-side and both covered by tests:

- **Role** (`role:developer`) — who may manage companies, clients and invitations.
- **Tenancy** (`project.access`) — *which* projects a given client may touch at all. A client
  belongs to a company; every route under `/projects/{project}` is guarded, and nested bindings
  are scoped so a `{task}` or `{invoice}` id from another project resolves to a 404 rather than
  being acted on.

Role alone is not enough — it would let any authenticated client reach another company's project
by guessing an id. The SPA hides those screens from clients, but hiding a link is not
authorization; the API enforces it independently.

---

*Built by Yoshiro Moriyama · [Takumi Web Services](https://parlour.takumi.ca/)*
