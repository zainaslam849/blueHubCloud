# Deployment Steps for Authorization Fix

## What Changed

1. **Controller Enhancement**: Added debug logging to `AdminWeeklyCallReportsController::show()` method
    - Logs all authentication attempts with guard status
    - Helps diagnose why "This action is unauthorized" appears on production
    - File: `app/Http/Controllers/Admin/AdminWeeklyCallReportsController.php`

2. **Troubleshooting Guide**: Created `PRODUCTION_AUTH_TROUBLESHOOTING.md`
    - Step-by-step guide to diagnose session/auth issues
    - Includes common root causes and solutions

---

## Deployment Procedure

### On Production Server

1. **Backup current code** (optional but recommended):

    ```bash
    cd /var/www/bluehubcloud
    git status  # Check for uncommitted changes
    ```

2. **Pull latest code**:

    ```bash
    git pull origin main
    ```

3. **Clear all caches** (this is critical):

    ```bash
    php artisan cache:clear
    php artisan config:cache
    php artisan route:cache
    php artisan session:flush
    ```

4. **Restart web server**:

    ```bash
    # For PHP-FPM
    sudo systemctl restart php8.3-fpm

    # For Apache
    sudo systemctl restart apache2

    # For Nginx
    sudo systemctl restart nginx
    ```

5. **Verify logs directory exists**:
    ```bash
    mkdir -p storage/logs
    chmod 775 storage/logs
    ```

### Immediate Testing

1. **Check logs for authentication info**:

    ```bash
    tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
    ```

2. **Access a report in your browser**:

    ```
    https://your-production-domain/admin/weekly-call-reports/1
    ```

3. **Look for log entries** showing authentication details:
    ```
    [2026-01-28 10:30:15] production.INFO: Weekly report access attempt {...}
    ```

---

## If Error Still Appears

If users still see "This action is unauthorized.":

1. **Follow the troubleshooting guide**:
    - Read `PRODUCTION_AUTH_TROUBLESHOOTING.md` in the repository root
    - Check logs for which guard (admin/web) is returning user data
    - Verify SESSION_DRIVER configuration

2. **Run diagnostics**:

    ```bash
    cd /var/www/bluehubcloud

    # Test session persistence
    php artisan tinker
    >>> Session::put('test', 'value')
    >>> Session::get('test')

    # Test user authentication
    >>> Auth::guard('admin')->user()
    >>> Auth::user()

    # Check database sessions if using database driver
    >>> DB::table('sessions')->count()
    ```

3. **Common Quick Fixes**:

    ```bash
    # If using database sessions, ensure table exists
    php artisan migrate --force

    # If sessions seem corrupted
    php artisan session:flush

    # If config seems stale
    php artisan config:clear && php artisan config:cache
    ```

---

## Monitoring After Deployment

**Watch logs for authentication patterns**:

```bash
# Monitor in real-time
tail -f storage/logs/laravel.log | grep "Weekly report"

# Or check the daily log
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
```

**Expected log output when user successfully accesses report**:

```json
{
    "message": "Weekly report access attempt",
    "report_id": 1,
    "admin_guard_user": {
        "id": 1,
        "email": "admin@example.com",
        "role": "admin"
    },
    "web_guard_user": null,
    "final_user": {
        "id": 1,
        "email": "admin@example.com",
        "role": "admin",
        "is_admin": true
    }
}
```

**Expected log output when authorization fails (user not admin)**:

```json
{
    "message": "Report access denied: user not admin",
    "report_id": 1,
    "user_id": 2,
    "user_role": "user"
}
```

---

## Rollback Steps (If Needed)

If something breaks:

```bash
cd /var/www/bluehubcloud
git revert HEAD  # Reverts the last commit
git push origin main
php artisan cache:clear && php artisan config:cache
sudo systemctl restart php8.3-fpm
```

---

## Files Changed

- `app/Http/Controllers/Admin/AdminWeeklyCallReportsController.php` — Added logging, improved auth flow
- `PRODUCTION_AUTH_TROUBLESHOOTING.md` — New troubleshooting guide (reference, not deployed code)

---

## Summary

These changes **do not alter the authorization logic**, they only:

- ✅ Add visibility into authentication/authorization decisions via logging
- ✅ Provide a comprehensive troubleshooting guide
- ✅ Make error debugging easier in production

The real fix for the recurring "This action is unauthorized." error depends on your production environment's session/cache configuration. Use the troubleshooting guide to identify which component is failing.
