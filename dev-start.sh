#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "${ROOT_DIR}"

COMPOSE_FILES=(-f docker-compose.yaml)
SERVICES=(db redis aperture reverb horizon scheduler vite ssh-proxy)
STATE_DIR="${ROOT_DIR}/.dev-env"
PID_DIR="${STATE_DIR}/pids"
LOG_DIR="${STATE_DIR}/logs"
MODE_FILE="${STATE_DIR}/mode"
START_MODE="${DEV_START_MODE:-auto}"
TRAEFIK_CONTAINER="${DEV_TRAEFIK_CONTAINER:-traefik}"
TRAEFIK_NETWORK="${DEV_TRAEFIK_NETWORK:-frontend}"
ACTIVE_MODE=""
RUN_MODE=""

LOCAL_APP_HOST="${DEV_APP_HOST:-0.0.0.0}"
LOCAL_APP_PORT="${DEV_APP_PORT:-8000}"
LOCAL_REVERB_HOST="${DEV_REVERB_HOST:-0.0.0.0}"
LOCAL_REVERB_PORT="${DEV_REVERB_PORT:-8080}"
LOCAL_VITE_HOST="${DEV_VITE_HOST:-0.0.0.0}"
LOCAL_VITE_PORT="${DEV_VITE_PORT:-5173}"
LOCAL_VITE_SCHEME="${DEV_VITE_SCHEME:-https}"
LOCAL_SSH_PROXY_HOST="${DEV_SSH_PROXY_HOST:-127.0.0.1}"
LOCAL_SSH_PROXY_PORT="${DEV_SSH_PROXY_PORT:-8022}"

export CADDY_TRUSTED_PROXIES="${CADDY_TRUSTED_PROXIES:-0.0.0.0/0 ::/0}"

if [[ -x "${ROOT_DIR}/bin/frankenphp" ]]; then
    export PATH="${ROOT_DIR}/bin:${PATH}"
    export PHPRC="${ROOT_DIR}/docker/develop/php-dev.ini"
fi

if [[ -z "${APERTURE_SSH_PROXY_API_KEY:-}" && -f "${ROOT_DIR}/.env" ]]; then
    raw_ssh_proxy_key="$(grep -m1 '^APERTURE_SSH_PROXY_API_KEY=' "${ROOT_DIR}/.env" | cut -d= -f2- || true)"
    raw_ssh_proxy_key="${raw_ssh_proxy_key%\"}"
    raw_ssh_proxy_key="${raw_ssh_proxy_key#\"}"
    raw_ssh_proxy_key="${raw_ssh_proxy_key%\'}"
    raw_ssh_proxy_key="${raw_ssh_proxy_key#\'}"
    if [[ -n "${raw_ssh_proxy_key}" ]]; then
        APERTURE_SSH_PROXY_API_KEY="${raw_ssh_proxy_key}"
    fi
fi

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

is_pid_running() {
    local pid_file="$1"
    if [[ ! -f "${pid_file}" ]]; then
        return 1
    fi

    local pid
    pid="$(cat "${pid_file}" 2>/dev/null || true)"
    [[ "${pid}" =~ ^[0-9]+$ ]] || return 1
    kill -0 "${pid}" >/dev/null 2>&1
}

is_tcp_reachable() {
    local host="$1"
    local port="$2"
    timeout 2 bash -c ":</dev/tcp/${host}/${port}" >/dev/null 2>&1
}

detect_listener_pid() {
    local port="$1"
    local pid=""

    if command -v ss >/dev/null 2>&1; then
        pid="$(ss -ltnp "sport = :${port}" 2>/dev/null | awk -F'pid=' 'NR>1 {split($2,a,","); if (a[1] ~ /^[0-9]+$/) {print a[1]; exit}}')"
    fi

    if [[ -z "${pid}" ]] && command -v lsof >/dev/null 2>&1; then
        pid="$(lsof -t -iTCP:"${port}" -sTCP:LISTEN 2>/dev/null | head -n1 || true)"
    fi

    if [[ "${pid}" =~ ^[0-9]+$ ]]; then
        echo "${pid}"
        return 0
    fi

    return 1
}

start_local_service() {
    local service="$1"
    local command="$2"
    local probe_host="${3:-}"
    local probe_port="${4:-}"
    local pid_file="${PID_DIR}/${service}.pid"
    local log_file="${LOG_DIR}/${service}.log"

    if is_pid_running "${pid_file}"; then
        echo "[local] ${service} already running (pid $(cat "${pid_file}"))."
        return 0
    fi

    rm -f "${pid_file}"
    touch "${log_file}"

    echo "[local] starting ${service}..."
    nohup bash -lc "cd '${ROOT_DIR}' && ${command}" >> "${log_file}" 2>&1 &
    local pid=$!
    echo "${pid}" > "${pid_file}"

    sleep 1
    if ! kill -0 "${pid}" >/dev/null 2>&1; then
        if [[ -n "${probe_host}" && -n "${probe_port}" ]] && is_tcp_reachable "${probe_host}" "${probe_port}"; then
            if listener_pid="$(detect_listener_pid "${probe_port}")"; then
                echo "[local] ${service} already listening on ${probe_host}:${probe_port}; adopting pid ${listener_pid}."
                echo "${listener_pid}" > "${pid_file}"
                return 0
            fi

            echo "[local] ${service} already listening on ${probe_host}:${probe_port}; keeping external process."
            echo "external:${probe_host}:${probe_port}" > "${pid_file}"
            return 0
        fi
        echo "Error: failed to start local service '${service}'. Check ${log_file}" >&2
        rm -f "${pid_file}"
        return 1
    fi
}

mkdir -p "${PID_DIR}" "${LOG_DIR}"

is_traefik_running() {
    docker ps --format '{{.Names}}' | grep -Fxq "${TRAEFIK_CONTAINER}"
}

apply_traefik_mode() {
    COMPOSE_FILES+=(-f docker-compose.override.traefik.yml)
    if ! docker network inspect "${TRAEFIK_NETWORK}" >/dev/null 2>&1; then
        docker network create "${TRAEFIK_NETWORK}" >/dev/null
    fi
}

if compose_available; then
    RUN_MODE="compose"
    case "${START_MODE}" in
        auto)
            if is_traefik_running; then
                apply_traefik_mode
                ACTIVE_MODE="traefik"
                echo "Using Traefik mode (${TRAEFIK_CONTAINER})."
            else
                COMPOSE_FILES+=(-f docker-compose.override.ports.yml)
                ACTIVE_MODE="ports"
                echo "Traefik container '${TRAEFIK_CONTAINER}' is not running; using local port fallback."
                echo "Tip: start Traefik or run DEV_START_MODE=traefik ./dev-start.sh for strict mode."
            fi
            ;;
        traefik)
            if ! is_traefik_running; then
                echo "Error: Traefik container '${TRAEFIK_CONTAINER}' is not running." >&2
                echo "Start Traefik first, or run DEV_START_MODE=ports ./dev-start.sh for local ports." >&2
                exit 1
            fi
            apply_traefik_mode
            ACTIVE_MODE="traefik"
            echo "Using Traefik mode (${TRAEFIK_CONTAINER})."
            ;;
        ports)
            COMPOSE_FILES+=(-f docker-compose.override.ports.yml)
            ACTIVE_MODE="ports"
            echo "Using local port mode."
            ;;
        *)
            echo "Error: DEV_START_MODE must be one of: auto, traefik, ports." >&2
            exit 1
            ;;
    esac

    "${COMPOSE[@]}" "${COMPOSE_FILES[@]}" up -d "${SERVICES[@]}"

    failed_services=()
    for service in "${SERVICES[@]}"; do
        container_id="$("${COMPOSE[@]}" "${COMPOSE_FILES[@]}" ps -q "${service}")"
        if [[ -n "${container_id}" ]]; then
            container_status="$(docker inspect -f '{{.State.Status}}' "${container_id}")"
            if [[ "${container_status}" != "running" ]]; then
                failed_services+=("${service}")
                continue
            fi
            docker inspect -f '{{.State.Pid}}' "${container_id}" > "${PID_DIR}/${service}.pid"
            "${COMPOSE[@]}" "${COMPOSE_FILES[@]}" logs --no-color --tail=200 "${service}" > "${LOG_DIR}/${service}.log" || true
        else
            failed_services+=("${service}")
        fi
    done

    if [[ "${#failed_services[@]}" -gt 0 ]]; then
        echo "Error: failed to start services: ${failed_services[*]}" >&2
        echo "Run: ${COMPOSE[*]} ${COMPOSE_FILES[*]} ps" >&2
        exit 1
    fi
else
    RUN_MODE="local"
    ACTIVE_MODE="local"
    echo "docker compose unavailable; using local process mode."
    echo "Logs: ${LOG_DIR}"

    failed_services=()

    start_local_service "aperture" "php artisan octane:frankenphp --host=${LOCAL_APP_HOST} --port=${LOCAL_APP_PORT} --caddyfile=docker/Caddyfile --max-requests=1 --watch --poll" "${LOCAL_APP_HOST}" "${LOCAL_APP_PORT}" || failed_services+=("aperture")
    start_local_service "reverb" "php artisan reverb:start --host=${LOCAL_REVERB_HOST} --port=${LOCAL_REVERB_PORT}" "${LOCAL_REVERB_HOST}" "${LOCAL_REVERB_PORT}" || failed_services+=("reverb")
    start_local_service "horizon" "php artisan horizon" || failed_services+=("horizon")
    start_local_service "scheduler" "php artisan schedule:work" || failed_services+=("scheduler")
    start_local_service "vite" "env VITE_HMR_HOST='${PUBLIC_VITE_HOSTNAME:-vite.hallowed-rincewind.ws.cloudagent.mintopia.net}' VITE_HMR_PORT='${DEV_VITE_HMR_PORT:-443}' VITE_HMR_PROTOCOL='${DEV_VITE_HMR_PROTOCOL:-wss}' npm run dev -- --host=${LOCAL_VITE_HOST} --port=${LOCAL_VITE_PORT} --strictPort" "${LOCAL_VITE_HOST}" "${LOCAL_VITE_PORT}" || failed_services+=("vite")
    if [[ -z "${APERTURE_SSH_PROXY_API_KEY:-}" ]]; then
        echo "Warning: APERTURE_SSH_PROXY_API_KEY is empty. SSH proxy auth may return 401."
    fi
    start_local_service "ssh-proxy" "cd ssh-proxy && env SSH_PROXY_API_KEY='${APERTURE_SSH_PROXY_API_KEY:-}' SSH_PROXY_LISTEN_ADDR=0.0.0.0:${LOCAL_SSH_PROXY_PORT} go run ." "${LOCAL_SSH_PROXY_HOST}" "${LOCAL_SSH_PROXY_PORT}" || failed_services+=("ssh-proxy")

    if [[ "${#failed_services[@]}" -gt 0 ]]; then
        echo "Error: failed to start local services: ${failed_services[*]}" >&2
        exit 1
    fi
fi

echo "${RUN_MODE}" > "${MODE_FILE}"

echo "Dev environment started (${RUN_MODE} mode)."
echo "PIDs: ${PID_DIR}"
echo "Logs: ${LOG_DIR}"
if [[ "${RUN_MODE}" == "local" ]]; then
    echo "App:    http://${LOCAL_APP_HOST}:${LOCAL_APP_PORT}"
    echo "Vite:   ${LOCAL_VITE_SCHEME}://${LOCAL_VITE_HOST}:${LOCAL_VITE_PORT}"
    echo "Reverb: http://${LOCAL_REVERB_HOST}:${LOCAL_REVERB_PORT}"
    echo "SSH Proxy: http://${LOCAL_SSH_PROXY_HOST}:${LOCAL_SSH_PROXY_PORT}"
elif [[ "${ACTIVE_MODE}" == "ports" ]]; then
    echo "App:   http://127.0.0.1:8000"
    echo "Vite:  http://127.0.0.1:5173"
    echo "Reverb ws endpoint: ws://127.0.0.1:8080"
    echo "SSH Proxy: http://127.0.0.1:8022"
else
    echo "App:   https://${PUBLIC_APP_HOSTNAME}"
    echo "Vite:  https://${PUBLIC_VITE_HOSTNAME}"
    echo "Reverb: wss://${PUBLIC_REVERB_HOSTNAME}"
    echo "SSH Proxy: https://${PUBLIC_SSH_PROXY_HOSTNAME}"
fi
