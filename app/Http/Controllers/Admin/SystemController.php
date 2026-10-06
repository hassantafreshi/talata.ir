<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Models\SmsMessage;
use App\Support\ScheduleMonitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** System health (docs/handoff/04_SCREENS_ADMIN.md A-12): queues, failed jobs, scheduler, backups, version. */
class SystemController extends AdminController
{
    public function index()
    {
        $jobs = DB::table('jobs')->selectRaw('queue, count(*) c, min(available_at) oldest')->groupBy('queue')->get();
        $oldest = $jobs->min('oldest');
        $migrator = app('migrator');
        $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $pending = array_values(array_diff($files, $migrator->getRepository()->getRan()));

        return view('admin.system', [
            'jobs' => $jobs,
            'lag' => $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : 0,
            'failed' => DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get(['id', 'uuid', 'queue', 'exception', 'failed_at']),
            'failedCount' => DB::table('failed_jobs')->count(),
            'outbox' => SmsMessage::query()->whereIn('status', ['QUEUED', 'SENDING'])->count(),
            'outboxOldest' => SmsMessage::query()->whereIn('status', ['QUEUED', 'SENDING'])->min('created_at'),
            'schedule' => collect(ScheduleMonitor::JOBS)->map(fn ($meta, $key) => ['label' => $meta[0], 'cadence' => $meta[1]] + ScheduleMonitor::last($key)),
            'backup' => $this->heartbeat((string) config('talata.ops.backup_heartbeat_file')),
            'drill' => $this->heartbeat((string) config('talata.ops.restore_drill_file')),
            'version' => config('talata.ops.release'),
            'env' => app()->environment(), 'debug' => (bool) config('app.debug'),
            'pending' => $pending,
            'php' => PHP_VERSION, 'laravel' => app()->version(),
            'canManage' => $this->staff()->allows('system.manage'),
        ]);
    }

    /** Re-runs one failed queue job. Jobs are idempotent (SendSms claims its row; billing locks orders). */
    public function retry(string $uuid): JsonResponse
    {
        if (! preg_match('/^[0-9a-f-]{36}$/', $uuid) || ! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            throw new DomainError('JOB_NOT_FOUND', 'این کار در فهرست کارهای ناموفق نیست (شاید قبلاً اجرا شده است).', 404);
        }
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        Audit::record('system.job_retried', null, ['uuid' => $uuid], null, 'staff');

        return response()->json(['message_fa' => 'دوباره در صف قرار گرفت.']);
    }

    /** A file the backup / restore-drill script touches after success; its time is the last success. */
    private function heartbeat(string $path): ?int
    {
        return $path !== '' && is_file($path) ? (int) filemtime($path) : null;
    }
}
