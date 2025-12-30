-- Insert admin user for electrox_primary database
-- Password: Admin@123 (hashed with bcrypt)

INSERT INTO users (username, email, password, first_name, last_name, status, role_id, created_at)
VALUES ('admin', 'admin@electrox.co.zw', '$2y$10$3xkndv4Den7JbXkyUOfm2urr7JNex7EWTd7a0sXn9W0CgJIa8L116', 'System', 'Administrator', 'active', 1, NOW())
ON DUPLICATE KEY UPDATE email=email;

