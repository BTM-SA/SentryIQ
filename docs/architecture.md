# SentryIQ Repository Architecture

This document records the repository organization and the boundaries between public pages and endpoints, internal application code, the cloud layer, private runtime data, assets, diagnostics, and documentation.

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
└── public pages, bootstrap entry points, and asset endpoints at repository root
```

### Public HTTP entry points

The repository root retains the small set of top-level pages and infrastructure entry points that are part of the deployed interface. Migrated action endpoints are no longer represented by duplicate root PHP wrapper files.

The root `.htaccess` preserves the existing deployed action URLs by internally routing them to the corresponding implementation under `app/`. This separates the repository layout from the public URL surface without requiring an immediate URL migration.

### Internal application code

Application implementation is grouped by responsibility rather than by individual HTTP filename.

- `app/Auth/` contains authentication and access-flow implementation.
- `app/Vault/` contains vault records, category/folder handling, and vault-specific UI modules.
- `app/Documents/` contains local document application endpoints and services.
- `app/Gallery/` contains local gallery application endpoints and services.
- `app/Security/` contains shared security bootstrap, auditing, first-run installation, and security services.

### Cloud layer

`cloud/` is intentionally separate from `app/`. It contains the reusable SentryIQ Cloud/domain services and storage components. Local application code should depend on those services where appropriate without collapsing the two layers into one directory tree.

### Runtime and private data

Vault encryption data, runtime configuration, authentication tokens, throttling records, audit logs, and generated private assets are runtime concerns. They must remain outside tracked application code and outside public static assets.

### Assets

Static CSS, JavaScript, images, and other browser assets belong under `assets/`. Existing root-level assets are migrated only after every consumer has been identified and updated.

### Diagnostics

`diagnostics/` contains diagnostic and troubleshooting scripts. These tools are kept separate from normal application execution paths.

## Migration principles

- Preserve deployed URLs when practical through routing rather than duplicate implementation files.
- Keep public pages and deliberate infrastructure entry points at the root when they are part of the deployed interface.
- Move implementation behind domain-oriented `app/` boundaries.
- Map dependencies before moving a file.
- Make small, reversible changes and verify each migration before continuing.
- Do not merge the local application layer with the cloud service layer.
- Keep secrets and runtime data out of Git.
