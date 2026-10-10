# App Store screenshots

`admin-settings.png` and `sync-status.png` show the app's actual admin UI in a disposable Nextcloud 34 installation. Company, group, target-list and synchronization values are synthetic browser fixtures. No real account, API key, recipient data or AutoPhish API response is included. The status illustrates a successful synchronization; it is not evidence of a live tenant sync.

To refresh them, install the development archive in an isolated Nextcloud instance, open Administration settings → Additional settings, and supply synthetic responses for the app's settings endpoint in a browser test. Keep the actual template, JavaScript and Nextcloud styles unchanged. Capture the settings panel and the synchronization-status view as PNGs below 2 MiB each. Update `appinfo/info.xml` if filenames change; the store URLs point to these files on `main`.
