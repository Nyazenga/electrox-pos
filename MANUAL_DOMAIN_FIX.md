# Manual Steps to Fix Domain and SSL

## Current Issue
The domain `electrox-pos.com` is still showing Hostinger's default page instead of your application.

## Root Cause
The domain DNS was updated to point to `31.97.199.82`, but there might be:
1. DNS caching (can take up to 48 hours)
2. Browser caching
3. The domain might still be using Hostinger's nameservers

## Steps to Fix

### Step 1: Verify DNS on Hostinger

**On Hostinger, check:**

1. **Nameservers**: The domain should NOT be using Hostinger's nameservers if you want it to point to your server.
   - If using Hostinger nameservers, you need to update A records in Hostinger's DNS panel
   - If using custom nameservers, update A records there

2. **A Records**: Verify these exist:
   - `@` (root) → `31.97.199.82`
   - `www` → `31.97.199.82`

3. **Check if domain is using Hostinger's nameservers**:
   - If yes, you need to either:
     a. Keep using Hostinger nameservers and update A records in Hostinger panel
     b. Change to custom nameservers (if you have them)

### Step 2: Clear DNS Cache

**On your local machine:**
```cmd
ipconfig /flushdns
```

**Or test with different DNS:**
```cmd
nslookup electrox-pos.com 8.8.8.8
nslookup www.electrox-pos.com 8.8.8.8
```

### Step 3: Test HTTP Access Directly

**SSH into server and test:**
```bash
ssh root@31.97.199.82
# Password: GRCAdmin123/

# Test if site works on HTTP
curl -I http://localhost
curl -I http://electrox-pos.com

# Check nginx is serving the site
systemctl status nginx
```

### Step 4: Generate SSL Certificate (After HTTP Works)

Once HTTP is working, generate SSL:

```bash
# On server
certbot certonly --webroot \
    -w /var/www/html \
    -d electrox-pos.com \
    -d www.electrox-pos.com \
    --non-interactive \
    --agree-tos \
    --email admin@electrox-pos.com
```

If this fails due to IPv6, you can temporarily disable IPv6 for Let's Encrypt or use DNS challenge.

### Step 5: Update Nginx for HTTPS

After SSL certificate is generated, update nginx config:

```bash
# The script will do this automatically, or manually:
nano /etc/nginx/sites-available/electrox-pos
# Update to include HTTPS block with SSL certificates
nginx -t
systemctl reload nginx
```

## Quick Test

**Test if your server is accessible:**
```bash
# From your local machine
curl -I http://31.97.199.82
```

If this works but `http://electrox-pos.com` doesn't, it's a DNS issue.

## Alternative: Use Hostinger's DNS Panel

If the domain is still using Hostinger's nameservers:

1. Log into Hostinger
2. Go to DNS management for `electrox-pos.com`
3. Add/Update A records:
   - Name: `@` or blank → Value: `31.97.199.82`
   - Name: `www` → Value: `31.97.199.82`
4. Wait 5-10 minutes for propagation
5. Test: `http://electrox-pos.com`

## Current Server Status

✅ Web root configured: `/var/www/electro-pos`
✅ Nginx configured for domain
✅ HTTP should work (once DNS propagates)
⏳ SSL certificate pending (IPv6 issue)

## Next Steps

1. **Verify DNS is pointing correctly** (check Hostinger panel)
2. **Wait for DNS propagation** (can take up to 48 hours, usually 5-30 minutes)
3. **Test HTTP access** once DNS propagates
4. **Generate SSL certificate** once HTTP works
5. **Push code to git** (ready to go)
