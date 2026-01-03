# PowerShell script to set up the server for electrox-pos deployment
# This script will:
# 1. Create the deployment directory
# 2. Set up MySQL databases
# 3. Configure Nginx virtual host
# 4. Deploy initial code from Git

$serverHost = "31.97.199.82"
$serverUser = "root"
$serverPassword = "GRCAdmin123/"
$deployPath = "/var/www/electro-pos"
$plinkPath = "C:\xampp\htdocs\plink.exe"

Write-Host "Setting up server for ELECTROX-POS deployment..."
Write-Host "Server: $serverHost"
Write-Host "Deploy Path: $deployPath"
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

# Step 1: Create deployment directory
Write-Host "`n=== Step 1: Creating deployment directory ===" -ForegroundColor Cyan
Execute-SSHCommand "mkdir -p $deployPath && chmod 755 $deployPath && echo 'Directory created: $deployPath'"

# Step 2: Create MySQL databases
Write-Host "`n=== Step 2: Creating MySQL databases ===" -ForegroundColor Cyan
Execute-SSHCommand 'mysql -u root -e "CREATE DATABASE IF NOT EXISTS electrox_primary CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"'
Execute-SSHCommand 'mysql -u root -e "CREATE DATABASE IF NOT EXISTS electrox_base CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"'
Execute-SSHCommand 'mysql -u root -e "SHOW DATABASES LIKE '\''electrox_%'\'';"'

# Step 3: Check Nginx configuration
Write-Host "`n=== Step 3: Checking Nginx configuration ===" -ForegroundColor Cyan
Execute-SSHCommand "nginx -t"

# Step 4: Create Nginx virtual host (if needed)
Write-Host "`n=== Step 4: Setting up Nginx virtual host ===" -ForegroundColor Cyan
$nginxConfig = @"
server {
    listen 80;
    server_name nedcom.co.zw www.nedcom.co.zw;
    root $deployPath;
    index index.php index.html;

    access_log /var/log/nginx/nedcom_access.log;
    error_log /var/log/nginx/nedcom_error.log;

    location / {
        try_files `$uri `$uri/ /index.php?`$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME `$document_root`$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
"@

$nginxConfigPath = "/etc/nginx/sites-available/nedcom.co.zw"
$createNginxConfig = "echo '$nginxConfig' > $nginxConfigPath && ln -sf $nginxConfigPath /etc/nginx/sites-enabled/nedcom.co.zw && nginx -t && systemctl reload nginx"
Execute-SSHCommand $createNginxConfig

# Step 5: Initialize Git repository
Write-Host "`n=== Step 5: Initializing Git repository ===" -ForegroundColor Cyan
$gitInitScript = @"
cd $deployPath
if [ ! -d ".git" ]; then
    git init
    echo "Git repository initialized"
else
    echo "Git repository already exists"
fi
"@
Execute-SSHCommand $gitInitScript

Write-Host "`n=== Server setup completed! ===" -ForegroundColor Green
Write-Host "Next steps:"
Write-Host "1. Push code to GitHub"
Write-Host "2. GitHub Actions will automatically deploy"
Write-Host "3. Or manually pull: cd $deployPath && git pull origin main"

