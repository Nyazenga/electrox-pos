# PowerShell script to deploy initial code to server
# This will clone/update the repository on the server

$serverHost = "31.97.199.82"
$serverUser = "root"
$serverPassword = "GRCAdmin123/"
$deployPath = "/var/www/electro-pos"
$plinkPath = "C:\xampp\htdocs\plink.exe"
# Git token should be read from PAT file or environment variable
$gitToken = if (Test-Path "PAT") { Get-Content PAT -Raw | ForEach-Object { $_.Trim() } } else { $env:GIT_TOKEN }

Write-Host "Deploying ELECTROX-POS to server..."
Write-Host ""

# Function to execute SSH command
function Execute-SSHCommand {
    param(
        [string]$Command
    )
    
    $fullCommand = "echo y | $plinkPath -ssh -pw `"$serverPassword`" $serverUser@$serverHost `"$Command`""
    Write-Host "Executing: $Command"
    
    try {
        $output = Invoke-Expression $fullCommand 2>&1
        Write-Host $output
        return $output
    } catch {
        Write-Host "Error: $_" -ForegroundColor Red
        return $null
    }
}

# Step 1: Import SQL files
Write-Host "`n=== Step 1: Importing SQL files ===" -ForegroundColor Cyan
Execute-SSHCommand "cd $deployPath && if [ -f electrox_primary.sql ]; then mysql -u root electrox_primary < electrox_primary.sql && echo 'electrox_primary imported'; else echo 'electrox_primary.sql not found'; fi"
Execute-SSHCommand "cd $deployPath && if [ -f electrox_base.sql ]; then mysql -u root electrox_base < electrox_base.sql && echo 'electrox_base imported'; else echo 'electrox_base.sql not found'; fi"

# Step 2: Set up Git and pull code
Write-Host "`n=== Step 2: Setting up Git repository ===" -ForegroundColor Cyan
$gitSetup = @"
cd $deployPath
if [ ! -d ".git" ]; then
    git init
    git remote add origin https://${gitToken}@github.com/Nyazenga/electrox-pos.git
    git fetch origin main
    git reset --hard origin/main
    git clean -fd
    echo "Repository cloned"
else
    git remote set-url origin https://${gitToken}@github.com/Nyazenga/electrox-pos.git
    git fetch origin main
    git reset --hard origin/main
    git clean -fd
    echo "Repository updated"
fi
"@
Execute-SSHCommand $gitSetup

# Step 3: Set permissions
Write-Host "`n=== Step 3: Setting file permissions ===" -ForegroundColor Cyan
Execute-SSHCommand "cd $deployPath && find . -type f -exec chmod 644 {} \; && find . -type d -exec chmod 755 {} \; && chmod -R 755 logs/ assets/uploads/ 2>/dev/null || true"

# Step 4: Reload services
Write-Host "`n=== Step 4: Reloading services ===" -ForegroundColor Cyan
Execute-SSHCommand "nginx -t && systemctl reload nginx && systemctl reload php8.3-fpm && echo 'Services reloaded'"

Write-Host "`n=== Deployment completed! ===" -ForegroundColor Green
Write-Host "Site should be accessible at: https://nedcom.co.zw"

