<?php
use WHMCS\Database\Capsule;

add_hook('DailyCronJob', 1, function () {

    $targetRegistrar = 'openprovider';
    $noteMarker      = 'Migration triggered'; // plain ASCII — avoids Unicode LIKE issues

    logActivity("[MIGRATION_AUTORENEW] DailyCronJob fired — scanning for transferred domains");

    $q = Capsule::table('tbldomains')
        ->whereRaw('LOWER(registrar) = ?', [strtolower($targetRegistrar)])
        ->whereRaw('LOWER(status) = ?', ['active'])
        ->where('donotrenew', 1)
        ->where('additionalnotes', 'like', '%' . $noteMarker . '%');

    $rows = $q->pluck('id');

    if ($rows->isEmpty()) {
        logActivity("[MIGRATION_AUTORENEW] No domains found matching criteria (Active + openprovider + donotrenew=1 + migration note)");
        return;
    }

    $ids     = $rows->all();
    $updated = Capsule::table('tbldomains')
        ->whereIn('id', $ids)
        ->update(['donotrenew' => 0]);

    logActivity("[MIGRATION_AUTORENEW] Cleared donotrenew for {$updated} domain(s). IDs: " . implode(', ', $ids));
});
