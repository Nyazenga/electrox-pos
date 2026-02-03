@echo off
REM Run password reset on remote server
REM Usage: run_password_reset.bat

echo Connecting to server and resetting password...
echo.

plink.exe -ssh 31.97.199.82 -pw "GRCAdmin123/" "cd /var/www/html/electrox-pos && php -r \"define('HASH_COST', 10); \$dbHost = 'localhost'; \$dbUser = 'root'; \$dbPass = 'GRCAdmin123/'; \$dbName = 'electrox_primary'; \$email = 'nyazengamd@gmail.com'; \$newPassword = 'Admin123/'; try { \$pdo = new PDO('mysql:host='.\$dbHost.';dbname='.\$dbName.';charset=utf8mb4', \$dbUser, \$dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); echo 'Connected to database: '.\$dbName.PHP_EOL; \$stmt = \$pdo->prepare('SELECT * FROM users WHERE email = :email'); \$stmt->execute([':email' => \$email]); \$user = \$stmt->fetch(); if (!\$user) { echo 'ERROR: User not found!'.PHP_EOL; \$allUsers = \$pdo->query('SELECT id, email, username FROM users LIMIT 10')->fetchAll(); echo 'Available users:'.PHP_EOL; foreach (\$allUsers as \$u) { echo '  - '.\$u['email'].' (ID: '.\$u['id'].')'.PHP_EOL; } exit(1); } echo 'User found: ID='.\$user['id'].', Email='.\$user['email'].PHP_EOL; \$hashedPassword = password_hash(\$newPassword, PASSWORD_BCRYPT, ['cost' => HASH_COST]); \$updateStmt = \$pdo->prepare('UPDATE users SET password = :password, login_attempts = 0, status = \"active\" WHERE id = :id'); \$result = \$updateStmt->execute([':password' => \$hashedPassword, ':id' => \$user['id']]); if (\$result) { echo 'SUCCESS: Password updated!'.PHP_EOL; echo 'New password: '.\$newPassword.PHP_EOL; } else { echo 'FAILED: Could not update password'.PHP_EOL; exit(1); } } catch (Exception \$e) { echo 'ERROR: '.\$e->getMessage().PHP_EOL; exit(1); }\""

pause
