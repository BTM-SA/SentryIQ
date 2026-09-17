# SentryIQ

SentryIQ is a self-hosted secure vault application for credential management, documents, gallery storage, passkeys, and security auditing.

## Repository structure

```text
SentryIQ/
├── app/          # Application code grouped by responsibility
├── assets/       # Shared static assets
├── cloud/        # SentryIQ Cloud/domain services
├── diagnostics/  # Diagnostic and troubleshooting tools
├── docs/         # Architecture and project documentation
└── *.php         # Public pages, bootstrap entry points, and asset endpoints
```

## Application code

The application layer is organized into domain areas:

- `app/Auth/` — authentication and access-flow implementation.
- `app/Vault/` — vault records, categories, folders, and vault UI modules.
- `app/Documents/` — document-related application services.
- `app/Gallery/` — gallery-related application endpoints and services.
- `app/Security/` — security bootstrap, first-run installation, auditing, and security services.

The repository no longer keeps separate root PHP wrapper files for migrated action endpoints. Existing deployed endpoint URLs are preserved by the root `.htaccess`, which internally routes them to their corresponding `app/` implementation.

## Cloud layer

The `cloud/` directory is kept separate from the local application layer. It contains reusable SentryIQ Cloud services such as document storage and gallery storage/processing components.

## Private data

Runtime vault data, configuration, encryption material, tokens, throttling data, logs, and generated private assets are kept outside the public application code. Runtime secrets must never be committed to the repository.

## Diagnostics

Operational and troubleshooting scripts live under `diagnostics/` and are kept separate from normal application execution paths.

## Documentation

Architecture decisions are recorded under `docs/adr/`. The repository structure and migration strategy are documented in `docs/architecture.md`.
