#!/usr/bin/env bash
# Restore IAE Central mock server data from a backup archive.
#
# Stops mock-server briefly during restore; RabbitMQ keeps running.
#
# Usage:
#   ./scripts/restore.sh backups/iae-central-backup-20260619-120000.tar.gz
#   ./scripts/restore.sh --no-restart backup.tar.gz

set -euo pipefail

source "$(dirname "$0")/lib/common.sh"

ARCHIVE=""
NO_RESTART=0
SKIP_CONFIRM=0

usage() {
    cat <<'EOF'
Usage: ./scripts/restore.sh [options] BACKUP.tar.gz

Options:
      --no-restart   Restore files but do not stop/start mock-server (manual restart required)
  -y, --yes          Skip confirmation prompt
  -h, --help         Show this help
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --no-restart)
            NO_RESTART=1
            shift
            ;;
        -y|--yes)
            SKIP_CONFIRM=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        -*)
            echo "Unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
        *)
            if [[ -n "${ARCHIVE}" ]]; then
                echo "Error: only one archive path is allowed" >&2
                exit 1
            fi
            ARCHIVE="$1"
            shift
            ;;
    esac
done

if [[ -z "${ARCHIVE}" ]]; then
    usage >&2
    exit 1
fi

if [[ ! -f "${ARCHIVE}" ]]; then
    echo "Error: archive not found: ${ARCHIVE}" >&2
    exit 1
fi

cd "${PROJECT_ROOT}"

VOLUME="$(resolve_volume_name)"
STAGING="$(mktemp -d "${TMPDIR:-/tmp}/iae-restore.XXXXXX")"
trap 'rm -rf "${STAGING}"' EXIT

echo "==> Extracting ${ARCHIVE}"
tar -xzf "${ARCHIVE}" -C "${STAGING}"

if [[ ! -f "${STAGING}/manifest.json" ]]; then
    echo "Error: invalid backup — manifest.json missing" >&2
    exit 1
fi

echo "==> Backup manifest:"
cat "${STAGING}/manifest.json"
echo

if [[ "${SKIP_CONFIRM}" -eq 0 ]]; then
    echo "This will overwrite data in volume: ${VOLUME}"
    if [[ "${NO_RESTART}" -eq 0 ]]; then
        echo "mock-server will be stopped briefly during restore."
    else
        echo "mock-server will NOT be restarted automatically (--no-restart)."
    fi
    read -r -p "Continue? [y/N] " reply
    if [[ ! "${reply}" =~ ^[Yy]$ ]]; then
        echo "Aborted."
        exit 0
    fi
fi

WAS_RUNNING=0
if is_mock_server_running; then
    WAS_RUNNING=1
fi

if [[ "${NO_RESTART}" -eq 0 && "${WAS_RUNNING}" -eq 1 ]]; then
    echo "==> Stopping mock-server (rabbitmq stays up)"
    docker compose stop mock-server
fi

echo "==> Restoring volume: ${VOLUME}"
docker run --rm \
    -v "${VOLUME}:/dest" \
    -v "${STAGING}/data:/source:ro" \
    alpine:3.20 sh -eu -c '
        if [ -f /source/activity.db ]; then
            cp /source/activity.db /dest/activity.db
            chmod 664 /dest/activity.db 2>/dev/null || true
        fi
        if [ -d /source/keys ]; then
            rm -rf /dest/keys
            cp -a /source/keys /dest/keys
        fi
        chown -R 82:82 /dest 2>/dev/null || true
    '

if [[ -d "${STAGING}/config" ]]; then
    echo "==> Restoring config files on host"
    for file in api_keys.php citizens.php; do
        if [[ -f "${STAGING}/config/${file}" ]]; then
            cp "${STAGING}/config/${file}" "${PROJECT_ROOT}/config/${file}"
        fi
    done
    if [[ -f "${STAGING}/config/.env" ]]; then
        cp "${STAGING}/config/.env" "${PROJECT_ROOT}/.env"
    fi
fi

if [[ "${NO_RESTART}" -eq 0 && "${WAS_RUNNING}" -eq 1 ]]; then
    echo "==> Starting mock-server"
    docker compose start mock-server
elif [[ "${NO_RESTART}" -eq 1 && "${WAS_RUNNING}" -eq 1 ]]; then
    echo "==> Restore complete. Restart mock-server when ready:"
    echo "    docker compose restart mock-server"
else
    echo "==> Restore complete. Start the stack when ready:"
    echo "    docker compose up -d"
fi

echo "Done."
