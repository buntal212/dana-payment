# DANA Gapura Integration Protection Rules

These rules supplement the existing Laravel Boost guidelines in `AGENTS.md`. Preserve all existing instructions. In case of uncertainty or conflicting instructions, stop and request clarification before editing payment-critical code.

## Scope
The Laravel `dana-payment` project already has a working integration with DANA via Gapura. Treat the integration as protected and read-only unless the user explicitly approves a narrowly described change.

## Protected components — no changes without explicit approval
- DANA/Gapura base URLs, sandbox/production selection, merchant identifiers, credentials, private/public keys, certificates, signature generation and verification, authentication headers, timestamps, nonces, and encryption.
- `.env`, `.env.*`, gateway configuration, Docker Compose, deployment settings, reverse proxy, callback URLs, and secrets.
- Existing payment creation, inquiry, cancellation, refunds, idempotency, reconciliation, webhook controllers/routes/middleware, transaction state transitions, queues, and audit logs.
- Existing payment database schema, records, migrations, and transaction history.
- Dependencies or formatting/refactoring changes that could affect the gateway integration.

## Permitted work
- Inspect code, routes, models, tests, and configuration *names* without revealing secret values.
- Build new isolated, authenticated and authorized read-only admin reporting endpoints and tests, reusing existing services without changing payment behavior.
- Prepare a proposed change plan and request approval before any migration or modification of protected components.

## Frontend boundary
- Nuxt SSR UI belongs in sibling `udumbara-payment` project, not this Laravel backend.
- Never expose gateway credentials to the browser or send browser requests directly to Gapura.
- Never claim that an admin API endpoint exists without checking the actual route definitions.
- Future UDEV billing/subscriptions remain separate from this gateway service.

## Required workflow
1. Read the applicable `AGENTS.md` and `.ai/rules` guidelines before editing.
2. Inventory affected files and identify protected components.
3. Show a minimal proposed change plan; if it touches protected components, stop for user approval.
4. Do not run real payment requests, destructive migrations, database resets, or credential rotations.
5. Run safe, targeted tests; if Pint is required, restrict formatting to newly changed, approved PHP files and inspect the diff to ensure protected files are untouched.
6. Review `git diff` and report changed files, checks, and any unverified behavior.

## Non-negotiable safeguards
- Never disable webhook signature verification, authentication, authorization, tenant isolation, rate limiting, or audit logs.
- Never commit, print, or copy secrets into code, logs, screenshots, or reports.
- Never alter production or sandbox settings without explicit approval.
- On conflict with a requested task, preserve existing payment behavior and ask first.
