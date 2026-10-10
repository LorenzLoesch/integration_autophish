# Membership removal

Each mapping must have its own dedicated ordinary list. Complete source/destination reads and successful verified additions precede removal. Missing or invalid emails, duplicate normalized source addresses, malformed destination data or failed reads/additions block pruning. A confirmed empty source can clear its mapped list.

Remove only membership IDs returned by that list, through its list-specific DELETE endpoint. Never use company-wide target deletion, hard-delete flags, or replace a canonical target's full list membership set. Other list memberships and historical results remain intact. Already absent memberships are harmless. Partial removals are reported and a later diff converges. Disabling or removing a local mapping leaves remote memberships untouched.
