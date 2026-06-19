#!/usr/bin/env bash
# Shared helpers for backup/restore scripts.

set -euo pipefail

readonly SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly PROJECT_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
readonly CONTAINER_NAME="iae-central-mock"
readonly DATA_MOUNT="/var/www/data"

resolve_volume_name() {
    local vol=""

    if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "${CONTAINER_NAME}"; then
        vol="$(docker inspect "${CONTAINER_NAME}" \
            --format '{{range .Mounts}}{{if eq .Destination "'"${DATA_MOUNT}"'"}}{{.Name}}{{end}}{{end}}' \
            2>/dev/null || true)"
    fi

    if [[ -z "${vol}" ]]; then
        vol="$(docker volume ls --format '{{.Name}}' 2>/dev/null | grep '_mock_data$' | head -1 || true)"
    fi

    if [[ -z "${vol}" ]]; then
        echo "Error: volume mock_data not found. Start the stack first: docker compose up -d" >&2
        return 1
    fi

    echo "${vol}"
}

is_mock_server_running() {
    docker ps --format '{{.Names}}' 2>/dev/null | grep -qx "${CONTAINER_NAME}"
}

timestamp_utc() {
    date -u +"%Y-%m-%dT%H:%M:%SZ"
}

timestamp_label() {
    date +"%Y%m%d-%H%M%S"
}
