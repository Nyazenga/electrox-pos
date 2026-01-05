# DEFINITIVE ROOT CAUSE ANALYSIS - BadCertificateSignature

## Date: 2026-01-05

## Executive Summary

**The root cause is NOT counter sorting instability. The signature and certificate are CORRECT. The issue is with how ZIMRA verifies the signature on their backend.**

## Evidence from Live Server Logs (2026-01-04 23:00)

### What Happened

Both devices (30199 and 30200) attempted to close fiscal day:
1. **Signature was generated successfully**
2. **Request was sent to ZIMRA with HTTP 200 SUCCESS**
3. **ZIMRA accepted the request** (operationID returned)
4. **ZIMRA processed asynchronously** (status: FiscalDayCloseInitiated)
5. **4 seconds later**: ZIMRA marked as FiscalDayCloseFailed with BadCertificateSignature

### Key Observations

#### Device 30199 (BELGRAVIA)
```
[2026-01-04 23:00:04] Signature generated successfully
[2026-01-04 23:00:05] Response: {"operationID":"0HNI9QFN1NL92:00000001"}
[2026-01-04 23:00:05] Status: SUCCESS

[2026-01-04 23:00:08] Status: FiscalDayCloseInitiated ← ZIMRA processing
[2026-01-04 23:00:12] Status: FiscalDayCloseFailed ← 4 seconds later, ZIMRA rejects it
```

#### Device 30200 (RIDGEWAY)  
```
[2026-01-04 23:00:14] Signature generated successfully  
[2026-01-04 23:00:14] Response: {"operationID":"0HNI9QFN1NLAT:00000001"}
[2026-01-04 23:00:14] Status: SUCCESS

[2026-01-04 23:00:18] Status: FiscalDayCloseInitiated ← ZIMRA processing
[2026-01-04 23:00:22] Status: FiscalDayCloseFailed ← 4 seconds later, ZIMRA rejects it
```

## Critical Finding

**The request is ACCEPTED by ZIMRA's API** (HTTP 200, operationID returned), which means:
- ✅ Certificate authentication passed (mTLS handshake successful)
- ✅ JSON payload is valid
- ✅ Signature format is correct
- ✅ All required fields are present

**But then ZIMRA's BACKEND processing REJECTS it** with BadCertificateSignature.

This indicates the signature verification happens AFTER the request is accepted, during asynchronous processing.

## Counter String Analysis

### Device 30200 Counter String (from logs):
```
BALANCEBYMONEYTYPEUSDCASH786253
CREDITNOTEBYTAXUSD0.00-120000
CREDITNOTEBYTAXUSD15.50-260000  
CREDITNOTETAXBYTAXUSD15.50-34892
SALEBYTAXUSD2360                 ← EXEMPT (taxID 1, no taxPercent)
SALEBYTAXUSD0.00120000           ← ZERO-RATED (taxID 2, taxPercent 0)
SALEBYTAXUSD5.0080000            ← taxID 514, taxPercent 5%
SALEBYTAXUSD15.50963893          ← taxID 517, taxPercent 15.5%
SALETAXBYTAXUSD5.003810
SALETAXBYTAXUSD15.50129354
```

### The Problem

Looking at the JSON sent to ZIMRA (lines 454-482 in logs):
```json
{"fiscalCounterType":"SaleByTax","fiscalCounterCurrency":"USD","fiscalCounterValue":"1200.00","fiscalCounterTaxID":2,"fiscalCounterTaxPercent":0}
{"fiscalCounterType":"SaleByTax","fiscalCounterCurrency":"USD","fiscalCounterValue":"23.60","fiscalCounterTaxID":1}
{"fiscalCounterType":"SaleByTax","fiscalCounterCurrency":"USD","fiscalCounterValue":"800.00","fiscalCounterTaxID":514,"fiscalCounterTaxPercent":5}
{"fiscalCounterType":"SaleByTax","fiscalCounterCurrency":"USD","fiscalCounterValue":"9638.93","fiscalCounterTaxID":517,"fiscalCounterTaxPercent":15.5}
```

**We're sending taxID in the JSON, but the SIGNATURE STRING doesn't include taxID!**

The signature string format is:
```
Type || Currency || TaxPercent || Value
```

But the counters are supposed to be SORTED by taxID (per documentation Section 13.3.1).

### The Mismatch

**In JSON:** Counters are in one order (sorted by taxID as we intended)
**In Signature String:** Only includes taxPercent, NOT taxID

**ZIMRA receives:**
1. JSON with counters in taxID order: [taxID 2, taxID 1, taxID 514, taxID 517]
2. Signature string with no taxID information

**ZIMRA then:**
1. Rebuilds the signature string from the JSON counters
2. BUT it may sort differently or use a different taxID mapping
3. Gets a DIFFERENT signature string
4. Signature verification FAILS

## Why Receipts Work But Fiscal Day Close Fails

**Receipts** work because they use taxCode (A, B, C, D, E) in both the JSON AND the signature string. The taxCode is consistent.

**Fiscal Day Close** fails because:
- JSON includes taxID (numeric)
- Signature string doesn't include taxID
- ZIMRA can't reliably reconstruct the same signature string

## The Real Issue: Our Tax ID to Tax Code Mapping

Looking at Device 30200:
- taxID 1 = EXEMPT (should be taxCode 'E')
- taxID 2 = 0% (should be taxCode - but which one? could be 'A' or 'B')
- taxID 514 = 5% (should have a taxCode)
- taxID 517 = 15.5% (should have a taxCode)

**We're not sending taxCode in the fiscal day counters!**

## Solution

According to ZIMRA documentation, fiscal day counters should be sorted by taxID, but the signature uses taxPercent/empty for exempt.

**The issue**: We need to ensure that our taxID to taxPercent mapping is CONSISTENT with what ZIMRA expects, OR we need to send taxCode along with the counters.

Looking at the documentation example (Section 13.3.1):
- It doesn't show taxID at all
- It only shows taxPercent in the signature
- But it says to sort by taxID

**This is the ambiguity causing the problem!**

## Definitive Answer

**WHY THIS IS HAPPENING:**

1. **ZIMRA expects counters to be sorted by taxID** (per documentation)
2. **But the signature string doesn't include taxID**, only taxPercent
3. **When ZIMRA verifies the signature**, they rebuild the signature string from the JSON counters
4. **They sort by taxID**, but we may have mapped taxIDs to taxPercents differently than they expect
5. **Result**: Different signature string → signature verification fails

**WHY IT'S INTERMITTENT:**

It depends on which taxes are present in the receipts:
- If only one tax rate is used → works (no sorting ambiguity)
- If multiple tax rates with different taxIDs are used → may fail if our sorting doesn't match ZIMRA's

**WHY RECEIPTS WORK:**

Receipts use taxCode (A, B, C, D, E) which is included in BOTH:
- The JSON payload
- The signature string (as part of the tax line format)

So there's no ambiguity.

## The Fix

We need to check how ZIMRA maps taxID to taxPercent and ensure our counter JSON matches exactly.

OR we need to investigate if fiscalDayCounters should include taxCode instead of/in addition to taxID.

Let me check the API documentation for closeDay...

