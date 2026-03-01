# Tenant Sync Verification Guide

## How to Verify Automated Tenant Sync is Working

### 1. Check Scheduler is Registered

```bash
php artisan schedule:list
```

Look for the `pbx:sync-tenants` command running hourly. You should see output like:

```
0 * * * * php artisan pbx:sync-tenants
```

### 2. Test Manual Sync First

Before relying on automation, test manually:

1. Go to `/admin/settings/tenant-sync`
2. Configure a provider:
    - Enable the toggle
    - Set frequency (start with "hourly" for faster testing)
    - Set scheduled time
    - Click "Update Settings"
3. Click "Sync Now" button
4. Wait for results to appear in "LAST SYNCED" and "TENANTS FOUND"

**Expected output:**

- Last Synced: Shows current timestamp
- Tenants Found: Shows number > 0
- Last Result: "Created: X, Linked: Y, Skipped: Z"

### 3. Run Scheduler Locally (Development)

Test the scheduler without waiting:

```bash
# Run scheduler worker (stays running, checks every minute)
php artisan schedule:work

# OR run scheduler once
php artisan schedule:run
```

Watch for output like:

```
Running scheduled command: php artisan pbx:sync-tenants
```

### 4. Manually Trigger the Command

Test the sync command directly:

```bash
# Sync all enabled providers
php artisan pbx:sync-tenants

# Sync specific provider
php artisan pbx:sync-tenants --provider-id=1
```

**Check the output for:**

- ✅ "Syncing tenants for provider: [Name]"
- ✅ "Found X tenants"
- ✅ "Created X companies, Linked Y accounts, Skipped Z"

### 5. Check Database

Verify data is being synced:

```sql
-- Check tenant sync settings
SELECT * FROM tenant_sync_settings;

-- Check when last sync occurred
SELECT
    pbx_provider_id,
    enabled,
    frequency,
    last_synced_at,
    last_sync_count,
    last_sync_log
FROM tenant_sync_settings
WHERE enabled = 1;

-- Check synced tenants
SELECT COUNT(*) as total_tenants FROM pbxware_tenants;

-- Check newest companies created
SELECT name, status, created_at
FROM companies
ORDER BY created_at DESC
LIMIT 10;
```

### 6. Monitor Logs

Check Laravel logs for sync activity:

```bash
# Watch logs in real-time
tail -f storage/logs/laravel.log | grep -i "sync\|tenant"

# Check for errors
grep -i "error\|exception" storage/logs/laravel.log | tail -20
```

### 7. Verify on Production Server

On production, ensure cron is running Laravel scheduler:

```bash
# Check cron is configured
crontab -l | grep schedule

# Should see:
# * * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

If not configured, add to cron:

```bash
crontab -e

# Add this line:
* * * * * cd /var/www/blueHubCloud && php artisan schedule:run >> /dev/null 2>&1
```

### 8. Test Frequency Logic

Verify `shouldSyncNow()` respects frequency settings:

**Hourly Test:**

1. Set frequency to "hourly", enable sync
2. Wait for next hour boundary
3. Check if `last_synced_at` updated

**Daily Test:**

1. Set frequency to "daily" at specific time
2. Wait until that time (in UTC)
3. Verify sync occurred

**Weekly Test:**

1. Set frequency to "weekly" on Monday at 02:00
2. Wait until next Monday 02:00 UTC
3. Verify sync occurred

### 9. Performance Check

Monitor sync execution time:

```bash
# Time the command
time php artisan pbx:sync-tenants
```

Expected: < 30 seconds for ~100 tenants

### 10. Dashboard Verification

In admin dashboard `/admin/companies`:

1. Confirm new companies appear (status: inactive by default)
2. Check server_id and tenant_code are populated
3. Verify package_name shows PBXware package
4. Look for pbx_synced_at timestamp

## Common Issues & Solutions

### Issue: Sync Never Runs Automatically

**Solution:** Verify cron is running: `service cron status`

### Issue: "Tenants Found" shows 0

**Solution:**

1. Check PBX provider credentials are correct
2. Test API manually: `curl https://pbx.example.com/api/tenant/list -H "Authorization: Bearer YOUR_KEY"`
3. Check API key has read permissions

### Issue: Duplicate Companies Created

**Solution:** Check `shouldSyncNow()` logic - may be syncing too frequently

### Issue: Last Synced Never Updates

**Solution:**

1. Check `enabled` is true in database
2. Verify scheduler is running: `php artisan schedule:list`
3. Check for errors in `last_sync_log`

## Success Indicators

✅ `last_synced_at` updates regularly based on frequency
✅ `last_sync_count` matches actual tenant count from PBXware
✅ `last_sync_log` shows success JSON (not errors)
✅ New tenants appear in Companies page within sync frequency window
✅ No duplicate companies created on subsequent syncs

## Emergency: Stop Automated Sync

If sync is causing issues:

1. **Disable via UI:** Toggle off in `/admin/settings/tenant-sync`
2. **Database:** `UPDATE tenant_sync_settings SET enabled = 0;`
3. **Scheduler:** Comment out in `app/Console/Kernel.php` and deploy

## Need Help?

Check logs first:

```bash
tail -100 storage/logs/laravel.log
```

Run with verbose output:

```bash
php artisan pbx:sync-tenants -v
```
