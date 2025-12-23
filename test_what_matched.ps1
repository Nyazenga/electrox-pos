# Clarifying what matched in my tests

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "What Matched vs What Didn't" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# What I said matched: Example 2 from documentation
Write-Host "WHAT I SAID MATCHED:" -ForegroundColor Yellow
Write-Host "----------------------------------------" -ForegroundColor Yellow
$example2String = "07000.000100014.50535 hNVJXP/ACOiE8McD3pKsDlqBXpuaUqQOfPnMyfZWI9k="
$example2Expected = "2zInR7ciOQ9PbtQlKaU5XoktQ/4/y1XShfzEEoSVO7s="
$example2Bytes = [System.Text.Encoding]::UTF8.GetBytes($example2String)
$example2Hash = [Convert]::ToBase64String([System.Security.Cryptography.SHA256]::Create().ComputeHash($example2Bytes))
Write-Host "Example 2 String: $example2String" -ForegroundColor Gray
Write-Host "Generated Hash: $example2Hash" -ForegroundColor White
Write-Host "Expected Hash:  $example2Expected" -ForegroundColor White
if ($example2Hash -eq $example2Expected) {
    Write-Host "MATCH: YES - This is what I said matched!" -ForegroundColor Green
} else {
    Write-Host "MATCH: NO" -ForegroundColor Red
}
Write-Host ""
Write-Host "NOTE: This example has:" -ForegroundColor Cyan
Write-Host "  - Multiple taxes with EMPTY taxCodes" -ForegroundColor Gray
Write-Host "  - A previousReceiptHash" -ForegroundColor Gray
Write-Host ""

# What the current sale has
Write-Host "WHAT YOUR CURRENT SALE HAS:" -ForegroundColor Yellow
Write-Host "----------------------------------------" -ForegroundColor Yellow
$currentSaleString = "A15.5040300"
$currentSaleZimraHash = "q0AWSOZbZPTbj8gRPIkBaUU0NrdgdBwnAX3kdRzpW8o="
$currentSaleBytes = [System.Text.Encoding]::UTF8.GetBytes($currentSaleString)
$currentSaleHash = [Convert]::ToBase64String([System.Security.Cryptography.SHA256]::Create().ComputeHash($currentSaleBytes))
Write-Host "Current Sale String: $currentSaleString" -ForegroundColor Gray
Write-Host "Generated Hash: $currentSaleHash" -ForegroundColor White
Write-Host "ZIMRA Hash:     $currentSaleZimraHash" -ForegroundColor White
if ($currentSaleHash -eq $currentSaleZimraHash) {
    Write-Host "MATCH: YES" -ForegroundColor Green
} else {
    Write-Host "MATCH: NO - This is what's NOT matching!" -ForegroundColor Red
}
Write-Host ""
Write-Host "NOTE: Your sale has:" -ForegroundColor Cyan
Write-Host "  - ONE tax with taxCode='A'" -ForegroundColor Gray
Write-Host "  - NO previousReceiptHash (first receipt)" -ForegroundColor Gray
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "CONCLUSION" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "I said Example 2 matched (which it does), but that's a DIFFERENT" -ForegroundColor Yellow
Write-Host "format than your current sale. Your sale format doesn't match ZIMRA's hash." -ForegroundColor Yellow

