# PowerShell script to upload and run reregister_device_30199.php on live server
$server = "root@31.97.199.82"
$password = "grc@2024Admin"
$scriptPath = "C:\xampp\htdocs\electrox-pos\reregister_device_30199.php"
$remotePath = "/var/www/electro-pos/reregister_device_30199.php"

# Read the file content
$fileContent = Get-Content $scriptPath -Raw

# Create a temporary script that will upload the file
$uploadScript = @"
`$content = @'
$fileContent
'@
`$content | Out-File -FilePath '$remotePath' -Encoding utf8
"@

# Try using plink if available
$plinkPath = "C:\Program Files\PuTTY\plink.exe"
if (Test-Path $plinkPath) {
    Write-Host "Using plink from: $plinkPath"
    echo y | & $plinkPath -ssh -pw $password $server "cat > $remotePath" < $scriptPath
} else {
    # Try to find plink in PATH
    try {
        echo y | plink -ssh -pw $password $server "cat > $remotePath" < $scriptPath
    } catch {
        Write-Host "plink not found. Please install PuTTY or provide the path to plink.exe"
        Write-Host "Alternatively, manually upload the file and run: php reregister_device_30199.php"
    }
}

