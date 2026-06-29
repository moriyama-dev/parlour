# Parlour

> **A communication & approval portal for freelance web developers and their clients.**

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
| Backend      | Laravel 11 (API)                    |
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
├── api/    # Laravel 11 backend (REST API, auth, real-time, notifications)
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

## Security & Open-Source Notes

The guiding principle for this public repository: **code and schema are open; secrets and real
client data are not.**

- All secrets live in a single untracked `.env` file (see `api/.env.example` for the
  required keys).
- Real client data is kept out of version control.
- Application code, migrations, and logic are fully published — the implementation *is* the
  portfolio.

---

*Built by Yoshiro Moriyama · [Takumi Web Services](https://parlour.takumi.ca/)*
