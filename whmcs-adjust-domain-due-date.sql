-- ============================================================================
-- WHMCS Domain Due Date Adjustment Script
-- ============================================================================
-- Purpose: Move next due date one week earlier for active CNIC domains
--          to provide buffer time for domain transfers to complete
-- 
-- IMPORTANT: BACKUP YOUR DATABASE BEFORE RUNNING THIS!
-- ============================================================================

-- Step 1: Preview what will change (RUN THIS FIRST)
-- This shows you exactly which domains will be affected and their new dates
SELECT 
    id,
    domain,
    registrar,
    status,
    nextduedate AS current_due_date,
    DATE_SUB(nextduedate, INTERVAL 7 DAY) AS new_due_date,
    DATEDIFF(nextduedate, CURDATE()) AS days_until_due
FROM 
    tbldomains
WHERE 
    LOWER(registrar) = 'cnic'
    AND status = 'Active'
    AND nextduedate IS NOT NULL
    AND nextduedate > CURDATE()  -- Only future due dates
ORDER BY 
    nextduedate ASC;

-- ============================================================================
-- Step 2: Actually update the dates (RUN AFTER REVIEWING PREVIEW)
-- ============================================================================
-- UNCOMMENT THE LINES BELOW WHEN READY TO EXECUTE

/*
UPDATE tbldomains
SET 
    nextduedate = DATE_SUB(nextduedate, INTERVAL 7 DAY),
    nextinvoicedate = DATE_SUB(nextinvoicedate, INTERVAL 7 DAY)
WHERE 
    LOWER(registrar) = 'cnic'
    AND status = 'Active'
    AND nextduedate IS NOT NULL
    AND nextduedate > CURDATE();

-- Show how many domains were updated
SELECT ROW_COUNT() AS domains_updated;
*/

-- ============================================================================
-- Step 3: Verify the changes (RUN AFTER UPDATE)
-- ============================================================================
/*
SELECT 
    id,
    domain,
    registrar,
    status,
    nextduedate,
    nextinvoicedate,
    DATEDIFF(nextduedate, CURDATE()) AS days_until_due
FROM 
    tbldomains
WHERE 
    LOWER(registrar) = 'cnic'
    AND status = 'Active'
ORDER BY 
    nextduedate ASC
LIMIT 20;
*/

-- ============================================================================
-- OPTIONAL: More targeted updates
-- ============================================================================

-- Option A: Only adjust domains due within the next 30 days
/*
UPDATE tbldomains
SET 
    nextduedate = DATE_SUB(nextduedate, INTERVAL 7 DAY),
    nextinvoicedate = DATE_SUB(nextinvoicedate, INTERVAL 7 DAY)
WHERE 
    LOWER(registrar) = 'cnic'
    AND status = 'Active'
    AND nextduedate IS NOT NULL
    AND nextduedate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY);
*/

-- Option B: Only adjust specific domains (whitelist)
/*
UPDATE tbldomains
SET 
    nextduedate = DATE_SUB(nextduedate, INTERVAL 7 DAY),
    nextinvoicedate = DATE_SUB(nextinvoicedate, INTERVAL 7 DAY)
WHERE 
    LOWER(registrar) = 'cnic'
    AND status = 'Active'
    AND LOWER(domain) IN (
        'example.com',
        'testdomain.net',
        'myipnetworks.com'
    );
*/

-- Option C: Different interval (e.g., 10 days instead of 7)
/*
UPDATE tbldomains
SET 
    nextduedate = DATE_SUB(nextduedate, INTERVAL 10 DAY),
    nextinvoicedate = DATE_SUB(nextinvoicedate, INTERVAL 10 DAY)
WHERE 
    LOWER(registrar) = 'cnic'
    AND status = 'Active'
    AND nextduedate IS NOT NULL
    AND nextduedate > CURDATE();
*/

-- ============================================================================
-- ROLLBACK: If you need to undo (within same session, if using transactions)
-- ============================================================================
/*
-- If you wrapped the UPDATE in a transaction:
START TRANSACTION;
-- ... run your UPDATE ...
-- If something looks wrong:
ROLLBACK;
-- If everything looks good:
COMMIT;
*/
