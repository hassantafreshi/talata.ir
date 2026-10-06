<?php

namespace App\Domain\Invoices;

/**
 * Allowlisted invoice layout settings (docs/design/invoice-templates/invoice-layout.schema.json).
 * Anything not listed is dropped. Protected invariants: required blocks visible, name/amount columns,
 * gold columns re-added when needed, QR fixed at the physical upper-left (not configurable at all).
 */
final class LayoutSettings
{
    public const TEMPLATES = ['simple_readable', 'shop', 'ledger'];

    public const REQUIRED_BLOCKS = ['shop_name', 'contact_primary', 'address'];

    public const BLOCK_KINDS = ['shop_name', 'address', 'contact_primary', 'contact_mobile_extra', 'website', 'social', 'license_union', 'license_online'];

    public const COLUMNS = ['row_no', 'name', 'description', 'weight_g', 'purity', 'weight_750', 'unit_rate', 'wage', 'profit', 'vat', 'amount'];

    public static function preset(string $id): array
    {
        $id = in_array($id, self::TEMPLATES, true) ? $id : 'simple_readable';
        $json = json_decode(file_get_contents(resource_path("layout-presets/{$id}.json")), true);
        unset($json['$comment'], $json['version']);
        $json['blocks'] = array_values(array_filter($json['blocks'], fn ($b) => $b['kind'] !== 'social'));
        $json['blocks'][] = ['kind' => 'social', 'area' => 'footer', 'align' => 'center', 'visible' => $id === 'shop'];

        return $json;
    }

    /** Builds a valid settings array from untrusted input, starting from the chosen preset. */
    public static function sanitize(array $input): array
    {
        $out = self::preset((string) ($input['template_id'] ?? 'simple_readable'));

        $blocks = [];
        foreach ((array) ($input['blocks'] ?? []) as $block) {
            $kind = $block['kind'] ?? null;
            if (! in_array($kind, self::BLOCK_KINDS, true) || isset($blocks[$kind])) {
                continue;
            }
            $blocks[$kind] = [
                'kind' => $kind,
                'area' => in_array($block['area'] ?? '', ['header', 'footer'], true) ? $block['area'] : 'header',
                'align' => in_array($block['align'] ?? '', ['right', 'center', 'left'], true) ? $block['align'] : 'right',
                'visible' => in_array($kind, self::REQUIRED_BLOCKS, true) ? true : filter_var($block['visible'] ?? false, FILTER_VALIDATE_BOOL),
            ];
        }
        foreach ($out['blocks'] as $default) {
            $blocks[$default['kind']] ??= $default;
        }
        $out['blocks'] = array_values($blocks);

        if (isset($input['logo'])) {
            $out['logo'] = [
                'visible' => filter_var($input['logo']['visible'] ?? false, FILTER_VALIDATE_BOOL),
                'size' => in_array($input['logo']['size'] ?? '', ['small', 'medium', 'large'], true) ? $input['logo']['size'] : 'medium',
            ];
        }

        if (isset($input['items_table']['columns'])) {
            $cols = array_values(array_unique(array_intersect(array_map('strval', (array) $input['items_table']['columns']), self::COLUMNS)));
            foreach (['name', 'amount'] as $required) {
                if (! in_array($required, $cols, true)) {
                    $cols[] = $required;
                }
            }
            $out['items_table']['columns'] = array_values(array_intersect(self::COLUMNS, $cols));
        }

        if (isset($input['summary'])) {
            $out['summary']['show_component_breakdown'] = filter_var($input['summary']['show_component_breakdown'] ?? true, FILTER_VALIDATE_BOOL);
            $out['summary']['signature_box'] = filter_var($input['summary']['signature_box'] ?? true, FILTER_VALIDATE_BOOL);
            $text = trim(strip_tags((string) ($input['summary']['public_note']['text'] ?? '')));
            $out['summary']['public_note'] = [
                'visible' => filter_var($input['summary']['public_note']['visible'] ?? false, FILTER_VALIDATE_BOOL) && $text !== '',
                'text' => mb_substr($text, 0, 300),
            ];
        }

        if (isset($input['typography'])) {
            $t = $input['typography'];
            $out['typography']['text_size'] = in_array($t['text_size'] ?? '', ['normal', 'large'], true) ? $t['text_size'] : 'normal';
            $out['typography']['density'] = in_array($t['density'] ?? '', ['comfortable', 'compact'], true) ? $t['density'] : 'comfortable';
            $out['typography']['accent'] = in_array($t['accent'] ?? '', ['ink', 'gold_deep'], true) ? $t['accent'] : 'ink';
        }

        if (isset($input['print'])) {
            $out['print']['orientation'] = in_array($input['print']['orientation'] ?? '', ['portrait', 'landscape'], true) ? $input['print']['orientation'] : 'portrait';
            $out['print']['margins'] = in_array($input['print']['margins'] ?? '', ['narrow', 'normal'], true) ? $input['print']['margins'] : 'normal';
        }

        return $out;
    }

    /** Free plan: always the fixed simple preset regardless of stored settings. */
    public static function effective(array $stored, bool $canCustomize): array
    {
        return $canCustomize ? self::sanitize($stored) : self::preset('simple_readable');
    }

    /** @return list<string> columns to render for a given invoice */
    public static function columnsFor(array $settings, bool $hasGold, bool $hasGoldIn = false): array
    {
        $cols = $settings['items_table']['columns'];
        // Gold received from the customer is only readable with weight, purity, 750-equivalent weight and rate (Tahesab-style).
        $forced = $hasGoldIn ? ['weight_g', 'purity', 'weight_750', 'unit_rate'] : ($hasGold ? ['weight_g', 'purity'] : []);
        if ($forced) {
            foreach ($forced as $c) {
                if (! in_array($c, $cols, true)) {
                    $cols[] = $c;
                }
            }
        }

        return array_values(array_intersect(self::COLUMNS, $cols));
    }
}
