# SentryIQ Application Code

This directory contains the internal SentryIQ application layer, grouped by platform responsibility and user-facing domain.

## Platform responsibilities

- `Auth/` — authentication, passkeys, and access-flow logic.
- `Security/` — security bootstrap, first-run installation, auditing, logging, and security services.

## User-facing domains

- `Vault/` — secure structured information, records, categories, folders, and vault-specific services.
- `Documents/` — document storage and document application services.
- `Gallery/` — personal media management, currently focused on photos and albums and designed to expand to video and audio.

A future `Contacts/` domain may be added for structured personal contact data and import functionality.

A future `Core/` area may be introduced when genuinely shared capabilities such as storage abstractions, metadata, search, audit, or common file handling are used across multiple domains. It should not become a general-purpose catch-all.

Public HTTP action URLs are kept at the repository URL surface and routed through the repository `.htaccess` to their implementations under `app/`.

The `cloud/` directory is a separate service layer and should not be collapsed into `app/`.
