# ELECTROX-POS System

Comprehensive Stock Management, Invoicing & POS System for Electronics Retail Businesses

## System Setup Complete

### Database Setup
- Base Database: `electrox_base` (tenant registry)
- Primary Database: `electrox_primary` (template database)

### Login Information

**Default Primary Tenant (Ready to Use):**
1. Go to: http://localhost/electrox-pos/login.php
2. Tenant Name: `primary`
3. Email: `admin@electrox.co.zw`
4. Password: `Admin@123`

**Test Cashier Account (Same Tenant):**
- Tenant Name: `primary`
- Email: `cashier@electrox.co.zw`
- Password: `Admin@123`

**After registering a new tenant:**
1. Register at: http://localhost/electrox-pos/register.php
2. Admin approves at: http://localhost/electrox-pos/admin/login.php
3. Login with your registered tenant name and credentials

### First Steps

1. **Register a Tenant:**
   - Go to: http://localhost/electrox-pos/register.php
   - Fill in business details
   - Wait for admin approval (or approve manually in admin panel)

2. **Admin Approval:**
   - Admin can approve tenants from the admin panel
   - Once approved, tenant database is created automatically

3. **Login and Start Using:**
   - Login with tenant credentials
   - Start adding products, processing sales, managing inventory

### Required Images

Place the following images in `assets/images/`:
- logo.png - ELECTROX company logo
- logo-icon.png - Small icon version
- favicon.ico - Browser favicon
- default-product.jpg - Default product image
- default-avatar.png - Default user avatar

### Features Implemented

- Multi-tenant SaaS architecture
- Product management
- Inventory management
- Point of Sale (POS)
- Invoicing system
- Customer management
- Trade-in management
- Reporting system
- User & role management
- Branch management
- Settings configuration

### System Requirements

- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx web server
- Composer (for dependencies)

### Dependencies

Run `composer install` to install:
- PHPMailer
- TCPDF
- Google Generative AI (Gemini)

### Color Scheme

The system uses a blue color scheme based on the ELECTROX logo:
- Primary Blue: #1e3a8a
- Secondary Blue: #3b82f6
- Light Blue: #dbeafe
- Accent Blue: #60a5fa
- Dark Navy: #1e40af

### Support

For issues or questions, contact the development team.

