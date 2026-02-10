<?php
/**
 * WHMCS Domain Due Date Adjuster
 * 
 * Adjusts the next due date for active domains to be 7 days earlier,
 * providing a safety buffer for domain transfers to complete.
 * 
 * Usage:
 * 1. Place this file in your WHMCS root directory
 * 2. Run: php whmcs-adjust-domain-due-date.php
 * 3. Review the output
 * 4. Set $dryRun = false and run again to actually update
 * 
 * OR run from browser: yourdomain.com/adjust_dates.php
 * (Remember to delete after use for security!)
 */

use WHMCS\Database\Capsule;

// Autoload WHMCS
$whmcsRoot = __DIR__;
require_once($whmcsRoot . '/init.php');

// ============================================================================
// CONFIGURATION
// ============================================================================

$dryRun = true;  // SET TO false TO ACTUALLY UPDATE DATES
$daysToSubtract = 7;  // Number of days to move the due date earlier
$registrarToAdjust = 'cnic';  // Which registrar's domains to adjust

// Optional: Only adjust domains due within X days from now
// Set to null to adjust ALL future domains
$onlyWithinDays = null;  // Example: 30 (only domains due in next 30 days)

// Optional: Whitelist specific domains (empty array = all domains)
$domainWhitelist = [];
// Example:
// $domainWhitelist = ['example.com', 'testdomain.net'];

// Optional: Blacklist specific domains
$domainBlacklist = [];

// ============================================================================
// SCRIPT START
// ============================================================================

echo "================================================================================\n";
echo "WHMCS Domain Due Date Adjuster\n";
echo "================================================================================\n";
echo "Mode: " . ($dryRun ? "DRY RUN (no changes will be made)" : "LIVE MODE (will update database)") . "\n";
echo "Registrar: {$registrarToAdjust}\n";
echo "Adjustment: -{$daysToSubtract} days\n";
echo "================================================================================\n\n";

try {
    // Build query
    $query = Capsule::table('tbldomains')
        ->where('status', 'Active')
        ->whereNotNull('nextduedate')
        ->where('nextduedate', '>', date('Y-m-d'));
    
    // Case-insensitive registrar match
    $query->whereRaw('LOWER(registrar) = ?', [strtolower($registrarToAdjust)]);
    
    // Optional: only domains due within X days
    if ($onlyWithinDays !== null) {
        $futureDate = date('Y-m-d', strtotime("+{$onlyWithinDays} days"));
        $query->where('nextduedate', '<=', $futureDate);
    }
    
    // Optional: whitelist
    if (!empty($domainWhitelist)) {
        $query->whereIn(Capsule::raw('LOWER(domain)'), array_map('strtolower', $domainWhitelist));
    }
    
    // Optional: blacklist
    if (!empty($domainBlacklist)) {
        $query->whereNotIn(Capsule::raw('LOWER(domain)'), array_map('strtolower', $domainBlacklist));
    }
    
    // Get domains to update
    $domains = $query->get();
    
    if ($domains->isEmpty()) {
        echo "No domains found matching criteria.\n";
        exit(0);
    }
    
    echo "Found " . count($domains) . " domain(s) to adjust:\n\n";
    echo str_pad("ID", 6) . str_pad("Domain", 35) . str_pad("Current Due", 15) . str_pad("New Due", 15) . "Days Until\n";
    echo str_repeat("-", 90) . "\n";
    
    $updatedCount = 0;
    
    foreach ($domains as $domain) {
        $currentDue = $domain->nextduedate;
        $newDue = date('Y-m-d', strtotime($currentDue . " -{$daysToSubtract} days"));
        $daysUntil = round((strtotime($currentDue) - time()) / 86400);
        
        echo str_pad($domain->id, 6) . 
             str_pad($domain->domain, 35) . 
             str_pad($currentDue, 15) . 
             str_pad($newDue, 15) . 
             $daysUntil . "\n";
        
        if (!$dryRun) {
            // Actually update the database
            $updateData = [
                'nextduedate' => $newDue,
            ];
            
            // Also update nextinvoicedate if it exists
            if (!empty($domain->nextinvoicedate)) {
                $currentInvoiceDate = $domain->nextinvoicedate;
                $newInvoiceDate = date('Y-m-d', strtotime($currentInvoiceDate . " -{$daysToSubtract} days"));
                $updateData['nextinvoicedate'] = $newInvoiceDate;
            }
            
            Capsule::table('tbldomains')
                ->where('id', $domain->id)
                ->update($updateData);
            
            // Log to WHMCS activity log
            logActivity(
                "Domain Due Date Adjusted: {$domain->domain} (ID {$domain->id}) - " .
                "Due date changed from {$currentDue} to {$newDue}"
            );
            
            $updatedCount++;
        }
    }
    
    echo str_repeat("-", 90) . "\n";
    
    if ($dryRun) {
        echo "\nDRY RUN: No changes were made to the database.\n";
        echo "Set \$dryRun = false to actually update these " . count($domains) . " domain(s).\n";
    } else {
        echo "\nSUCCESS: Updated {$updatedCount} domain(s).\n";
        echo "All changes have been logged to WHMCS Activity Log.\n";
        
        // Optional: Send admin email notification
        $adminEmail = Capsule::table('tbladmins')
            ->where('roleid', 1)
            ->first();
        
        if ($adminEmail && !empty($adminEmail->email)) {
            $message = "Domain Due Date Adjustment Complete\n\n";
            $message .= "Updated {$updatedCount} domain(s)\n";
            $message .= "Adjustment: -{$daysToSubtract} days\n\n";
            $message .= "Check WHMCS Activity Log for full details.";
            
            // Uncomment to send email:
            // mail($adminEmail->email, "WHMCS: Due Dates Adjusted", $message);
        }
    }
    
    echo "\n================================================================================\n";
    
} catch (\Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
