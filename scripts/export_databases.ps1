# PowerShell script to export databases from localhost to electrox-pos folder
# This should be run before committing to git

$mysqlPath = "C:\xampp\mysql\bin"
$exportDir = "C:\xampp\htdocs\electrox-pos"
$databases = @("electrox_primary", "electrox_base")

Write-Host "Exporting databases to $exportDir..."

foreach ($db in $databases) {
    $sqlFile = Join-Path $exportDir "$db.sql"
    Write-Host "Exporting $db..."
    
    # Export structure only (no data) with compatible collations
    & "$mysqlPath\mysqldump.exe" -u root --single-transaction --routines --triggers --no-data --default-character-set=utf8mb4 $db | Out-File -FilePath $sqlFile -Encoding UTF8
    
    # Fix collations for XAMPP compatibility
    $content = Get-Content $sqlFile -Raw
    $content = $content -replace 'utf8mb4_0900_ai_ci', 'utf8mb4_general_ci'
    $content = $content -replace 'utf8mb4_0900_as_ci', 'utf8mb4_general_ci'
    $content = $content -replace 'utf8mb4_0900_as_cs', 'utf8mb4_general_ci'
    Set-Content -Path $sqlFile -Value $content -NoNewline
    
    $size = [math]::Round((Get-Item $sqlFile).Length / 1KB, 2)
    Write-Host "  Exported: $sqlFile ($size KB)"
}

Write-Host "`nDatabase exports completed!"
Write-Host "Files are ready in: $exportDir"
Write-Host "`nRemember to commit these files to git!"

