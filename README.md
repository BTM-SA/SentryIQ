# SentryIQ

SentryIQ is a self-hosted secure vault application for credential management, documents, gallery storage, passkeys, and security auditing.

## Repository structure

```text
SentryIQ/
├── app/          # Internal application code grouped by responsibility
├── assets/       # Shared static browser assets
├── cloud/        # SentryIQ Cloud/domain services
├── diagnostics/  # Diagnostic and troubleshooting tools
├── docs/         # Architecture and project documentation
└── *.php         # Public pages/endpoints and required bootstrap files
```

## Application code

The internal application layer is organized into domain areas:

- `app/Auth/` — authentication and access-flow logic.
- `app/Vault/` — vault records, categories, folders, and vault UI modules.
- `app/Documents/` — document-related application services.
- `app/Gallery/` — gallery-related application services.
- `app/Security/` — security bootstrap, auditing, first-run installation, and shared security services.

Public HTTP action URLs are preserved through `.htaccess` routing while their implementations live under `app/`. A small number of root-level PHP files remain where they are still used as shared bootstrap/includes or public entry points.

## Assets

Browser assets are organized under `assets/css/`, `assets/js/`, and `assets/images/`. Existing public asset URLs remain compatible through `.htaccess` routing.

## Cloud layer

The `cloud/` directory is kept separate from the local application layer. It contains reusable SentryIQ Cloud services such as document storage and gallery storage/processing components.

## Private data

Runtime vault data, configuration, encryption material, tokens, throttling data, logs, and generated private assets are kept outside the public application code. Runtime secrets must never be committed to the repository.

## Diagnostics

Operational and troubleshooting scripts live under `diagnostics/` and are kept separate from normal application code.

## Documentation

Architecture decisions are recorded under `docs/adr/`. The repository structure and migration strategy are documented in `docs/architecture.md`.
