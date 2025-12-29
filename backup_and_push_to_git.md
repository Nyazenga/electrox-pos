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

**Note:** If `mysqldump` command is not found, use the full path: `C:\xampp\mysql\bin\mysqldump.exe`

#### Backup electrox_primary database:
```powershell
# Try with mysqldump first, fallback to full path if needed
C:\xampp\mysql\bin\mysqldump.exe -u root --password= electrox_primary > electrox_primary.sql
```

#### Backup electrox_base database:
```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root --password= electrox_base > electrox_base.sql
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

### 7. Set Git Remote with PAT

**IMPORTANT:** The PAT (Personal Access Token) is stored in a file named `PAT` in the root folder (`electrox-pos/PAT`). This file is gitignored and should NOT be committed to the repository.

**To set the remote URL with PAT (read from PAT file):**

**PowerShell:**
```powershell
$pat = Get-Content PAT -Raw | ForEach-Object { $_.Trim() }
git remote set-url origin "https://$pat@github.com/Nyazenga/electrox-pos.git"
```

**Note:** The PAT file should already exist. If it doesn't exist or authentication fails, check if the PAT has expired and needs to be updated in the `PAT` file.

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

## Quick Command Summary (PowerShell)

```powershell
# Backup (using full path for XAMPP)
C:\xampp\mysql\bin\mysqldump.exe -u root --password= electrox_primary > electrox_primary.sql
C:\xampp\mysql\bin\mysqldump.exe -u root --password= electrox_base > electrox_base.sql

# Verify backups exist and have size > 0
if (Test-Path electrox_primary.sql) { Write-Host "✓ electrox_primary.sql - $((Get-Item electrox_primary.sql).Length) bytes" } else { Write-Host "✗ electrox_primary.sql NOT FOUND" }
if (Test-Path electrox_base.sql) { Write-Host "✓ electrox_base.sql - $((Get-Item electrox_base.sql).Length) bytes" } else { Write-Host "✗ electrox_base.sql NOT FOUND" }

# Configure Git (if needed)
git config user.name "Nyazenga"
git config user.email "nyazengamd@gmail.com"

# Set remote with PAT from file
$pat = Get-Content PAT -Raw | ForEach-Object { $_.Trim() }
git remote set-url origin "https://$pat@github.com/Nyazenga/electrox-pos.git"

# Stage, commit, and push
git add .
git commit -m "Your commit message here"
git push origin main
```

