<?php
use WHMCS\Database\Capsule;

/**
 * Replace renewal with a transfer (CNIC -> OpenProvider) on paid renewal attempt.
 * WHMCS 8.13.1
 */
add_hook('PreRegistrarRenewDomain', 1, function (array $vars) {

    // ---------------- CONFIG ----------------
    $adminUsername = 'chris';

    // Only trigger if current domain registrar matches this (case-insensitive).
    $triggerOnlyIfCurrentRegistrar = 'cnic'; // old provider

    // Target registrar module slug
    $targetRegistrar = 'openprovider'; // new provider

    // Safety: only run for these domains (lowercase). Keep empty [] to allow all.
    $allowlistDomains = [
        'myipnetworks.com', // test domain
        // add more, or leave empty for all
    ];

    // Optional: never run for these domains (lowercase)
    $blocklistDomains = [];

    // Dry run mode: still does unlock/idprotect/epp, but DOES NOT change registrar or submit transfer.
    $dryRun = true; // SET TO false WHEN READY

    // Best-effort steps
    $unlockDomain     = true;
    $disableIdProtect = true;

    // When switching to transfer mode, also set donotrenew=1 to avoid renewal re-queue.
    $setDoNotRenewDuringTransfer = true;

    // If EPP is empty, do you still want to flip status/registrar?
    // Recommended: false (only mark pending when you can submit transfer).
    $markPendingWithoutEpp = false;
    // ----------------------------------------

    $params   = $vars['params'] ?? [];
    $domainId = (int)($params['domainid'] ?? 0);
    $domain   = strtolower(trim((string)($params['domain'] ?? '')));

    if (!$domainId || $domain === '') {
        return;
    }

    $row = Capsule::table('tbldomains')->where('id', $domainId)->first();
    if (!$row) {
        return;
    }

    $currentRegistrar = strtolower(trim((string)($row->registrar ?? '')));

    $logPrefix = strtoupper($triggerOnlyIfCurrentRegistrar) . '→' . strtoupper($targetRegistrar);

    $logBoth = function (string $msg) use ($domain, $domainId, $currentRegistrar, $logPrefix) {
        logActivity("[{$logPrefix}] {$domain} (ID {$domainId}, registrar={$currentRegistrar}) - {$msg}");
    };

    $addAdminNote = function (string $note) use ($domainId, $logPrefix) {
        try {
            $existing  = Capsule::table('tbldomains')->where('id', $domainId)->value('additionalnotes') ?? '';
            $timestamp = date('Y-m-d H:i:s');
            $newNote   = "[{$timestamp}] [{$logPrefix}] {$note}";
            $updated   = trim($existing . "\n" . $newNote);

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

    $call = function (string $action, array $data = []) use ($adminUsername) {
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

        $unlockStatus    = 'not attempted';
        $idProtectStatus = 'not attempted';
        $eppCode         = '';

        // 2) Unlock domain (best-effort)
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

        // 3) Disable ID Protection (best-effort)
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

        // 4) Request EPP (best-effort)
        try {
            $eppRes  = $call('DomainRequestEPP', ['domainid' => $domainId]);
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

        // If we can't submit transfer (no EPP) and we don't want to mark pending, stop here.
        if (!$dryRun && $eppCode === '' && !$markPendingWithoutEpp) {
            $logBoth("LIVE: EPP missing; not flipping registrar/status (markPendingWithoutEpp=false)");
            $addAdminNote("LIVE: EPP missing; transfer not submitted; registrar/status not changed");
            return [
                'abortWithError' =>
                    "Renewal replaced by transfer to " . ucfirst($targetRegistrar) .
                    ", but EPP was not returned (check email). No transfer submitted yet.",
            ];
        }

        if ($dryRun) {
            $logBoth("DRY RUN: Skipping registrar change and transfer submission");
            $addAdminNote("DRY RUN: Would change registrar to {$targetRegistrar} and submit transfer");

            return [
                'abortWithError' => "DRY RUN: Renewal aborted for testing. Check Activity Log and domain admin notes. No transfer initiated.",
            ];
        }

        // ---------------- LIVE MODE ----------------

        // 6) Switch registrar + set Pending Transfer (DB-level) + optional donotrenew=1
        $alreadyPending = false;

        Capsule::connection()->transaction(function () use (
            $domainId,
            $targetRegistrar,
            $setDoNotRenewDuringTransfer,
            &$alreadyPending
        ) {
            $locked = Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->lockForUpdate()
                ->first();

            if (!$locked) {
                throw new \RuntimeException("Domain ID {$domainId} not found inside transaction");
            }

            $status = strtolower(trim((string)($locked->status ?? '')));
            if ($status === 'pending transfer') {
                $alreadyPending = true;
                return; // idempotent exit
            }

            $update = [
                'registrar' => $targetRegistrar,
                'status'    => 'Pending Transfer',
            ];

            if ($setDoNotRenewDuringTransfer) {
                $update['donotrenew'] = 1;
            }

            $updated = Capsule::table('tbldomains')
                ->where('id', $domainId)
                ->update($update);

            if ($updated < 1) {
                throw new \RuntimeException("Failed to update tbldomains (no rows updated)");
            }
        });

        if ($alreadyPending) {
            $logBoth("Already Pending Transfer — skipping DB flip + transfer submit");
            $addAdminNote("Already Pending Transfer — skipped");
            return ['abortWithSuccess' => true];
        }

        $logBoth("Updated WHMCS DB: registrar={$targetRegistrar}, status=Pending Transfer" .
            ($setDoNotRenewDuringTransfer ? ", donotrenew=1" : ""));
        $addAdminNote("Registrar changed to {$targetRegistrar}, status set to Pending Transfer" .
            ($setDoNotRenewDuringTransfer ? ", donotrenew=1" : ""));

        // 6b) Optional: set type=Transfer in WHMCS (supported by UpdateClientDomain)
        try {
            $call('UpdateClientDomain', [
                'domainid' => $domainId,
                'type'     => 'Transfer',
            ]);
            $logBoth("Updated WHMCS type=Transfer");
            $addAdminNote("WHMCS type set to Transfer");
        } catch (\Throwable $e) {
            $logBoth("UpdateClientDomain(type=Transfer) failed (non-fatal): " . $e->getMessage());
            $addAdminNote("UpdateClientDomain(type=Transfer) failed (non-fatal): " . $e->getMessage());
        }

        // 7) Submit transfer (only if EPP available, unless you want manual EPP flow)
        if ($eppCode !== '') {
            $call('DomainTransfer', [
                'domainid' => $domainId,
                'eppcode'  => $eppCode,
            ]);
            $logBoth("Transfer submitted to {$targetRegistrar}");
            $addAdminNote("Transfer submitted to {$targetRegistrar}");
        } else {
            $logBoth("Transfer NOT submitted (no EPP available)");
            $addAdminNote("Transfer NOT submitted (no EPP available)");
        }

        return [
            'abortWithError' =>
                $eppCode === ''
                    ? "Renewal replaced by transfer to " . ucfirst($targetRegistrar) .
                      ". EPP requested but not returned (check email). Domain set to Pending Transfer."
                    : "Renewal replaced by " . ucfirst($targetRegistrar) .
                      " transfer (submitted). Domain set to Pending Transfer; dates will update via Domain Sync.",
        ];

    } catch (\Throwable $e) {
        $logBoth("FAILED: " . $e->getMessage());
        $addAdminNote("Migration FAILED: " . $e->getMessage());
        return [
            'abortWithError' => "{$logPrefix} migration failed: " . $e->getMessage(),
        ];
    }
});
