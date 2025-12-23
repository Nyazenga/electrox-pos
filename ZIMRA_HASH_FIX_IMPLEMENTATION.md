# ZIMRA Hash Fix Implementation

## Date: 2025-12-21

## Problem

The RCPT020 error ("Invoice signature is not valid") was occurring because:

1. **Receipts with validation errors weren't being saved**: When ZIMRA returned validation errors, the code threw an exception before saving the receipt to the database. This prevented retrieval of the previous receipt's hash for subsequent receipts.

2. **ZIMRA still accepts receipts with validation errors**: Even when ZIMRA flags validation errors (RCPT011, RCPT012, RCPT020), it still:
   - Accepts the receipt (returns receiptID)
   - Stores the receipt
   - Returns receiptServerSignature with ZIMRA's calculated hash
   - Increments lastReceiptGlobalNo

3. **Previous receipt hash must be ZIMRA's hash**: For the receipt chain to work correctly, each receipt must use ZIMRA's hash from the previous receipt's `receiptServerSignature`, not our calculated hash.

## Solution Implemented

### 1. Save Receipts Even With Validation Errors

**File**: `includes/fiscal_service.php`

**Change**: Moved exception throwing to AFTER saving the receipt to the database.

**Before**:
```php
if (!empty($response['validationErrors'])) {
    // ... log errors ...
    throw new Exception($errorMsg); // Exception thrown BEFORE saving
}
// Save receipt to database (never reached if validation errors)
```

**After**:
```php
$hasValidationErrors = false;
if (!empty($response['validationErrors'])) {
    $hasValidationErrors = true;
    // ... log errors ...
    // NOTE: Don't throw exception yet - save receipt first
}
// Save receipt to database (ALWAYS executed, even with validation errors)
// ...
// Throw exception AFTER saving (if there were validation errors)
if ($hasValidationErrors) {
    throw new Exception($errorMsg);
}
```

**Rationale**: ZIMRA accepts receipts with validation errors and returns a hash. We need to save the receipt (and its hash) so subsequent receipts can retrieve the correct previousReceiptHash.

### 2. Always Use ZIMRA's Hash

**File**: `includes/fiscal_service.php` (lines 1127-1148)

**Change**: Enhanced comments and logging to emphasize that we ALWAYS use ZIMRA's hash from `receiptServerSignature`, not our calculated hash.

**Key Points**:
- ZIMRA returns `receiptServerSignature` for ALL receipts (including the first one), even with validation errors
- ZIMRA's hash is calculated from ZIMRA's internal signature string format
- Even if our signature string format doesn't match exactly, we must use ZIMRA's hash for the next receipt's `previousReceiptHash`
- This ensures the receipt chain is maintained correctly from ZIMRA's perspective

### 3. Enhanced Hash Extraction Logging

**File**: `includes/fiscal_service.php`

**Change**: Added detailed logging to track hash extraction and comparison:

```php
$writeLog("ZIMRA HASH EXTRACTION: ZIMRA hash: " . $zimraHash);
$writeLog("ZIMRA HASH EXTRACTION: Our hash: " . $ourHash);
$writeLog("ZIMRA HASH EXTRACTION: Hashes match: " . ($ourHash === $zimraHash ? "YES ✓" : "NO ✗ - This indicates signature string format mismatch, but using ZIMRA's hash for next receipt"));
```

This helps identify when our signature format doesn't match ZIMRA's, while still ensuring we use ZIMRA's hash for the chain.

## Impact

### Before Fix:
- Receipts with validation errors: ❌ Not saved to database
- Previous receipt hash retrieval: ❌ Failed (receipt not in database)
- Receipt chain: ❌ Broken (wrong or missing previousReceiptHash)

### After Fix:
- Receipts with validation errors: ✅ Saved to database (with ZIMRA's hash)
- Previous receipt hash retrieval: ✅ Works (can retrieve from database)
- Receipt chain: ✅ Maintained (uses ZIMRA's hash from previous receipt)

## Database Schema

The fix relies on the `fiscal_receipts` table storing:
- `receipt_hash`: ZIMRA's hash from `receiptServerSignature['hash']`
- `receipt_server_signature`: Full JSON of ZIMRA's `receiptServerSignature`
- `submission_status`: 'Submitted' (even if validation errors occurred)

## Testing

After this fix:
1. Receipts with validation errors are saved to the database
2. Subsequent receipts can retrieve the correct previousReceiptHash
3. Receipt chain is maintained correctly
4. RCPT020 errors may still occur due to signature format mismatch, but the receipt chain won't be broken

## Next Steps

While this fix ensures the receipt chain works correctly, RCPT020 errors may still persist if:
- Our signature string format doesn't exactly match ZIMRA's expectations
- ZIMRA uses different values for signature calculation than we send

**Recommendation**: Contact ZIMRA support with:
- Exact signature strings we're generating
- ZIMRA's calculated hashes
- Request clarification on the exact signature format they use

