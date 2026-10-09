<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopProfile;
use App\Models\StaffUser;
use App\Support\Digits;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Shared helpers for admin console controllers. */
abstract class AdminController extends Controller
{
    protected function staff(): StaffUser
    {
        return Auth::guard('staff')->user();
    }

    /** Dangerous actions carry a human reason that lands in the audit log (A-00). */
    protected function reason(Request $request, string $field = 'reason'): string
    {
        $data = $request->validate([$field => ['required', 'string', 'min:5', 'max:250']]);

        return trim(preg_replace('/\s+/u', ' ', strip_tags($data[$field])));
    }

    /** tenant id => shop name, for tables (no merchant customer data). */
    protected function shopNames(Collection $tenantIds): Collection
    {
        return ShopProfile::withoutGlobalScope('tenant')->whereIn('tenant_id', $tenantIds->filter()->unique())->pluck('name', 'tenant_id');
    }

    /**
     * CSV download with a UTF-8 BOM (Excel shows Persian correctly). Cells that a spreadsheet would run
     * as a formula (=, +, -, @, tab, CR) are prefixed with an apostrophe: shop names are merchant input.
     *
     * @param  iterable<array<int,mixed>>  $rows
     */
    protected function csv(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => self::cell($v), $row), escape: '');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    protected function latinDigits(?string $value): string
    {
        return Digits::toLatin((string) $value);
    }
}
