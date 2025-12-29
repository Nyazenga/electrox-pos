# Database Backup and Git Push Instructions

This document contains step-by-step instructions for backing up databases and pushing to Git.

## Prerequisites
- XAMPP MySQL/MariaDB running
- Git configured
- Database credentials:
  - Username: `root`
  - Password: (empty)
  - Databases: `electrox_primary`, `electrox_base`

## Step-by-Step Process

### 1. Backup Databases

#### Backup electrox_primary database:
```bash
mysqldump -u root -p"" electrox_primary > electrox_primary.sql
```

#### Backup electrox_base database:
```bash
mysqldump -u root -p"" electrox_base > electrox_base.sql
```

### 2. Verify Backups

#### Check electrox_primary.sql exists:
```bash
# Windows PowerShell:
if (Test-Path electrox_primary.sql) { Write-Host "✓ electrox_primary.sql exists - Size: $((Get-Item electrox_primary.sql).Length) bytes" } else { Write-Host "✗ electrox_primary.sql NOT FOUND" }
```

#### Check electrox_base.sql exists:
```bash
# Windows PowerShell:
if (Test-Path electrox_base.sql) { Write-Host "✓ electrox_base.sql exists - Size: $((Get-Item electrox_base.sql).Length) bytes" } else { Write-Host "✗ electrox_base.sql NOT FOUND" }
```

**IMPORTANT:** Do not proceed to step 3 until both backup files are confirmed to exist and have a size greater than 0 bytes.

### 3. Configure Git (if not already configured)

```bash
git config user.name "Nyazenga"
git config user.email "nyazengamd@gmail.com"
```

### 4. Check Git Status

```bash
git status
```

Review the changes to ensure only intended files are being committed.

### 5. Stage All Changes

```bash
git add .
```

### 6. Commit Changes

```bash
git commit -m "Your commit message here"
```

Replace "Your commit message here" with a descriptive message about the changes.

### 7. Set Git Remote with PAT (if needed)

The PAT (Personal Access Token) should be stored securely. To set the remote URL with PAT:

```bash
git remote set-url origin https://YOUR_PAT_HERE@github.com/Nyazenga/electrox-pos.git
```

**Note:** Replace `YOUR_PAT_HERE` with your actual Personal Access Token.

**Note:** The PAT in this file may expire. Update it if authentication fails.

### 8. Push to Git

```bash
git push origin main
```

Or if your default branch is `master`:
```bash
git push origin master
```

## Restoring Databases

To restore the databases on XAMPP MySQL/phpMyAdmin/MariaDB:

### Restore electrox_primary:
```bash
mysql -u root -p"" electrox_primary < electrox_primary.sql
```

### Restore electrox_base:
```bash
mysql -u root -p"" electrox_base < electrox_base.sql
```

**Alternative method using phpMyAdmin:**
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Select the database (electrox_primary or electrox_base)
3. Click on "Import" tab
4. Choose the corresponding .sql file
5. Click "Go"

## Troubleshooting

### If mysqldump command not found:
- Ensure XAMPP MySQL bin directory is in your PATH
- Or use full path: `C:\xampp\mysql\bin\mysqldump.exe`

### If git push fails with authentication error:
- Check if PAT is still valid
- Update the PAT in step 7 if expired
- Ensure remote URL is correct

### If database restore fails:
- Ensure databases exist: `CREATE DATABASE IF NOT EXISTS electrox_primary;`
- Check file permissions
- Verify SQL file is not corrupted

## Quick Command Summary

```bash
# Backup
mysqldump -u root -p"" electrox_primary > electrox_primary.sql
mysqldump -u root -p"" electrox_base > electrox_base.sql

# Verify
Test-Path electrox_primary.sql
Test-Path electrox_base.sql

# Git
git add .
git commit -m "Your message"
git push origin main
```

