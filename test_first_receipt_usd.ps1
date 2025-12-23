# Test First Receipt USD Format
# Testing what format ZIMRA expects for first receipt in USD

Write-Host "Testing First Receipt USD Format..." -ForegroundColor Cyan
Write-Host ""

$zimraHash = "q0AWSOZbZPTbj8gRPIkBaUU0NrdgdBwnAX3kdRzpW8o="

# Receipt data
$deviceID = "30200"
$receiptType = "FISCALINVOICE"
$receiptCurrency = "USD"
$receiptGlobalNo = "12"
$receiptDate = "2025-12-19T01:21:05"
$receiptTotal = "300"  # 3 USD = 300 cents
$receiptTaxes = "A15.5040300"

# Test variations for first receipt (no previousReceiptHash)
$variations = @()

# Variation 1: receiptTaxes only (current implementation)
$variations += @{Name="receiptTaxes only"; String=$receiptTaxes}

# Variation 2: All 8 fields (like ZWL)
$variations += @{Name="All 8 fields"; String="$deviceID$receiptType$receiptCurrency$receiptGlobalNo$receiptDate$receiptTotal$receiptTaxes"}

# Variation 3: Maybe first receipt needs all fields but subsequent ones don't?
# This would explain why Example 2 shows only taxes

foreach ($var in $variations) {
    $bytes = [System.Text.Encoding]::UTF8.GetBytes($var.String)
    $hash = [System.Security.Cryptography.SHA256]::Create().ComputeHash($bytes)
    $hashBase64 = [Convert]::ToBase64String($hash)
    
    Write-Host "$($var.Name):" -ForegroundColor Yellow
    Write-Host "  String: $($var.String)" -ForegroundColor Gray
    Write-Host "  Hash: $hashBase64" -ForegroundColor White
    
    if ($hashBase64 -eq $zimraHash) {
        Write-Host "  *** MATCH FOUND! ***" -ForegroundColor Green
        Write-Host ""
        Write-Host "The correct format for FIRST receipt in USD is: $($var.Name)" -ForegroundColor Green
        exit 0
    } else {
        Write-Host "  Match: NO" -ForegroundColor Red
    }
    Write-Host ""
}

Write-Host "None of the variations match." -ForegroundColor Red
Write-Host "ZIMRA Expected Hash: $zimraHash" -ForegroundColor Yellow

