<?php
use WHMCS\Database\Capsule;

/**
 * Replace renewal with a transfer (CNIC -> OpenProvider) on paid renewal attempt.
 * WHMCS 8.13.1
 *
 * Logs important events into mod_domain_migration (addon table), with noise control.
 * Resolves NS IPs via dns_get_record / gethostbyname / dig before passing to
 * DomainTransfer, bypassing the broken gethostbyname path in APITools::createNameserversArray().
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
    $dryRun = false;

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

    // -------- NOISE CONTROL ----------------
    // If false: do NOT store "skipped" rows (registrar mismatch, already on target, already pending, etc.)
    $auditSkipped = false;

    // If true: store allow/block list blocks as "blocked"
    $auditBlocked = true;

    // If true: keep Activity Log entries for skipped cases (registrar mismatch etc.)
    $logSkippedToActivityLog = false;
    // ---------------------------------------

    $params    = $vars['params'] ?? [];
    $domainId  = (int)($params['domainid'] ?? 0);
    $domain    = strtolower(trim((string)($params['domain'] ?? '')));
    $invoiceId = (int)($params['invoiceid'] ?? 0);

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
    // -------------------------------------------------------------------------

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

    // 1) Registrar mismatch -> quiet return
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

    // ---------------- EXECUTION ----------------

    $call = function (string $action, array $data = []) use ($adminUsername) {
        $res = localAPI($action, $data, $adminUsername);
        if (!is_array($res) || ($res['result'] ?? '') !== 'success') {
            $msg = is_array($res) ? ($res['message'] ?? 'Unknown error') : 'Unknown error';
            throw new \RuntimeException("$action failed: $msg");
        }
        return $res;
    };

    $callSoft = function (string $action, array $data = []) use ($adminUsername) {
        $res = localAPI($action, $data, $adminUsername);
        if (!is_array($res)) {
            return ['ok' => false, 'data' => [], 'error' => 'Invalid API response'];
        }
        if (($res['result'] ?? '') !== 'success') {
            return ['ok' => false, 'data' => $res, 'error' => (string)($res['message'] ?? 'Unknown error')];
        }
        return ['ok' => true, 'data' => $res, 'error' => ''];
    };

    // ---- NS IP resolution helper ----
    // Resolves a nameserver hostname to IPv4 (or IPv6 as fallback).
    // Tries three methods in order: dns_get_record, gethostbyname, dig.
    // Passes in name/ip format so APITools::createNameserversArray() skips
    // its own broken resolution path entirely for external nameservers.
    $resolveNsIp = function (string $nsName) use ($domain, $domainId, $addAdminNote): ?string {

        $log = function (string $ip, string $method) use ($nsName, $domain, $domainId, $addAdminNote) {
            logActivity("[NS_RESOLVE] {$domain} (ID {$domainId}) - {$nsName} → {$ip} ({$method})");
            $addAdminNote("NS resolve: {$nsName} → {$ip} via {$method}");
        };

        $fail = function (string $detail) use ($nsName, $domain, $domainId, $addAdminNote) {
            logActivity("[NS_RESOLVE] {$domain} (ID {$domainId}) - {$nsName} → FAILED ({$detail})");
            $addAdminNote("NS resolve: {$nsName} → FAILED ({$detail})");
        };

        // Method 1: dns_get_record/A — no shell, handles CNAME chains, preferred.
        $records = @dns_get_record($nsName, DNS_A);
        if (is_array($records) && !empty($records)) {
            $ip = $records[0]['ip'] ?? null;
            if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $log($ip, 'dns_get_record/A');
                return $ip;
            }
        }

        // Method 2: gethostbyname — fast, IPv4 only, fails on AAAA-only hosts.
        $resolved = gethostbyname($nsName);
        if ($resolved !== $nsName && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $log($resolved, 'gethostbyname');
            return $resolved;
        }

        // Method 3: dig — last resort, requires exec() and the dig binary.
        // Only IPv4 results are used; AAAA results are intentionally NOT passed
        // as name/ip since the OP module treats the ip field as IPv4 only.
        $digAvailable = function_exists('exec') && !in_array('exec', array_map(
            'trim', explode(',', ini_get('disable_functions') ?: '')
        ));

        if ($digAvailable) {
            $digBin = trim((string)@shell_exec('command -v dig 2>/dev/null'));
            if ($digBin === '') {
                $digBin = file_exists('/usr/bin/dig') ? '/usr/bin/dig' : '';
            }

            if ($digBin !== '') {
                $safeNs  = escapeshellarg($nsName);
                $safeDig = escapeshellarg($digBin);

                // Check if timeout binary exists; use it if available.
                $timeoutBin = trim((string)@shell_exec('command -v timeout 2>/dev/null'));
                $prefix     = $timeoutBin !== '' ? 'timeout 5 ' : '';

                $out = [];
                exec("{$prefix}{$safeDig} +short A {$safeNs} 2>/dev/null", $out);
                foreach ($out as $line) {
                    $line = trim($line);
                    if (filter_var($line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $log($line, 'dig/A');
                        return $line;
                    }
                }

                // AAAA exists but we do NOT return it for the name/ip payload.
                // Log it so it's visible, but return null — caller will pass
                // the hostname without IP, which is safer than a broken IPv6 entry.
                $out6 = [];
                exec("{$prefix}{$safeDig} +short AAAA {$safeNs} 2>/dev/null", $out6);
                foreach ($out6 as $line) {
                    $line = trim($line);
                    if (filter_var($line, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        logActivity("[NS_RESOLVE] {$domain} (ID {$domainId}) - {$nsName} has AAAA {$line} but IPv6 skipped for OP transfer payload");
                        $addAdminNote("NS resolve: {$nsName} → AAAA {$line} found but IPv6 not used in transfer (OP API IPv4 only); hostname passed without IP");
                        // Return null intentionally — no IPv4 found.
                        $fail('only AAAA exists; IPv6 not passed to OP module');
                        return null;
                    }
                }

                $fail('dig returned no A or AAAA record');
            } else {
                $fail('dig binary not found; exec available but dig missing');
            }
        } else {
            $fail('exec() disabled or unavailable; dig skipped');
        }

        return null;
    };

    // ---- Nameserver resolution with multi-source fallback ----
    $resolveNameservers = function () use ($callSoft, $domainId) {
        $nameservers = [];
        $errors      = [];

        // Source 1: registrar module live query.
        $nsRes = $callSoft('DomainGetNameservers', ['domainid' => $domainId]);
        if ($nsRes['ok']) {
            for ($i = 1; $i <= 5; $i++) {
                $ns = trim((string)($nsRes['data']["ns{$i}"] ?? ''));
                if ($ns !== '') {
                    $nameservers[$i] = $ns;
                }
            }
            if (!empty($nameservers)) {
                return ['nameservers' => $nameservers, 'source' => 'DomainGetNameservers', 'errors' => []];
            }
            $errors[] = 'DomainGetNameservers returned success but no nameservers';
        } else {
            $errors[] = 'DomainGetNameservers failed: ' . $nsRes['error'];
        }

        // Source 2: WHMCS DB via GetClientsDomains.
        $domainRes = $callSoft('GetClientsDomains', ['domainid' => $domainId, 'limitnum' => 1]);
        if ($domainRes['ok']) {
            $entry = $domainRes['data']['domains']['domain'][0] ?? null;
            if (is_array($entry)) {
                for ($i = 1; $i <= 5; $i++) {
                    $ns = trim((string)($entry["ns{$i}"] ?? ''));
                    if ($ns !== '') {
                        $nameservers[$i] = $ns;
                    }
                }
            }
            if (!empty($nameservers)) {
                return ['nameservers' => $nameservers, 'source' => 'GetClientsDomains', 'errors' => $errors];
            }
            $errors[] = 'GetClientsDomains returned no nameservers';
        } else {
            $errors[] = 'GetClientsDomains failed: ' . $domainRes['error'];
        }

        return ['nameservers' => [], 'source' => 'none', 'errors' => $errors];
    };

    $resolvedNameservers     = $resolveNameservers();
    $nameservers             = $resolvedNameservers['nameservers'];
    $nameserverSource        = $resolvedNameservers['source'];
    $nameserverResolveErrors = $resolvedNameservers['errors'];

    // If WHMCS API sources returned nothing, fall back to tbldomains columns directly.
    if (empty($nameservers)) {
        for ($i = 1; $i <= 5; $i++) {
            $ns = trim((string)($row->{"ns{$i}"} ?? ''));
            if ($ns !== '') {
                $nameservers[$i] = $ns;
            }
        }
        if (!empty($nameservers)) {
            $nameserverSource = 'tbldomains_direct';
        }
    }

    // Create addon log row NOW (we are truly triggered).
    $mode  = $dryRun ? 'DRY RUN' : 'LIVE';
    $migId = $dmInsert([
        'domain_id'     => $domainId,
        'domain'        => $domain,
        'client_id'     => $clientId ?: null,
        'invoice_id'    => $invoiceId ?: null,
        'old_registrar' => $currentRegistrar,
        'new_registrar' => $targetRegistrar,
        'dry_run'       => $dryRun ? 1 : 0,
        'status'        => 'triggered',
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
            $addAdminNote(
                'Nameservers resolved for transfer (' . $nameserverSource . '): ' .
                implode(', ', $nameservers)
            );
        } else {
            $addAdminNote(
                'No nameservers resolved — transfer will use registrar defaults. Details: ' .
                implode(' | ', $nameserverResolveErrors)
            );
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

        if ($alreadyPending) {
            $logActivityMsg("Already Pending Transfer — skipping DB flip + transfer submit");
            $addAdminNote("Already Pending Transfer — skipped");

            if ($auditSkipped) {
                $dmSetStatus($migId, 'skipped', 'Already Pending Transfer; skipped to avoid duplicates', [
                    'transfer_submitted' => 0,
                ]);
            } else {
                try {
                    Capsule::table('mod_domain_migration')->where('id', $migId)->delete();
                } catch (\Throwable $e) {
                    // ignore
                }
            }

            return ['abortWithSuccess' => true];
        }

        $logActivityMsg(
            "Updated WHMCS DB: registrar={$targetRegistrar}, status=Pending Transfer" .
            ($setDoNotRenewDuringTransfer ? ", donotrenew=1" : "")
        );
        $addAdminNote(
            "Registrar changed to {$targetRegistrar}, status set to Pending Transfer" .
            ($setDoNotRenewDuringTransfer ? ", donotrenew=1" : "")
        );

        // 6b) Set type=Transfer in WHMCS
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

            // Resolve each NS hostname to an IPv4 (or IPv6) address and pass in
            // name/ip format. APITools::createNameserversArray() checks for a '/'
            // separator and takes the IP directly, bypassing both the OP registry
            // lookup and the broken gethostbyname path that discards external NSes.
            $resolvedForTransfer = [];
            foreach ($nameservers as $index => $nsName) {
                $nsIp = $resolveNsIp($nsName);
                if ($nsIp !== null) {
                    $transferPayload["ns{$index}"] = $nsName . '/' . $nsIp;
                    $resolvedForTransfer[]         = $nsName . ' (' . $nsIp . ')';
                } else {
                    // Pass without IP as last resort; combined with the plugin patch
                    // (if applied) external NSes will still be accepted.
                    $transferPayload["ns{$index}"] = $nsName;
                    $resolvedForTransfer[]         = $nsName . ' (IP unresolved - passed without)';
                }
            }

            $call('DomainTransfer', $transferPayload);

            $logActivityMsg(
                "Transfer submitted to {$targetRegistrar} with resolved NSes: " .
                implode(', ', $resolvedForTransfer)
            );
            $addAdminNote(
                "Transfer submitted to {$targetRegistrar} with NSes: " .
                implode(', ', $resolvedForTransfer)
            );

            $dmSetStatus($migId, 'submitted', null, ['transfer_submitted' => 1]);

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
