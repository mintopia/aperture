#!/usr/bin/env bash
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

traefik_container="${DEV_TRAEFIK_CONTAINER:-traefik}"
traefik_network="${DEV_TRAEFIK_NETWORK:-frontend}"
mode="${DEV_START_MODE:-auto}"

traefik_running() {
    docker ps --format '{{.Names}}' | grep -Fxq "${traefik_container}"
}

case "${mode}" in
    auto) traefik_running && mode=traefik || mode=ports ;;
    traefik)
        traefik_running || { echo "Error: Traefik container '${traefik_container}' is not running." >&2; exit 1; }
        ;;
    ports) ;;
    *) echo "Error: DEV_START_MODE must be one of: auto, traefik, ports." >&2; exit 1 ;;
esac

export COMPOSE_FILE="docker-compose.yaml:docker-compose.override.${mode}.yml"

if [[ "${mode}" == traefik ]]; then
    docker network inspect "${traefik_network}" >/dev/null 2>&1 || docker network create "${traefik_network}" >/dev/null
fi
