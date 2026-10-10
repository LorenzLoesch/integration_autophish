# Implementation plan

1. Establish a classic PHP app and bounded public API client; test headers, pagination, idempotency, redirects and safe errors.
2. Add encrypted administrator configuration and dedicated group/list mappings; test access, validation, stable creation retries and safe responses.
3. Reconcile complete group/list snapshots with bounded additions, then safe membership removals; test failure barriers and convergence.
4. Share one locked runner across cron and occ; persist bounded status and clean local state through lifecycle hooks.
5. Build a reproducible allowlisted release archive, document installation/data flow, and exercise a mocked journey plus real Nextcloud install/update/removal smoke checks.
6. Review security and runtime compatibility, run the complete verification suite, publish focused commits/PRs and a release.

Runtime baseline: Nextcloud 32, PHP 8.3. No frontend build dependency: ship plain JavaScript/CSS and server-rendered administrator settings. Runtime dependencies come from Nextcloud. No live SaaS calls in verification.
