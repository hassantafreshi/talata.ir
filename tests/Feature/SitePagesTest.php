<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\StaffUser;
use Tests\TestCase;

/** zarlio.ir home, terms, privacy and the admin-editable support phone (owner 2026-10-07). */
class SitePagesTest extends TestCase
{
    public function test_public_pages_are_indexable_and_show_the_support_phone(): void
    {
        foreach (['/', '/terms', '/privacy'] as $url) {
            $res = $this->get($url)->assertOk()->assertSee('۰۹۳۹۶۷۲۷۲۱۵')->assertSee('قوانین و مقررات');
            $this->assertStringNotContainsString('noindex', $res->getContent(), $url);
        }
        $this->get('/')->assertSee('ورود / ثبت‌نام')->assertSee('مالیات')->assertDontSee('طلاتا');
        $this->actingAs($this->merchant())->get('/')->assertRedirect(route('home'));
    }

    public function test_only_admins_change_the_support_phone_and_merchants_see_it(): void
    {
        $support = StaffUser::query()->create(['mobile' => '09120004001', 'name' => 'پشتیبان', 'role' => 'support', 'active' => true]);
        $this->asStaff($support)->postJson('/admin/api/settings/support', ['phone' => '02112345678', 'reason' => 'تغییر خط', 'idempotency_key' => 'adm-sp000001'])->assertForbidden();

        $admin = StaffUser::query()->create(['mobile' => '09120004002', 'name' => 'مدیر', 'role' => 'admin', 'active' => true]);
        $this->asStaff($admin)->postJson('/admin/api/settings/support', ['phone' => '12', 'reason' => 'آزمون شماره بد', 'idempotency_key' => 'adm-sp000002'])->assertStatus(422);
        $this->postJson('/admin/api/settings/support', ['phone' => '۰۲۱-۱۲۳۴۵۶۷۸', 'reason' => 'خط ثابت پشتیبانی', 'idempotency_key' => 'adm-sp000003'])->assertOk()->assertJsonPath('phone', '02112345678');
        $this->assertSame(1, AuditEvent::query()->where('event', 'platform.support_phone_changed')->count());

        $this->actingAs($this->merchant())->get('/settings')->assertOk()->assertSee('۰۲۱۱۲۳۴۵۶۷۸');
        $this->get('/terms')->assertSee('۰۲۱۱۲۳۴۵۶۷۸');
    }
}
