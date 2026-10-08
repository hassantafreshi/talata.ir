<?php

namespace Tests\Feature;

use App\Domain\Plans\UpgradeInfo;
use Tests\TestCase;

/** Free-plan locked features open a clear «ارتقا» prompt instead of doing nothing (owner request 2026-10-08). */
class UpgradePromptTest extends TestCase
{
    public function test_upgrade_info_comes_from_the_commercial_config(): void
    {
        $this->assertSame(['plans' => 'پایه و حرفه‌ای', 'price_fa' => '۷۹۰٬۰۰۰'], UpgradeInfo::for('invoice.customize'));
        $this->assertSame('حرفه‌ای', UpgradeInfo::for('installments.manage')['plans']);
        $this->assertSame(['plans' => '', 'price_fa' => null], UpgradeInfo::for('no.such.capability'));
    }

    public function test_free_shop_sees_locked_controls_that_open_the_upgrade_sheet(): void
    {
        $this->actingAs($this->merchant());
        foreach ([
            '/settings/appearance' => 'invoice.customize',
            '/settings/sms-template' => 'sms.template_edit',
            '/settings/proforma' => 'proforma.configure',
            '/settings/backups' => 'settings.backup',
            '/settings/users' => 'team.permissions_edit',
            '/invoices' => 'installments.manage',
            '/customers' => 'installments.manage',
            '/dashboard' => 'reports.financial',
            '/settings/business' => 'invoice.shop_logo',
        ] as $url => $cap) {
            $this->get($url)->assertOk()->assertSee('data-upgrade="'.$cap.'"', false)->assertSee('data-upgrade-plans=', false);
        }
        $this->get('/settings')->assertOk()->assertSee('lock-badge', false);
    }

    public function test_paid_shop_sees_no_locks_for_its_features(): void
    {
        $this->actingAs($this->merchant('professional'));
        foreach (['/settings/appearance', '/settings/sms-template', '/settings/proforma', '/settings/backups', '/invoices', '/customers'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('data-upgrade=', false);
        }
        $this->get('/settings')->assertOk()->assertDontSee('lock-badge', false);
    }
}
