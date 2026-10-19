# LONGOS Commerce SaaS

![LONGOS](public/image/longos-logo.svg)

[![LONGOS CI](https://github.com/GUSHU101/LONGOS/actions/workflows/ci.yml/badge.svg)](https://github.com/GUSHU101/LONGOS/actions/workflows/ci.yml)

LONGOS is a multi-tenant commerce SaaS platform for brands, cross-border teams, agencies, and multi-store operators. It provisions isolated storefronts from one control plane, assigns platform domains automatically, and lets tenants activate their own domains after DNS ownership verification.

> One platform. Every storefront.

## Core capabilities

- Batch storefront provisioning with encrypted UUID idempotency replay, renewable execution leases, lifecycle orchestration, rollback, audit logs, and health checks.
- Tenant isolation for database context, cache, sessions, files, mail, themes, and queued work.
- Automatic platform subdomains plus TXT-verified tenant-owned domains, with Shopify-style primary, redirect, and alias roles.
- Fail-closed host routing: unknown or unverified domains never enter a tenant application.
- A protected LONGOS control-plane identity with per-tenant storefront white-labeling.
- Laravel 12, Spatie Laravel Multitenancy v4, and a compatibility layer for the existing plugin and theme ecosystem.

## Architecture boundary

LONGOS is the product and platform identity. The `Beike\\` namespace, selected database structures, and compatibility aliases remain as stable upstream integration boundaries; they are not the customer-facing brand. Runtime tenancy uses one authoritative switching mechanism so database, queue, and request contexts cannot conflict.

## Quick start

Production uses **Coolify + Docker Compose + Coolify's integrated Caddy**. Create a Git-based Docker Compose resource, set `DOMAIN`, `SAAS_BASE_DOMAIN`, and `ADMIN_EMAIL`, then deploy. Image builds, first installation, safe upgrades, queues, scheduling, the mail engine, and HTTPS are automatic; no host installer or panel plugin is required.

Start with the [production configuration tutorial](docs/CONFIGURATION.md), then use the complete [Coolify + Caddy deployment guide](docs/COOLIFY.md) for migration, rollback, and troubleshooting.

Run the complete regression suite, production asset build, and frontend dependency audit before deployment:

```bash
npm run check
```

The `longos:install` command and historical `beikeshop:install` alias remain available for development. The production entrypoint calls the safe installer automatically and never overwrites a non-empty database or rotates an existing `APP_KEY`.

## SaaS operations

The built-in Postfix/OpenDKIM service is deployed by the Coolify Compose stack, so Cloud Mail needs no separate server component. See [Built-in mail server](docs/MAIL.md).

```bash
php artisan saas:health --active-only
php artisan saas:domains:verify-pending
php artisan saas:run process:order --continue-on-error
```

See [SaaS operations](docs/SAAS.md) for tenant provisioning, isolation, domain verification, and control-plane APIs. See the [LONGOS brand standard](docs/BRAND.md) for product naming, white-label boundaries, and visual rules.

Configuration references: [production setup](docs/CONFIGURATION.md), [Coolify/Caddy operations](docs/COOLIFY.md), and [built-in mail engine with Cloud Mail integration](docs/MAIL.md).

## Custom-domain flow

1. A tenant submits its domain.
2. LONGOS returns a unique `_longos-challenge` TXT record and value.
3. The tenant publishes that record with its DNS provider.
4. LONGOS also verifies the traffic DNS and temporarily authorizes Coolify Caddy to issue a certificate for that exact host.
5. The domain becomes active only after a signed HTTPS probe reaches this LONGOS instance; no proxy reload or server command is required.

## Ownership and license

LONGOS is the product name, control-plane identity, and attribution for the original work and modifications in this distribution. Copyright in LONGOS-specific original work and modifications belongs to LONGOS.

This project is derived from [BeikeShop](https://github.com/beikeshop/beikeshop). Copyright, trademark, and attribution for upstream code and third-party components remain with their respective owners. Retained source notices are legal provenance, not the product-facing brand. The distribution is licensed under [OSL-3.0](LICENSE); external deployment and redistribution must comply with that license, retain required notices, and provide the corresponding source.
