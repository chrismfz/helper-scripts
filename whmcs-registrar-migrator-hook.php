<?php
use WHMCS\Database\Capsule;

add_hook('PreRegistrarRenewDomain', 1, function(array $vars) {

    // ---------------- CONFIG ----------------
    $adminUsername = 'chris';

    // Only trigger if current domain registrar matches this (case-insensitive).
    $triggerOnlyIfCurrentRegistrar = 'cnic'; //The old provider//

    // Target registrar module slug
    $targetRegistrar = 'openprovider'; //The new provider//

    // Safety: only run for these domains (lowercase). Keep empty [] to allow all.
    $allowlistDomains = [
        'myipnetworks.com',  // a test domain to dryrun. Leave empty for all, or create a list of domains needed to migrate
        // Add your test domain here
    ];

    // Optional: never run for these domains (lowercase)
    $blocklistDomains = [];

    // Dry run mode: unlock/disable ID/get EPP, but DON'T transfer or change registrar
    $dryRun = true;  // SET TO false WHEN READY FOR PRODUCTION

    // Best-effort steps
    $unlockDomain     = true;
    $disableIdProtect = true;
    // ----------------------------------------

    $params   = $vars['params'] ?? [];
    $domainId = (int)($params['domainid'] ?? 0);
    $domain   = strtolower(trim((string)($params['domain'] ?? '')));

    if (!$domainId || $domain === '') return;

    $row = Capsule::table('tbldomains')->where('id', $domainId)->first();
    if (!$row) return;

    $currentRegistrar = strtolower(trim((string)($row->registrar ?? '')));

    // Dynamic log prefix using the configured registrars
    $logPrefix = strtoupper($triggerOnlyIfCurrentRegistrar) . '→' . strtoupper($targetRegistrar);

    $logBoth = function(string $msg) use ($domain, $domainId, $currentRegistrar, $logPrefix) {
        logActivity("[{$logPrefix}] {$domain} (ID {$domainId}, registrar={$currentRegistrar}) - {$msg}");
    };

    // Helper to add admin notes to the domain
    $addAdminNote = function(string $note) use ($domainId, $logPrefix) {
        try {
            $existing = Capsule::table('tbldomains')->where('id', $domainId)->value('additionalnotes') ?? '';
            $timestamp = date('Y-m-d H:i:s');
            $newNote = "[{$timestamp}] [{$logPrefix}] {$note}";
            $updated = trim($existing . "\n" . $newNote);

            Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->update(['additionalnotes' => $updated]);
        } catch (\Throwable $e) {
            logActivity("[{$logPrefix}] Failed to add admin note: " . $e->getMessage());
        }
    };

    // 0) Allow/block list gating
    if (!empty($allowlistDomains) && !in_array($domain, array_map('strtolower', $allowlistDomains), true)) {
        return;
    }
    if (!empty($blocklistDomains) && in_array($domain, array_map('strtolower', $blocklistDomains), true)) {
        return;
    }

    // 1) Only trigger if current registrar matches
    if ($triggerOnlyIfCurrentRegistrar !== '' &&
        $currentRegistrar !== strtolower($triggerOnlyIfCurrentRegistrar)) {
        return;
    }

    // If already on target registrar, do nothing
    if ($currentRegistrar === strtolower($targetRegistrar)) {
        return;
    }

    $call = function(string $action, array $data = []) use ($adminUsername) {
        $res = localAPI($action, $data, $adminUsername);
        if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
            $msg = is_array($res) ? ($res['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException("$action failed: $msg");
        }
        return $res;
    };

    try {
        $mode = $dryRun ? 'DRY RUN' : 'LIVE';
        $logBoth("Triggered ({$mode} mode)");
        $addAdminNote("Migration triggered ({$mode} mode)");

        $unlockStatus = 'not attempted';
        $idProtectStatus = 'not attempted';
        $eppCode = '';

        // 2) Unlock domain (ALWAYS in dry run AND live mode)
        if ($unlockDomain) {
            try {
                $call('DomainUpdateLockingStatus', [
                    'domainid'   => $domainId,
                    'lockstatus' => false,
                ]);
                $unlockStatus = 'success';
                $logBoth("Domain unlocked successfully");
                $addAdminNote("Domain unlocked");
            } catch (\Throwable $e) {
                $unlockStatus = 'failed: ' . $e->getMessage();
                $logBoth("Unlock failed: " . $e->getMessage());
                $addAdminNote("Unlock failed: " . $e->getMessage());
            }
        }

        // 3) Disable ID Protection (ALWAYS in dry run AND live mode)
        if ($disableIdProtect) {
            try {
                $call('DomainToggleIdProtect', [
                    'domainid'  => $domainId,
                    'idprotect' => false,
                ]);
                $idProtectStatus = 'success';
                $logBoth("ID Protection disabled successfully");
                $addAdminNote("ID Protection disabled");
            } catch (\Throwable $e) {
                $idProtectStatus = 'failed: ' . $e->getMessage();
                $logBoth("Disable ID Protection failed: " . $e->getMessage());
                $addAdminNote("ID Protection disable failed: " . $e->getMessage());
            }
        }

        // 4) Request EPP (ALWAYS in dry run AND live mode)
        try {
            $eppRes = $call('DomainRequestEPP', ['domainid' => $domainId]);
            $eppCode = trim(html_entity_decode((string)($eppRes['eppcode'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($eppCode === '') {
                $logBoth("EPP requested but not returned (likely emailed to registrant)");
                $addAdminNote("EPP code requested (not returned via API - check email)");
            } else {
                $logBoth("EPP code obtained successfully (length=" . strlen($eppCode) . ")");
                $addAdminNote("EPP code obtained: {$eppCode}");
            }
        } catch (\Throwable $e) {
            $logBoth("EPP request failed: " . $e->getMessage());
            $addAdminNote("EPP request failed: " . $e->getMessage());
        }

        // 5) Summary to admin notes
        $summary = "Migration summary - Unlock: {$unlockStatus}, ID Protect: {$idProtectStatus}, EPP: " .
                   ($eppCode !== '' ? 'obtained' : 'not obtained');
        $addAdminNote($summary);

        if ($dryRun) {
            // DRY RUN: Don't change registrar or submit transfer
            $logBoth("DRY RUN: Skipping registrar change and transfer submission");
            $addAdminNote("DRY RUN: Would change registrar to {$targetRegistrar} and submit transfer");

            return [
                'abortWithError' => "DRY RUN: Renewal aborted for testing. Check Activity Log and domain admin notes. No transfer initiated."
            ];
        } else {
            // LIVE MODE: Actually change registrar and submit transfer

            // 6) Switch registrar + set Pending Transfer
            $call('UpdateClientDomain', [
                'domainid'  => $domainId,
                'registrar' => $targetRegistrar,
                'status'    => 'Pending Transfer',
            ]);
            $logBoth("Updated WHMCS: registrar={$targetRegistrar}, status=Pending Transfer");
            $addAdminNote("Registrar changed to {$targetRegistrar}, status set to Pending Transfer");

            // 7) Submit transfer if EPP available
            if ($eppCode !== '') {
                $call('DomainTransfer', [
                    'domainid' => $domainId,
                    'eppcode'  => $eppCode,
                ]);
                $logBoth("Transfer submitted to {$targetRegistrar}");
                $addAdminNote("Transfer submitted to {$targetRegistrar}");
            }

            return [
                'abortWithError' =>
                    $eppCode === ''
                    ? "Renewal replaced by transfer to " . ucfirst($targetRegistrar) . ". EPP requested but not returned (check email). Domain set to Pending Transfer."
                    : "Renewal replaced by " . ucfirst($targetRegistrar) . " transfer (submitted). Domain set to Pending Transfer; dates will update via Domain Sync.",
            ];
        }

    } catch (\Throwable $e) {
        $logBoth("FAILED: " . $e->getMessage());
        $addAdminNote("Migration FAILED: " . $e->getMessage());
        return [
            'abortWithError' => "{$logPrefix} migration failed: " . $e->getMessage(),
        ];
    }
});
