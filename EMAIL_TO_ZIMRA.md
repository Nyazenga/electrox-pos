# Email to ZIMRA - Request for Device Certificates

## Subject Line
**Request for Fiscal Device Certificates - Device IDs 30199 and 30200**

## Email Body

Dear ZIMRA Fiscal Device Management Team,

I hope this email finds you well. I am writing to request assistance with obtaining device certificates for my registered fiscal devices.

**Background:**
I have successfully registered two fiscal devices in the ZIMRA Fiscal Device Management System (FDMS) test environment, but I do not have the device certificates and private keys needed to enable fiscalization in my POS system.

**Device Details:**

1. **Device 30199**
   - Device Model: Server
   - Device Model Version: v1
   - Serial Number: electrox-1
   - Activation Key: 00544726
   - Status: Registered

2. **Device 30200**
   - Device Model: Server
   - Device Model Version: v1
   - Serial Number: electrox-2
   - Activation Key: 00294543
   - Status: Registered

**Request:**
I need the X.509 device certificates and corresponding private keys (in PEM format) for both devices to enable mutual TLS authentication with the ZIMRA Fiscal Device Gateway API. These certificates are required for:
- Opening and closing fiscal days
- Submitting fiscal receipts
- Generating QR codes for receipts

Could you please provide:
1. The device certificates (PEM format) for both devices
2. The corresponding private keys (PEM format) for both devices

Alternatively, if these devices need to be reset and re-registered, please advise on the process to do so.

**Additional Information:**
- Platform: Test Environment (https://fdmsapitest.zimra.co.zw)
- Taxpayer TIN: [Your TIN if needed]
- Company: Electro X Zimbabwe Pvt Ltd

I appreciate your assistance with this matter. Please let me know if you need any additional information.

Thank you for your time and support.

Best regards,
[Your Name]
[Your Position]
[Company Name]
[Contact Information]

---

## Alternative Shorter Version

Dear ZIMRA Support Team,

I have two fiscal devices registered in your system but I don't have the device certificates needed for API authentication:

- Device 30199 (Serial: electrox-1, Activation Key: 00544726)
- Device 30200 (Serial: electrox-2, Activation Key: 00294543)

Could you please provide the X.509 certificates and private keys (PEM format) for these devices? I need them to enable fiscalization in my POS system.

Alternatively, please advise if these devices need to be reset for re-registration.

Thank you for your assistance.

Best regards,
[Your Name]

