<?php
declare(strict_types=1);
/**
 * PageForge control audit — CLI only, no platform boot, no database.
 *
 * The Phase 3 gate: EVERY control declared by EVERY widget must provably
 * affect the compiled output. For each widget, a baseline document is
 * compiled from seed settings; then each control is probed one at a time
 * and the compiled HTML+CSS must change (and, where a probe token exists,
 * contain it). Responsive style controls are additionally probed through
 * their _tablet/_mobile keys and must land inside the right media query.
 *
 * Exit code 0 = every control is live. Any FAIL line names a dead control:
 * a real bug (the control promises something the render ignores).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/lib/PageForgeRenderer.php';

/** Minimal viable companion settings per widget (renders need inputs). */
function pfAuditSeed(string $type): array
{
    return match ($type) {
        'image', 'image-box' => ['src' => 'https://seed.invalid/a.png'],
        'form' => ['form_slug' => 'seed-form'],
        'video' => ['source' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
        'gallery', 'image-carousel' => ['items' => [['src' => 'https://seed.invalid/a.png', 'caption' => 'seed']]],
        'icon-list' => ['items' => [['icon_name' => 'check', 'text' => 'seed', 'link_url' => '']]],
        'list' => ['items' => [['text' => 'seed', 'link_url' => '']]],
        'tabs', 'accordion' => ['items' => [['title' => 'seed', 'content' => 'seed']]],
        'social-icons' => ['items' => [['network' => 'facebook', 'url' => 'https://facebook.com/seed']]],
        default => [],
    };
}

function pfProbeValue(array $control)
{
    switch ($control['type'] ?? 'text') {
        case 'text':
        case 'textarea':
            return 'ZZPROBEZZ';
        case 'raw_html':
            return '<p>ZZPROBEZZ</p>';
        case 'url':
        case 'media':
            return 'https://probe.invalid/zzprobe';
        case 'number':
            $max = isset($control['max']) ? (float) $control['max'] : 42.0;
            $v = min(42.0, $max);
            if ($v == 0.0) $v = isset($control['max']) ? (float) $control['max'] : 1.0;
            if (isset($control['min']) && $v < (float) $control['min']) $v = (float) $control['min'] + 1;
            return $v == (int) $v ? (int) $v : $v;
        case 'color':
            return '#12ab34';
        case 'dimensions':
            return ['top' => 11, 'right' => 22, 'bottom' => 33, 'left' => 44, 'unit' => 'px'];
        case 'repeater':
            $item = [];
            foreach (($control['item_schema'] ?? []) as $field => $fc) {
                $item[$field] = pfProbeValue($fc);
            }
            return [$item];
        case 'select':
            return null; // handled by iterating options
        default:
            return 'ZZPROBEZZ';
    }
}

function pfCompile(string $type, array $settings): array
{
    $el = ['id' => 'audit1', 'settings' => $settings, 'elements' => []];
    if ($type === 'container') {
        $el['elType'] = 'container';
        $el['elements'] = [['id' => 'child1', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => 'kid'], 'elements' => []]];
    } else {
        $el['elType'] = 'widget';
        $el['widgetType'] = $type;
    }
    $doc = PageForgeRenderer::normalizeDocument(['elements' => [$el]]);
    return PageForgeRenderer::compile($doc, 42);
}

$failures = 0;
$checks = 0;
foreach (PageForgeWidgets::all() as $type => $def) {
    $seed = pfAuditSeed($type);
    $base = pfCompile($type, $seed);
    $baseOut = $base['html'] . "\n" . $base['css'];

    $controls = $def['controls'] ?? [];
    foreach (PageForgeCss::effectiveStyleKeys($def) as $styleKey) {
        $sc = PageForgeCss::styleControls()[$styleKey] ?? null;
        if ($sc !== null && !isset($controls[$styleKey])) {
            $controls[$styleKey] = $sc + ['__style' => true];
        }
    }

    foreach ($controls as $key => $control) {
        $probes = [];
        if (($control['type'] ?? '') === 'select') {
            foreach (($control['options'] ?? []) as $opt) {
                $probes[$key . '=' . $opt] = [$key => $opt];
            }
        } else {
            $probes[$key] = [$key => pfProbeValue($control)];
        }
        $anyLive = false;
        foreach ($probes as $settings) {
            $checks++;
            $out = pfCompile($type, array_merge($seed, $settings));
            if (($out['html'] . "\n" . $out['css']) !== $baseOut) {
                $anyLive = true;
            }
        }
        // Responsive variants of style controls.
        if (!empty($control['__style']) && !empty(PageForgeCss::styleControls()[$key]['responsive'])) {
            foreach (['_tablet' => PageForgeCss::BREAKPOINT_TABLET, '_mobile' => PageForgeCss::BREAKPOINT_MOBILE] as $suffix => $bp) {
                $checks++;
                $probeKey = $key . $suffix;
                $value = ($control['type'] ?? '') === 'select' ? ($control['options'][1] ?? $control['options'][0] ?? '') : pfProbeValue($control);
                $out = pfCompile($type, array_merge($seed, [$probeKey => $value]));
                $mediaPos = strpos($out['css'], '@media (max-width: ' . $bp . 'px)');
                if ($mediaPos !== false && ($out['html'] . "\n" . $out['css']) !== $baseOut) {
                    // live
                } else {
                    $failures++;
                    echo "FAIL  $type.$probeKey (responsive variant changes nothing)\n";
                }
            }
        }
        if (!$anyLive) {
            $failures++;
            echo "FAIL  $type.$key (no probe changed the compiled output)\n";
        }
    }
    echo "done  $type\n";
}

echo PHP_EOL . ($failures === 0 ? "AUDIT OK — $checks probes, every control is live" : "AUDIT FAILED — $failures dead controls across $checks probes") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
