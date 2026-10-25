-- REVIEW ONLY. Not run during the audit. Run only on a verified synthetic MySQL database.
-- These are representative SQL shapes from the named code, NOT captured query traces.
-- Replace fixture IDs and dates. Do not treat them as production query plans.
-- Capture exact Eloquent SQL + bindings privately first, including soft-delete scopes,
-- manager permission subqueries and eager-load SQL; EXPLAIN those as well.
-- EXPLAIN FORMAT=JSON does not execute the SELECT. EXPLAIN ANALYZE does: do not use here.
SET SESSION max_execution_time = 2000;
SET TRANSACTION READ ONLY;
START TRANSACTION;

SHOW INDEX FROM rooms;
SHOW INDEX FROM tenants;
SHOW INDEX FROM room_tenants;
SHOW INDEX FROM contracts;
SHOW INDEX FROM invoices;
SHOW INDEX FROM payments;
SHOW INDEX FROM utility_readings;

-- TenantController::index, unfiltered and contains-search variants.
EXPLAIN FORMAT=JSON SELECT * FROM tenants
WHERE landlord_id = 100001 AND deleted_at IS NULL
ORDER BY created_at DESC, id DESC;
EXPLAIN FORMAT=JSON SELECT * FROM tenants
WHERE landlord_id = 100001 AND deleted_at IS NULL
AND (full_name LIKE '%synthetic%' OR phone LIKE '%123%' OR email LIKE '%synthetic%' OR identity_number LIKE '%123%')
ORDER BY created_at DESC, id DESC;

-- RoomController list core: app adds correlated counts/EXISTS + eager loads;
-- PHP RoomOrderingService, not this SQL, currently performs final room ordering.
EXPLAIN FORMAT=JSON SELECT * FROM rooms
WHERE boarding_house_id = 100001 AND deleted_at IS NULL;

-- InvoiceController::index and UtilityMeter::readings.
EXPLAIN FORMAT=JSON SELECT * FROM invoices
WHERE room_id = 100001 AND deleted_at IS NULL ORDER BY billing_period DESC;
EXPLAIN FORMAT=JSON SELECT * FROM utility_readings
WHERE utility_meter_id = 100001 ORDER BY reading_date;

-- Dashboard recent debt ordering shape (permission clauses omitted here).
EXPLAIN FORMAT=JSON SELECT id, room_id, due_date FROM invoices
WHERE room_id IN (100001) AND deleted_at IS NULL
AND status IN ('UNPAID', 'PARTIALLY_PAID') AND total_amount > paid_amount
ORDER BY CASE WHEN due_date < '2026-10-25' THEN 0 ELSE 1 END,
CASE WHEN due_date IS NULL THEN 1 ELSE 0 END, due_date, id DESC LIMIT 8;

-- BillingService date wrapper vs direct comparison: compare chosen key/rows examined.
EXPLAIN FORMAT=JSON SELECT * FROM utility_readings
WHERE utility_meter_id = 100001 AND DATE(reading_date) <= '2026-10-31'
ORDER BY reading_date DESC, id DESC LIMIT 1;
EXPLAIN FORMAT=JSON SELECT * FROM utility_readings
WHERE utility_meter_id = 100001 AND reading_date <= '2026-10-31'
ORDER BY reading_date DESC, id DESC LIMIT 1;
ROLLBACK;
