<?php
use WHMCS\Database\Capsule;

/**
 * Replace renewal with a transfer (CNIC -> OpenProvider) on paid renewal attempt.
 * WHMCS 8.13.1
 *
 * Logs important events into mod_domain_migration (addon table), with noise control.
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
        // 'myipnetworks.com', 'nixpal.com',
    ];

    // Optional: never run for these domains (lowercase)
    $blocklistDomains = [];

    // Dry run mode: still does unlock/idprotect/epp, but DOES NOT change registrar or submit transfer.
    $dryRun = false; // SET TO false WHEN READY

    // Best-effort steps
    $unlockDomain     = true;
    $disableIdProtect = true;

    // When switching to transfer mode, also set donotrenew=1 to avoid renewal re-queue.
    $setDoNotRenewDuringTransfer = true;

    // If EPP is empty, do you still want to flip status/registrar?
    // Recommended: false (only mark pending when you can submit transfer).
    $markPendingWithoutEpp = false;

    // If true, store EPP in addon table too.
    $storeEppInAddonTable = true;

    // -------- NOISE CONTROL (NEW) ----------
    // If false: do NOT store "skipped" rows (registrar mismatch, already on target, already pending, etc.)
    $auditSkipped = false;

    // If true: store allow/block list blocks as "blocked"
    $auditBlocked = true;

    // If true: keep Activity Log entries for skipped cases (registrar mismatch etc.)
    // Recommend false if you also want to reduce WHMCS Activity Log spam.
    $logSkippedToActivityLog = false;
    // ---------------------------------------

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

    // Preserve current nameservers so transfer submission can keep DNS delegation intact.
    $nameservers = [];
    for ($i = 1; $i <= 5; $i++) {
        $ns = trim((string)($row->{"ns{$i}"} ?? ''));
        if ($ns !== '') {
            $nameservers[$i] = $ns;
        }
    }

    $logPrefix = strtoupper($triggerOnlyIfCurrentRegistrar) . '→' . strtoupper($targetRegistrar);

    $logActivityMsg = function (string $msg) use ($domain, $domainId, $currentRegistrar, $logPrefix) {
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

    $dmInsert = function (array $data) use ($logPrefix, $addonTableExists) {
        if (!$addonTableExists) return 0;
        try {
            $now = date('Y-m-d H:i:s');
            $data['created_at'] = $data['created_at'] ?? $now;
            $data['updated_at'] = $data['updated_at'] ?? $now;
            return (int) Capsule::table('mod_domain_migration')->insertGetId($data);
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

    $dmSetStatus = function (int $id, string $status, string $error = null, array $extra = []) use ($dmUpdate) {
        if ($id <= 0) return;
        $data = array_merge(['status' => $status], $extra);
        if ($error !== null && $error !== '') {
            $data['error'] = $error;
        }
        $dmUpdate($id, $data);
    };
    // ----------------------------------------------------------------------

    // Helper: optional audit skipped
    $auditSkipRow = function (string $why) use (
        $auditSkipped, $dmInsert,
        $domainId, $domain, $clientId, $invoiceId,
        $currentRegistrar, $targetRegistrar, $dryRun
    ) {
        if (!$auditSkipped) return;
        $dmInsert([
            'domain_id'     => $domainId,
            'domain'        => $domain,
            'client_id'     => $clientId ?: null,
            'invoice_id'    => $invoiceId ?: null,
            'old_registrar' => $currentRegistrar,
            'new_registrar' => $targetRegistrar,
            'dry_run'       => $dryRun ? 1 : 0,
            'status'        => 'skipped',
            'error'         => $why,
        ]);
    };

    // ---------------- GATING (NOISE-FREE) ----------------

    // 0) Allowlist gating
    if (!empty($allowlistDomains) && !in_array($domain, array_map('strtolower', $allowlistDomains), true)) {
        if ($auditBlocked) {
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
        }
        if ($logSkippedToActivityLog) {
            $logActivityMsg("Blocked (not in allowlist)");
        }
        return;
    }

    // 0b) Blocklist gating
    if (!empty($blocklistDomains) && in_array($domain, array_map('strtolower', $blocklistDomains), true)) {
        if ($auditBlocked) {
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
        }
        if ($logSkippedToActivityLog) {
            $logActivityMsg("Blocked (in blocklist)");
        }
        return;
    }

    // 1) Registrar mismatch -> quiet return (no spam)
    if ($triggerOnlyIfCurrentRegistrar !== '' &&
        $currentRegistrar !== strtolower($triggerOnlyIfCurrentRegistrar)) {

        $auditSkipRow('Skipped: registrar mismatch');
        if ($logSkippedToActivityLog) {
            $logActivityMsg("Skipped: registrar mismatch");
        }
        return;
    }

    // 2) Already on target registrar -> quiet return
    if ($currentRegistrar === strtolower($targetRegistrar)) {
        $auditSkipRow('Skipped: already on target registrar');
        if ($logSkippedToActivityLog) {
            $logActivityMsg("Skipped: already on target registrar");
        }
        return;
    }

    // ---------------- EXECUTION (LOG ONLY REAL RUNS) ----------------

    $call = function (string $action, array $data = []) use ($adminUsername) {
        $res = localAPI($action, $data, $adminUsername);
        if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
            $msg = is_array($res) ? ($res['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException("$action failed: $msg");
        }
        return $res;
    };

    $resolveNameservers = function () use ($call, $domainId) {
        $nameservers = [];

        // Preferred source: registrar module/WHMCS live nameserver query.
        try {
            $nsRes = $call('DomainGetNameservers', ['domainid' => $domainId]);
            for ($i = 1; $i <= 5; $i++) {
                $ns = trim((string)($nsRes["ns{$i}"] ?? ''));
                if ($ns !== '') {
                    $nameservers[$i] = $ns;
                }
            }
            if (!empty($nameservers)) {
                return ['nameservers' => $nameservers, 'source' => 'DomainGetNameservers'];
            }
        } catch (\Throwable $e) {
            // ignore and fallback
        }

        // Fallback source: WHMCS client-domain API payload.
        try {
            $domainRes = $call('GetClientsDomains', [
                'domainid' => $domainId,
                'limitnum' => 1,
            ]);

            $entry = $domainRes['domains']['domain'][0] ?? null;
            if (is_array($entry)) {
                for ($i = 1; $i <= 5; $i++) {
                    $ns = trim((string)($entry["ns{$i}"] ?? $entry["nameserver{$i}"] ?? ''));
                    if ($ns !== '') {
                        $nameservers[$i] = $ns;
                    }
                }
            }
            if (!empty($nameservers)) {
                return ['nameservers' => $nameservers, 'source' => 'GetClientsDomains'];
            }
        } catch (\Throwable $e) {
            // ignore and return empty
        }

        return ['nameservers' => [], 'source' => 'none'];
    };

    $resolvedNameservers = $resolveNameservers();
    $nameservers = $resolvedNameservers['nameservers'];
    $nameserverSource = $resolvedNameservers['source'];

    // Create addon log row NOW (we are truly triggered)
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
        $logActivityMsg("Triggered ({$mode} mode)");
        $addAdminNote("Migration triggered ({$mode} mode)");

        $unlockStatus    = 'not attempted';
        $idProtectStatus = 'not attempted';
        $eppCode         = '';

        // 3) Unlock domain (best-effort)
        if ($unlockDomain) {
            try {
                $call('DomainUpdateLockingStatus', [
                    'domainid'   => $domainId,
                    'lockstatus' => false,
                ]);
                $unlockStatus = 'success';
                $logActivityMsg("Domain unlocked successfully");
                $addAdminNote("Domain unlocked");
                $dmUpdate($migId, ['unlock_ok' => 1]);
            } catch (\Throwable $e) {
                $unlockStatus = 'failed: ' . $e->getMessage();
                $logActivityMsg("Unlock failed: " . $e->getMessage());
                $addAdminNote("Unlock failed: " . $e->getMessage());
                $dmUpdate($migId, ['unlock_ok' => 0]);
            }
        }

        // 4) Disable ID Protection (best-effort)
        if ($disableIdProtect) {
            try {
                $call('DomainToggleIdProtect', [
                    'domainid'  => $domainId,
                    'idprotect' => false,
                ]);
                $idProtectStatus = 'success';
                $logActivityMsg("ID Protection disabled successfully");
                $addAdminNote("ID Protection disabled");
                $dmUpdate($migId, ['idprotect_ok' => 1]);
            } catch (\Throwable $e) {
                $idProtectStatus = 'failed: ' . $e->getMessage();
                $logActivityMsg("Disable ID Protection failed: " . $e->getMessage());
                $addAdminNote("ID Protection disable failed: " . $e->getMessage());
                $dmUpdate($migId, ['idprotect_ok' => 0]);
            }
        }

        // 5) Request EPP (best-effort)
        try {
            $eppRes  = $call('DomainRequestEPP', ['domainid' => $domainId]);
            $eppCode = trim(html_entity_decode((string)($eppRes['eppcode'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($storeEppInAddonTable && $eppCode !== '') {
                $dmUpdate($migId, ['epp' => $eppCode]);
            }

            if ($eppCode === '') {
                $logActivityMsg("EPP requested but not returned (likely emailed to registrant)");
                $addAdminNote("EPP code requested (not returned via API - check email)");
            } else {
                $logActivityMsg("EPP code obtained successfully (length=" . strlen($eppCode) . ")");
                $addAdminNote("EPP code obtained: {$eppCode}");
            }
        } catch (\Throwable $e) {
            $logActivityMsg("EPP request failed: " . $e->getMessage());
            $addAdminNote("EPP request failed: " . $e->getMessage());
        }

        // Summary to admin notes
        $summary = "Migration summary - Unlock: {$unlockStatus}, ID Protect: {$idProtectStatus}, EPP: " .
            ($eppCode !== '' ? 'obtained' : 'not obtained');
        $addAdminNote($summary);

        if (!empty($nameservers)) {
            $addAdminNote('Nameservers preserved for transfer (' . $nameserverSource . '): ' . implode(', ', $nameservers));
        } else {
            $addAdminNote('No nameservers resolved via API; transfer will use registrar/WHMCS defaults');
        }

        // If we can't submit transfer (no EPP) and we don't want to mark pending, stop here.
        if (!$dryRun && $eppCode === '' && !$markPendingWithoutEpp) {
            $msg = "EPP missing; not flipping registrar/status (markPendingWithoutEpp=false)";
            $logActivityMsg("LIVE: {$msg}");
            $addAdminNote("LIVE: {$msg}");

            $dmSetStatus($migId, 'failed', 'EPP missing; transfer not submitted; registrar/status not changed', [
                'transfer_submitted' => 0,
            ]);

            return [
                'abortWithError' =>
                    "Renewal replaced by transfer to " . ucfirst($targetRegistrar) .
                    ", but EPP was not returned (check email). No transfer submitted yet.",
            ];
        }

        if ($dryRun) {
            $logActivityMsg("DRY RUN: Skipping registrar change and transfer submission");
            $addAdminNote("DRY RUN: Would change registrar to {$targetRegistrar} and submit transfer");

            $dmSetStatus($migId, 'skipped', 'Dry run: no registrar change / no transfer submit', [
                'transfer_submitted' => 0,
            ]);

            return [
                'abortWithError' => "DRY RUN: Renewal aborted for testing. Check Activity Log and domain admin notes. No transfer initiated.",
            ];
        }

        // ---------------- LIVE MODE ----------------

        // 6) Switch registrar + set Pending Transfer + optional donotrenew=1 (DB-level)
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
                return;
            }

            $update = [
                'registrar' => $targetRegistrar,
                'status'    => 'Pending Transfer',
            ];

            if ($setDoNotRenewDuringTransfer) {
                $update['donotrenew'] = 1;
            }

            $updated = Capsule::table('tbldomains')->where('id', $domainId)->update($update);
            if ($updated < 1) {
                throw new \RuntimeException("Failed to update tbldomains (no rows updated)");
            }
        });

        // Already pending -> avoid duplicate transfer; remove the row unless you want skip auditing
        if ($alreadyPending) {
            $logActivityMsg("Already Pending Transfer — skipping DB flip + transfer submit");
            $addAdminNote("Already Pending Transfer — skipped");

            if ($auditSkipped) {
                $dmSetStatus($migId, 'skipped', 'Already Pending Transfer; skipped to avoid duplicates', [
                    'transfer_submitted' => 0,
                ]);
            } else {
                // Remove the 'triggered' audit row to avoid noise
                try {
                    Capsule::table('mod_domain_migration')->where('id', $migId)->delete();
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            return ['abortWithSuccess' => true];
        }

        $logActivityMsg("Updated WHMCS DB: registrar={$targetRegistrar}, status=Pending Transfer" .
            ($setDoNotRenewDuringTransfer ? ", donotrenew=1" : ""));
        $addAdminNote("Registrar changed to {$targetRegistrar}, status set to Pending Transfer" .
            ($setDoNotRenewDuringTransfer ? ", donotrenew=1" : ""));

        // 6b) Optional: set type=Transfer in WHMCS (supported by UpdateClientDomain)
        try {
            $call('UpdateClientDomain', [
                'domainid' => $domainId,
                'type'     => 'Transfer',
            ]);
            $logActivityMsg("Updated WHMCS type=Transfer");
            $addAdminNote("WHMCS type set to Transfer");
        } catch (\Throwable $e) {
            $logActivityMsg("UpdateClientDomain(type=Transfer) failed (non-fatal): " . $e->getMessage());
            $addAdminNote("UpdateClientDomain(type=Transfer) failed (non-fatal): " . $e->getMessage());
        }

        // 7) Submit transfer
        if ($eppCode !== '') {
            $transferPayload = [
                'domainid' => $domainId,
                'eppcode'  => $eppCode,
            ];

            // WHMCS transfer APIs commonly accept ns1..ns5.
            foreach ($nameservers as $index => $ns) {
                $transferPayload["ns{$index}"] = $ns;
            }

            $call('DomainTransfer', $transferPayload);

            $logActivityMsg(
                "Transfer submitted to {$targetRegistrar}" .
                (!empty($nameservers) ? ' with preserved nameservers' : '')
            );
            $addAdminNote(
                "Transfer submitted to {$targetRegistrar}" .
                (!empty($nameservers) ? ' using nameservers: ' . implode(', ', $nameservers) : '')
            );

            $dmSetStatus($migId, 'submitted', null, [
                'transfer_submitted' => 1,
            ]);
        } else {
            // Reached here only if markPendingWithoutEpp=true
            $logActivityMsg("Transfer NOT submitted (no EPP available)");
            $addAdminNote("Transfer NOT submitted (no EPP available)");

            $dmSetStatus($migId, 'triggered', 'No EPP; Pending Transfer set but transfer not submitted', [
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
        $logActivityMsg("FAILED: " . $e->getMessage());
        $addAdminNote("Migration FAILED: " . $e->getMessage());
        $dmSetStatus($migId, 'failed', $e->getMessage());

        return [
            'abortWithError' => "{$logPrefix} migration failed: " . $e->getMessage(),
        ];
    }
});
