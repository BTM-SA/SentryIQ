# SentryIQ Application Code

This directory contains internal application code grouped by responsibility.

## Boundaries

- `Auth/` — authentication, passkeys, and access-flow logic.
- `Vault/` — vault records, categories, folders, icons, and vault-specific services.
- `Documents/` — document storage and document services.
- `Gallery/` — gallery application services that are not public HTTP routes.
- `Security/` — security bootstrap, first-run installation, auditing, and security services.
- `Shared/` — small reusable application components that do not belong to one domain.

Public HTTP action endpoints are kept out of this directory. Their established URLs are routed by the repository `.htaccess` to the appropriate implementation under `app/`.

A small number of root-level PHP files remain as public entry points or shared bootstrap/includes where existing consumers still depend on them.

The `cloud/` directory is a separate layer and should not be collapsed into `app/`.
