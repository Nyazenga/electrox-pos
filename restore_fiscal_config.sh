#!/bin/bash
# Restore fiscal_config from backup

DB_HOST="localhost"
DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"

echo "=========================================="
echo "Restoring Fiscal Config"
echo "=========================================="
echo ""

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;

-- Restore fiscal_config
INSERT INTO `fiscal_config` (`id`, `branch_id`, `device_id`, `taxpayer_name`, `taxpayer_tin`, `vat_number`, `device_branch_name`, `device_branch_address`, `device_branch_contacts`, `device_operating_mode`, `taxpayer_day_max_hrs`, `taxpayer_day_end_notification_hrs`, `applicable_taxes`, `certificate_valid_till`, `qr_url`, `last_synced`, `created_at`, `updated_at`) VALUES
(4, 3, 30200, 'Electro X Zimbabwe Pvt Ltd', '2001286483', '220108354', 'Electro X Zimbabwe Pvt Ltd', '{"province":"HARARE","city":"HARARE","street":"ED Mnangagwa Rd","houseNo":"147"}', '{"phoneNo":"0776190449","email":"accounts@electrox.co.zw"}', 'Online', 24, 2, '[{"taxID":1,"taxPercent":0,"taxCode":"E","taxName":"Exempt","taxValidFrom":"2023-01-01T00:00:00"},{"taxID":2,"taxPercent":0,"taxCode":"C","taxName":"Zero rated 0%","taxValidFrom":"2023-01-01T00:00:00"},{"taxID":514,"taxPercent":5,"taxCode":"B","taxName":"Non-VAT Withholding Tax","taxValidFrom":"2024-01-01T00:00:00"},{"taxID":517,"taxPercent":15.5,"taxCode":"A","taxName":"Standard rated 15.5%","taxValidFrom":"2025-12-15T00:00:00"}]', '2026-12-23 14:38:37', 'https://fdmstest.zimra.co.zw', '2025-12-23 16:38:38', '2025-12-23 16:38:38', '2025-12-23 16:38:38'),
(5, 1, 30199, 'Electro X Zimbabwe Pvt Ltd', '2001286483', '220108354', 'Electro X Zimbabwe Pvt Ltd', '{"province":"HARARE","city":"HARARE","street":"ED Mnangagwa Rd","houseNo":"147"}', '{"phoneNo":"0776190449","email":"accounts@electrox.co.zw"}', 'Online', 24, 2, '[{"taxID":1,"taxPercent":0,"taxCode":"E","taxName":"Exempt","taxValidFrom":"2023-01-01T00:00:00"},{"taxID":2,"taxPercent":0,"taxCode":"C","taxName":"Zero rated 0%","taxValidFrom":"2023-01-01T00:00:00"},{"taxID":514,"taxPercent":5,"taxCode":"B","taxName":"Non-VAT Withholding Tax","taxValidFrom":"2024-01-01T00:00:00"},{"taxID":517,"taxPercent":15.5,"taxCode":"A","taxName":"Standard rated 15.5%","taxValidFrom":"2025-12-15T00:00:00"}]', '2026-12-23 14:42:03', 'https://fdmstest.zimra.co.zw', '2025-12-23 16:42:05', '2025-12-23 16:42:04', '2025-12-23 16:42:05')
ON DUPLICATE KEY UPDATE
    branch_id = VALUES(branch_id),
    device_id = VALUES(device_id),
    taxpayer_name = VALUES(taxpayer_name),
    taxpayer_tin = VALUES(taxpayer_tin),
    vat_number = VALUES(vat_number),
    device_branch_name = VALUES(device_branch_name),
    device_branch_address = VALUES(device_branch_address),
    device_branch_contacts = VALUES(device_branch_contacts),
    device_operating_mode = VALUES(device_operating_mode),
    taxpayer_day_max_hrs = VALUES(taxpayer_day_max_hrs),
    taxpayer_day_end_notification_hrs = VALUES(taxpayer_day_end_notification_hrs),
    applicable_taxes = VALUES(applicable_taxes),
    certificate_valid_till = VALUES(certificate_valid_till),
    qr_url = VALUES(qr_url),
    last_synced = VALUES(last_synced),
    updated_at = NOW();

SET FOREIGN_KEY_CHECKS=1;

SELECT 'Fiscal config restored successfully' as status;
SELECT id, branch_id, device_id, taxpayer_name, taxpayer_tin FROM fiscal_config;
SQL

echo ""
echo "=========================================="
echo "✓ Fiscal Config Restored"
echo "=========================================="
