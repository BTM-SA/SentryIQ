# ADR-0003: Evolve SentryIQ into a domain-oriented personal content platform

## Status

Accepted

## Date

2026-09-21

## Problem

SentryIQ began as a password manager and its early repository structure naturally reflected that origin. The application now includes a secure Vault, Documents, and Gallery, with future plans for additional personal data such as contacts and additional media types such as video and audio.

Continuing to organize the repository around its original password-manager identity would make future domains harder to add cleanly and could encourage duplicated infrastructure.

## Decision

SentryIQ will use a domain-oriented application structure built around the product's current and emerging user-facing domains:

- `Vault` for structured secure information.
- `Documents` for private document/file content.
- `Gallery` as the personal-media domain, covering photos, videos, audio, and albums.
- `Contacts` reserved as a future structured personal-data domain.

Authentication, security, and genuinely shared platform capabilities remain separate from those user-facing domains.

A future shared `app/Core/` area may be introduced when common capabilities are proven to span multiple domains.

## Reasoning

This reflects the application SentryIQ has become rather than preserving an implementation structure based solely on its original feature.

Treating Gallery as the media domain avoids separate top-level Photo, Video, and Audio applications that would likely duplicate storage, metadata, preview, upload, and organization functionality.

Keeping Contacts separate preserves a clear boundary between structured personal data and media while leaving room for future iPhone/vCard-oriented import functionality.

The decision also allows new domains to be added without forcing another broad repository reorganization.

## Consequences

Positive consequences:

- Repository structure follows product domains.
- Gallery can expand naturally to video and audio.
- Future Contacts functionality has a defined architectural home.
- Shared platform responsibilities can be extracted deliberately instead of prematurely.
- Future development no longer needs to treat SentryIQ primarily as a password manager with attached features.

Trade-offs:

- Some existing filenames and historical terminology still reflect the application's earlier Vault-centric origins.
- The repository is intentionally transitional while remaining public URLs are migrated behind the organized application layer.
- A shared Core layer must be introduced carefully to avoid becoming an unrelated catch-all directory.
