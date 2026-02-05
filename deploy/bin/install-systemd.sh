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

SRC_DIR="${APP_PATH}/deploy/systemd"
DEST_DIR="/etc/systemd/system"

if [[ ! -d "${SRC_DIR}" ]]; then
  echo "Systemd templates not found at ${SRC_DIR}" >&2
  exit 1
fi

install_one() {
  local src="$1"
  local dest="$2"
  sed \
    -e "s|__APP_PATH__|${APP_PATH}|g" \
    -e "s|__APP_USER__|${APP_USER}|g" \
    "${src}" > "${dest}"
}

install_one "${SRC_DIR}/bluehubcloud-horizon.service" "${DEST_DIR}/bluehubcloud-horizon.service"
install_one "${SRC_DIR}/bluehubcloud-scheduler.service" "${DEST_DIR}/bluehubcloud-scheduler.service"
install_one "${SRC_DIR}/bluehubcloud-scheduler.timer" "${DEST_DIR}/bluehubcloud-scheduler.timer"

systemctl daemon-reload
systemctl enable --now bluehubcloud-horizon.service
systemctl enable --now bluehubcloud-scheduler.timer

echo "Installed and started Horizon + Scheduler with APP_PATH=${APP_PATH}, APP_USER=${APP_USER}"
