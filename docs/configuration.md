# Configuration

Open **Administration settings → Additional settings → AutoPhish** as a full Nextcloud administrator. Save the AutoPhish HTTPS origin (no path or URL credentials), company ID, API key, and synchronization interval. The interval accepts 300–86400 seconds; the default is 3600 seconds. Saving settings never creates duplicate background jobs: Nextcloud registers the single job from app metadata.

The API key requires `targets:read` and `targets:write` for the selected company. The server encrypts it with Nextcloud's `ICrypto` using the instance secret. The settings API returns only a configured flag, never the plaintext key or ciphertext. Leave the key field blank to keep the existing key. Protect the Nextcloud configuration secret and database backups together.

Test the saved connection to discover groups and writable lists. Map each Nextcloud group to its own dedicated AutoPhish target list, either an existing list or a newly created list. A group and a list can each appear only once. Managed directory lists and masked or pseudonymous lists cannot be selected. Existing lists must be dedicated to the mapped group: synchronization removes stale **list memberships**, so an unrelated list is unsuitable. Disabling or removing a mapping stops synchronization without deleting its remote list.

Changing the server origin or company ID clears mappings, last-run status, and pending list creation state. Rotating an API key preserves mappings. Changing the server origin requires explicitly entering a key for the new server; the saved credential cannot silently transfer to another origin. Settings and mapping mutations use the same owned database lease as synchronization. Configuration reads bypass process-local caches; writes atomically require the current lease, active app, and unchanged local state. Disable/removal deletes local state even during an in-flight request; that request cannot recreate cleared settings or write obsolete status. Install and upgrade hooks initialize missing state without replacing existing settings. Settings operations and synchronization stop starting remote requests after 300 seconds, safely before the shared 600-second lease expires.

## New-list recovery

Before a create request, the app persists a random idempotency key with the group, list name, and creation time. A failed or uncertain response preserves this record. Retry the original group and name to reuse the key; creation of a different list is blocked while the earlier creation remains unresolved. A successful response is mapped locally before releasing the lock.

AutoPhish's replay window is 24 hours. After that time the app blocks another create request rather than risk a duplicate. Inspect AutoPhish for the original list and attach that existing list to the same group to resolve the pending operation. If no list exists, tick the inspection acknowledgement and clear the expired pending creation before attempting a fresh creation. The reset is rejected during the replay window and requires explicit confirmation that the administrator verified no list was created.

Use Nextcloud system cron for normal scheduling. For an immediate run, execute `occ integration_autophish:sync` as the Nextcloud web-server user. The admin screen displays synchronization status and counts without recipient email addresses or credentials.
