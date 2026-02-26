<?php
use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function domain_migration_config()
{
    return [
        'name'        => 'Domain Migration Audit',
        'description' => 'Tracks renewal->transfer migrations (e.g. CNIC -> OpenProvider) and provides an admin audit table.',
        'author'      => 'chris',
        'version'     => '1.0.0',
        'language'    => 'english',
    ];
}

function domain_migration_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_domain_migration')) {
            Capsule::schema()->create('mod_domain_migration', function ($table) {
                /** @var \Illuminate\Database\Schema\Blueprint $table */
                $table->increments('id');

                $table->integer('domain_id')->unsigned()->index();
                $table->string('domain', 255)->index();
                $table->integer('client_id')->unsigned()->nullable()->index();
                $table->integer('invoice_id')->unsigned()->nullable()->index();

                $table->string('old_registrar', 64)->nullable()->index();
                $table->string('new_registrar', 64)->nullable()->index();

                $table->boolean('dry_run')->default(false)->index();

                $table->text('epp')->nullable();

                $table->boolean('unlock_ok')->nullable();
                $table->boolean('idprotect_ok')->nullable();
                $table->boolean('transfer_submitted')->nullable();

                $table->string('status', 32)->default('triggered')->index(); // triggered|skipped|blocked|submitted|failed
                $table->text('error')->nullable();

                $table->timestamps();
            });
        }

        return ['status' => 'success', 'description' => 'Domain Migration Audit activated'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Activate failed: ' . $e->getMessage()];
    }
}

function domain_migration_deactivate()
{
    // Usually you DON'T drop audit tables on deactivate. Safer to keep history.
    // If you want a full removal path, add a "Delete Data" tool/action instead.
    return ['status' => 'success', 'description' => 'Deactivated (data retained)'];
}

function domain_migration_output($vars)
{
    $modulelink = $vars['modulelink']; // e.g. addonmodules.php?module=domain_migration

    // simple pagination
    $page  = max(1, (int)($_GET['page'] ?? 1));
    $limit = 50;
    $off   = ($page - 1) * $limit;

    // filters
    $status = trim((string)($_GET['status'] ?? ''));
    $q      = trim((string)($_GET['q'] ?? '')); // domain / domain_id / invoice_id

    $query = Capsule::table('mod_domain_migration')->orderBy('id', 'desc');

    if ($status !== '') {
        $query->where('status', $status);
    }

    if ($q !== '') {
        $query->where(function ($w) use ($q) {
            if (ctype_digit($q)) {
                $w->orWhere('domain_id', (int)$q)
                  ->orWhere('invoice_id', (int)$q)
                  ->orWhere('client_id', (int)$q);
            }
            $w->orWhere('domain', 'like', '%' . $q . '%');
        });
    }

    $total = (clone $query)->count();
    $rows  = $query->limit($limit)->offset($off)->get();

    // helpers for WHMCS admin links (these paths are standard in WHMCS admin)
    $linkDomain  = fn($domainId) => 'clientsdomains.php?domainid=' . (int)$domainId;
    $linkClient  = fn($clientId) => 'clientssummary.php?userid=' . (int)$clientId;
    $linkInvoice = fn($invoiceId) => 'invoices.php?action=edit&id=' . (int)$invoiceId;

    echo '<div class="content-area">';
    echo '<h2>Domain Migration Audit</h2>';

    // filter form
    echo '<form method="get" style="margin: 12px 0;">';
    echo '<input type="hidden" name="module" value="domain_migration" />';
    echo 'Status: <select name="status">';
    echo '<option value="">(all)</option>';
    foreach (['triggered','submitted','failed','skipped','blocked'] as $s) {
        $sel = ($status === $s) ? 'selected' : '';
        echo "<option value=\"{$s}\" {$sel}>{$s}</option>";
    }
    echo '</select> ';
    echo 'Search: <input type="text" name="q" value="' . htmlspecialchars($q) . '" placeholder="domain / domainid / invoiceid / userid" /> ';
    echo '<button type="submit" class="btn btn-default">Filter</button>';
    echo '</form>';

    // table
    echo '<table class="table table-striped table-bordered">';
    echo '<thead><tr>
            <th>ID</th>
            <th>Domain</th>
            <th>Client</th>
            <th>Invoice</th>
            <th>Old → New</th>
            <th>Dry</th>
            <th>Status</th>
            <th>Transfer</th>
            <th>When</th>
          </tr></thead><tbody>';

    foreach ($rows as $r) {
        $domLink = '<a href="' . $linkDomain($r->domain_id) . '">' . htmlspecialchars($r->domain) . '</a>';
        $cliLink = $r->client_id ? '<a href="' . $linkClient($r->client_id) . '">#' . (int)$r->client_id . '</a>' : '-';
        $invLink = $r->invoice_id ? '<a href="' . $linkInvoice($r->invoice_id) . '">#' . (int)$r->invoice_id . '</a>' : '-';

        $reg = htmlspecialchars(($r->old_registrar ?? '-') . ' → ' . ($r->new_registrar ?? '-'));
        $dry = $r->dry_run ? 'yes' : 'no';
        $st  = htmlspecialchars($r->status);
        $tr  = is_null($r->transfer_submitted) ? '-' : ($r->transfer_submitted ? 'submitted' : 'no');

        $when = htmlspecialchars((string)$r->created_at);

        echo "<tr>
                <td>{$r->id}</td>
                <td>{$domLink}<br/><small>domainid={$r->domain_id}</small></td>
                <td>{$cliLink}</td>
                <td>{$invLink}</td>
                <td>{$reg}</td>
                <td>{$dry}</td>
                <td>{$st}</td>
                <td>{$tr}</td>
                <td>{$when}</td>
              </tr>";

        if (!empty($r->error)) {
            echo '<tr><td colspan="9"><div style="color:#a94442;"><strong>Error:</strong> '
               . htmlspecialchars($r->error)
               . '</div></td></tr>';
        }
    }

    if (count($rows) === 0) {
        echo '<tr><td colspan="9"><em>No records</em></td></tr>';
    }

    echo '</tbody></table>';

    // pagination
    $pages = (int)ceil($total / $limit);
    if ($pages > 1) {
        echo '<div style="margin: 10px 0;">Pages: ';
        for ($p=1; $p <= $pages; $p++) {
            $url = $modulelink . '&page=' . $p . '&status=' . urlencode($status) . '&q=' . urlencode($q);
            if ($p === $page) {
                echo " <strong>{$p}</strong> ";
            } else {
                echo ' <a href="' . $url . '">' . $p . '</a> ';
            }
        }
        echo '</div>';
    }

    echo '</div>';
}
