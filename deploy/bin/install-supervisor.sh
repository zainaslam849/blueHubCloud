#!/usr/bin/env bash
set -euo pipefail

APP_PATH_DEFAULT="/var/www/blueHubCloud"
APP_PATH="${APP_PATH:-$APP_PATH_DEFAULT}"

if [[ -f "${APP_PATH}/.env" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "${APP_PATH}/.env"
  set +a
fi

APP_PATH="${APP_PATH:-$APP_PATH_DEFAULT}"
APP_USER="${APP_USER:-www-data}"

SRC_FILE="${APP_PATH}/deploy/supervisor/bluehubcloud-horizon.conf"
DEST_FILE="/etc/supervisor/conf.d/bluehubcloud-horizon.conf"

if [[ ! -f "${SRC_FILE}" ]]; then
  echo "Supervisor template not found at ${SRC_FILE}" >&2
  exit 1
fi

sed \
  -e "s|__APP_PATH__|${APP_PATH}|g" \
  -e "s|__APP_USER__|${APP_USER}|g" \
  "${SRC_FILE}" > "${DEST_FILE}"

supervisorctl reread
supervisorctl update
supervisorctl start bluehubcloud-horizon

echo "Installed and started Supervisor config with APP_PATH=${APP_PATH}, APP_USER=${APP_USER}"
