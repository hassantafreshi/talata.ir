<?php

namespace Tests\Feature;

use App\Domain\Invoices\LayoutSettings;
use Tests\TestCase;

/** Layout editor colours: print-safe presets, readable custom colours only, preview carries the accent. */
class InvoiceAppearanceTest extends TestCase
{
    public function test_custom_accent_is_kept_only_when_readable_on_white(): void
    {
        $dark = LayoutSettings::sanitize(['template_id' => 'shop', 'typography' => ['accent' => 'custom', 'accent_hex' => '#5B2A86']]);
        $this->assertSame('custom', $dark['typography']['accent']);
        $this->assertSame('#5b2a86', LayoutSettings::accentHex($dark));

        $light = LayoutSettings::sanitize(['typography' => ['accent' => 'custom', 'accent_hex' => '#ffee99']]);
        $this->assertSame('ink', $light['typography']['accent']);
        $this->assertArrayNotHasKey('accent_hex', $light['typography']);

        $preset = LayoutSettings::sanitize(['typography' => ['accent' => 'navy', 'accent_hex' => '#000000']]);
        $this->assertSame('#1F3A5F', LayoutSettings::accentHex($preset));
        $this->assertArrayNotHasKey('accent_hex', $preset['typography']);
        $this->assertSame('ink', LayoutSettings::sanitize(['typography' => ['accent' => 'url(javascript:x)']])['typography']['accent']);

        foreach (LayoutSettings::ACCENTS as $hex) {
            $this->assertTrue(LayoutSettings::readableOnWhite($hex), "preset {$hex} must print legibly");
        }
        $this->assertFalse(LayoutSettings::readableOnWhite('red; }'));
    }

    public function test_saving_a_light_custom_colour_is_refused_with_a_clear_message(): void
    {
        $this->actingAs($this->merchant('basic'));
        $res = $this->api('PUT', '/api/settings/appearance', ['settings' => ['template_id' => 'shop', 'typography' => ['accent' => 'custom', 'accent_hex' => '#fafafa']], 'version' => 1]);
        $res->assertStatus(422)->assertJsonPath('code', 'ACCENT_TOO_LIGHT');

        $this->api('PUT', '/api/settings/appearance', ['settings' => ['template_id' => 'shop', 'typography' => ['accent' => 'custom', 'accent_hex' => '#0f5e46']], 'version' => 1])->assertOk();
        $preview = $this->api('POST', '/api/settings/appearance/preview', ['settings' => ['template_id' => 'shop', 'typography' => ['accent' => 'custom', 'accent_hex' => '#0f5e46']]])->assertOk();
        $this->assertStringContainsString('data-accent="#0f5e46"', $preview->json('html'));
        $this->assertStringContainsString('a-custom', $preview->json('html'));
    }

    public function test_editor_page_offers_colours_and_both_views(): void
    {
        $this->actingAs($this->merchant('basic'));
        $this->get('/settings/appearance')->assertOk()
            ->assertSee('name="accent" value="navy"', false)->assertSee('name="accent_hex"', false)
            ->assertSee('name="ap-view" value="preview"', false)->assertSee('form="ap-form"', false);
    }
}
