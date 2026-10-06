<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Digits;
use App\Support\Jalali;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Technical log (system_logs) per service. Admin role only. */
class TechLogController extends Controller
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    public function index(Request $request)
    {
        $db = DB::connection('pgsql_log');
        $q = $db->table('system_logs')->orderByDesc('id');
        if ($s = $request->query('service')) {
            $q->where('service', $s);
        }
        if (($l = $request->query('level')) && in_array($l, self::LEVELS, true)) {
            $q->whereIn('level', array_slice(self::LEVELS, array_search($l, self::LEVELS, true)));
        }
        if ($r = trim((string) $request->query('request'))) {
            $q->where('request_id', strtolower($r));
        }
        if ($t = trim((string) $request->query('q'))) {
            $q->where('message', 'ilike', '%'.addcslashes($t, '%_\\').'%');
        }
        if ($t = $request->query('tenant')) {
            $q->where('tenant_id', (int) $t);
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            if ($d = Jalali::parse(Digits::toLatin((string) $request->query($key)), config('talata.timezone'))) {
                $q->where('created_at', $op, $key === 'to' ? $d->addDay() : $d);
            }
        }

        return view('admin.tech', [
            'page' => $q->paginate(50)->withQueryString(),
            'filters' => $request->query(),
            'services' => $db->table('system_logs')->where('created_at', '>=', now()->subDays(30))->distinct()->orderBy('service')->pluck('service'),
            'levels' => self::LEVELS,
        ]);
    }
}
