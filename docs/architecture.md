# SentryIQ Repository Architecture

This document records the repository organization and the boundaries between public endpoints, internal application code, the cloud layer, private runtime data, assets, diagnostics, and documentation.

## Repository boundaries

```text
SentryIQ/
├── app/
│   ├── Auth/
│   ├── Documents/
│   ├── Gallery/
│   ├── Security/
│   └── Vault/
├── assets/
│   ├── css/
│   ├── js/
│   └── images/
├── cloud/
├── diagnostics/
├── docs/
└── public PHP entry points and required bootstrap files at repository root
```

### Public HTTP entry points

The deployed application serves its public PHP pages and dynamic endpoints from the repository root. Existing endpoint URLs are preserved through `.htaccess` rewrite rules even when the implementation now lives under `app/`.

Root-level PHP files that are still required as shared bootstrap/includes remain until their remaining consumers can be migrated safely.

### Internal application code

Application implementation is grouped by responsibility rather than by individual HTTP filename.

- `app/Auth/` contains authentication and access-flow implementation.
- `app/Vault/` contains vault records, category/folder handling, and vault-specific UI modules.
- `app/Documents/` contains local document application services.
- `app/Gallery/` contains local gallery application services that are not public HTTP routes.
- `app/Security/` contains shared security bootstrap, auditing, and security services.

### Cloud layer

`cloud/` is intentionally separate from `app/`. It contains the reusable SentryIQ Cloud/domain services and storage components. Local application code should depend on those services where appropriate without collapsing the two layers into one directory tree.

### Runtime and private data

Vault encryption data, runtime configuration, authentication tokens, throttling records, audit logs, and generated private assets are runtime concerns. They must remain outside tracked application code and outside public static assets.

### Assets

Static CSS, JavaScript, and images live under `assets/`. `.htaccess` preserves the established public URLs for these files while keeping the repository organized.

### Diagnostics

`diagnostics/` contains diagnostic and troubleshooting scripts. These tools are kept separate from normal application execution paths.

## Migration principles

- Keep public URLs stable unless a routing migration is explicitly planned.
- Move implementation behind organized application paths while preserving compatibility where practical.
- Map dependencies before moving a file.
- Preserve existing file bytes when a move does not require content changes.
- Make small, reversible changes and verify each migration before continuing.
- Do not merge the local application layer with the cloud service layer.
- Keep secrets and runtime data out of Git.
