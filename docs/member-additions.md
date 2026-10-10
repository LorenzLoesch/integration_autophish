# Member additions

Read each selected group through public Nextcloud APIs and every destination member page before adding recipients. Only enabled users' usable primary email and display name are considered. Emails use trim/lowercase normalization; duplicates are represented once, and missing/invalid emails are counted. Nextcloud exposes a display name, so it is sent as `firstName` (at most 100 characters), without guessing a surname. Names on existing canonical targets are preserved.

The public bulk-create API receives at most 200 members and less than 64 KiB per request. Payloads contain email and available name fields only. Verified recipient domains and the existing target key scopes are required. After adding, reread the destination to verify all intended memberships; an incomplete or failed addition is an incomplete reconciliation. Repeated runs diff the actual destination and add no duplicates.
