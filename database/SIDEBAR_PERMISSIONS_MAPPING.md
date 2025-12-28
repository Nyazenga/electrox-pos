# Sidebar Navigation to Permissions Mapping

## Comprehensive Mapping Table

| Sidebar Element | Permission Key | Permission Name | Page File | Page Permission Check | Status |
|----------------|---------------|-----------------|-----------|----------------------|--------|
| **Dashboard** | | | | | |
| Dashboard | `dashboard.view` | View Dashboard | `modules/dashboard/index.php` | `dashboard.view` | ✅ |
| **Products** | | | | | |
| Manage Products | `products.view` | View Manage Products | `modules/products/index.php` | `products.view` | ✅ |
| Manage Categories | `products.categories` | View Manage Categories | `modules/products/categories.php` | `products.categories` | ✅ |
| Stock Levels | `inventory.view` | View Stock Levels | `modules/inventory/index.php` | `inventory.view` | ✅ |
| Goods Received | `grn.view` | View Goods Received | `modules/inventory/grn.php` | `grn.view` | ✅ |
| Transfers | `transfers.view` | View Transfers | `modules/inventory/transfers.php` | `transfers.view` | ✅ |
| Trade-Ins | `tradeins.view` | View Trade-Ins | `modules/tradeins/index.php` | `tradeins.view` | ✅ |
| **POS** | | | | | |
| New Sales | `pos.create_sale` | Create New Sales | `modules/pos/index.php` | `pos.view` OR `pos.create_sale` | ✅ |
| Manage Sales | `pos.manage_sales` | View Manage Sales | `modules/pos/manage.php` | `pos.manage_sales` | ✅ |
| Cash Management | `pos.cash_management` | View Cash Management | `modules/pos/cash.php` | `pos.cash_management` OR `drawer.transaction` OR `drawer.report` | ✅ |
| POS Customization | `pos.customize` | View POS Customization | `modules/pos/customize.php` | `pos.customize` | ✅ |
| **Sales** | | | | | |
| Sales Dashboard | `sales.view` | View Sales | `modules/sales/dashboard.php` | `sales.view` | ✅ |
| Sales | `sales.view` | View Sales | `modules/sales/index.php` | `sales.view` | ✅ |
| **Invoicing** | | | | | |
| All Invoices | `invoicing.view` | View All Invoices | `modules/invoicing/index.php` | `invoicing.view` | ✅ |
| Proforma Invoice | `invoicing.create` | Create Invoices | `modules/invoicing/create.php?type=proforma` | `invoicing.create` | ✅ |
| Tax Invoice | `invoicing.create` | Create Invoices | `modules/invoicing/create.php?type=tax` | `invoicing.create` | ✅ |
| Quote | `invoicing.create` | Create Invoices | `modules/invoicing/create.php?type=quote` | `invoicing.create` | ✅ |
| Credit Note | `invoicing.create` | Create Invoices | `modules/invoicing/create.php?type=credit` | `invoicing.create` | ✅ |
| Customize | `invoicing.customize` | View Customize | `modules/invoicing/customize.php` | `invoicing.customize` | ✅ |
| **Customers** | | | | | |
| All Customers | `customers.view` | View All Customers | `modules/customers/index.php` | `customers.view` | ✅ |
| Add Customer | `customers.create` | Create Customers | `modules/customers/add.php` | `customers.create` | ✅ |
| **Suppliers** | | | | | |
| All Suppliers | `suppliers.view` | View All Suppliers | `modules/suppliers/index.php` | `suppliers.view` | ✅ |
| Add Supplier | `suppliers.create` | Create Suppliers | `modules/suppliers/add.php` | `suppliers.create` | ✅ |
| **Reports** | | | | | |
| Dashboard | `reports.view` | View Reports | `modules/reports/index.php` | `reports.view` | ✅ |
| **General Reports** (Category) | `reports.general` | View All General Reports | N/A (Category) | Category OR specific | ⚠️ NEW |
| Sales Summary | `reports.sales_summary` | Sales Summary Report | `modules/reports/sales_summary.php` | `reports.general` OR `reports.sales_summary` OR `reports.view` | ✅ |
| Receipts | `reports.receipts` | Receipts Report | `modules/reports/receipts.php` | `reports.general` OR `reports.receipts` OR `reports.view` | ✅ |
| Refunds | `reports.refunds` | Refunds Report | `modules/reports/refunds.php` | `reports.general` OR `reports.refunds` OR `reports.view` | ✅ |
| Sales by Products | `reports.sales_by_product` | Sales by Product Report | `modules/reports/sales_by_products.php` | `reports.general` OR `reports.sales_by_product` OR `reports.view` | ✅ |
| Sales by Category | `reports.sales_by_category` | Sales by Category Report | `modules/reports/sales_by_category.php` | `reports.general` OR `reports.sales_by_category` OR `reports.view` | ✅ |
| Sales by Discounts | `reports.sales_by_discount` | Sales by Discount Report | `modules/reports/sales_by_discounts.php` | `reports.general` OR `reports.sales_by_discount` OR `reports.view` | ✅ |
| Sales by Payment Types | `reports.sales_by_payment` | Sales by Payment Type Report | `modules/reports/sales_by_payment_types.php` | `reports.general` OR `reports.sales_by_payment` OR `reports.view` | ✅ |
| Taxes | `reports.taxes` | Taxes Report | `modules/reports/taxes.php` | `reports.general` OR `reports.taxes` OR `reports.view` | ✅ |
| Shifts | `reports.shifts` | Shifts Report | `modules/reports/shifts.php` | `reports.general` OR `reports.shifts` OR `reports.view` | ✅ |
| **Advanced Sales Reports** (Category) | `reports.advanced_sales` | View All Advanced Sales Reports | N/A (Category) | Category OR specific | ⚠️ NEW |
| Product Wise Receipt | `reports.product_wise_receipt` | Product Wise Receipt Report | `modules/reports/product_wise_receipt.php` | `reports.advanced_sales` OR `reports.product_wise_receipt` OR `reports.view` | ✅ |
| Sales by Trend | `reports.sales_by_trend` | Sales Trend Report | `modules/reports/sales_by_trend.php` | `reports.advanced_sales` OR `reports.sales_by_trend` OR `reports.view` | ✅ |
| Deleted Receipts | `reports.deleted_receipts` | Deleted Receipts Report | `modules/reports/deleted_receipts.php` | `reports.advanced_sales` OR `reports.deleted_receipts` OR `reports.view` | ✅ |
| Order Type Wise Sales | `reports.order_type_wise` | Order Type Wise Sales Report | `modules/reports/order_type_wise_sales.php` | `reports.advanced_sales` OR `reports.order_type_wise` OR `reports.view` | ✅ |
| Product Wise Tax/Charge | `reports.product_wise_tax` | Product Wise Tax Report | `modules/reports/product_wise_tax_charge.php` | `reports.advanced_sales` OR `reports.product_wise_tax` OR `reports.view` | ✅ |
| Manual Receipts | `reports.manual_receipts` | Manual Receipts Report | `modules/reports/manual_receipts.php` | `reports.advanced_sales` OR `reports.manual_receipts` OR `reports.view` | ✅ |
| Sales by Orders | `reports.sales_by_order` | Sales by Order Report | `modules/reports/sales_by_orders.php` | `reports.advanced_sales` OR `reports.sales_by_order` OR `reports.view` | ✅ |
| Product Wise Orders | `reports.product_wise_order` | Product Wise Order Report | `modules/reports/product_wise_orders.php` | `reports.advanced_sales` OR `reports.product_wise_order` OR `reports.view` | ✅ |
| Sales by Modifiers | `reports.sales_by_modifier` | Sales by Modifier Report | `modules/reports/sales_by_modifiers.php` | `reports.advanced_sales` OR `reports.sales_by_modifier` OR `reports.view` | ✅ |
| Product Sales by Staff | `reports.product_sales_by_staff` | Product Sales by Staff Report | `modules/reports/product_sales_by_staff.php` | `reports.advanced_sales` OR `reports.product_sales_by_staff` OR `reports.view` | ✅ |
| Sales by Staff | `reports.sales_by_staff` | Sales by Staff Report | `modules/reports/sales_by_staff.php` | `reports.advanced_sales` OR `reports.sales_by_staff` OR `reports.view` | ✅ |
| Products Consumed by Staff | `reports.products_consumed_by_staff` | Products Consumed by Staff Report | `modules/reports/products_consumed_by_staff.php` | `reports.advanced_sales` OR `reports.products_consumed_by_staff` OR `reports.view` | ✅ |
| Ecommerce Sales | `reports.ecommerce_sales` | E-commerce Sales Report | `modules/reports/ecommerce_sales.php` | `reports.advanced_sales` OR `reports.ecommerce_sales` OR `reports.view` | ✅ |
| **Suspicious Reports** (Category) | `reports.suspicious` | View All Suspicious Reports | N/A (Category) | Category OR specific | ⚠️ NEW |
| Product Wise Deleted Receipts | `reports.product_wise_deleted` | Product Wise Deleted Receipts Report | `modules/reports/product_wise_deleted_receipts.php` | `reports.suspicious` OR `reports.product_wise_deleted` OR `reports.view` | ✅ |
| Refunds & Credit Notes | `reports.refunds_credit_notes` | Refunds and Credit Notes Report | `modules/reports/refunds_credit_notes.php` | `reports.suspicious` OR `reports.refunds_credit_notes` OR `reports.view` | ✅ |
| Deleted Products in Open Orders | `reports.deleted_products_open_orders` | Deleted Products Open Orders Report | `modules/reports/deleted_products_open_orders.php` | `reports.suspicious` OR `reports.deleted_products_open_orders` OR `reports.view` | ✅ |
| **Administration** | | | | | |
| Branches | `branches.view` | View Branches | `modules/branches/index.php` | `branches.view` | ✅ |
| Users | `users.view` | View Users | `modules/users/index.php` | `users.view` | ✅ |
| Roles & Permissions | `roles.view` | View Roles | `modules/roles/index.php` | `roles.view` | ✅ |
| Currencies | `currencies.view` | View Currencies | `modules/currencies/index.php` | `currencies.view` | ✅ |
| Settings | `settings.view` | View Settings | `modules/settings/index.php` | `settings.view` | ✅ |
| Fiscalization Status | `fiscalization.view_status` | View Branch Device Status | `check_fiscalization_status.php` | `fiscalization.view_status` | ✅ |
| All Fiscalizations | `fiscalization.view_all` | View All Fiscalizations | `view_all_fiscalizations.php` | `fiscalization.view_all` | ✅ |

## Notes

- ✅ = Permission exists and is correctly mapped
- ⚠️ NEW = New category permission to be added
- All permissions follow the pattern: `module.action` (e.g., `products.view`, `invoicing.create`)
- Report category permissions (`reports.general`, `reports.advanced_sales`, `reports.suspicious`) will auto-select all sub-permissions when assigned
- Pages check for category permission OR specific permission OR `reports.view` for backward compatibility

