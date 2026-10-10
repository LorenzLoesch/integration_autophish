#!/usr/bin/env bash
# Source only from a script with an EXIT cleanup trap. Never enable shell tracing.
set +x
umask 077
[[ -n ${APP_PRIVATE_KEY:-} && -n ${APP_CERTIFICATE:-} ]] || { printf 'Both signing secrets are required\n' >&2; exit 1; }
key_dir=$(mktemp -d)
printf '%s' "$APP_PRIVATE_KEY" > "$key_dir/key.pem"
printf '%s' "$APP_CERTIFICATE" > "$key_dir/cert.crt"
unset APP_PRIVATE_KEY APP_CERTIFICATE
php scripts/check-signing-material.php "$key_dir/key.pem" "$key_dir/cert.crt"
