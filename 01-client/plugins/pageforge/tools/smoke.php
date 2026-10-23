<?php
declare(strict_types=1);
/**
 * PageForge engine smoke test — CLI only, no platform boot, no database.
 *
 * Exercises the pure engine (normalize → sanitize → render → CSS compile)
 * on a sample document that mirrors the Phase 1 gate: a container holding
 * a heading, a text block and a button, with desktop + mobile styles.
 * Exits non-zero on the first failed expectation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once dirname(__DIR__) . '/lib/PageForgeRenderer.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $label . PHP_EOL;
    if (!$ok) $fail++;
};

$raw = [
    'elements' => [
        [
            'id' => 'hero', 'elType' => 'container',
            'settings' => ['flex_direction' => 'column', 'gap' => 12, 'padding' => ['top' => 24, 'right' => 16, 'bottom' => 24, 'left' => 16, 'unit' => 'px'], 'background_color' => '#F5F3EE', 'evil_key' => 'dropped'],
            'elements' => [
                ['id' => 'h1', 'elType' => 'widget', 'widgetType' => 'heading',
                 'settings' => ['text' => 'Build <b>bold</b> pages', 'html_tag' => 'h1', 'font_size' => 40, 'font_size_mobile' => 28, 'color' => '#11130F', 'text_align' => 'center']],
                ['id' => 'cta', 'elType' => 'widget', 'widgetType' => 'button',
                 'settings' => ['text' => 'Start', 'link_url' => 'https://example.com/start', 'background_color' => '#454299']],
                ['id' => 'bad', 'elType' => 'widget', 'widgetType' => 'nope-not-real', 'settings' => []],
                ['id' => 'xss', 'elType' => 'widget', 'widgetType' => 'heading',
                 'settings' => ['text' => '<script>alert(1)</script>', 'link_url' => 'javascript:alert(1)']],
            ],
        ],
    ],
];

$doc = PageForgeRenderer::normalizeDocument($raw);
$check(count($doc['elements']) === 1, 'one top-level container survives normalization');
$check(count($doc['elements'][0]['elements']) === 3, 'unknown widget dropped at save-time normalization');
$check(!isset($doc['elements'][0]['settings']['evil_key']), 'undeclared setting key dropped by sanitizer');

$compiled = PageForgeRenderer::compile($doc, 7);
$html = $compiled['html'];
$css = $compiled['css'];

$check(str_contains($html, 'pf-page-7'), 'page wrapper class present');
$check(str_contains($html, '<h1 class="pf-heading pf-el-h1">Build &lt;b&gt;bold&lt;/b&gt; pages</h1>'), 'heading text is escaped, tag honored');
$check(str_contains($html, 'href="https://example.com/start"'), 'button link rendered');
$check(!str_contains($html, '<script>'), 'script payload never reaches output');
$check(!str_contains($html, 'javascript:'), 'javascript: URL refused by sanitizer');

$check(str_contains($css, '.pf-page-7 .pf-el-hero { display: flex; }'), 'container is a flex parent');
$check(str_contains($css, 'background-color: #f5f3ee;'), 'container background compiled (lowercased hex)');
$check(str_contains($css, 'gap: 12px;'), 'container gap compiled with unit');
$check(str_contains($css, 'font-size: 40px;'), 'desktop font size compiled');
$check(str_contains($css, '@media (max-width: 767px)'), 'mobile media query emitted');
$check(str_contains($css, 'font-size: 28px;'), 'mobile font size compiled inside the responsive pass');

echo $fail === 0 ? PHP_EOL . 'SMOKE OK' . PHP_EOL : PHP_EOL . 'SMOKE FAILED (' . $fail . ')' . PHP_EOL;
exit($fail === 0 ? 0 : 1);
