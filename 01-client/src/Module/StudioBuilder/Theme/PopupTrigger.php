<?php
/**
 * Kohevo Studio (studio-builder) — Popup trigger settings.
 *
 * The canonical shape stored in a popup template's backing-page settings
 * (`settings_json.trigger`) and mirrored into its document settings:
 *
 *   type:           load | delay | scroll | exit | click | none
 *   delay_ms:       wait before opening (delay type; also allowed after load)
 *   scroll_percent: page scroll depth that opens it (scroll type)
 *   frequency:      always | session | once  (client-side cap)
 *   size:           sm | md | lg | full      (the .sb-modal size variant)
 *
 * `click` needs no runtime work: any element with
 * `data-sb-modal-open="sb-popup-{key}"` opens the popup through the existing
 * overlay mechanism. `none` renders the popup but never auto-opens it.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Theme;

final class PopupTrigger
{
    public const TYPES       = ['load', 'delay', 'scroll', 'exit', 'click', 'none'];
    public const FREQUENCIES = ['always', 'session', 'once'];
    public const SIZES       = ['sm', 'md', 'lg', 'full'];

    /**
     * @return array{type: string, delay_ms: int, scroll_percent: int, frequency: string, size: string}
     */
    public static function normalize(mixed $raw): array
    {
        $raw    = is_array($raw) ? $raw : [];
        $type   = in_array($raw['type'] ?? null, self::TYPES, true) ? (string) $raw['type'] : 'load';
        $delay  = is_numeric($raw['delay_ms'] ?? null) ? max(0, min(60000, (int) $raw['delay_ms'])) : 2000;
        $scroll = is_numeric($raw['scroll_percent'] ?? null) ? max(1, min(100, (int) $raw['scroll_percent'])) : 50;
        $freq   = in_array($raw['frequency'] ?? null, self::FREQUENCIES, true) ? (string) $raw['frequency'] : 'session';
        $size   = in_array($raw['size'] ?? null, self::SIZES, true) ? (string) $raw['size'] : 'md';

        return [
            'type'           => $type,
            'delay_ms'       => $delay,
            'scroll_percent' => $scroll,
            'frequency'      => $freq,
            'size'           => $size,
        ];
    }
}
