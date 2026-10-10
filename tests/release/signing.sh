#!/usr/bin/env bash
# Disposable test CA only. No real signing key, store token or external SaaS call.
set -euo pipefail
set +x
cd "$(dirname "$0")/../.."
root=$PWD
umask 077
temporary=$(mktemp -d)
image="autophish-signing-test:$$"
docker_bin=$(command -v docker)
cleanup() { "$docker_bin" image rm "$image" >/dev/null 2>&1 || true; rm -rf "$temporary"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$temporary/context" "$temporary/bin" "$temporary/project"
openssl req -x509 -newkey rsa:4096 -nodes -sha256 -days 1 -subj /CN=Disposable-test-root \
  -keyout "$temporary/ca.key" -out "$temporary/context/root.crt" >/dev/null 2>&1
openssl req -new -newkey rsa:4096 -nodes -subj /CN=integration_autophish \
  -keyout "$temporary/app.key" -out "$temporary/app.csr" >/dev/null 2>&1
openssl x509 -req -in "$temporary/app.csr" -CA "$temporary/context/root.crt" -CAkey "$temporary/ca.key" \
  -set_serial 1 -days 1 -sha256 -out "$temporary/app.crt" >/dev/null 2>&1
base=$(python3 scripts/release.py image)
printf 'FROM %s\nCOPY --chmod=644 root.crt /usr/src/nextcloud/resources/codesigning/root.crt\n' "$base" > "$temporary/context/Dockerfile"
"$docker_bin" build --network none -q -t "$image" "$temporary/context" >/dev/null
# Substitute the test image only in this disposable test's PATH. Release scripts have no trust bypass.
cat > "$temporary/bin/docker" <<'WRAPPER'
#!/usr/bin/env python3
import os, sys
args = [os.environ['TEST_IMAGE'] if arg == os.environ['TEST_BASE'] else arg for arg in sys.argv[1:]]
os.execv(os.environ['TEST_DOCKER'], [os.environ['TEST_DOCKER'], *args])
WRAPPER
chmod 700 "$temporary/bin/docker"
export TEST_IMAGE="$image" TEST_BASE="$base" TEST_DOCKER="$docker_bin"
for entry in appinfo lib templates js css img LICENSE license.md README.md CHANGELOG.md package.json package-lock.json scripts screenshots; do
  [[ ! -e $entry ]] || cp -R "$entry" "$temporary/project/"
done
cd "$temporary/project"
export APP_PRIVATE_KEY="$(cat "$temporary/app.key")" APP_CERTIFICATE="$(cat "$temporary/app.crt")" REQUIRE_SIGNING=true
original_path=$PATH
export PATH="$temporary/bin:$PATH"
bash scripts/package.sh
archive="dist/integration_autophish-$(python3 scripts/release.py version).tar.gz"
cp "$archive" "$temporary/first.tar.gz"
bash scripts/package.sh
cmp "$temporary/first.tar.gz" "$archive"
bash scripts/release-signature.sh
cp "$archive.sig" "$temporary/first.sig"
bash scripts/release-signature.sh
cmp "$temporary/first.sig" "$archive.sig"
# The identical certificate must be rejected by an unmodified official Nextcloud image.
rm -rf dist
export PATH="$original_path"
if bash scripts/package.sh > "$temporary/untrusted.log" 2>&1; then
  printf 'ERROR: official image accepted an untrusted test certificate\n' >&2; exit 1
fi
if ! grep -q 'Certificate is not valid' "$temporary/untrusted.log"; then cat "$temporary/untrusted.log" >&2; exit 1; fi
[[ ! -e $archive ]]
printf 'Synthetic signing: two identical verified archives/signatures; official trust rejection passed\n'
