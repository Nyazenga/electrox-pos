#!/bin/bash
# Restore fiscal devices exactly from backup

DB_HOST="localhost"
DB_USER="grcadmin"
DB_PASS="GRCAdmin123/"
DB_NAME="electrox_primary"

echo "=========================================="
echo "Restoring Fiscal Devices (Exact Data)"
echo "=========================================="
echo ""

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;

-- Insert fiscal devices with exact data from backup
INSERT INTO `fiscal_devices` (`id`, `branch_id`, `device_id`, `device_serial_no`, `activation_key`, `device_model_name`, `device_model_version`, `certificate_pem`, `certificate_valid_till`, `private_key_pem`, `is_registered`, `is_active`, `operating_mode`, `last_config_sync`, `last_ping`, `created_at`, `updated_at`) VALUES
(5, 1, '30199', 'electrox-1', '00544726', 'Server', 'v1', '-----BEGIN CERTIFICATE-----\nMIIEDzCCAvegAwIBAgIIQ4Aif9BvE/swDQYJKoZIhvcNAQELBQAwTzELMAkGA1UE\nBhMCWlcxIzAhBgNVBAoTGlppbWJhYndlIFJldmVudWUgQXV0aG9yaXR5MRswGQYD\nVQQDExJmb3ItZGV2aWNlLXNpZ25pbmcwHhcNMjYwMTAzMTA0MTI4WhcNMjcwMTAz\nMTA0MTI4WjBrMQswCQYDVQQGEwJaVzERMA8GA1UECAwIWmltYmFid2UxIzAhBgNV\nBAoMGlppbWJhYndlIFJldmVudWUgQXV0aG9yaXR5MSQwIgYDVQQDDBtaSU1SQS1l\nbGVjdHJveC0xLTAwMDAwMzAxOTkwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEK\nAoIBAQC7WXvpOrH4+tTeqF1jTmmdy4SN7KMP0X4/mXitznwya06C2vP2UcQskcGY\nRUSQocJLSh3bCBSlWft6bJvAf6Rn0lTwLp+YgWSfQ/k0kRv8sJixRSW6cCoB+VFI\ng6zFiaeMymZ0CGadxbIQgJ/aoP9Qbuj3Cu3sb8wxCwrQoE8KRzYtMiAYCW5H14Lp\n4XYCnRekrYQMu5lOJN9rbF9RA46SWr3r5F6CXfJgvBPcsJsBuo842kb61oiG9OJd\nxbwWSZzOekbLCheUcjdsm79izPslURRjxmrD+/2+3bYEb7iCnNU6bYSgigcNEKjV\nyQCD+WeXjwx+/sKz/NV+hrx71goFAgMBAAGjgdIwgc8wCQYDVR0TBAIwADAdBgNV\nHQ4EFgQUWMV3iGcFOxf+lJ+h37SBvnFM/4kwfgYDVR0jBHcwdYAUU7/avL3rxixS\nYklqUei9iWSpTjahSKRGMEQxEjAQBgoJkiaJk/IsZAEZFgJaVzESMBAGCgmSJomT\n8ixkARkWAlJBMRowGAYDVQQDExFaSU1SQSBJc3N1aW5nIENBM4ITFAAAATQI/Nxa\npLIgcgAAAAABNDAOBgNVHQ8BAf8EBAMCBeAwEwYDVR0lBAwwCgYIKwYBBQUHAwIw\nDQYJKoZIhvcNAQELBQADggEBAJ88PKJJuYlhEmkSjvfIROXt4nUQECFzU+5/Zgj5\nWTFa2e/BLKwUA9xv+k4eWawFU/SKyLG3jX8I2SUzHSroJ6a56Vf44r3/hbHU9Ax3\njyrzKr4EAzPus3ArxRJwqV16SsNEiPW63eKRrLmTogxut+cctM6MNvv1+eiMYgfL\npXtruteM0KNIzTF9GTm1Lj+en8nPEq5201VHLvTMYwbOMSA6G8H71k5U3KGcWWa0\nyGKKuWUS+SSfRucnJusYO1O7Yry2VVJuCWnC/8/ORH4yG54fJafotz1xRytBdkp4\nZZ80jWqwXtB1DMVSYmCLrhDgwZcsvHWL1YLcmUKIss28THM=\n-----END CERTIFICATE-----', '2027-01-03 12:41:28', '-----BEGIN PRIVATE KEY-----\nMIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC7WXvpOrH4+tTe\nqF1jTmmdy4SN7KMP0X4/mXitznwya06C2vP2UcQskcGYRUSQocJLSh3bCBSlWft6\nbJvAf6Rn0lTwLp+YgWSfQ/k0kRv8sJixRSW6cCoB+VFIg6zFiaeMymZ0CGadxbIQ\ngJ/aoP9Qbuj3Cu3sb8wxCwrQoE8KRzYtMiAYCW5H14Lp4XYCnRekrYQMu5lOJN9r\nbF9RA46SWr3r5F6CXfJgvBPcsJsBuo842kb61oiG9OJdxbwWSZzOekbLCheUcjds\nm79izPslURRjxmrD+/2+3bYEb7iCnNU6bYSgigcNEKjVyQCD+WeXjwx+/sKz/NV+\nhrx71goFAgMBAAECggEAEIcoGlRLnSaBHxTatZxrpDhvJ5d9Gd57ynU/Tei4e443\nQpi5tbz9lzJTUkancjGtvWH62PbmiLaJLAIRijL4jbw4Mr7k5Nm4HHYtBwks3zxe\nds0d3fZv46AGHrqXGbo3e4E49qWYap96l7VxOiLCmMBKxyNCCCjOFR7fQ7Z7d/GC\nXl8zHQduGNFVmfIlPLEuPwAutc2zxh70uimpChe2gL7JMlF51VJyue39fBFdz/JF\nrL9K7FreugWDoNqs6Qh7TPez3JtcG2Pxc35UZaHvgG9njqxRJPlf49LipGYBG3+N\nV4VyHhS9NbcQkPLvkiYyCDvwWYB7fQOrnIbqAFCypQKBgQDdVfbjzMkk5XtiE2fe\nF9b3o6+8lr+gaHIBYJYLCaw0ORQskCbrKZrt3q6P5Suzd16xUNq5J/VTVAGpNBfE\nhx7EZqECcC0KraTfOmKMcVBTvvDyHG+tX164tWEI/h4cK72U5eJxxvJcl1poFc7D\na/Dl6IISQjzSre8JZ9ZNU1m6ZwKBgQDYsOfl1rAJ2WduGuVBH/ZzHsV4cLJqdo7z\nL+kBaonRb5vC07BHUF21KPptmp2wzJSAENazOYKgalTA40Omx558AeBnPzlpd9qx\npdAtO66UIyp0ZV1ju29f0P+VwGAYwLJQ+blUm9xT9wkVm54tOU+fpBIw8f6RuCHu\nQy4STBksswKBgQCna9AeDhiUXTWQQUePGo9TsLBMwebfikG6QvocDUwCEK7u6ndV\n6Jm1lnyKgfolfYTWMWfRKKWMS34aJDpaQS8Htu3Rr1KSwjh1Vm+W9luhjUwqh1H+\nXaaDp0doCvhxrLBxwdYg5DEN0rrjAqPs9Gg7MD27W/kwD7tBbRcQVJ0JvwKBgBYK\nWGwOXDWEQXr3jV4EbELlXFyVye/+QygFNYQJXB9LZOJ6ObHnQMDOfDptwaBcDra/\n/7aXIOxEJH7CHv11zG78meCmk6ZgpIPxQ612Jpm2wfi43rjoNbnfPj/zI1MhNoH6\nBJnQiKaZt/jUrVAYRjsMqzUDSEt2GS1s8+C0kNL1AoGBAMHYPYwA8l1B3W6+C/zj\nXLNXnBXoM4VWbFxeonxnqk0CsDmLrYfQ0A6NbjGeW2KESSQ7D+ks2Yvm303Sl1Ec\nGOceZanJIvvv8mYUafwZobZgZdQpgYM0jsqGp1Fs4FaJPVx3U0hfFdmvLFYyirV2\nnIi57EfK5YHoPK+gb0V7v2WW\n-----END PRIVATE KEY-----\n', 1, 1, 'Online', '2025-12-23 16:42:05', NULL, '2025-12-23 16:37:43', '2026-01-03 12:41:29'),
(6, 3, '30200', 'electrox-2', '00294543', 'Server', 'v1', '-----BEGIN CERTIFICATE-----\nMIIEDzCCAvegAwIBAgIIdQe0BxMCht4wDQYJKoZIhvcNAQELBQAwTzELMAkGA1UE\nBhMCWlcxIzAhBgNVBAoTGlppbWJhYndlIFJldmVudWUgQXV0aG9yaXR5MRswGQYD\nVQQDExJmb3ItZGV2aWNlLXNpZ25pbmcwHhcNMjUxMjI5MDU1OTI2WhcNMjYxMjI5\nMDU1OTI2WjBrMQswCQYDVQQGEwJaVzERMA8GA1UECAwIWmltYmFid2UxIzAhBgNV\nBAoMGlppbWJhYndlIFJldmVudWUgQXV0aG9yaXR5MSQwIgYDVQQDDBtaSU1SQS1l\nbGVjdHJveC0yLTAwMDAwMzAyMDAwggEiMA0GCSqGSIb3DQEBAQUAA4IBDwAwggEK\nAoIBAQCGM30Fm1dRsXvs1isC7kMmm229TKrgbAC/zm5GFL9nZJ76uFG2qt7iEEcs\n2OZTk3Wcn1YK7OrDQN+dDpti7c+joNsBCIiFhprBxidc509KKxhlftB3IH//DIlO\n5KCzfVIk+bTY7jFId+Vrl0ua2T8lkVZhuN9hksiu5FQfXSMg6LT+yPylO1YznpBV\nrAvkVsd6jFgyAqHO+9hHAKzan8QGlzYBrjiJCKovV8N3yYn4Ij5cVtJ5oEkf3PIG\n6nJTY4abn18Pu6IvGuHFzI6ZR2KSicXxBDPm5KiK0X86gVYxx99Bg6+nm2Vdx0nx\nSoqc5BI0n/fZGer5s1jsskeLD/jPAgMBAAGjgdIwgc8wCQYDVR0TBAIwADAdBgNV\nHQ4EFgQUwsGVn42F+34CjppO2e/RXpB7M14wfgYDVR0jBHcwdYAUU7/avL3rxixS\nYklqUei9iWSpTjahSKRGMEQxEjAQBgoJkiaJk/IsZAEZFgJaVzESMBAGCgmSJomT\n8ixkARkWAlJBMRowGAYDVQQDExFaSU1SQSBJc3N1aW5nIENBM4ITFAAAATQI/Nxa\npLIgcgAAAAABNDAOBgNVHQ8BAf8EBAMCBeAwEwYDVR0lBAwwCgYIKwYBBQUHAwIw\nDQYJKoZIhvcNAQELBQADggEBACtbkfksAfI4pnG84qXWTK+d4X6rv8oGSEpez98D\nD6a85Z5sTlfM7Jyvx+gTjdOmNdIr6lxAIVWvCGStM4qwLpQSPvfm8elM8JEvAHSI\nRh9hcwKiWGEpIILa1pgN5q9Z6uUuVr6XOu2uVhayTwbG6wFCTAcJIeZBsiUfjnAe\njCBYDM3UnaYgjaPXd9nD925f/znFSdpSi7zwFwbULhEvCH2iDsL8NR+W8fP8MCII\nHpy/n6rmOGWiS20oCCJ6bIJEKKtzH31Gap7H8KOZlmImycZZRzK5NfHvNfasV6Mc\nazDHTuR4Y2vNgKDvLrUj1lIBjX4Zx7LLwTuFxMnVlIjhD3Q=\n-----END CERTIFICATE-----', '2026-12-29 07:59:26', '-----BEGIN PRIVATE KEY-----\nMIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQCGM30Fm1dRsXvs\n1isC7kMmm229TKrgbAC/zm5GFL9nZJ76uFG2qt7iEEcs2OZTk3Wcn1YK7OrDQN+d\nDpti7c+joNsBCIiFhprBxidc509KKxhlftB3IH//DIlO5KCzfVIk+bTY7jFId+Vr\nl0ua2T8lkVZhuN9hksiu5FQfXSMg6LT+yPylO1YznpBVrAvkVsd6jFgyAqHO+9hH\nAKzan8QGlzYBrjiJCKovV8N3yYn4Ij5cVtJ5oEkf3PIG6nJTY4abn18Pu6IvGuHF\nzI6ZR2KSicXxBDPm5KiK0X86gVYxx99Bg6+nm2Vdx0nxSoqc5BI0n/fZGer5s1js\nskeLD/jPAgMBAAECggEACYIxbAnHJJMqQCwmjQUbvesKWfzKKK+OWAjE2HNU4nsH\nJqWTqJkvxJ25pUxS+X37udayToDd22rHzUWBLf/ClAnsKoUTwz43zd3/4P3EqPEn\nv9094Qrs9sHJIs1hM4aAIP2OWkZ6OHPCTh7ArR6SclN7Zt4l+bBgRsAH09cSC51H\nHINhxkEJl/i/0PHjaItxj2PBOeBhn+9Y8t9IHkg6qMFunU3BFn+QkPDbneX4rddv\n6VLAHb1edcpQNN7eZCOJQzFDWJDCzIyiaegKsD0pwMmEwn9FQudfeZGUB2EaH68k\nbzX8+OYRrJlGvg5ojNFkXiXQB28K/vVWkVFHUlF7hQKBgQC8yPWyjN3UKVHPsLhb\nl8a5La8AlmVz51tNm6ogTduX+3fLZY4EhweDgcDzgKO4zqDA36IEo5WpD6THqhsV\nv9KaP3/e6xBFxLcOUh7xggCOlIsFKC51lijrOMazraj80jxqckz1BGK/X1V3vkij\nb+bIVj3Asgr12C9OKSRizYTUKwKBgQC1+2ojmzqJLd4r+S4314czmv7FzqGzMZYu\n+YA6e+6nURs0wvkC2EMVgSho4lvJLbB5qzM//CpQgDPdjh7Rex6dbW7GzYd6cd0k\ntmORVSRC8hl2G+NKlg2PdHDdTf0fB25XNlcbuq1bOi58z5IXg1PVbiCZmkcjxYL3\nLxFfdM8n7QKBgQCT1OpRv22WTiT6dnBniRrct6Fq3FrlwC4HP/ahBVcIVKsiY4wq\nj3Ka0GjARePvPB816el9qHvxv4ZRtCsxhNzuXPtNHNXJTJnsZPJGPH8jJ78Vcrmu\n6r9wMy2mVj8We6tDz+3jkGOjaIwNELzg/yfBiYch9koO6hNhKWaM2FNDsQKBgATy\nvyIUuHS+cIoVjnIqRBzdHAxY4AC1WnYQhrIQaJ7YD7tRid/P7ZMKHgUsEn7X5TKJ\nuy0EOEpUEhT2JlRf2qdBMH/rWsGzkuXKp85t2DyRxKt3eqiuh9PcwKzjz/wmAZQR\ngDDa1Jfkbxspsbk98uucwPosPb71QehiuUA1NuTJAoGAEleFYdvokpJRH1v0fqdM\ndkwg3ZXv8SoQBr1yQXgNJEtumcK2V/A7DWkj/BciszV/CeX+17xw7AZixhyNU6be\nz96pIQNTuYnbYsz/4a682sKG0OmFfKkVrv+lEHOwAiwc74ebiMyVvy23VmZy/QLg\n6oVEpgADQyMYiV+G4b4xBzg=\n-----END PRIVATE KEY-----\n', 1, 1, 'Online', '2025-12-23 16:38:38', NULL, '2025-12-23 16:37:43', '2025-12-29 07:59:28')
ON DUPLICATE KEY UPDATE
    branch_id = VALUES(branch_id),
    device_id = VALUES(device_id),
    device_serial_no = VALUES(device_serial_no),
    activation_key = VALUES(activation_key),
    device_model_name = VALUES(device_model_name),
    device_model_version = VALUES(device_model_version),
    certificate_pem = VALUES(certificate_pem),
    certificate_valid_till = VALUES(certificate_valid_till),
    private_key_pem = VALUES(private_key_pem),
    is_registered = VALUES(is_registered),
    is_active = VALUES(is_active),
    operating_mode = VALUES(operating_mode),
    last_config_sync = VALUES(last_config_sync),
    updated_at = NOW();

SET FOREIGN_KEY_CHECKS=1;

SELECT 'Fiscal devices restored successfully' as status;
SELECT id, branch_id, device_id, device_serial_no, activation_key, is_registered, is_active FROM fiscal_devices;
SQL

echo ""
echo "=========================================="
echo "✓ Fiscal Devices Restored"
echo "=========================================="
