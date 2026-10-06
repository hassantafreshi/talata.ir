<?php

namespace App\Domain\Settings;

use App\Domain\Audit\Audit;
use App\Domain\DomainError;
use App\Domain\Invoices\LayoutSettings;
use App\Domain\Invoices\Numbering;
use App\Domain\Plans\Entitlements;
use App\Models\InvoiceLayout;
use App\Models\SettingsBackup;
use App\Models\ShopProfile;
use App\Models\SmsSetting;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Settings backups (docs/SETTINGS_BACKUPS.md): after every settings change the whole settings state of the
 * shop is saved; the newest N (quota settings_backups, default 50) are kept. Capability settings.backup
 * (Basic/Professional). Restore is per section, always saves the current state first ("before_restore"),
 * and never touches invoices, counters, team members, credit or billing.
 */
final class SettingsBackups
{
    public const SECTIONS = [
        'profile' => 'اطلاعات کسب‌وکار (نام، تلفن، آدرس، شبکه‌ها، مجوزها)',
        'logo' => 'لوگو',
        'layout' => 'ظاهر فاکتور',
        'sms_template' => 'متن پیامک فاکتور',
        'numbering' => 'شماره‌گذاری فاکتور',
    ];

    private const PROFILE_FIELDS = ['name', 'business_mobile', 'landline', 'address', 'website', 'socials', 'license_union', 'license_online'];

    public function __construct(private readonly Entitlements $entitlements) {}

    public function enabled(Tenant $tenant): bool
    {
        return $this->entitlements->can($tenant, 'settings.backup');
    }

    public function keep(Tenant $tenant): int
    {
        return max(1, (int) ($this->entitlements->limitOr($tenant, 'settings_backups', 50) ?? 50));
    }

    /** Current settings state of the tenant in context. */
    public function state(): array
    {
        $profile = ShopProfile::query()->first();
        $layout = InvoiceLayout::query()->first();

        return [
            'schema' => 1,
            'profile' => $profile ? $profile->only(self::PROFILE_FIELDS) : null,
            'logo' => $profile?->logo_path ? ['path' => $profile->logo_path, 'version' => $profile->logo_version] : null,
            'layout' => $layout?->settings,
            'sms_template' => SmsSetting::query()->value('invoice_template'),
            'numbering' => TenantSetting::query()->where('key', Numbering::KEY)->value('value'),
        ];
    }

    /**
     * Saves the current state if the plan allows it and it differs from the newest backup. Never throws:
     * a backup problem must not undo the settings change the user just made.
     */
    public function capture(Tenant $tenant, string $reason, ?string $label = null, ?int $staffId = null, bool $force = false): ?SettingsBackup
    {
        if (! $this->enabled($tenant)) {
            return null;
        }
        try {
            $state = $this->state();
            $hash = hash('sha256', self::canon($state));
            $latest = SettingsBackup::query()->latest('id')->first();
            if (! $force && $latest && $latest->payload_hash === $hash) {
                return null;
            }
            $backup = SettingsBackup::create([
                'reason' => array_key_exists($reason, SettingsBackup::REASONS) ? $reason : 'manual',
                'label' => $label ? mb_substr(trim(strip_tags($label)), 0, 80) : null,
                'payload' => $state, 'payload_hash' => $hash,
                'created_by' => $staffId ? null : auth()->id(), 'created_by_staff' => $staffId,
            ]);
            $keep = $this->keep($tenant);
            $cut = SettingsBackup::query()->orderByDesc('id')->skip($keep)->value('id');
            if ($cut) {
                SettingsBackup::query()->where('id', '<=', $cut)->delete();
            }

            return $backup;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Restores the chosen sections of a backup. The current state is backed up first so the restore itself
     * can be undone. @param list<string> $sections
     */
    public function restore(Tenant $tenant, SettingsBackup $backup, array $sections, ?int $staffId = null): SettingsBackup
    {
        if (! $staffId && ! $this->enabled($tenant)) {
            throw new DomainError('CAPABILITY_SETTINGS_BACKUP', 'پشتیبان تنظیمات در پلن پایه و حرفه‌ای است.', 403);
        }
        $sections = array_values(array_intersect(array_keys(self::SECTIONS), $sections));
        if (! $sections) {
            throw new DomainError('VALIDATION', 'دست‌کم یک بخش را برای بازگرداندن انتخاب کنید.', 422, ['errors' => ['sections' => ['دست‌کم یک بخش را انتخاب کنید.']]]);
        }
        $p = $backup->payload;

        return DB::transaction(function () use ($tenant, $backup, $sections, $p, $staffId) {
            // Same lock as issuing and numbering saves.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $undo = $this->captureForced($tenant, $staffId);
            $profile = ShopProfile::query()->first();
            foreach ($sections as $section) {
                match ($section) {
                    'profile' => $p['profile'] && $profile ? $profile->update(array_intersect_key($p['profile'], array_flip(self::PROFILE_FIELDS))) : null,
                    'logo' => $profile ? $this->restoreLogo($profile, $p['logo'] ?? null) : null,
                    'layout' => $this->restoreLayout($p['layout'] ?? null),
                    'sms_template' => SmsSetting::query()->updateOrCreate([], ['invoice_template' => $p['sms_template'] ?? null]),
                    'numbering' => $this->restoreNumbering($p['numbering'] ?? null),
                };
            }
            Audit::record('settings.backup_restored', $backup, ['backup' => $backup->id, 'sections' => $sections, 'undo_backup' => $undo?->id], $tenant->id, $staffId ? 'staff' : 'user');

            return $undo;
        });
    }

    private function captureForced(Tenant $tenant, ?int $staffId): ?SettingsBackup
    {
        $state = $this->state();
        $backup = SettingsBackup::create([
            'reason' => 'before_restore', 'payload' => $state, 'payload_hash' => hash('sha256', self::canon($state)),
            'created_by' => $staffId ? null : auth()->id(), 'created_by_staff' => $staffId,
        ]);
        $cut = SettingsBackup::query()->orderByDesc('id')->skip($this->keep($tenant))->value('id');
        if ($cut) {
            SettingsBackup::query()->where('id', '<=', $cut)->delete();
        }

        return $backup;
    }

    /** Old logo files are kept for issued invoices; the version counter never goes back (no overwrite). */
    private function restoreLogo(ShopProfile $profile, ?array $logo): void
    {
        if (! $logo) {
            $profile->update(['logo_path' => null]);

            return;
        }
        if (Storage::disk('local')->exists($logo['path'])) {
            $profile->update(['logo_path' => $logo['path'], 'logo_version' => max((int) $profile->logo_version, (int) $logo['version'])]);
        }
    }

    private function restoreLayout(?array $settings): void
    {
        $layout = InvoiceLayout::query()->first();
        $clean = LayoutSettings::sanitize($settings ?? []);
        if ($layout) {
            $layout->update(['settings' => $clean, 'version' => $layout->version + 1, 'updated_by' => auth()->id()]);
        } else {
            InvoiceLayout::create(['settings' => $clean, 'version' => 1, 'updated_by' => auth()->id()]);
        }
    }

    private function restoreNumbering(?array $settings): void
    {
        $row = TenantSetting::query()->where('key', Numbering::KEY)->first();
        if ($settings === null) {
            $row?->delete();

            return;
        }
        $clean = Numbering::sanitize($settings, false);
        if ($row) {
            $row->update(['value' => $clean, 'version' => $row->version + 1, 'updated_by' => auth()->id()]);
        } else {
            TenantSetting::create(['key' => Numbering::KEY, 'value' => $clean, 'version' => 1, 'updated_by' => auth()->id()]);
        }
    }

    /** Human summary of what differs from the current state, per section. @return list<string> */
    public function differs(array $payload): array
    {
        $now = $this->state();

        return array_values(array_map(fn ($k) => self::SECTIONS[$k], array_filter(array_keys(self::SECTIONS), fn ($k) => self::canon($now[$k] ?? null) !== self::canon($payload[$k] ?? null))));
    }

    /** jsonb reorders object keys: compare (and hash) with keys sorted recursively. */
    private static function canon(mixed $v): string
    {
        $sort = function ($x) use (&$sort) {
            if (! is_array($x)) {
                return $x;
            }
            if (! array_is_list($x)) {
                ksort($x);
            }

            return array_map($sort, $x);
        };

        return json_encode($sort($v), JSON_UNESCAPED_UNICODE);
    }
}
