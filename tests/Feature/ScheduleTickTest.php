<?php

namespace Tests\Feature;

use App\Support\ScheduleMonitor;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** `talata:tick` runs the due scheduled jobs inside the PHP process, for hosts where proc_open is disabled. */
class ScheduleTickTest extends TestCase
{
    public function test_tick_runs_every_minute_jobs_in_process_and_records_their_last_run(): void
    {
        $this->assertNull(ScheduleMonitor::last('payments-reconcile')['at']);
        $this->assertSame(0, Artisan::call('talata:tick'));
        $last = ScheduleMonitor::last('payments-reconcile');
        $this->assertNotNull($last['at']);
        $this->assertTrue($last['ok']);
        $this->assertNotNull(ScheduleMonitor::last('sms-reconcile')['at']);
        $this->assertNotNull(ScheduleMonitor::last('quotes')['at']);
    }

    public function test_the_tick_does_not_start_a_shell_process(): void
    {
        $code = (string) file_get_contents(base_path('routes/console.php'));
        $tick = substr($code, (int) strpos($code, "Artisan::command('talata:tick'"));
        $tick = substr($tick, 0, (int) strpos($tick, '// Each job records'));
        $this->assertDoesNotMatchRegularExpression('/\b(exec|shell_exec|system|passthru|proc_open|popen)\s*\(|new Process|Process::/', $tick);
    }
}
