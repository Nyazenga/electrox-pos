# Testing Steps for Laybyes and Wholesale Sales

## Step 1: Run Database Setup

### Option A: Via phpMyAdmin (Recommended)
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Select database: `electrox_primary`
3. Click on "SQL" tab
4. Copy and paste the contents of `database/setup_laybyes_wholesale.sql`
5. Click "Go" to execute

### Option B: Via MySQL Command Line
```bash
mysql -u root -p electrox_primary < database/setup_laybyes_wholesale.sql
```
(When prompted for password, just press Enter since password is empty)

## Step 2: Seed Permissions

Run this in your browser or command line:
```
http://localhost/electrox-pos/database/seed_permissions.php
```

Or via command line:
```bash
php database/seed_permissions.php
```

## Step 3: Testing Checklist

### ✅ Test Wholesale Price Fields

1. **Product Add Page**
   - Go to: Products → Add Product
   - Fill in product details
   - Check that "Wholesale Price" field appears after "Selling Price"
   - Enter a wholesale price (e.g., 7.00 if selling price is 8.00)
   - Save the product
   - Verify wholesale price is saved

2. **Product Edit Page**
   - Go to: Products → Edit any product
   - Check that "Wholesale Price" field is visible
   - Update the wholesale price
   - Save and verify

3. **GRN (Goods Received Notes)**
   - Go to: Inventory → GRN → Add GRN
   - Add an item to the GRN
   - In the "Add Item" modal, check that "Wholesale Price" field appears
   - Enter a wholesale price
   - Save GRN
   - Verify the product's wholesale price was updated

4. **Bulk Upload**
   - Go to: Products → Bulk Upload Products
   - Download the template CSV
   - Check that "Wholesale Price" column is included
   - Fill in a test row with wholesale price
   - Upload the CSV
   - Verify the product was created with wholesale price

### ✅ Test Wholesale Sales

1. **POS - Wholesale Sale Checkbox**
   - Login as Administrator (only admins can see this)
   - Go to: POS → New Sales
   - Add products to cart
   - Go to Payment page
   - Check that "Wholesale/Dealer Sale" checkbox appears (yellow background)
   - Check the checkbox
   - Verify cart prices update to wholesale prices (if set)
   - Process payment
   - Verify sale is marked as `is_wholesale_sale = 1`

2. **Pending Payment**
   - In POS payment page, check "Wholesale/Dealer Sale"
   - Enter $0.00 as payment amount
   - Process payment
   - Verify sale is marked as `is_pending_payment = 1`
   - Later, when dealer pays, you can update the sale

### ✅ Test Laybyes

1. **Create Laybye**
   - Go to: Sales → Laybyes
   - Click "New Laybye"
   - Select a customer
   - Add products (note: product_specific_list items are NOT captured here)
   - Set payment schedule (monthly or custom)
   - Create laybye
   - Verify laybye appears in list with status "pending"

2. **Add Payment to Laybye**
   - Open a laybye from the list
   - Click "Add Payment"
   - Enter payment amount
   - Select payment method
   - Save payment
   - Verify amount_paid and amount_remaining update correctly

3. **Complete Laybye**
   - When laybye is fully paid (amount_remaining = 0)
   - Click "Complete" button
   - System will create actual sale
   - At this point, you can assign product_specific_list items
   - Verify sale is created and linked to laybye

## Step 4: Verify Database

Check these tables/columns exist:
- ✅ `products.wholesale_price`
- ✅ `sales.is_wholesale_sale`
- ✅ `sales.is_pending_payment`
- ✅ `laybyes` table
- ✅ `laybye_items` table
- ✅ `laybye_payments` table
- ✅ `laybye_payment_schedule` table

## Step 5: Verify Permissions

Check that these permissions exist in `permissions` table:
- `sales.laybyes`
- `sales.laybyes.create`
- `sales.laybyes.edit`
- `sales.laybyes.complete`
- `sales.laybyes.cancel`
- `sales.laybyes.add_payment`
- `reports.laybyes`
- `sales.wholesale`
- `reports.wholesale`

## Notes

- **Wholesale checkbox** only appears for Administrators
- **Laybyes** don't capture product_specific_list items until completion
- **Wholesale prices** are optional - if not set, regular price is used
- **Pending payments** can be updated later when dealer brings payment

## Troubleshooting

If you see errors:
1. Make sure MySQL is running
2. Check database credentials in `config.php`
3. Verify you're using the correct database (`electrox_primary`)
4. Check that all foreign key tables exist (customers, branches, users, products, sales)
