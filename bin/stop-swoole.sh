#!/usr/bin/env bash
set -euo pipefail

NAME=${SWOOLE_CONTAINER_NAME:-let-me-see-swoole}

if ! command -v docker >/dev/null 2>&1; then
  echo "docker: command not found" >&2
  exit 1
fi

if ! docker ps -aq --filter "name=^${NAME}$" >/dev/null 2>&1; then
  echo "No container named '${NAME}' exists." >&2
  exit 1
fi

if [ -z "$(docker ps -q --filter "name=^${NAME}$")" ]; then
  echo "Container '${NAME}' is not running." >&2
  exit 1
fi

docker stop "$NAME"
