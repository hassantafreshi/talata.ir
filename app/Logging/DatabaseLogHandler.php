<?php

namespace App\Logging;

use App\Support\TechLog;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Throwable;

/**
 * Writes log records to `system_logs` through a dedicated connection, so entries survive
 * rollbacks of the request's own transaction. Never throws; never recurses.
 */
final class DatabaseLogHandler extends AbstractProcessingHandler
{
    private static bool $writing = false;

    protected function write(LogRecord $record): void
    {
        if (self::$writing) {
            return;
        }
        self::$writing = true;
        try {
            $context = $record->context;
            $service = (string) ($context['service'] ?? 'app');
            unset($context['service']);
            if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
                $e = $context['exception'];
                $context['exception'] = ['class' => $e::class, 'message' => TechLog::scrub(mb_substr($e->getMessage(), 0, 500)),
                    'file' => str_replace(base_path().'/', '', $e->getFile()).':'.$e->getLine(),
                    'trace' => array_slice(array_map(fn ($f) => ($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? '').' '.str_replace(base_path().'/', '', $f['file'] ?? '').':'.($f['line'] ?? ''), $e->getTrace()), 0, 12)];
            }
            DB::connection(config('database.log_connection'))->table('system_logs')->insert([
                'created_at' => $record->datetime->format('Y-m-d H:i:s.uP'),
                'level' => strtolower($record->level->getName()),
                'service' => mb_substr(preg_replace('/[^a-z0-9_.-]/i', '', $service) ?: 'app', 0, 30),
                'message' => mb_substr(TechLog::scrub($record->message), 0, 500),
                'context' => json_encode(TechLog::redact($context), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'request_id' => Context::get('request_id'),
                'tenant_id' => Context::get('tenant_id'),
                'user_id' => Context::get('user_id'),
            ]);
        } catch (Throwable) {
            // Logging must never break the request.
        } finally {
            self::$writing = false;
        }
    }
}
