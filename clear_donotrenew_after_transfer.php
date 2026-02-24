<?php
use WHMCS\Database\Capsule;

add_hook('DailyCronJob', 1, function () {

    $targetRegistrar = 'openprovider';

    // Optional: only clear flags for domains you migrated (recommended).
    // If you logged a marker in additionalnotes like "[CNIC→OPENPROVIDER] Migration triggered"
    $noteMarker = 'CNIC→OPENPROVIDER';

    $q = Capsule::table('tbldomains')
        ->whereRaw('LOWER(registrar) = ?', [strtolower($targetRegistrar)])
        ->whereRaw('LOWER(status) = ?', ['active'])
        ->where('donotrenew', 1);

    // If you use a marker, uncomment this:
    // $q->where('additionalnotes', 'like', '%' . $noteMarker . '%');

    $rows = $q->pluck('id');

    if ($rows->isEmpty()) {
        return;
    }

    $updated = Capsule::table('tbldomains')
        ->whereIn('id', $rows->all())
        ->update(['donotrenew' => 0]);

    logActivity("[CNIC→OPENPROVIDER] Cleared donotrenew=1 for {$updated} transferred domains now Active on {$targetRegistrar}");
});
