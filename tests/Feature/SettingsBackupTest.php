<?php

namespace Tests\Feature;

use App\Domain\Identity\LoginService;
use App\Domain\Settings\SettingsBackups;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\SettingsBackup;
use App\Models\ShopProfile;
use App\Models\StaffUser;
use App\Models\TenantSetting;
use Tests\TestCase;

/** Settings backups: docs/SETTINGS_BACKUPS.md. */
class SettingsBackupTest extends TestCase
{
    private function business(string $name): void
    {
        $this->api('POST', '/api/settings/business', ['name' => $name, 'business_mobile' => '09121112233', 'address' => 'تهران، بازار بزرگ، پلاک ۱'])->assertOk();
    }

    private function backups(int $tenantId)
    {
        return SettingsBackup::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->orderBy('id')->get();
    }

    public function test_every_settings_change_is_backed_up_and_owner_restores_a_section(): void
    {
        $owner = $this->merchant('basic');
        $tenant = $this->tenantOf($owner);
        $this->actingAs($owner);
        $this->business('طلافروشی الف');
        $this->business('طلافروشی الف'); // unchanged → no duplicate backup
        $this->business('طلافروشی ب');
        $this->api('PUT', '/api/settings/numbering', ['settings' => ['prefix' => 'ط', 'year' => 'short', 'month' => false, 'separator' => '-', 'digits' => 4, 'reset' => 'yearly'], 'version' => 0])->assertOk();
        $list = $this->backups($tenant->id);
        $this->assertSame(['profile', 'profile', 'numbering'], $list->pluck('reason')->all());
        $this->assertSame('طلافروشی الف', $list[0]->payload['profile']['name']);

        $this->assertSame([], app(SettingsBackups::class)->differs(SettingsBackup::query()->latest('id')->first()->payload), 'the newest backup equals the current settings');
        $this->get('/settings/backups')->assertOk()->assertSee('پشتیبان تنظیمات')->assertSee('فرق با الان')->assertSee('بازگرداندن بخش‌های انتخاب‌شده');
        // Restore only the business profile of the first backup; numbering stays as it is now.
        $this->api('POST', "/api/settings/backups/{$list[0]->id}/restore", ['sections' => ['profile']])->assertOk();
        $this->assertSame('طلافروشی الف', ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->value('name'));
        $this->assertSame('ط', TenantSetting::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->value('value')['prefix']);
        $last = $this->backups($tenant->id)->where('reason', 'before_restore')->last();
        $this->assertSame('طلافروشی ب', $last->payload['profile']['name'], 'current state saved first, so the restore can be undone');
        $this->assertSame(1, AuditEvent::query()->where('event', 'settings.backup_restored')->where('actor_type', 'user')->count());

        $this->api('POST', '/api/settings/backups', ['label' => 'قبل از عید'])->assertCreated();
        $this->assertSame('قبل از عید', $this->backups($tenant->id)->last()->label);
        $this->api('POST', "/api/settings/backups/{$list[0]->id}/restore", ['sections' => []])->assertStatus(422);
    }

    public function test_only_the_last_50_are_kept_and_free_plan_has_no_backups(): void
    {
        $owner = $this->merchant('professional');
        $tenant = $this->tenantOf($owner);
        $this->actingAs($owner);
        // 53 settings changes (driven through the service: the HTTP save is rate limited to 20/minute).
        $this->inTenant($owner, function () use ($tenant) {
            for ($i = 1; $i <= 53; $i++) {
                ShopProfile::query()->update(['name' => 'طلافروشی شماره '.$i]);
                app(SettingsBackups::class)->capture($tenant, 'profile');
            }
        });
        $list = $this->backups($tenant->id);
        $this->assertCount(50, $list);
        $this->assertSame('طلافروشی شماره 53', $list->last()->payload['profile']['name']);
        $this->assertSame('طلافروشی شماره 4', $list->first()->payload['profile']['name']);

        $free = $this->merchant('free');
        $this->actingAs($free);
        $this->business('فروشگاه رایگان');
        $this->assertCount(0, $this->backups($this->tenantOf($free)->id));
        $this->get('/settings/backups')->assertOk()->assertSee('data-upgrade="settings.backup"', false)->assertSee('پایه و حرفه‌ای')->assertDontSee('data-manual', false);
        $this->api('POST', '/api/settings/backups', [])->assertStatus(403);
    }

    public function test_members_cannot_restore_and_admin_restores_on_request(): void
    {
        $owner = $this->merchant('basic');
        $tenant = $this->tenantOf($owner);
        $this->actingAs($owner);
        $this->business('نام قدیمی');
        $this->business('نام جدید');
        $first = $this->backups($tenant->id)->first();

        $this->api('POST', '/api/users/invite', ['mobile' => '09371112288'])->assertOk();
        $member = app(LoginService::class)->completeLogin('09371112288')['user'];
        $invite = Membership::query()->where('invited_mobile', '09371112288')->firstOrFail();
        $this->actingAs($member)->api('POST', "/api/invites/{$invite->id}/accept")->assertOk();
        $this->get('/settings/backups')->assertForbidden();
        $this->api('POST', "/api/settings/backups/{$first->id}/restore", ['sections' => ['profile']])->assertForbidden();

        $staff = StaffUser::query()->create(['mobile' => '09120000021', 'name' => 'پشتیبان ارشد', 'role' => 'admin', 'active' => true]);
        $this->asStaff($staff);
        $this->get("/admin/tenants/{$tenant->id}")->assertOk()->assertSee('پشتیبان تنظیمات');
        $this->postJson("/admin/api/tenants/{$tenant->id}/backups/{$first->id}/restore", ['sections' => ['profile']])->assertOk();
        $this->assertSame('نام قدیمی', ShopProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->value('name'));
        $this->assertSame(1, AuditEvent::query()->where('event', 'settings.backup_restored')->where('staff_id', $staff->id)->where('tenant_id', $tenant->id)->count());

        // A backup of another shop is never reachable through this shop's URL.
        $other = $this->merchant('basic');
        $this->actingAs($other);
        $this->business('دیگری');
        $foreign = $this->backups($this->tenantOf($other)->id)->first();
        $this->asStaff($staff);
        $this->postJson("/admin/api/tenants/{$tenant->id}/backups/{$foreign->id}/restore", ['sections' => ['profile']])->assertNotFound();
    }
}
