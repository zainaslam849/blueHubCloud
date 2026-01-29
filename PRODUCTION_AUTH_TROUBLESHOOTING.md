# Production Authorization Error Troubleshooting

## Error: "This action is unauthorized." on `/admin/api/weekly-call-reports/{id}`

### Overview

This guide helps diagnose why the authorization error appears on production but not locally.

---

## Quick Checklist

- [ ] Verify `.env` configuration (SESSION_DRIVER, CACHE_DRIVER)
- [ ] Check if `sessions` table exists and is clean
- [ ] Clear all caches
- [ ] Verify admin guard configuration
- [ ] Check application logs for debug info
- [ ] Verify user has admin role

---

## Step 1: Check Production `.env` Configuration

SSH into production (`/var/www/bluehubcloud/`) and verify critical settings:

```bash
cd /var/www/bluehubcloud
grep "SESSION_DRIVER\|CACHE_DRIVER\|QUEUE_CONNECTION\|APP_DEBUG" .env
```

**Expected values:**

```
APP_DEBUG=false  # Set to true temporarily for debugging
SESSION_DRIVER=database  # or file (NOT cache without proper setup)
CACHE_DRIVER=file  # or redis if properly configured
```

**Common issues:**

- `SESSION_DRIVER=cache` without working cache backend → sessions lost
- `SESSION_DRIVER=redis` but Redis not running → authentication fails
- `SESSION_DRIVER=memcached` but Memcached not configured → users not authenticated

---

## Step 2: Verify Session Table

If using `SESSION_DRIVER=database`, the sessions table must exist:

```bash
php artisan migrate --force
```

Check the table:

```bash
php artisan tinker
>>> DB::table('sessions')->count()
>>> DB::table('sessions')->first()
```

If sessions table is corrupted or full, flush it:

```bash
php artisan session:flush
```

---

## Step 3: Clear All Caches

Production caches can cause stale auth config:

```bash
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan session:flush
```

Then restart the web server:

```bash
# If using PHP-FPM
sudo systemctl restart php8.3-fpm

# If using Apache
sudo systemctl restart apache2

# If using Nginx
sudo systemctl restart nginx
```

---

## Step 4: Verify Admin Guard Configuration

Check that the admin guard uses the correct session driver:

```bash
cat config/auth.php | grep -A 10 "'admin'"
```

Must show:

```php
'admin' => [
    'driver' => 'session',  // Should be 'session'
    'provider' => 'users',
],
```

---

## Step 5: Check Application Logs

The updated controller logs all authentication attempts. Check logs:

```bash
# View recent logs
tail -f storage/logs/laravel.log

# Or check daily log
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log
```

Look for entries like:

```
[2026-01-28 10:30:15] local.INFO: Weekly report access attempt {"report_id":7,"admin_guard_user":{"id":1,"email":"admin@example.com","role":"admin"},...}
```

**Key fields to check:**

- `admin_guard_user`: Should NOT be null for logged-in admins
- `web_guard_user`: Fallback if admin guard fails
- `final_user`: Should be non-null and admin
- `is_admin`: Should be true

**If `admin_guard_user` is always null:**

- Session not persisting
- Admin guard not configured for session
- User logged out between requests

---

## Step 6: Verify User has Admin Role

```bash
php artisan tinker
>>> $user = User::find(1);
>>> $user->role
>>> $user->isAdmin()
```

If user role is incorrect:

```bash
>>> $user->update(['role' => 'admin'])
```

---

## Step 7: Debug Session Persistence

Test if sessions persist across requests:

```bash
# Start tinker
php artisan tinker

# Set a test session value
>>> Session::put('test_key', 'test_value')
>>> Session::get('test_key')

# Exit tinker and make an HTTP request to the API
# Sessions should still be retrievable
```

---

## Step 8: Check Web Server Session Permissions

Web server must be able to write to session storage:

```bash
# If using file-based sessions
ls -la storage/framework/sessions/
sudo chown www-data:www-data storage/framework/sessions/ -R
sudo chmod 755 storage/framework/sessions/ -R

# If using database sessions
php artisan migrate --force
```

---

## Advanced Debugging

If the above steps don't work, enable temporary debug mode:

1. Set `APP_DEBUG=true` in `.env`
2. Try accessing the report again
3. Check `/storage/logs/laravel.log` for detailed error trace
4. Look for which component is failing (guard, session, database, etc.)
5. Set `APP_DEBUG=false` again (never leave true in production)

---

## Common Root Causes

### Cause 1: Session Driver Misconfiguration

**Symptom:** `admin_guard_user` is always null, even after login
**Solution:** Ensure `SESSION_DRIVER=database` or `SESSION_DRIVER=file` and restart web server

### Cause 2: Database Sessions Not Migrated

**Symptom:** Sessions table doesn't exist
**Solution:** Run `php artisan migrate --force`

### Cause 3: Cache Backend Down

**Symptom:** `SESSION_DRIVER=redis` but Redis not running
**Solution:** Start Redis or change to `SESSION_DRIVER=file`

### Cause 4: Stale Config Cache

**Symptom:** Old configuration is being used
**Solution:** Run `php artisan config:cache` and restart web server

### Cause 5: User Lost Admin Role

**Symptom:** `is_admin` is false even for admin users
**Solution:** Verify user role in database or re-seed admin user

---

## Contact

If issues persist, check the logs in `/storage/logs/laravel.log` and provide:

1. Recent log entries from the authorization attempt
2. Output of `php artisan tinker` session tests
3. Output of `grep SESSION_DRIVER .env`
4. Output of `php artisan migrate:status`
