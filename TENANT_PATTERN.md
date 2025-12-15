# Tenant Naming Pattern Documentation

## Pattern Rule
**tenant_slug = database suffix**

This means:
- If `tenant_slug = "primary"`, then `database_name = "electrox_primary"`
- If `tenant_slug = "acme"`, then `database_name = "electrox_acme"`

## How It Works

### Registration Process
1. User enters `tenant_name` in registration form (e.g., "primary")
2. On approval, `tenant_name` becomes `tenant_slug` (lowercased): `tenant_slug = "primary"`
3. Database is created: `database_name = "electrox_" + tenant_slug = "electrox_primary"`

### Login Process
1. User enters `tenant_name` in login form (e.g., "primary")
2. System stores it in session as `$_SESSION['tenant_name'] = "primary"`
3. Database connection uses: `electrox_` + `tenant_name` = `electrox_primary`

### Database Connection
- `getCurrentTenantDbName()` returns `$_SESSION['tenant_name']` (e.g., "primary")
- `Database::getInstance()` constructs: `electrox_` + `tenant_name` = `electrox_primary`

## Important Notes

1. **tenant_slug is the unique identifier** - it's what users enter at login
2. **tenant_name in tenants table** - this is the display name (e.g., "ELECTROX Primary")
3. **database_name** - always follows pattern: `electrox_{tenant_slug}`
4. **Registration table** - `tenant_name` field becomes `tenant_slug` on approval

## Current Tenants

- **primary**: `tenant_slug = "primary"`, `database = "electrox_primary"` ✓

## Code References

- `config.php`: `getCurrentTenantDbName()` - returns session tenant_name
- `includes/db.php`: Line 21 - constructs database name: `'electrox_' . $currentTenant`
- `includes/functions.php`: `approveTenant()` - Line 141: `$tenantSlug = strtolower($registration['tenant_name'])`
- `includes/functions.php`: `approveTenant()` - Line 151: `'database_name' => 'electrox_' . $tenantSlug`

