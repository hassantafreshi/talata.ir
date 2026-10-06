<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Screen-level team permissions (docs/TEAM_PERMISSIONS.md). Members who already had a restricted list could
 * open مظنه, the calculator, the invoice list and the customer list without a permission; keep that access
 * so nobody loses a screen by this change. New features (e.g. reports.view) are not granted silently.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('memberships')->where('role', '!=', 'owner')->get(['id', 'permissions']) as $m) {
            $perms = json_decode($m->permissions ?? '[]', true) ?: [];
            $perms = array_values(array_unique(array_merge($perms, ['mazneh.view', 'calculator.use', 'invoices.view', 'customers.view'])));
            DB::table('memberships')->where('id', $m->id)->update(['permissions' => json_encode($perms)]);
        }
    }

    public function down(): void {}
};
