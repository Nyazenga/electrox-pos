# PowerShell script to export FULL databases (structure + data) from localhost
# This exports everything to restore on live server

$mysqlPath = "C:\xampp\mysql\bin"
$exportDir = "C:\xampp\htdocs\electrox-pos"
$databases = @("electrox_primary", "electrox_base")

Write-Host "Exporting FULL databases (structure + data) to $exportDir..."

foreach ($db in $databases) {
    $sqlFile = Join-Path $exportDir "${db}_full.sql"
    Write-Host "Exporting $db (structure + data)..."
    
    # Export everything (structure + data) with compatible collations
    & "$mysqlPath\mysqldump.exe" -u root --single-transaction --routines --triggers --default-character-set=utf8mb4 $db | Out-File -FilePath $sqlFile -Encoding UTF8
    
    # Fix collations and syntax for compatibility
    $content = Get-Content $sqlFile -Raw
    $content = $content -replace 'utf8mb4_0900_ai_ci', 'utf8mb4_general_ci'
    $content = $content -replace 'utf8mb4_0900_as_ci', 'utf8mb4_general_ci'
    $content = $content -replace 'utf8mb4_0900_as_cs', 'utf8mb4_general_ci'
    $content = $content -replace 'ON UPDATE current_timestamp\(\)', 'ON UPDATE CURRENT_TIMESTAMP'
    $content = $content -replace 'DEFAULT current_timestamp\(\)', 'DEFAULT CURRENT_TIMESTAMP'
    $content = $content -replace "SET TIME_ZONE='NULL'", "SET TIME_ZONE='+00:00'"
    Set-Content -Path $sqlFile -Value $content -NoNewline
    
    $size = [math]::Round((Get-Item $sqlFile).Length / 1KB, 2)
    Write-Host "  Exported: $sqlFile ($size KB)"
}

Write-Host "`nFull database exports completed!"
Write-Host "Files are ready in: $exportDir"
Write-Host "`nThese files contain structure + data - ready to restore on live server!"

