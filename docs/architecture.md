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
├── cloud/
├── diagnostics/
├── docs/
└── public HTTP entry points at repository root
```

### Public HTTP entry points

The deployed application currently serves PHP endpoints directly from the repository root. These filenames are part of the deployed interface and should remain stable during the migration.

A root endpoint may therefore be a thin wrapper that delegates to implementation code under `app/`.

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

Static CSS, JavaScript, images, and other browser assets belong under `assets/`. Existing root-level assets are migrated only after every consumer has been identified and updated.

### Diagnostics

`diagnostics/` contains diagnostic and troubleshooting scripts. These tools are kept separate from normal application execution paths.

## Migration principles

- Keep public entry points stable unless a routing migration is explicitly planned.
- Move implementation behind stable wrappers rather than changing deployed URLs unnecessarily.
- Map dependencies before moving a file.
- Make small, reversible changes and verify each migration before continuing.
- Do not merge the local application layer with the cloud service layer.
- Keep secrets and runtime data out of Git.
