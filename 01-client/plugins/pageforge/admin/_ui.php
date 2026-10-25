<?php
/**
 * PageForge — shared admin UI layer (organization Tier 1).
 *
 * Studio's model (admin/_nav.php / admin/_ui.php): one partial every
 * admin page includes, so the whole plugin reads as one product.
 * Provides:
 *  - pf_admin_head(): the PageForge page head (mark + title) and the
 *    sub-navigation (Pages / Site Settings / Setup), plus the
 *    admin.css link. The platform header/footer partials stay in
 *    charge of the outer shell — this layer lives inside it.
 *  - pf_flash(): the one flash renderer (replaces per-page copies).
 *  - pf_steps(): the setup wizard's step indicator.
 *
 * Presentation only: no handlers, no state, no permissions here.
 */

declare(strict_types=1);

if (!function_exists('pf_admin_head')) {
    /** The admin.css link tag (filemtime-versioned, like the editor assets). */
    function pf_admin_css(): string
    {
        $ver = @filemtime(dirname(__DIR__) . '/assets/admin.css') ?: 1;
        return '<link rel="stylesheet" href="'
            . htmlspecialchars(plugin_url('pageforge', 'assets/admin.css'), ENT_QUOTES, 'UTF-8')
            . '?v=' . $ver . '">';
    }

    /**
     * Page head + sub-nav. $active is one of: pages, settings, setup,
     * builder. $ctaHref/$ctaLabel optionally add the right-aligned
     * dashed action (e.g. "Open builder" when a page id is known).
     */
    function pf_admin_head(string $active, string $title, string $sub = '', string $ctaHref = '', string $ctaLabel = ''): void
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $items = [
            'pages'    => ['index.php', 'Pages'],
            'settings' => ['site-settings.php', 'Site Settings'],
            'setup'    => ['setup.php', 'Setup'],
        ];
        echo pf_admin_css() . "\n";
        echo '<div class="pf-head"><span class="pf-mark">P</span><div><h1>' . $e($title) . '</h1>';
        if ($sub !== '') {
            echo '<p>' . $e($sub) . '</p>';
        }
        echo '</div></div>' . "\n";
        echo '<nav class="pf-subnav">';
        foreach ($items as $key => [$href, $label]) {
            echo '<a href="' . $e($href) . '"' . ($key === $active ? ' class="on"' : '') . '>' . $e($label) . '</a>';
        }
        if ($ctaHref !== '') {
            echo '<span class="pf-sp"></span><a class="pf-cta" href="' . $e($ctaHref) . '">' . $e($ctaLabel) . '</a>';
        }
        echo '</nav>' . "\n";
    }

    /** The shared flash renderer. $flash = ['type' => ..., 'msg' => ...] or null. */
    function pf_flash(?array $flash): void
    {
        if (!$flash) {
            return;
        }
        $type = (string) ($flash['type'] ?? 'info');
        echo '<div class="pf-flash pf-flash--' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string) ($flash['msg'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div>' . "\n";
    }

    /** Wizard step indicator: bubbles 1..$total, done = check, current = filled. */
    function pf_steps(int $current, int $total): void
    {
        echo '<div class="pf-steps">';
        for ($n = 1; $n <= $total; $n++) {
            $cls = $n < $current ? 'done' : ($n === $current ? 'on' : '');
            echo '<span class="pf-step ' . $cls . '"><span class="pf-step-n">'
                . ($n < $current ? '✓' : (string) $n) . '</span>'
                . ($n < $total ? '<span class="pf-step-bar"></span>' : '')
                . '</span>';
        }
        echo '</div>' . "\n";
    }
}
