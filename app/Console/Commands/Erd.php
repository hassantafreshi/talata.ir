<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Regenerates docs/ERD.md from the live PostgreSQL schema (tables, keys, foreign keys) so the diagram
 * never drifts from the migrations. Run after `php artisan migrate`: `php artisan talata:erd`.
 */
class Erd extends Command
{
    protected $signature = 'talata:erd {--path=docs/ERD.md}';

    protected $description = 'Write the entity-relationship diagram (Mermaid) generated from the database schema';

    private const SKIP = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'sessions', 'password_reset_tokens'];

    public function handle(): int
    {
        $tables = collect(DB::select("select table_name from information_schema.tables where table_schema = current_schema() and table_type = 'BASE TABLE' order by table_name"))
            ->pluck('table_name')->reject(fn ($t) => in_array($t, self::SKIP, true))->values();
        $fks = collect(DB::select(<<<'SQL'
            select tc.table_name as child, kcu.column_name as col, ccu.table_name as parent
            from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on tc.constraint_name = kcu.constraint_name and tc.table_schema = kcu.table_schema
            join information_schema.constraint_column_usage ccu on tc.constraint_name = ccu.constraint_name and tc.table_schema = ccu.table_schema
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = current_schema()
            order by child, col
        SQL));
        $cols = collect(DB::select("select table_name, column_name, data_type, is_nullable from information_schema.columns where table_schema = current_schema() order by table_name, ordinal_position"))->groupBy('table_name');

        $lines = ['erDiagram'];
        foreach ($fks as $fk) {
            if ($tables->contains($fk->child) && $tables->contains($fk->parent)) {
                $lines[] = "    {$fk->parent} ||--o{ {$fk->child} : \"{$fk->col}\"";
            }
        }
        foreach ($tables as $t) {
            $fkCols = $fks->where('child', $t)->pluck('col')->all();
            $keep = collect($cols[$t] ?? [])->filter(fn ($c) => $c->column_name === 'id' || $c->column_name === 'tenant_id' || $c->column_name === 'public_id' || in_array($c->column_name, $fkCols, true) || $c->column_name === 'status');
            $lines[] = "    {$t} {";
            foreach ($keep as $c) {
                $type = preg_replace('/[^a-z]/', '', strtolower(explode(' ', $c->data_type)[0]));
                $flag = $c->column_name === 'id' ? ' PK' : (in_array($c->column_name, $fkCols, true) ? ' FK' : '');
                $lines[] = "        {$type} {$c->column_name}{$flag}";
            }
            $lines[] = '    }';
        }

        $tenantScoped = $tables->filter(fn ($t) => collect($cols[$t] ?? [])->contains('column_name', 'tenant_id'))->values();
        $md = "# Zarlio — entity-relationship diagram\n\n"
            ."Generated from the database schema by `php artisan talata:erd` (do not edit by hand; re-run after migrations).\n"
            ."Shows primary keys, foreign keys, `tenant_id`, `public_id` and `status`. Framework tables (sessions, cache, jobs, migrations) are omitted.\n\n"
            .'Tables carrying `tenant_id` (merchant data uses the fail-closed `BelongsToTenant` scope; audit, log and admin tables only reference the shop — see `docs/adr/0003-tenant-isolation.md`): '
            .$tenantScoped->map(fn ($t) => "`{$t}`")->implode(', ').".\n\n"
            ."```mermaid\n".implode("\n", $lines)."\n```\n";

        file_put_contents(base_path($this->option('path')), $md);
        $this->info('Wrote '.$this->option('path').' ('.$tables->count().' tables, '.$fks->count().' foreign keys).');

        return self::SUCCESS;
    }
}
