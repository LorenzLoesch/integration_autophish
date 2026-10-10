# Integration app guide

Use public Nextcloud APIs, PHP 8.3, Nextcloud 32 and Node 24. Keep runtime dependencies in Nextcloud; Composer/npm dependencies are development-only.

Keep tenant context explicit in every AutoPhish API request. Never export source user IDs, directory attributes, credentials or provider response text. All SaaS calls in tests must be mocked. Runtime smoke checks use disposable non-root containers with isolated networking.

Settings and sync share the app-local owner lease. Fresh reads and conditional database writes must never recreate state after disable/removal or overwrite another owner's status. Read complete source and destination snapshots and verify additions before membership removal; never use company-wide or hard-delete APIs.

Run `npm run build` and `npm run lint` before committing; run `npm test` before pushing. Exercise database changes through real Nextcloud smoke tests, including PostgreSQL. Keep matching concise docs/tests with feature changes. Use Conventional Commits. Exclude private planning records, tenant data and secrets from public history.
