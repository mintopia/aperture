#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${ROOT_DIR}"

STATE_DIR="${ROOT_DIR}/.dev-env"
PID_DIR="${STATE_DIR}/pids"
MODE_FILE="${STATE_DIR}/mode"

START_MODE="${DEV_START_MODE:-auto}"
TRAEFIK_CONTAINER="${DEV_TRAEFIK_CONTAINER:-traefik}"

APP_HOSTNAME="${PUBLIC_APP_HOSTNAME:-hallowed-rincewind.ws.cloudagent.mintopia.net}"
REVERB_HOSTNAME="${PUBLIC_REVERB_HOSTNAME:-reverb.hallowed-rincewind.ws.cloudagent.mintopia.net}"
VITE_HOSTNAME="${PUBLIC_VITE_HOSTNAME:-vite.hallowed-rincewind.ws.cloudagent.mintopia.net}"
SSH_PROXY_HOSTNAME="${PUBLIC_SSH_PROXY_HOSTNAME:-ssh-proxy.hallowed-rincewind.ws.cloudagent.mintopia.net}"

LOCAL_APP_HOST="${DEV_APP_HOST:-127.0.0.1}"
LOCAL_APP_PORT="${DEV_APP_PORT:-8000}"
LOCAL_REVERB_HOST="${DEV_REVERB_HOST:-127.0.0.1}"
LOCAL_REVERB_PORT="${DEV_REVERB_PORT:-8080}"
LOCAL_VITE_HOST="${DEV_VITE_HOST:-127.0.0.1}"
LOCAL_VITE_PORT="${DEV_VITE_PORT:-5173}"
LOCAL_VITE_SCHEME="${DEV_VITE_SCHEME:-https}"
LOCAL_SSH_PROXY_HOST="${DEV_SSH_PROXY_HOST:-127.0.0.1}"
LOCAL_SSH_PROXY_PORT="${DEV_SSH_PROXY_PORT:-8022}"

compose_available() {
    if docker compose version >/dev/null 2>&1; then
        COMPOSE=(docker compose)
    elif command -v docker-compose >/dev/null 2>&1; then
        COMPOSE=(docker-compose)
    else
        return 1
    fi

    if ! docker info >/dev/null 2>&1; then
        return 1
    fi

    return 0
}

is_traefik_running() {
    docker ps --format '{{.Names}}' 2>/dev/null | grep -Fxq "${TRAEFIK_CONTAINER}"
}

determine_compose_mode() {
    case "${START_MODE}" in
        auto)
            if is_traefik_running; then
                echo "traefik"
            else
                echo "ports"
            fi
            ;;
        traefik|ports)
            echo "${START_MODE}"
            ;;
        *)
            echo "ports"
            ;;
    esac
}

determine_runtime_mode() {
    if [[ -f "${MODE_FILE}" ]]; then
        local recorded
        recorded="$(cat "${MODE_FILE}" 2>/dev/null || true)"
        if [[ "${recorded}" == "local" ]]; then
            echo "local"
            return
        fi
        if [[ "${recorded}" == "compose" ]]; then
            if compose_available; then
                echo "compose"
                return
            fi
            echo "local"
            return
        fi
    fi

    if compose_available; then
        echo "compose"
    else
        echo "local"
    fi
}

check_tcp() {
    local host="$1"
    local port="$2"
    timeout 2 bash -c ":</dev/tcp/${host}/${port}" >/dev/null 2>&1
}

print_row() {
    printf '%-10s %-12s %-8s %-45s %-8s\n' "$1" "$2" "$3" "$4" "$5"
}

status_for_local_service() {
    local service="$1"
    local endpoint="$2"
    local probe_host="$3"
    local probe_port="$4"
    local pid_file="${PID_DIR}/${service}.pid"
    local source="not running"
    local pid="-"
    local health="down"

    if [[ -f "${pid_file}" ]]; then
        local pid_value
        pid_value="$(cat "${pid_file}" 2>/dev/null || true)"

        if [[ "${pid_value}" == external:* ]]; then
            source="external"
            if [[ "${pid_value}" =~ ^external:([^:]+):([0-9]+)$ ]]; then
                probe_host="${BASH_REMATCH[1]}"
                probe_port="${BASH_REMATCH[2]}"
                endpoint="${probe_host}:${probe_port}"
            fi
        elif [[ "${pid_value}" =~ ^[0-9]+$ ]] && kill -0 "${pid_value}" >/dev/null 2>&1; then
            source="managed"
            pid="${pid_value}"
        fi
    fi

    if [[ "${source}" != "not running" ]]; then
        if [[ -n "${probe_host}" && -n "${probe_port}" ]]; then
            if check_tcp "${probe_host}" "${probe_port}"; then
                health="ok"
            else
                health="degraded"
            fi
        else
            health="ok"
        fi
    fi

    print_row "${service}" "${source}" "${pid}" "${endpoint}" "${health}"
}

status_for_compose_service() {
    local service="$1"
    local endpoint="$2"
    local probe_host="$3"
    local probe_port="$4"
    local source="not running"
    local pid="-"
    local health="down"

    local container_id
    container_id="$("${COMPOSE[@]}" -f docker-compose.yaml ps -q "${service}" 2>/dev/null || true)"
    if [[ -n "${container_id}" ]]; then
        local state
        state="$(docker inspect -f '{{.State.Status}}' "${container_id}" 2>/dev/null || true)"
        if [[ "${state}" == "running" ]]; then
            source="managed"
            pid="$(docker inspect -f '{{.State.Pid}}' "${container_id}" 2>/dev/null || true)"
            if [[ -z "${probe_host}" || -z "${probe_port}" ]]; then
                health="ok"
            elif check_tcp "${probe_host}" "${probe_port}"; then
                health="ok"
            else
                health="degraded"
            fi
        fi
    fi

    print_row "${service}" "${source}" "${pid:-"-"}" "${endpoint}" "${health}"
}

RUNTIME_MODE="$(determine_runtime_mode)"

ENDPOINT_APERTURE="-"
ENDPOINT_REVERB="-"
ENDPOINT_VITE="-"
ENDPOINT_SSH_PROXY="-"
APERTURE_HOST=""
APERTURE_PORT=""
REVERB_HOST=""
REVERB_PORT=""
VITE_HOST=""
VITE_PORT=""
SSH_PROXY_HOST=""
SSH_PROXY_PORT=""

if [[ "${RUNTIME_MODE}" == "local" ]]; then
    ENDPOINT_APERTURE="http://${LOCAL_APP_HOST}:${LOCAL_APP_PORT}"
    ENDPOINT_REVERB="ws://${LOCAL_REVERB_HOST}:${LOCAL_REVERB_PORT}"
    ENDPOINT_VITE="${LOCAL_VITE_SCHEME}://${LOCAL_VITE_HOST}:${LOCAL_VITE_PORT}"
    ENDPOINT_SSH_PROXY="http://${LOCAL_SSH_PROXY_HOST}:${LOCAL_SSH_PROXY_PORT}"
    APERTURE_HOST="${LOCAL_APP_HOST}"
    APERTURE_PORT="${LOCAL_APP_PORT}"
    REVERB_HOST="${LOCAL_REVERB_HOST}"
    REVERB_PORT="${LOCAL_REVERB_PORT}"
    VITE_HOST="${LOCAL_VITE_HOST}"
    VITE_PORT="${LOCAL_VITE_PORT}"
    SSH_PROXY_HOST="${LOCAL_SSH_PROXY_HOST}"
    SSH_PROXY_PORT="${LOCAL_SSH_PROXY_PORT}"
else
    COMPOSE_MODE="$(determine_compose_mode)"
    if [[ "${COMPOSE_MODE}" == "traefik" ]]; then
        ENDPOINT_APERTURE="https://${APP_HOSTNAME}"
        ENDPOINT_REVERB="wss://${REVERB_HOSTNAME}"
        ENDPOINT_VITE="https://${VITE_HOSTNAME}"
        ENDPOINT_SSH_PROXY="https://${SSH_PROXY_HOSTNAME}"
        APERTURE_HOST="${APP_HOSTNAME}"
        APERTURE_PORT="443"
        REVERB_HOST="${REVERB_HOSTNAME}"
        REVERB_PORT="443"
        VITE_HOST="${VITE_HOSTNAME}"
        VITE_PORT="443"
        SSH_PROXY_HOST="${SSH_PROXY_HOSTNAME}"
        SSH_PROXY_PORT="443"
    else
        ENDPOINT_APERTURE="http://127.0.0.1:8000"
        ENDPOINT_REVERB="ws://127.0.0.1:8080"
        ENDPOINT_VITE="http://127.0.0.1:5173"
        ENDPOINT_SSH_PROXY="http://127.0.0.1:8022"
        APERTURE_HOST="127.0.0.1"
        APERTURE_PORT="8000"
        REVERB_HOST="127.0.0.1"
        REVERB_PORT="8080"
        VITE_HOST="127.0.0.1"
        VITE_PORT="5173"
        SSH_PROXY_HOST="127.0.0.1"
        SSH_PROXY_PORT="8022"
    fi
fi

echo "Dev status (runtime=${RUNTIME_MODE})"
print_row "service" "source" "pid" "endpoint" "health"
print_row "--------" "------" "---" "--------" "------"

if [[ "${RUNTIME_MODE}" == "local" ]]; then
    status_for_local_service "aperture" "${ENDPOINT_APERTURE}" "${APERTURE_HOST}" "${APERTURE_PORT}"
    status_for_local_service "reverb" "${ENDPOINT_REVERB}" "${REVERB_HOST}" "${REVERB_PORT}"
    status_for_local_service "horizon" "-" "" ""
    status_for_local_service "scheduler" "-" "" ""
    status_for_local_service "vite" "${ENDPOINT_VITE}" "${VITE_HOST}" "${VITE_PORT}"
    status_for_local_service "ssh-proxy" "${ENDPOINT_SSH_PROXY}" "${SSH_PROXY_HOST}" "${SSH_PROXY_PORT}"
else
    status_for_compose_service "aperture" "${ENDPOINT_APERTURE}" "${APERTURE_HOST}" "${APERTURE_PORT}"
    status_for_compose_service "reverb" "${ENDPOINT_REVERB}" "${REVERB_HOST}" "${REVERB_PORT}"
    status_for_compose_service "horizon" "-" "" ""
    status_for_compose_service "scheduler" "-" "" ""
    status_for_compose_service "vite" "${ENDPOINT_VITE}" "${VITE_HOST}" "${VITE_PORT}"
    status_for_compose_service "ssh-proxy" "${ENDPOINT_SSH_PROXY}" "${SSH_PROXY_HOST}" "${SSH_PROXY_PORT}"
fi
