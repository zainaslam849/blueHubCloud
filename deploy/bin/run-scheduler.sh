#!/usr/bin/env bash
set -euo pipefail

APP_ROOT_DEFAULT="/var/www/blueHubCloud"
ENV_FILE="${APP_ROOT_DEFAULT}/.env"

if [[ -n "${APP_PATH:-}" ]]; then
  APP_ROOT="${APP_PATH}"
  ENV_FILE="${APP_ROOT}/.env"
else
  APP_ROOT="${APP_ROOT_DEFAULT}"
fi

if [[ -f "${ENV_FILE}" ]]; then
  set -a
  # shellcheck disable=SC1090
  . "${ENV_FILE}"
  set +a
fi

APP_ROOT="${APP_PATH:-${APP_ROOT}}"

exec /usr/bin/php "${APP_ROOT}/artisan" schedule:run
