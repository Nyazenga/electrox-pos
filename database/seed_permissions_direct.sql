-- Seed Permissions for Laybyes and Wholesale Sales
-- Run this in phpMyAdmin or MySQL command line

USE electrox_primary;

-- Insert laybyes permissions
INSERT IGNORE INTO permissions (permission_key, permission_name, module, description, created_at) VALUES
('sales.laybyes', 'View and manage laybyes', 'sales', 'View and manage laybyes', NOW()),
('sales.laybyes.create', 'Create new laybyes', 'sales', 'Create new laybyes', NOW()),
('sales.laybyes.edit', 'Edit laybyes', 'sales', 'Edit laybyes', NOW()),
('sales.laybyes.complete', 'Complete laybyes', 'sales', 'Complete laybyes', NOW()),
('sales.laybyes.cancel', 'Cancel laybyes', 'sales', 'Cancel laybyes', NOW()),
('sales.laybyes.add_payment', 'Add payments to laybyes', 'sales', 'Add payments to laybyes', NOW()),
('reports.laybyes', 'View laybye reports', 'reports', 'View laybye reports', NOW()),
('sales.wholesale', 'Process wholesale/dealer sales', 'sales', 'Process wholesale/dealer sales', NOW()),
('reports.wholesale', 'View wholesale sales reports', 'reports', 'View wholesale sales reports', NOW());

-- Assign permissions to Administrator role
SET @admin_role_id = (SELECT id FROM roles WHERE name LIKE '%Administrator%' OR name LIKE '%Admin%' LIMIT 1);

-- If admin role exists, assign all new permissions
INSERT IGNORE INTO role_permissions (role_id, permission_id, created_at)
SELECT @admin_role_id, p.id, NOW()
FROM permissions p
WHERE p.permission_key IN (
    'sales.laybyes',
    'sales.laybyes.create',
    'sales.laybyes.edit',
    'sales.laybyes.complete',
    'sales.laybyes.cancel',
    'sales.laybyes.add_payment',
    'reports.laybyes',
    'sales.wholesale',
    'reports.wholesale'
)
AND @admin_role_id IS NOT NULL;

SELECT 'Permissions seeded successfully!' AS message;
