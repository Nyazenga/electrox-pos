-- Password Reset SQL for electrox_primary database
-- Run this directly on the server using: mysql -u root -p'GRCAdmin123/' electrox_primary < reset_password_sql.sql
-- OR connect and run: mysql -u root -p'GRCAdmin123/' electrox_primary

-- First, let's see the current user
SELECT id, email, username, status FROM users WHERE email = 'nyazengamd@gmail.com';

-- Note: This SQL approach won't work directly because passwords are hashed with PHP's password_hash()
-- You need to run the PHP script instead
