-- Update stock_movements table to include 'Trade-In' in movement_type enum
-- This allows proper tracking of trade-in stock additions

ALTER TABLE `stock_movements` 
MODIFY COLUMN `movement_type` enum('Purchase','Sale','Transfer','Adjustment','Damage','Return','Trade-In') DEFAULT 'Adjustment';

