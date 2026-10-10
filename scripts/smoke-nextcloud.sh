#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
major=${NEXTCLOUD_MAJOR:-32}
image=$(python3 scripts/release.py image "$major")
version=$(php -r 'echo simplexml_load_file("appinfo/info.xml")->version;')
archive=$(realpath "${RELEASE_ARCHIVE:-dist/integration_autophish-$version-unsigned.tar.gz}")
fixture=$(realpath tests/smoke)
database=${NEXTCLOUD_SMOKE_DB:-sqlite}
network='none'
pg_name=''
nc_name="autophish-smoke-nextcloud-$RANDOM-$$"
cleanup() {
  docker rm -fv "$nc_name" >/dev/null 2>&1 || true
  if [[ -n $pg_name ]]; then docker rm -fv "$pg_name" >/dev/null 2>&1 || true; fi
  if [[ $network != none ]]; then docker network rm "$network" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if [[ $database == pgsql || $database == mysql ]]; then
  network="autophish-smoke-$RANDOM-$$"
  pg_name="$network-db"
  docker network create --internal "$network" >/dev/null
  if [[ $database == pgsql ]]; then
    docker run -d --name "$pg_name" --network "$network" --user 999:999 --cap-drop ALL --security-opt no-new-privileges \
      --tmpfs /var/lib/postgresql/data:uid=999,gid=999,mode=700 \
      -e POSTGRES_PASSWORD=mock-local-db-only -e POSTGRES_USER=smoke -e POSTGRES_DB=nextcloud postgres:17@sha256:d74eeac9a635390a49bc21bd49fccd973de707e2a53a76ac49b552b8712ec46f >/dev/null
    for attempt in {1..30}; do
      if docker exec --user 999:999 "$pg_name" pg_isready -U smoke -d nextcloud >/dev/null; then break; fi
      sleep 1
    done
  else
    docker run -d --name "$pg_name" --network "$network" --user 999:999 --cap-drop ALL --security-opt no-new-privileges \
      --tmpfs /var/lib/mysql:uid=999,gid=999,mode=700 --tmpfs /run/mysqld:uid=999,gid=999,mode=755 \
      -e MARIADB_ROOT_PASSWORD=mock-root-db-only -e MARIADB_PASSWORD=mock-local-db-only -e MARIADB_USER=smoke -e MARIADB_DATABASE=nextcloud \
      mariadb:11.4@sha256:1292844148b311e4ed4300022a996d39083f415a963e970cf47cad1b3b18e3a6 >/dev/null
    for attempt in {1..60}; do
      if docker exec --user 999:999 "$pg_name" healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
      sleep 1
    done
  fi
elif [[ $database != sqlite ]]; then
  echo 'NEXTCLOUD_SMOKE_DB must be sqlite, pgsql or mysql' >&2; exit 1
fi
# Explicit non-root users, no egress, no capabilities, disposable state.
# PostgreSQL runs on an internal network with no published ports.
docker run --rm --name "$nc_name" --network "$network" --user 33:33 --cap-drop ALL --security-opt no-new-privileges \
  -e "NEXTCLOUD_MAJOR=$major" -e "SMOKE_DATABASE=$database" -e "SMOKE_DB_HOST=$pg_name" \
  --entrypoint bash --mount "type=bind,src=$archive,dst=/release.tar.gz,readonly" \
  --mount "type=bind,src=$fixture,dst=/probes,readonly" "$image" -eu /probes/run.sh
