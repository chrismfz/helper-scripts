<?php
use WHMCS\Database\Capsule;

/**
 * Replace renewal with a transfer (CNIC -> OpenProvider) on paid renewal attempt.
 * WHMCS 8.13.1
 *
 * Also logs every trigger/decision/result into mod_domain_migration (addon table).
 *
 * REQUIREMENT:
 *   - Install/activate the addon module that creates `mod_domain_migration` table.
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

    // If true, store EPP in addon table too.
    // (You said you want it for verification; OK.)
    $storeEppInAddonTable = true;
    // ----------------------------------------

    $params    = $vars['params'] ?? [];
    $domainId  = (int)($params['domainid'] ?? 0);
    $domain    = strtolower(trim((string)($params['domain'] ?? '')));
    $invoiceId = (int)($params['invoiceid'] ?? 0); // may be empty depending on call path

    if (!$domainId || $domain === '') {
        return;
    }

    $row = Capsule::table('tbldomains')->where('id', $domainId)->first();
    if (!$row) {
        return;
    }

    $currentRegistrar = strtolower(trim((string)($row->registrar ?? '')));
    $clientId         = (int)($row->userid ?? 0);

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

    // ---- Addon table logging helpers (best-effort; never break the hook) ----
    $addonTableExists = (function () {
        try {
            return Capsule::schema()->hasTable('mod_domain_migration');
        } catch (\Throwable $e) {
            return false;
        }
    })();

    $migId = 0;

    $dmInsert = function (array $data) use ($logPrefix, $addonTableExists, &$migId) {
        if (!$addonTableExists) return 0;
        try {
            $now = date('Y-m-d H:i:s');
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $data['updated_at'] ?? $now;
            $migId = (int) Capsule::table('mod_domain_migration')->insertGetId($data);
            return $migId;
        } catch (\Throwable $e) {
            logActivity("[{$logPrefix}] mod_domain_migration insert failed: " . $e->getMessage());
            return 0;
        }
    };

    $dmUpdate = function (int $id, array $data) use ($logPrefix, $addonTableExists) {
        if (!$addonTableExists || $id <= 0) return;
        try {
            $data['updated_at'] = date('Y-m-d H:i:s');
            Capsule::table('mod_domain_migration')->where('id', $id)->update($data);
        } catch (\Throwable $e) {
            logActivity("[{$logPrefix}] mod_domain_migration update failed: " . $e->getMessage());
        }
    };

    $dmSetStatus = function (string $status, string $error = null, array $extra = []) use (&$migId, $dmUpdate) {
        $data = array_merge(['status' => $status], $extra);
        if ($error !== null && $error !== '') {
            $data['error'] = $error;
        }
        $dmUpdate($migId, $data);
    };
    // ----------------------------------------------------------------------

    // 0) Allow/block list gating
    if (!empty($allowlistDomains) && !in_array($domain, array_map('strtolower', $allowlistDomains), true)) {
        // log as blocked (optional)
        $dmInsert([
            'domain_id'     => $domainId,
            'domain'        => $domain,
            'client_id'     => $clientId ?: null,
            'invoice_id'    => $invoiceId ?: null,
            'old_registrar' => $currentRegistrar,
            'new_registrar' => $targetRegistrar,
            'dry_run'       => $dryRun ? 1 : 0,
            'status'        => 'blocked',
            'error'         => 'Blocked: not in allowlist',
        ]);
        return;
    }
    if (!empty($blocklistDomains) && in_array($domain, array_map('strtolower', $blocklistDomains), true)) {
        $dmInsert([
            'domain_id'     => $domainId,
            'domain'        => $domain,
            'client_id'     => $clientId ?: null,
            'invoice_id'    => $invoiceId ?: null,
            'old_registrar' => $currentRegistrar,
            'new_registrar' => $targetRegistrar,
            'dry_run'       => $dryRun ? 1 : 0,
            'status'        => 'blocked',
            'error'         => 'Blocked: in blocklist',
        ]);
        return;
    }

    // 1) Only trigger if current registrar matches
    if ($triggerOnlyIfCurrentRegistrar !== '' &&
        $currentRegistrar !== strtolower($triggerOnlyIfCurrentRegistrar)) {
        $dmInsert([
            'domain_id'     => $domainId,
            'domain'        => $domain,
            'client_id'     => $clientId ?: null,
            'invoice_id'    => $invoiceId ?: null,
            'old_registrar' => $currentRegistrar,
            'new_registrar' => $targetRegistrar,
            'dry_run'       => $dryRun ? 1 : 0,
            'status'        => 'skipped',
            'error'         => 'Skipped: registrar mismatch',
        ]);
        return;
    }

    // If already on target registrar, do nothing
    if ($currentRegistrar === strtolower($targetRegistrar)) {
        $dmInsert([
            'domain_id'     => $domainId,
            'domain'        => $domain,
            'client_id'     => $clientId ?: null,
            'invoice_id'    => $invoiceId ?: null,
            'old_registrar' => $currentRegistrar,
            'new_registrar' => $targetRegistrar,
            'dry_run'       => $dryRun ? 1 : 0,
            'status'        => 'skipped',
            'error'         => 'Skipped: already on target registrar',
        ]);
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

    // Create addon log row as soon as we decide we're "triggered"
    $mode = $dryRun ? 'DRY RUN' : 'LIVE';
    $migId = $dmInsert([
        'domain_id'      => $domainId,
        'domain'         => $domain,
        'client_id'      => $clientId ?: null,
        'invoice_id'     => $invoiceId ?: null,
        'old_registrar'  => $currentRegistrar,
        'new_registrar'  => $targetRegistrar,
        'dry_run'        => $dryRun ? 1 : 0,
        'status'         => 'triggered',
    ]);

    try {
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
                $dmUpdate($migId, ['unlock_ok' => 1]);
            } catch (\Throwable $e) {
                $unlockStatus = 'failed: ' . $e->getMessage();
                $logBoth("Unlock failed: " . $e->getMessage());
                $addAdminNote("Unlock failed: " . $e->getMessage());
                $dmUpdate($migId, ['unlock_ok' => 0]);
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
                $dmUpdate($migId, ['idprotect_ok' => 1]);
            } catch (\Throwable $e) {
                $idProtectStatus = 'failed: ' . $e->getMessage();
                $logBoth("Disable ID Protection failed: " . $e->getMessage());
                $addAdminNote("ID Protection disable failed: " . $e->getMessage());
                $dmUpdate($migId, ['idprotect_ok' => 0]);
            }
        }

        // 4) Request EPP (best-effort)
        try {
            $eppRes  = $call('DomainRequestEPP', ['domainid' => $domainId]);
            $eppCode = trim(html_entity_decode((string)($eppRes['eppcode'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($storeEppInAddonTable && $eppCode !== '') {
                $dmUpdate($migId, ['epp' => $eppCode]);
            }

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
            $msg = "EPP missing; not flipping registrar/status (markPendingWithoutEpp=false)";
            $logBoth("LIVE: {$msg}");
            $addAdminNote("LIVE: {$msg}");
            $dmSetStatus('failed', 'EPP missing; transfer not submitted; registrar/status not changed', [
                'transfer_submitted' => 0,
            ]);

            return [
                'abortWithError' =>
                    "Renewal replaced by transfer to " . ucfirst($targetRegistrar) .
                    ", but EPP was not returned (check email). No transfer submitted yet.",
            ];
        }

        if ($dryRun) {
            $logBoth("DRY RUN: Skipping registrar change and transfer submission");
            $addAdminNote("DRY RUN: Would change registrar to {$targetRegistrar} and submit transfer");
            $dmSetStatus('skipped', 'Dry run: no registrar change / no transfer submit', [
                'transfer_submitted' => 0,
            ]);

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
            $dmSetStatus('skipped', 'Already Pending Transfer; skipped to avoid duplicates', [
                'transfer_submitted' => 0,
            ]);
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

            $dmSetStatus('submitted', null, [
                'transfer_submitted' => 1,
            ]);
        } else {
            $logBoth("Transfer NOT submitted (no EPP available)");
            $addAdminNote("Transfer NOT submitted (no EPP available)");

            // If you reached here with empty EPP, it means markPendingWithoutEpp=true
            $dmSetStatus('triggered', 'No EPP; Pending Transfer set but transfer not submitted', [
                'transfer_submitted' => 0,
            ]);
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
        $dmSetStatus('failed', $e->getMessage());

        return [
            'abortWithError' => "{$logPrefix} migration failed: " . $e->getMessage(),
        ];
    }
});
