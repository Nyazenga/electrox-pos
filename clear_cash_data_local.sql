SET FOREIGN_KEY_CHECKS=0;

-- Clear drawer_transactions
TRUNCATE TABLE drawer_transactions;
ALTER TABLE drawer_transactions AUTO_INCREMENT = 1;

-- Clear shifts
TRUNCATE TABLE shifts;
ALTER TABLE shifts AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS=1;

-- Verify
SELECT 
    (SELECT COUNT(*) FROM drawer_transactions) as drawer_transactions_count,
    (SELECT COUNT(*) FROM shifts) as shifts_count;
