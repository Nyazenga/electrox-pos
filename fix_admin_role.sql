-- Fix admin user role_id to match localhost (role_id = 1 = Administrator)
UPDATE users SET role_id = 1 WHERE email = 'admin@electrox.co.zw';

