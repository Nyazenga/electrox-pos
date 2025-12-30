# GitHub Secrets Setup for ELECTROX-POS

## ⚠️ REQUIRED: Set GitHub Secrets

The deployment workflow requires two secrets to be configured in your GitHub repository.

### Step 1: Go to Repository Settings

1. Navigate to: **https://github.com/Nyazenga/electrox-pos/settings/secrets/actions**
2. Click **"New repository secret"**

### Step 2: Add SSH_PASSWORD Secret

1. **Name**: `SSH_PASSWORD`
2. **Value**: `GRCAdmin123/`
3. Click **"Add secret"**

### Step 3: Add GIT_TOKEN Secret

1. **Name**: `GIT_TOKEN`
2. **Value**: Your GitHub Personal Access Token (PAT)
   - If you don't have one, create it at: https://github.com/settings/tokens
   - Required permissions: `repo` (full control of private repositories)
3. Click **"Add secret"**

## Verify Secrets Are Set

After adding both secrets, you should see:
- ✅ `SSH_PASSWORD` (secret)
- ✅ `GIT_TOKEN` (secret)

## Test the Deployment

Once secrets are set:
1. Go to: https://github.com/Nyazenga/electrox-pos/actions
2. Click on the failed workflow run
3. Click **"Re-run jobs"** → **"Re-run failed jobs"**

Or make a small change and push to trigger a new deployment:
```bash
git commit --allow-empty -m "Test deployment"
git push origin main
```

## Current Status

- ✅ Workflow file created: `.github/workflows/deploy.yml`
- ✅ Server configured: `/var/www/electro-pos`
- ✅ Databases set up: `electrox_primary`, `electrox_base`
- ⚠️ **ACTION REQUIRED**: Set GitHub secrets (see above)

