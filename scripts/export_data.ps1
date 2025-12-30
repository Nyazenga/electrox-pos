# PowerShell script to export database DATA from localhost
# This exports only the data (INSERT statements) for critical tables

$mysqlPath = "C:\xampp\mysql\bin"
$exportDir = "C:\xampp\htdocs\electrox-pos"
$databases = @("electrox_primary", "electrox_base")

Write-Host "Exporting database DATA to $exportDir..."

foreach ($db in $databases) {
    $sqlFile = Join-Path $exportDir "${db}_data.sql"
    Write-Host "Exporting data from $db..."
    
    # Export data only (no structure) with compatible collations
    & "$mysqlPath\mysqldump.exe" -u root --single-transaction --no-create-info --routines --triggers --default-character-set=utf8mb4 $db | Out-File -FilePath $sqlFile -Encoding UTF8
    
    # Fix collations for XAMPP compatibility
    $content = Get-Content $sqlFile -Raw
    $content = $content -replace 'utf8mb4_0900_ai_ci', 'utf8mb4_general_ci'
    $content = $content -replace 'utf8mb4_0900_as_ci', 'utf8mb4_general_ci'
    $content = $content -replace 'utf8mb4_0900_as_cs', 'utf8mb4_general_ci'
    $content = $content -replace 'ON UPDATE current_timestamp\(\)', 'ON UPDATE CURRENT_TIMESTAMP'
    $content = $content -replace 'DEFAULT current_timestamp\(\)', 'DEFAULT CURRENT_TIMESTAMP'
    Set-Content -Path $sqlFile -Value $content -NoNewline
    
    $size = [math]::Round((Get-Item $sqlFile).Length / 1KB, 2)
    Write-Host "  Exported: $sqlFile ($size KB)"
}

Write-Host "`nDatabase data exports completed!"
Write-Host "Files are ready in: $exportDir"
Write-Host "`nRemember to commit these files to git!"

