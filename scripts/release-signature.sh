#!/usr/bin/env bash
set -euo pipefail
set +x
cd "$(dirname "$0")/.."
key_dir=''
trap 'if [[ -n $key_dir ]]; then rm -rf "$key_dir"; fi' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
source scripts/signing-material.sh
version=$(php -r 'echo simplexml_load_file("appinfo/info.xml")->version;')
archive="dist/integration_autophish-$version.tar.gz"
python3 scripts/check-artifact.py "$archive" --signed
openssl dgst -sha512 -sign "$key_dir/key.pem" "$archive" | openssl base64 -A > "$archive.sig"
openssl x509 -in "$key_dir/cert.crt" -pubkey -noout > "$key_dir/public.pem"
openssl base64 -d -A -in "$archive.sig" -out "$key_dir/signature.bin"
openssl dgst -sha512 -verify "$key_dir/public.pem" -signature "$key_dir/signature.bin" "$archive"
