-- Payment Milestones: deposit & percentage-based payment phases for quotes and invoices
-- Run this migration to add milestone support to the QI module.

-- 1. Create payment_milestones table
CREATE TABLE IF NOT EXISTS payment_milestones (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('quote','invoice') NOT NULL,
    entity_id INT(10) UNSIGNED NOT NULL,
    company_id INT(10) UNSIGNED NOT NULL,
    label VARCHAR(190) NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    due_date DATE DEFAULT NULL,
    status ENUM('pending','due','paid','overdue') DEFAULT 'pending',
    amount_paid DECIMAL(15,2) DEFAULT 0.00,
    sort_order INT(11) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Create milestone_payments linking table
CREATE TABLE IF NOT EXISTS milestone_payments (
    id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    milestone_id INT(10) UNSIGNED NOT NULL,
    payment_id INT(10) UNSIGNED NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_milestone (milestone_id),
    INDEX idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Add has_milestones flag to quotes
ALTER TABLE quotes ADD COLUMN has_milestones TINYINT(1) DEFAULT 0 AFTER notes;

-- 4. Add has_milestones flag to invoices
ALTER TABLE invoices ADD COLUMN has_milestones TINYINT(1) DEFAULT 0 AFTER notes;
