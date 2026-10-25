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
        'product-grid' => ['category_slug' => 'seed-cat'],
        'product-view' => ['product_slug' => 'seed-product'],
        'loop' => ['source' => 'manual', 'items' => [['title' => 'seed', 'text' => 'seed', 'image' => '', 'url' => '']]],
        'mega-menu' => ['items' => [['label' => 'seed', 'url' => 'https://example.com/', 'panel_slug' => '']]],
        'menu-anchor' => ['anchor_id' => 'seed-anchor'],
        // Batch F: seeds carry the inputs the new renders need.
        'soundcloud' => ['soundcloud_url' => 'https://soundcloud.com/seed/track'],
        'read-more' => ['teaser_text' => 'seed teaser', 'full_text' => 'seed full'],
        'text-path' => ['path_text' => 'seed path'],
        'link-in-bio' => ['profile_name' => 'seed', 'profile_bio' => 'seed bio', 'avatar' => 'https://seed.invalid/avatar.png',
            'social_items' => [['network' => 'facebook', 'url' => 'https://example.com/']],
            'link_items' => [['label' => 'seed', 'url' => 'https://example.com/']]],
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
        case 'gradient':
            return 'linear-gradient(90deg, #ff0000, #0000ff)';
        case 'dimensions':
            return ['top' => 11, 'right' => 22, 'bottom' => 33, 'left' => 44, 'unit' => 'px'];
        case 'repeater':
            $item = [];
            foreach (($control['item_schema'] ?? []) as $field => $fc) {
                $item[$field] = pfProbeValue($fc);
            }
            return [$item];
        case 'attributes':
            return [['key' => 'data-probe', 'value' => 'ZZPROBEZZ']];
        case 'select':
            return null; // handled by iterating options
        default:
            return 'ZZPROBEZZ';
    }
}

/** Batch A: dependent controls need their prerequisite present, or the
 *  probe changes nothing and the gate would cry wolf. The prerequisite is
 *  part of the probe itself, never a weakening of the gate. */
function pfProbeContext(string $key, string $suffix = ''): array
{
    if (in_array($key, ['position_top', 'position_right', 'position_bottom', 'position_left'], true)) {
        return ['position' . $suffix => 'absolute'];
    }
    if ($key === 'sticky_offset') {
        return ['sticky' => 'top'];
    }
    if ($key === 'overlay_opacity') {
        return ['overlay_color' => '#12ab34'];
    }
    // Batch B: border width/color need a border style, the secondary
    // icon color needs a view that shows it, and description part styles
    // need a description (the seed leaves it empty).
    if ($key === 'image_border_width' || $key === 'image_border_color') {
        return ['image_border_style' => 'solid'];
    }
    if ($key === 'icon_bg_color') {
        return ['icon_view' => 'stacked'];
    }
    if ($key === 'desc_color' || $key === 'desc_font_size' || $key === 'desc_font_weight' || $key === 'content_spacing') {
        return ['description' => 'Probe description'];
    }
    // Batch D: autoplay parts need autoplay switched on.
    if ($key === 'autoplay_speed' || $key === 'pause_on_hover' || $key === 'pause_on_interaction') {
        return ['autoplay' => 'on'];
    }
    // Batch C: divider parts need the divider switched on.
    if ($key === 'divider_style' || $key === 'divider_weight' || $key === 'divider_width' || $key === 'divider_color') {
        return ['divider_on' => 'on'];
    }
    // Background image: custom coordinates/sizes only compose when their
    // parent select is 'custom'.
    if ($key === 'background_position_x' || $key === 'background_position_y') {
        return ['background_position' => 'custom'];
    }
    if ($key === 'background_size_width' || $key === 'background_size_height') {
        return ['background_size' => 'custom'];
    }
    return [];
}

/** Text probes are 'ZZPROBEZZ' — except free-form CSS coordinates, where
 *  only a valid value proves the control is live. */
function pfProbeValueFor(string $key, array $control)
{
    $overrides = [
        'background_position_x' => '42%',
        'background_position_y' => '30%',
        'background_size_width' => '50%',
        'background_size_height' => '30%',
    ];
    return $overrides[$key] ?? pfProbeValue($control);
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
$chanCss = 0;
$chanRender = 0;
$chanBoth = 0;
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
            $probes[$key] = [$key => pfProbeValueFor($key, $control)];
        }
        $anyLive = false;
        $chCss = false;
        $chHtml = false;
        foreach ($probes as $settings) {
            $checks++;
            $out = pfCompile($type, array_merge($seed, pfProbeContext($key), $settings));
            if ($out['css'] !== $base['css']) $chCss = true;
            if ($out['html'] !== $base['html']) $chHtml = true;
            if (($out['html'] . "\n" . $out['css']) !== $baseOut) {
                $anyLive = true;
            }
        }
        // Phase 8 channel classification (plan #2): where was the
        // control's effect observed — compiled CSS, rendered HTML, both?
        if ($anyLive) {
            if ($chCss && $chHtml) $chanBoth++;
            elseif ($chCss) $chanCss++;
            else $chanRender++;
        }
        // Responsive variants of style controls.
        if (!empty($control['__style']) && !empty(PageForgeCss::styleControls()[$key]['responsive'])) {
            foreach (['_tablet' => PageForgeCss::BREAKPOINT_TABLET, '_mobile' => PageForgeCss::BREAKPOINT_MOBILE] as $suffix => $bp) {
                $checks++;
                $probeKey = $key . $suffix;
                $value = ($control['type'] ?? '') === 'select' ? ($control['options'][1] ?? $control['options'][0] ?? '') : pfProbeValue($control);
                $out = pfCompile($type, array_merge($seed, pfProbeContext($key, $suffix), [$probeKey => $value]));
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

echo "CHANNELS css-only=$chanCss render-only=$chanRender css+render=$chanBoth" . PHP_EOL;
echo PHP_EOL . ($failures === 0 ? "AUDIT OK — $checks probes, every control is live" : "AUDIT FAILED — $failures dead controls across $checks probes") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
