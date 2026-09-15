# SentryIQ Repository Architecture

This document records the repository organization and the boundaries between application endpoints, domain code, private data, assets, diagnostics, and documentation.

## Principles

- Keep public entry points stable unless a routing migration is explicitly planned.
- Keep domain code grouped by responsibility.
- Keep private application data separate from public assets.
- Keep diagnostics and one-off operational tools separate from normal application code.
- Prefer small, reversible structural changes over broad rewrites.
