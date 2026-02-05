# blueHubCloud

## Production queue & scheduler

Use Redis + Horizon for queue processing and a system scheduler for `schedule:run`.

### Option A: Supervisor (Horizon)

- Config: [deploy/supervisor/bluehubcloud-horizon.conf](deploy/supervisor/bluehubcloud-horizon.conf)
- Run: `supervisorctl reread && supervisorctl update && supervisorctl start bluehubcloud-horizon`
- Auto install: `sudo APP_PATH=/var/www/blueHubCloud APP_USER=www-data bash deploy/bin/install-supervisor.sh`

### Option B: systemd (Horizon + Scheduler)

- Horizon unit: [deploy/systemd/bluehubcloud-horizon.service](deploy/systemd/bluehubcloud-horizon.service)
- Scheduler unit: [deploy/systemd/bluehubcloud-scheduler.service](deploy/systemd/bluehubcloud-scheduler.service)
- Scheduler timer: [deploy/systemd/bluehubcloud-scheduler.timer](deploy/systemd/bluehubcloud-scheduler.timer)

Enable:

- `systemctl enable --now bluehubcloud-horizon`
- `systemctl enable --now bluehubcloud-scheduler.timer`

Auto install:

- `sudo APP_PATH=/var/www/blueHubCloud APP_USER=www-data bash deploy/bin/install-systemd.sh`

### Required env

- `QUEUE_CONNECTION=redis`
- Redis connection vars (`REDIS_HOST`, `REDIS_PORT`, etc.)
- Horizon vars (see [.env.example](.env.example))
- `APP_PATH=/var/www/blueHubCloud` (used by deploy scripts to locate the app)
