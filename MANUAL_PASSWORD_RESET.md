# Manual Password Reset Instructions

Since plink is getting stuck, here are alternative methods:

## Method 1: Run PHP script directly on server

1. SSH into the server manually using your preferred SSH client:
   ```
   ssh root@31.97.199.82
   Password: GRCAdmin123/
   ```

2. Once connected, run:
   ```bash
   php /tmp/reset_pwd.php
   ```

## Method 2: Use MySQL command line directly

1. SSH into the server
2. Generate the password hash first:
   ```bash
   php -r "echo password_hash('Admin123/', PASSWORD_BCRYPT, ['cost' => 10]);"
   ```
3. Copy the hash output
4. Run MySQL:
   ```bash
   mysql -u root -p'GRCAdmin123/' electrox_primary
   ```
5. In MySQL, run:
   ```sql
   UPDATE users SET password = 'PASTE_HASH_HERE', login_attempts = 0, status = 'active' WHERE email = 'nyazengamd@gmail.com';
   ```

## Method 3: Create and run script on server

1. SSH into server
2. Create the file:
   ```bash
   nano /tmp/reset_pwd.php
   ```
3. Paste the contents of `reset_pwd.php`
4. Save and run:
   ```bash
   php /tmp/reset_pwd.php
   ```

## Expected Output

You should see:
```
Connected to database: electrox_primary
User found: ID=X, Email=nyazengamd@gmail.com
SUCCESS: Password updated!
New password: Admin123/
Login at: https://electrox-pos.com/login.php
```

## New Login Credentials

- **Tenant**: primary
- **Email**: nyazengamd@gmail.com  
- **Password**: Admin123/
- **URL**: https://electrox-pos.com/login.php
