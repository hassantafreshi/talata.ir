<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Domain\Plans\Entitlements;
use App\Domain\Settings\SettingsBackups;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\SettingsBackup;
use App\Models\ShopProfile;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TenantsController extends Controller
{
    public function index(Request $request)
    {
        $q = Tenant::query()->with(['profile' => fn ($p) => $p->withoutGlobalScope('tenant')])->orderByDesc('id');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('public_id', strtolower($s))
                ->orWhereIn('id', ShopProfile::withoutGlobalScope('tenant')->where('name', 'ilike', '%'.addcslashes($s, '%_\\').'%')->select('tenant_id')));
        }

        return view('admin.tenants', ['page' => $q->paginate(50)->withQueryString(), 'search' => $request->query('q', '')]);
    }

    public function show(Tenant $tenant, Entitlements $ent)
    {
        Audit::record('admin.viewed_tenant', $tenant, [], $tenant->id, 'staff');

        return view('admin.tenant', [
            'tenant' => $tenant, 'profile' => $tenant->profile()->withoutGlobalScope('tenant')->first(),
            'summary' => $ent->summary($tenant),
            'members' => Membership::query()->where('tenant_id', $tenant->id)->with('user')->get(),
            'byService' => AuditEvent::query()->where('tenant_id', $tenant->id)->selectRaw('service, count(*) c')->groupBy('service')->pluck('c', 'service'),
            'backups' => SettingsBackup::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->orderByDesc('id')->limit(50)->get(),
            'sections' => SettingsBackups::SECTIONS,
        ]);
    }

    /** Support restores a shop's settings backup on the owner's request (admin role only; audited as staff). */
    public function restoreBackup(Request $request, Tenant $tenant, int $backup, TenantContext $context, SettingsBackups $backups)
    {
        $data = $request->validate(['sections' => ['required', 'array'], 'sections.*' => ['string', 'max:20']]);
        $row = SettingsBackup::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->findOrFail($backup);
        $undo = $context->runAs($tenant, fn () => $backups->restore($tenant, $row, $data['sections'], (int) Auth::guard('staff')->id()));

        return response()->json(['ok' => true, 'undo_backup' => $undo?->id]);
    }
}
