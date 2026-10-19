# AGENTS.md — LONGOS AI Agent Guide

## Overview

This file provides standardized instructions for AI agents working on and deploying LONGOS Commerce SaaS.

## Product Identity (Binding)

LONGOS is the project name, product identity, control-plane identity, and attribution for project-specific original work and modifications. Tenant storefronts may use their own brands, but the platform and administration surfaces remain LONGOS. BeikeShop names in source headers, the `Beike\\` namespace, marketplace integrations, and compatibility aliases are retained solely for upstream provenance or technical compatibility. Do not present them as the LONGOS product brand and do not remove legally required notices. See `docs/BRAND.md`.

## System Architecture

LONGOS is a Laravel 12 multi-tenant commerce SaaS platform with isolated storefronts, batch provisioning, and verified custom domains. It retains a modular upstream-compatible commerce core.

### Core Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Web Server Layer                      │
│       (Coolify Caddy → internal Nginx + PHP 8.2)        │
└────────────────┬────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────┐
│                  Laravel Application                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│  │   Admin      │  │    Shop      │  │   Plugins    │ │
│  │  (Backend)   │  │  (Frontend)  │  │  (Extension) │ │
│  └──────────────┘  └──────────────┘  └──────────────┘ │
└────────────────┬────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────┐
│                  Service Layer                           │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐ │
│  │  Services    │  │ Repositories │  │   Models     │ │
│  │  (Business)  │  │   (Data)     │  │  (ORM)       │ │
│  └──────────────┘  └──────────────┘  └──────────────┘ │
└────────────────┬────────────────────────────────────────┘
                 │
┌────────────────▼────────────────────────────────────────┐
│                  Database Layer                          │
│             (MySQL 8.4 LTS / PostgreSQL)                 │
└─────────────────────────────────────────────────────────┘
```

### Design Patterns

- **Repository Pattern**: Data access abstraction in `beike/Repositories/`
- **Service Layer**: Business logic in `beike/Services/`
- **Plugin System**: Extensible architecture in `beike/Plugin/`
- **Theme System**: Customizable frontend in `themes/`

## Project Structure

### Top-Level Directories

```
longos/
├── app/                 # Laravel application code
├── beike/               # Upstream-compatible commerce core
├── bootstrap/           # Laravel bootstrap files
├── config/              # Application configuration
├── database/            # Migrations, seeds, factories
├── plugins/             # Third-party plugins directory
├── public/              # Web root (index.php, assets)
├── lang/                # Multi-language files for admin and shop
├── resources/           # Views, frontend assets, and data
├── routes/              # Route definitions
├── storage/             # Logs, cache, uploads
├── tests/               # PHPUnit tests
├── themes/              # Frontend theme templates
└── vendor/              # Composer dependencies
```

### Commerce Compatibility Core (`beike/`)

The `beike/` directory contains the upstream-compatible commerce business logic used by LONGOS:

| Directory | Description |
|-----------|-------------|
| `Admin/` | Backend admin panel controllers, views, and logic |
| `AdminAPI/` | Admin API controllers, routes, and providers |
| `Shop/` | Frontend storefront controllers and views |
| `Models/` | Eloquent models (Product, Order, Customer, etc.) |
| `Repositories/` | Data access layer (DB queries and relationships) |
| `Services/` | Business logic services (cart, checkout, email, etc.) |
| `Config/` | Commerce-core compatibility configuration |
| `Console/` | Artisan commands |
| `Installer/` | Installation wizard logic |
| `Hook/` | Hook system and extension points |
| `Fields/` | Field definitions and form metadata |
| `Facades/` | Commerce-core compatibility facades |
| `Libraries/` | Shared utility classes |
| `Mail/` | Mail classes and templates integration |
| `Notifications/` | Notification classes |
| `Plugin/` | Plugin system core (loader, hooks, lifecycle) |

### Language Structure (`lang/`)

Language files are stored at the top-level `lang/` directory:

| Directory | Description |
|-----------|-------------|
| `{locale}/admin/` | Admin panel translations |
| `{locale}/shop/` | Shop frontend translations |

### Resources Structure (`resources/`)

Application resources are organized by runtime area:

| Directory | Description |
|-----------|-------------|
| `beike/admin/` | Admin panel frontend assets and views |
| `beike/shop/` | Shop frontend assets and default theme resources |
| `data/` | Seed and static data files |
| `js/` | Shared frontend JavaScript entry points |
| `views/` | Laravel views and vendor-published views |

### Theme Structure (`themes/default/`)

Frontend templates organized by feature:

| Directory | Description |
|-----------|-------------|
| `account/` | Customer account pages (login, register, profile) |
| `cart/` | Shopping cart views |
| `checkout/` | Checkout process views |
| `brand/` | Brand listing pages |
| `components/` | Reusable UI components |
| `design/` | Visual page builder components |
| `errors/` | Error pages (404, 500, etc.) |
| `layout/` | Base layout templates |
| `mails/` | Email templates |
| `page_categories/` | Page category templates |
| `pages/` | Static pages (about, contact, etc.) |
| `product/` | Product listing and detail templates |
| `shared/` | Shared partials (header, footer, sidebar) |

### Plugin System

Plugins extend LONGOS functionality without modifying compatibility core code:

```
plugins/
└── my-plugin/
    ├── Bootstrap.php       # Plugin entry point
    ├── composer.json       # Plugin dependencies
    ├── Routes/             # Plugin routes
    ├── Controllers/        # Plugin controllers
    ├── Models/             # Plugin models
    ├── Services/           # Plugin services
    ├── Static/             # Plugin static assets
    ├── Views/              # Plugin views
    └── Lang/               # Plugin translations
```

### Plugin Artisan Commands

LONGOS includes plugin scaffolding commands for creating and maintaining plugin files:

```bash
# Plugin lifecycle
php artisan plugin:make
php artisan plugin:list
php artisan plugin:use
php artisan plugin:unuse
php artisan plugin:delete
php artisan plugin:zip

# Plugin code generators
php artisan plugin:make-action
php artisan plugin:make-aspect
php artisan plugin:make-channel
php artisan plugin:make-command
php artisan plugin:make-component
php artisan plugin:make-component-view
php artisan plugin:make-controller
php artisan plugin:make-enum
php artisan plugin:make-event
php artisan plugin:make-event-provider
php artisan plugin:make-exception
php artisan plugin:make-factory
php artisan plugin:make-helper
php artisan plugin:make-interface
php artisan plugin:make-job
php artisan plugin:make-listener
php artisan plugin:make-mail
php artisan plugin:make-middleware
php artisan plugin:make-migration
php artisan plugin:make-model
php artisan plugin:make-notification
php artisan plugin:make-observer
php artisan plugin:make-policy
php artisan plugin:make-provider
php artisan plugin:make-request
php artisan plugin:make-resource
php artisan plugin:make-service
php artisan plugin:make-trait
php artisan plugin:make-view

# Plugin model inspection
php artisan plugin:model-show
```

## Environment Requirements

| Component | Minimum Version | Recommended | Notes |
|-----------|----------------|-------------|-------|
| **PHP** | 8.2 | 8.2+ | Required extensions: pdo_mysql, mbstring, gd, zip, bcmath, exif, pcntl, opcache |
| **MySQL** | 5.7 | 8.4 LTS | Docker defaults to the supported 8.4 LTS line; PostgreSQL 14+ is also supported |
| **Nginx** | 1.20 | 1.24+ | Required modules: rewrite, fastcgi |
| **Caddy** | 2.11.4 | 2.11.4+ | Public HTTPS edge and On-Demand TLS authorization |
| **Composer** | 2.0 | 2.5+ | PHP dependency manager |
| **Node.js** | 22 | 22+ | Only needed for theme development |
| **Docker** | 20.10 | 24+ | Only needed for Docker deployment |
| **Docker Compose** | 2.0 | 2.20+ | V2 syntax (`docker compose`) |

## Key Features

- **Multi-language**: Built-in support for 20+ languages
- **Multi-currency**: Dynamic currency switching
- **Responsive Design**: Mobile-first theme system
- **Plugin Marketplace**: Extensible via plugins
- **Visual Page Builder**: Drag-and-drop page design
- **RESTful API**: Headless commerce support
- **SEO Optimized**: Clean URLs and meta tags
- **Payment Gateways**: PayPal, Stripe, and more

## Production Deployment (Binding)

LONGOS production deployment uses one supported path: **Coolify + Git-based
Docker Compose + Coolify's integrated Caddy proxy**. Do not add BT Panel,
host-level PHP/Nginx installers, an application-level public Caddy container,
or Apache profiles back into the project.

### Architecture

- Coolify's server-wide `coolify-proxy` Caddy is the only process binding
  public TCP 80, TCP 443, and UDP 443.
- The `app` service exposes internal HTTP port 80 only.
- Caddy Docker labels register one hostless HTTPS route protected by LONGOS
  `/_longos/tls/authorize`; unknown domains fail before certificate issuance.
- MySQL, mail relay, worker, and scheduler stay on the private `longos` network.
- Persistent volumes own database data, Laravel storage, uploads, plugins,
  DKIM material, the Postfix spool, and relay idempotency state.

### Coolify Flow

1. Select Caddy under **Server → Proxy** and disable the default request
   handler override.
2. Create a Git-based Docker Compose application using `/docker-compose.yml`.
3. Enable Raw Compose deployment and connect to the predefined `coolify`
   network.
4. Set `DOMAIN`, `SAAS_BASE_DOMAIN`, and `ADMIN_EMAIL`.
5. Deploy. Coolify generates every `SERVICE_*` secret used by the stack.

The immutable application image installs locked Composer dependencies and
builds browser assets. Its entrypoint performs a resumable first install or a
serialized `saas:upgrade --if-needed`; agents must not add manual post-deploy
migration steps. Git/Coolify owns application updates, so
`LONGOS_UPDATER_ENABLED=false` in production.

See `docs/COOLIFY.md` for the complete deployment, DNS, migration, backup, and
rollback procedure.

## Upgrade Process

Push a reviewed commit to the configured Git branch or select Deploy in
Coolify. The new image installs locked dependencies at build time; the web
entrypoint takes the shared deployment lock and runs
`saas:upgrade --no-maintenance --if-needed` before accepting traffic. Never
run Composer, npm, or migrations manually inside a production container.

Back up the MySQL and persistent volumes through Coolify before a release.
Rollback selects the previous successful Coolify deployment; database state
must be restored from its matching backup if the release used irreversible
migrations.

## API Reference

LONGOS provides a RESTful API for headless commerce integration.

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/products` | List products |
| GET | `/api/products/{id}` | Product detail |
| GET | `/api/categories` | List categories |
| POST | `/api/cart/add` | Add to cart |
| GET | `/api/cart` | Get cart contents |
| POST | `/api/checkout` | Create order |
| GET | `/api/orders` | List orders (authenticated) |

### API Authentication

API uses Laravel Sanctum token authentication:

```bash
# Get token
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"***","password":"***"}'

# Use token
curl http://localhost/api/orders \
  -H "Authorization: Bearer {token}"
```

### API Documentation

- [Upstream-compatible API reference](https://docs.beikeshop.com/dev/api.html)
- Swagger/OpenAPI spec available at `/api/documentation` (if enabled)

## Troubleshooting

### 1. Permission Issues

Do not chmod host paths. Check the `app` startup log; the image entrypoint
creates and owns runtime directories in the named volumes automatically.

### 2. Database Connection Failed

```bash
# Inspect the mysql health status and the app deployment log in Coolify.
```

### 3. APP_URL Warning

```bash
# Correct DOMAIN in Coolify Environment Variables and redeploy.
```

## Security Recommendations

1. ✅ Change default admin password immediately in production
2. ✅ Configure HTTPS (use Let's Encrypt)
3. ✅ Regular database backups
4. ✅ Restrict admin panel access by IP
5. ✅ Enable firewall rules
6. ✅ Keep system updated

## Documentation

- [LONGOS Brand Standard](./docs/BRAND.md)
- [LONGOS SaaS Operations](./docs/SAAS.md)
- [Upstream compatibility docs](https://www.beikeshop.com/docs)
- [Installation Guide](./docs/INSTALL.md)
- [Publishing Guide](./docs/PUBLISH.md)

## License

LONGOS is distributed under OSL-3.0 and is derived from BeikeShop. Retain required source notices and upstream attribution; LONGOS-specific original work and modifications are attributed to LONGOS.
