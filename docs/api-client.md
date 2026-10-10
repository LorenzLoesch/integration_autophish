# AutoPhish API client

The app uses the public `/api/v1/target-lists` API with a Bearer key and an explicit `X-Company-Id` tenant header on every request. Required key scopes are `targets:read` and `targets:write`; the key owner must retain company administrator access.

Configure an HTTPS origin, such as `https://autophish.example`, with no credentials, query, fragment, or subpath. Nextcloud's HTTP client enforces its network access policy. TLS verification stays enabled and redirects are refused. Requests have a 20-second timeout and a 10-second connection timeout.

Lists and members are fetched across all cursor pages before returning results. Malformed pages, repeated cursors, duplicate membership IDs, unexpected list IDs, and a 1,000-page safety ceiling fail the operation rather than exposing a partial snapshot. Unknown response fields are accepted. Integration-managed, masked, and pseudonymous lists cannot be selected as synchronization destinations.

Create, bulk-add, and membership-delete requests carry caller-generated idempotency keys. The same key and encoded body are retained during retries. Only HTTP 425 and 429 are retried, up to three attempts. Numeric and HTTP-date `Retry-After` values and epoch or date-time `X-RateLimit-Reset` and `X-RateLimit-Daily-Reset` values are respected. Delays above ten seconds are deferred to a later run instead of retrying prematurely; absent hints use one and two seconds. Network failures are surfaced for durable run resumption without inventing a new operation key.

Bulk additions contain at most 200 recipients and an encoded body smaller than 64,000 bytes. Deletions use list membership IDs, never canonical target deletion. Typed errors expose only a safe category, HTTP status, and generic message. Documented managed/pseudonymous detail codes and the domain-validation error shape map to local actionable messages; remote error bodies and underlying exception text are not passed to administrators or logs. Unit tests mock Nextcloud's actual HTTP interfaces and never contact AutoPhish.

A scoped request guard checks cancellation, configuration generation, and the operation deadline before every HTTP attempt, including subsequent pages and retries after waiting. Its previous guard is restored even when the operation fails. A request already in flight may finish after cancellation; no later request starts.
