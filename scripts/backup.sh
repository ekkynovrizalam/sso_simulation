#!/usr/bin/env bash
# Hot backup of IAE Central mock server data without stopping containers.
#
# Usage:
#   ./scripts/backup.sh
#   ./scripts/backup.sh -o /path/to/backup.tar.gz
#   ./scripts/backup.sh --no-config

set -euo pipefail

source "$(dirname "$0")/lib/common.sh"

INCLUDE_CONFIG=1
OUTPUT=""

usage() {
    cat <<'EOF'
Usage: ./scripts/backup.sh [options]

Options:
  -o, --output PATH   Output archive path (default: backups/iae-central-backup-YYYYMMDD-HHMMSS.tar.gz)
      --no-config     Skip .env and config/*.php from the archive
  -h, --help          Show this help
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        -o|--output)
            OUTPUT="$2"
            shift 2
            ;;
        --no-config)
            INCLUDE_CONFIG=0
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
    esac
done

cd "${PROJECT_ROOT}"

VOLUME="$(resolve_volume_name)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/iae-backup.XXXXXX")"
trap 'rm -rf "${STAGING}"' EXIT

STAGING_DATA="${STAGING}/data"
mkdir -p "${STAGING_DATA}"

echo "==> Backing up volume: ${VOLUME}"
if is_mock_server_running; then
    echo "    mock-server is running — using SQLite hot backup (no downtime)"
else
    echo "    mock-server is not running — copying files from volume"
fi

docker run --rm \
    -v "${VOLUME}:/source:ro" \
    -v "${STAGING_DATA}:/dest" \
    alpine:3.20 sh -eu -c '
        if [ -f /source/activity.db ]; then
            apk add --no-cache sqlite >/dev/null
            sqlite3 /source/activity.db ".backup /dest/activity.db"
        fi
        if [ -d /source/keys ]; then
            cp -a /source/keys /dest/keys
        fi
    '

if [[ ! -f "${STAGING_DATA}/activity.db" && ! -d "${STAGING_DATA}/keys" ]]; then
    echo "Warning: volume appears empty (no activity.db or keys/ yet)" >&2
fi

if [[ "${INCLUDE_CONFIG}" -eq 1 ]]; then
    CONFIG_DIR="${STAGING}/config"
    mkdir -p "${CONFIG_DIR}"

    for file in config/api_keys.php config/citizens.php; do
        if [[ -f "${PROJECT_ROOT}/${file}" ]]; then
            cp "${PROJECT_ROOT}/${file}" "${CONFIG_DIR}/"
        fi
    done

    if [[ -f "${PROJECT_ROOT}/.env" ]]; then
        cp "${PROJECT_ROOT}/.env" "${CONFIG_DIR}/"
    fi
fi

cat > "${STAGING}/manifest.json" <<EOF
{
  "app": "iae-central-mock",
  "created_at": "$(timestamp_utc)",
  "volume": "${VOLUME}",
  "mock_server_running": $(is_mock_server_running && echo true || echo false),
  "includes_config": $( [[ "${INCLUDE_CONFIG}" -eq 1 ]] && echo true || echo false )
}
EOF

if [[ -z "${OUTPUT}" ]]; then
    mkdir -p "${PROJECT_ROOT}/backups"
    OUTPUT="${PROJECT_ROOT}/backups/iae-central-backup-$(timestamp_label).tar.gz"
else
    mkdir -p "$(dirname "${OUTPUT}")"
fi

tar -czf "${OUTPUT}" -C "${STAGING}" .

echo "==> Backup saved: ${OUTPUT}"
echo "    Contains: activity.db (consistent snapshot), JWT keys, manifest"
if [[ "${INCLUDE_CONFIG}" -eq 1 ]]; then
    echo "    Also: .env and config PHP files (if present)"
fi
echo
echo "Keep this archive private — it may contain private keys and activity logs."
