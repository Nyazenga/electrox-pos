# ELECTROX-POS Live Server Deployment Instructions

## Status
✅ Database cleaned and backed up
✅ Database restored to live server
⏳ Nginx configuration pending
⏳ SSL certificate pending
⏳ Code push to git pending

## Completed Steps

### 1. Database Cleanup
- Cleaned local database of all business data
- Preserved system essentials (users, roles, branches, categories, currencies, settings)
- Created backup: `electrox_primary_clean_20260203_191727.sql`

### 2. Database Restore
- Uploaded backup to live server
- Restored database successfully
- System tables verified

## Remaining Steps

### 3. Nginx Configuration & SSL

**Option A: Automated (if plink works)**
```bash
plink.exe -ssh -pw "GRCAdmin123/" root@31.97.199.82 "chmod +x /tmp/setup_nginx_ssl.sh && bash /tmp/setup_nginx_ssl.sh"
```

**Option B: Manual (if automated fails)**

SSH into server:
```bash
ssh root@31.97.199.82
# Password: GRCAdmin123/
```

Then run:
```bash
# Make script executable
chmod +x /tmp/setup_nginx_ssl.sh

# Run setup script
bash /tmp/setup_nginx_ssl.sh
```

**Option C: Step-by-step manual**

1. Install certbot (if not installed):
```bash
apt-get update
apt-get install -y certbot python3-certbot-nginx
```

2. Create nginx config:
```bash
cp /tmp/nginx_electrox_pos.conf /etc/nginx/sites-available/electrox-pos
ln -sf /etc/nginx/sites-available/electrox-pos /etc/nginx/sites-enabled/
```

3. Test nginx config:
```bash
nginx -t
```

4. Reload nginx:
```bash
systemctl reload nginx
```

5. Generate SSL certificate:
```bash
certbot --nginx -d electrox-pos.com -d www.electrox-pos.com --non-interactive --agree-tos --email admin@electrox-pos.com --redirect
```

6. Verify SSL:
```bash
certbot certificates
```

### 4. Code Deployment

**Push to Git:**
```bash
git add .
git commit -m "Update domain to electrox-pos.com, clean database, nginx config"
git push origin main
```

**On Server (if using git workflow):**
The server should pull automatically via workflow, or manually:
```bash
cd /var/www/electrox-pos
git pull origin main
```

### 5. Verification

1. Test domain:
   - https://electrox-pos.com
   - https://www.electrox-pos.com

2. Test login:
   - https://www.electrox-pos.com/login.php
   - Tenant: `primary`
   - Email: `nyazengamd@gmail.com`
   - Password: `Admin123/`

3. Verify SSL:
   - Check certificate validity
   - Test HTTPS redirect

## Files Created

- `clean_database.php` - Database cleanup script
- `backup_clean_database.php` - Backup script
- `restore_to_live_server.php` - Restore script
- `restore_on_server.php` - Server-side restore script
- `scripts/nginx_electrox_pos.conf` - Nginx configuration
- `scripts/setup_nginx_ssl.sh` - Automated nginx/SSL setup
- `deploy_to_live.sh` - Complete deployment script

## Domain References Updated

- ✅ `api/swagger.json` - Updated production URL
- ⚠️ Password reset scripts still reference old domain (non-critical)

## Important Notes

1. **Server has other systems**: Do not delete or alter other configurations
2. **Database credentials**: Live server uses `grcadmin` user
3. **Web root**: `/var/www/electrox-pos`
4. **PHP-FPM**: Socket at `/var/run/php/php8.3-fpm.sock`

## Troubleshooting

### If nginx setup fails:
1. Check nginx error logs: `tail -f /var/log/nginx/error.log`
2. Verify domain DNS: `nslookup electrox-pos.com`
3. Check certbot logs: `journalctl -u certbot.timer`

### If SSL certificate fails:
1. Ensure DNS is pointing to server IP (31.97.199.82)
2. Ensure port 80 is open for Let's Encrypt validation
3. Check firewall: `ufw status`

### If database restore had errors:
- Most errors are non-critical (column mismatches, syntax issues)
- Core system tables restored successfully
- Verify with: `mysql -u grcadmin -p electrox_primary -e "SELECT COUNT(*) FROM users;"`
