<?php
/**
 * Post-transfer nameserver correction hook.
 *
 * Fires after OpenProvider (or any registrar) completes a transfer.
 * If the nameservers OP received differ from what WHMCS has in tbldomains,
 * it pushes the correct ones back immediately via DomainSaveNameservers.
 *
 * Addresses a bug in the OpenProvider WHMCS module where external nameservers
 * are silently replaced with module defaults during transferDomain().
 */

use WHMCS\Database\Capsule;

add_hook('AfterRegistrarTransfer', 1, function (array $vars) {

    // ---- CONFIG ----
    $adminUsername   = 'chris';
    $onlyRegistrar   = 'openprovider'; // only act on OP transfers; '' = all registrars
    $logPrefix       = 'NS_CORRECTION';
    // ----------------

    $params   = $vars['params'] ?? [];
    $domainId = (int)($params['domainid'] ?? 0);
    $sld      = trim((string)($params['sld'] ?? ''));
    $tld      = trim((string)($params['tld'] ?? ''));
    $registrar = strtolower(trim((string)($params['registrar'] ?? '')));

    if (!$domainId || $sld === '' || $tld === '') {
        return;
    }

    if ($onlyRegistrar !== '' && $registrar !== strtolower($onlyRegistrar)) {
        return;
    }

    $domain = $sld . '.' . $tld;

    $log = function (string $msg) use ($domain, $domainId, $logPrefix) {
        logActivity("[{$logPrefix}] {$domain} (ID {$domainId}) - {$msg}");
    };

    // Pull NSes from tbldomains — these were preserved by the migration hook.
    $row = Capsule::table('tbldomains')->where('id', $domainId)->first();
    if (!$row) {
        $log("Could not load tbldomains row — skipping NS correction");
        return;
    }

    $storedNs = [];
    for ($i = 1; $i <= 5; $i++) {
        $ns = trim((string)($row->{"ns{$i}"} ?? ''));
        if ($ns !== '') {
            $storedNs["ns{$i}"] = $ns;
        }
    }

    if (count($storedNs) < 2) {
        $log("Fewer than 2 NSes in tbldomains — skipping NS correction (stored: " . implode(', ', $storedNs) . ")");
        return;
    }

    // Ask WHMCS/registrar what NSes are currently set on the domain.
    $currentNsRes = localAPI('DomainGetNameservers', ['domainid' => $domainId], $adminUsername);
    $currentNs    = [];
    if (is_array($currentNsRes) && ($currentNsRes['result'] ?? '') === 'success') {
        for ($i = 1; $i <= 5; $i++) {
            $ns = trim((string)($currentNsRes["ns{$i}"] ?? ''));
            if ($ns !== '') {
                $currentNs[] = strtolower($ns);
            }
        }
    }

    // Compare: are the stored NSes already in place?
    $storedNsLower = array_map('strtolower', array_values($storedNs));
    sort($storedNsLower);
    sort($currentNs);

    if ($storedNsLower === $currentNs) {
        $log("NSes already correct — no correction needed (" . implode(', ', $storedNsLower) . ")");
        return;
    }

    $log(
        "NS mismatch detected. Current: [" . implode(', ', $currentNs) . "] " .
        "Stored: [" . implode(', ', $storedNsLower) . "] — applying correction"
    );

    // Push the correct NSes via DomainSaveNameservers.
    $savePayload = array_merge(['domainid' => $domainId], $storedNs);
    $saveRes     = localAPI('DomainSaveNameservers', $savePayload, $adminUsername);

    if (is_array($saveRes) && ($saveRes['result'] ?? '') === 'success') {
        $log("NS correction applied successfully: " . implode(', ', array_values($storedNs)));
    } else {
        $msg = is_array($saveRes) ? ($saveRes['message'] ?? 'unknown error') : 'invalid response';
        $log("NS correction FAILED: {$msg}");
    }
});
