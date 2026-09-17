# SentryIQ Application Code

This directory contains application code grouped by responsibility.

## Boundaries

- `Auth/` — authentication, passkeys, and access-flow logic.
- `Vault/` — vault records, categories, folders, icons, and vault-specific services.
- `Documents/` — document storage and document endpoints.
- `Gallery/` — gallery endpoints and gallery application services.
- `Security/` — security bootstrap, first-run installation, auditing, and security services.
- `Shared/` — small reusable application components that do not belong to one domain.

Migrated HTTP action endpoints live under the relevant `app/` domain. Their former root URLs remain available through the repository `.htaccess` routing rules so deployed links and browser requests do not need to change immediately.

The `cloud/` directory is a separate layer and should not be collapsed into `app/`.
