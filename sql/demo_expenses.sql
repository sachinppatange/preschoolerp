-- Demo expenses for Collect / Expenses screens.
-- Safe to run more than once: previous [Demo] rows are removed first.
-- phpMyAdmin: select the preschool database, then Import this file.

DELETE FROM expenses WHERE title LIKE '[Demo]%';

INSERT INTO expenses (title, amount, category, expense_date, payment_method, notes, created_at) VALUES
('[Demo] Classroom rent — this month', 25000.00, 'Rent', DATE_SUB(CURDATE(), INTERVAL 8 DAY), 'Bank', 'Shree Properties, bill R-118', NOW()),
('[Demo] Teacher salary — Priya', 18000.00, 'Salary', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'UPI', 'Priya', NOW()),
('[Demo] Helper salary — Meena', 9000.00, 'Salary', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 'Cash', 'Meena', NOW()),
('[Demo] MSEB electricity', 2140.00, 'Electricity', DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'Online', 'MSEB consumer 55-221', NOW()),
('[Demo] Water tanker', 800.00, 'Water', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'Cash', 'Local tanker', NOW()),
('[Demo] Milk and snacks (week)', 1650.00, 'Food', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'UPI', 'Sai Dairy', NOW()),
('[Demo] Puzzle set and crayons', 2340.00, 'Learning', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'UPI', 'Toy World', NOW()),
('[Demo] A4 paper and prints', 420.00, 'Stationery', CURDATE(), 'Cash', 'Shah Stationery', NOW()),
('[Demo] Janmashtami decoration', 1200.00, 'Events', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'Cash', 'Local market', NOW()),
('[Demo] Auto for picnic', 1500.00, 'Transport', DATE_SUB(CURDATE(), INTERVAL 10 DAY), 'Cash', 'Raju auto', NOW()),
('[Demo] Tap repair', 650.00, 'Maintenance', DATE_SUB(CURDATE(), INTERVAL 7 DAY), 'Cash', 'Plumber Suresh', NOW()),
('[Demo] Wi-Fi recharge', 799.00, 'Internet', DATE_SUB(CURDATE(), INTERVAL 6 DAY), 'Online', 'JioFiber', NOW()),
('[Demo] First-aid kit restock', 380.00, 'Medical', DATE_SUB(CURDATE(), INTERVAL 9 DAY), 'Cash', 'Wellness Medical', NOW()),
('[Demo] Housekeeping supplies', 540.00, 'Housekeeping', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'Cash', 'Local kirana', NOW());
