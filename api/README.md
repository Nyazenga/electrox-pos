# ELECTROX-POS API Documentation

## Access Swagger UI

**Swagger UI:** http://localhost/electrox-pos/api/swagger-ui.php

**Swagger JSON:** http://localhost/electrox-pos/api/swagger.json

## API Base URL

- **Local:** http://localhost/electrox-pos/api/v1
- **Production:** https://app.electrox-pos.com/api/v1

## Authentication

All endpoints (except `/auth`) require authentication via Bearer token.

1. **Login** to get token:
   ```
   POST /api/v1/auth
   {
     "email": "admin@electrox.co.zw",
     "password": "Admin@123",
     "tenant_name": "primary"
   }
   ```

2. **Use token** in Authorization header:
   ```
   Authorization: Bearer {token}
   ```

## Available Endpoints

### Authentication
- `POST /api/v1/auth` - User login

### Products
- `GET /api/v1/products` - Get all products (with pagination, filters)
- `GET /api/v1/products/{id}` - Get product by ID
- `POST /api/v1/products` - Create new product
- `PUT /api/v1/products/{id}` - Update product
- `DELETE /api/v1/products/{id}` - Delete product (soft delete)

### Sales
- `GET /api/v1/sales` - Get all sales/receipts
- `GET /api/v1/sales/{id}` - Get sale by ID
- `POST /api/v1/sales` - Create new sale (POS transaction)

### Invoices
- `GET /api/v1/invoices` - Get all invoices
- `GET /api/v1/invoices/{id}` - Get invoice by ID
- `POST /api/v1/invoices` - Create new invoice
- `PUT /api/v1/invoices/{id}/status` - Update invoice status

### Customers
- `GET /api/v1/customers` - Get all customers
- `GET /api/v1/customers/{id}` - Get customer by ID
- `POST /api/v1/customers` - Create new customer
- `PUT /api/v1/customers/{id}` - Update customer

### Inventory
- `GET /api/v1/inventory` - Get inventory/stock levels
- `GET /api/v1/inventory/grn` - Get GRNs (Goods Received Notes)
- `POST /api/v1/inventory/grn` - Create GRN

### Shifts
- `GET /api/v1/shifts` - Get shifts
- `POST /api/v1/shifts/start` - Start a new shift
- `POST /api/v1/shifts/{id}/end` - End a shift

### Reports
- `GET /api/v1/reports/sales-summary` - Get sales summary report

## Example Requests

### Login
```bash
curl -X POST http://localhost/electrox-pos/api/v1/auth \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@electrox.co.zw",
    "password": "Admin@123",
    "tenant_name": "primary"
  }'
```

### Get Products
```bash
curl -X GET http://localhost/electrox-pos/api/v1/products \
  -H "Authorization: Bearer {token}"
```

### Create Sale
```bash
curl -X POST http://localhost/electrox-pos/api/v1/sales \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "cart": [
      {
        "id": 1,
        "name": "Product Name",
        "price": 100.00,
        "quantity": 2
      }
    ],
    "payments": [
      {
        "method": "cash",
        "amount": 200.00
      }
    ],
    "customer_id": 1
  }'
```

## Response Format

All responses follow this format:

**Success:**
```json
{
  "success": true,
  "message": "Success message",
  "data": { ... }
}
```

**Error:**
```json
{
  "success": false,
  "message": "Error message",
  "errors": []
}
```

## Pagination

List endpoints support pagination:
- `?page=1` - Page number (default: 1)
- `?limit=25` - Items per page (default: 25, max: 100)

Response includes pagination info:
```json
{
  "data": [...],
  "pagination": {
    "page": 1,
    "limit": 25,
    "total": 100,
    "pages": 4
  }
}
```

## Permissions

All endpoints require appropriate permissions:
- `products.view`, `products.create`, `products.edit`, `products.delete`
- `pos.access`
- `invoices.view`, `invoices.create`, `invoices.edit`
- `customers.view`, `customers.create`, `customers.edit`
- `inventory.view`, `inventory.create`
- `reports.view`


