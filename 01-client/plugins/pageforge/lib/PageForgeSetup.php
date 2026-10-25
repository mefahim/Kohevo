<?php
/**
 * PageForge setup — W1 onboarding state, starter content, and the
 * getting-started checklist (Phase 7).
 *
 * Working rule (SPEC wizard inventory): every wizard choice lands in
 * real storage the platform consumes — site identity writes THROUGH to
 * the core settings (`site_name`, `brand_sublabel`), the logo uses the
 * core branding key, the Kit step writes PageForgeKit overrides, and
 * the finish step creates real pages/parts through PageForgeStore.
 * Nothing here is decorative state.
 *
 * All settings access is defensive (method_exists + try/catch), like
 * PageForgeKit: CLI harnesses whose Database stub has no settings API
 * get safe no-ops and "not set" reads instead of fatals.
 */

declare(strict_types=1);

require_once __DIR__ . '/PageForgeStore.php';

final class PageForgeSetup
{
    public const KEY_DONE = 'pageforge_setup_done';
    public const KEY_PURPOSE = 'pageforge_site_purpose';
    public const KEY_CHECKLIST_DISMISSED = 'pageforge_checklist_dismissed';

    private static function setting(string $key): ?string
    {
        if (!class_exists('Database') || !method_exists('Database', 'setting')) {
            return null;
        }
        try {
            $v = Database::setting($key);
        } catch (Throwable) {
            return null;
        }
        return $v === null ? null : (string) $v;
    }

    private static function setSetting(string $key, string $value): void
    {
        if (!class_exists('Database') || !method_exists('Database', 'setSetting')) {
            return;
        }
        try {
            Database::setSetting($key, $value);
        } catch (Throwable) {
            // Storage unavailable (CLI harness): setup state is advisory,
            // the wizard flow itself must never fatal.
        }
    }

    // ---- wizard state -------------------------------------------------

    public static function isDone(): bool
    {
        return self::setting(self::KEY_DONE) === '1';
    }

    public static function markDone(): void
    {
        self::setSetting(self::KEY_DONE, '1');
    }

    public static function purpose(): string
    {
        $p = (string) (self::setting(self::KEY_PURPOSE) ?? '');
        return isset(self::purposes()[$p]) ? $p : 'business';
    }

    public static function setPurpose(string $purpose): void
    {
        if (isset(self::purposes()[$purpose])) {
            self::setSetting(self::KEY_PURPOSE, $purpose);
        }
    }

    public static function checklistDismissed(): bool
    {
        return self::setting(self::KEY_CHECKLIST_DISMISSED) === '1';
    }

    public static function dismissChecklist(): void
    {
        self::setSetting(self::KEY_CHECKLIST_DISMISSED, '1');
    }

    // ---- step data (pure) ----------------------------------------------

    /** W1 step 1: site purpose → which starter pages the finish step creates. */
    public static function purposes(): array
    {
        return [
            'business'  => ['label' => 'Business / company site', 'pages' => ['home', 'about', 'contact']],
            'portfolio' => ['label' => 'Portfolio', 'pages' => ['home', 'work', 'contact']],
            'shop'      => ['label' => 'Online shop', 'pages' => ['home', 'shop', 'contact']],
            'blog'      => ['label' => 'Blog / magazine', 'pages' => ['home', 'blog', 'about']],
            'agency'    => ['label' => 'Agency', 'pages' => ['home', 'services', 'contact']],
        ];
    }

    /** W1 step 5: color palette presets (real hex sets, validated like kit colors). */
    public static function palettes(): array
    {
        return [
            'site'   => ['label' => 'Keep site branding (recommended)', 'colors' => []],
            'ocean'  => ['label' => 'Ocean', 'colors' => ['primary' => '#2563eb', 'secondary' => '#0ea5e9', 'accent' => '#2563eb', 'link' => '#2563eb']],
            'forest' => ['label' => 'Forest', 'colors' => ['primary' => '#15803d', 'secondary' => '#65a30d', 'accent' => '#15803d', 'link' => '#15803d']],
            'sunset' => ['label' => 'Sunset', 'colors' => ['primary' => '#ea580c', 'secondary' => '#f59e0b', 'accent' => '#ea580c', 'link' => '#ea580c']],
            'violet' => ['label' => 'Violet', 'colors' => ['primary' => '#7c3aed', 'secondary' => '#a855f7', 'accent' => '#7c3aed', 'link' => '#7c3aed']],
        ];
    }

    /** W1 step 5: font pairings, restricted to the core brand_font_family allow-list. */
    public static function fontPairs(): array
    {
        return [
            'site'    => ['label' => 'Keep site font', 'fonts' => []],
            'modern'  => ['label' => 'Modern — Inter / Inter', 'fonts' => ['heading' => 'inter', 'body' => 'inter']],
            'classic' => ['label' => 'Classic — Playfair / Roboto', 'fonts' => ['heading' => 'playfair', 'body' => 'roboto']],
            'bold'    => ['label' => 'Bold — Outfit / Plus Jakarta', 'fonts' => ['heading' => 'outfit', 'body' => 'plus-jakarta']],
        ];
    }

    /**
     * Starter page documents for a purpose (pure). Each document is a
     * real element tree the renderer consumes — the finish step saves
     * them through PageForgeStore unchanged.
     *
     * @return array<int, array{title: string, slug: string, document: array}>
     */
    public static function starterDocuments(string $purpose, string $siteName): array
    {
        $purposes = self::purposes();
        if (!isset($purposes[$purpose])) {
            $purpose = 'business';
        }
        $name = $siteName !== '' ? $siteName : 'Welcome';
        $hero = static fn(string $title, string $sub): array => ['elements' => [[
            'id' => 'hero', 'elType' => 'container',
            'settings' => ['flex_direction' => 'column', 'gap' => 16],
            'elements' => [
                ['id' => 'h', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => ['text' => $title, 'html_tag' => 'h1']],
                ['id' => 't', 'elType' => 'widget', 'widgetType' => 'text', 'settings' => ['text' => $sub]],
            ],
        ]]];
        $docs = [];
        foreach ($purposes[$purpose]['pages'] as $slug) {
            switch ($slug) {
                case 'home':
                    $docs[] = ['title' => 'Home', 'slug' => 'home', 'document' => $hero($name, 'This page was created by the PageForge setup wizard. Open it in the builder to make it yours.')];
                    break;
                case 'shop':
                    $docs[] = ['title' => 'Shop', 'slug' => 'shop', 'document' => ['elements' => [[
                        'id' => 'grid', 'elType' => 'widget', 'widgetType' => 'product-grid',
                        'settings' => ['limit' => 6, 'columns' => '3'],
                    ]]]];
                    break;
                case 'contact':
                    $docs[] = ['title' => 'Contact', 'slug' => 'contact', 'document' => $hero('Contact', 'Tell visitors how to reach you — replace this text in the builder.')];
                    break;
                case 'about':
                    $docs[] = ['title' => 'About', 'slug' => 'about', 'document' => $hero('About ' . $name, 'Tell your story here — replace this text in the builder.')];
                    break;
                case 'work':
                    $docs[] = ['title' => 'Work', 'slug' => 'work', 'document' => $hero('Work', 'Showcase your best projects here.')];
                    break;
                case 'services':
                    $docs[] = ['title' => 'Services', 'slug' => 'services', 'document' => $hero('Services', 'List what you offer here.')];
                    break;
                case 'blog':
                    $docs[] = ['title' => 'Blog', 'slug' => 'blog', 'document' => $hero('Blog', 'Your latest writing will live here.')];
                    break;
            }
        }
        return $docs;
    }

    // ---- finish-step orchestration (real store) -------------------------

    /**
     * Create the starter pages for a purpose (drafts), publishing Home.
     * Idempotent: slugs that already exist are skipped, never duplicated.
     *
     * @return array{created: int, home_id: ?int}
     */
    public static function createStarters(string $purpose, string $siteName, int $userId): array
    {
        $created = 0;
        $homeId = null;
        foreach (self::starterDocuments($purpose, $siteName) as $doc) {
            $existing = PageForgeStore::getPageBySlug($doc['slug']);
            if ($existing !== null) {
                if ($doc['slug'] === 'home') {
                    $homeId = (int) $existing['id'];
                }
                continue;
            }
            $id = PageForgeStore::createPage($doc['title'], $doc['slug'], $userId, 'page');
            PageForgeStore::saveDocument($id, $doc['document'], $userId);
            $created++;
            if ($doc['slug'] === 'home') {
                $homeId = $id;
                PageForgeStore::publish($id, $userId);
            }
        }
        if ($homeId === null) {
            $home = PageForgeStore::getPageBySlug('home');
            $homeId = $home !== null ? (int) $home['id'] : null;
        }
        return ['created' => $created, 'home_id' => $homeId];
    }

    /**
     * Create (and publish) a starter header part with the site name.
     * Idempotent: returns null when a header part already exists.
     */
    public static function createHeaderPart(string $siteName, int $userId): ?int
    {
        foreach (PageForgeStore::listPages('part') as $p) {
            if (($p['part'] ?? '') === 'header') {
                return null;
            }
        }
        $id = PageForgeStore::createPage('Header', 'site-header', $userId, 'part', 'header');
        PageForgeStore::saveDocument($id, ['elements' => [[
            'id' => 'bar', 'elType' => 'container',
            'settings' => ['flex_direction' => 'row', 'justify_content' => 'space-between', 'align_items' => 'center'],
            'elements' => [
                ['id' => 'brand', 'elType' => 'widget', 'widgetType' => 'heading',
                 'settings' => ['text' => $siteName !== '' ? $siteName : 'Home', 'html_tag' => 'h3']],
                ['id' => 'cta', 'elType' => 'widget', 'widgetType' => 'button',
                 'settings' => ['text' => 'Home', 'link_url' => '/forge/home']],
            ],
        ]]], $userId);
        PageForgeStore::publish($id, $userId);
        return $id;
    }

    // ---- site identity (write-through to core settings) ------------------

    /** Core identity as the wizard shows it: site name + brand sublabel (tagline). */
    public static function siteIdentity(): array
    {
        return [
            'site_name' => (string) (self::setting('site_name') ?? ''),
            'tagline'   => (string) (self::setting('brand_sublabel') ?? ''),
        ];
    }

    public static function setSiteIdentity(string $name, string $tagline): void
    {
        $name = mb_substr(trim($name), 0, 120);
        if ($name !== '') {
            self::setSetting('site_name', $name);
        }
        self::setSetting('brand_sublabel', mb_substr(trim($tagline), 0, 120));
    }

    /** Apply a logo path under the core's own rule (media-library managed paths only). */
    public static function setLogoPath(string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            self::setSetting('brand_logo_path', '');
            return true;
        }
        if (class_exists('MediaLibrary') && method_exists('MediaLibrary', 'isManagedPath') && MediaLibrary::isManagedPath($path)) {
            self::setSetting('brand_logo_path', $path);
            return true;
        }
        return false;
    }

    /** W1 theme step: write-through to the core branding settings the kit syncs from. */
    public static function setBranding(string $buttonTextMode, string $borderRadius): void
    {
        if (in_array($buttonTextMode, ['auto', 'light', 'dark'], true)) {
            self::setSetting('brand_button_text_mode', $buttonTextMode);
        }
        if (isset(PageForgeKit::radiusMap()[$borderRadius])) {
            self::setSetting('brand_border_radius', $borderRadius);
        }
    }

    // ---- checklist -------------------------------------------------------

    /**
     * The getting-started checklist (the video's editor Checklist,
     * computed from real data — an item is done only when the thing it
     * names actually exists in storage).
     *
     * @return array<int, array{key: string, label: string, done: bool, href: string}>
     */
    public static function checklist(): array
    {
        $site = PageForgeKit::siteSync();
        $ov = PageForgeKit::storedOverrides();
        $colorsDone = self::setting('brand_accent_color') !== null && self::setting('brand_accent_color') !== ''
            || !empty($ov['colors']);
        $fontsDone = self::setting('brand_font_family') !== null && self::setting('brand_font_family') !== ''
            || !empty($ov['fonts']);
        $headerDone = false;
        foreach (PageForgeStore::listPages('part') as $p) {
            if (($p['part'] ?? '') === 'header') {
                $headerDone = true;
                break;
            }
        }
        $home = PageForgeStore::getPageBySlug('home');
        $homeDone = $home !== null && ($home['status'] ?? '') === 'published';
        return [
            ['key' => 'logo', 'label' => 'Add your logo', 'done' => $site['logo_path'] !== '', 'href' => 'setup.php?step=3'],
            ['key' => 'colors', 'label' => 'Set up global colors', 'done' => $colorsDone, 'href' => 'site-settings.php'],
            ['key' => 'fonts', 'label' => 'Set up global fonts', 'done' => $fontsDone, 'href' => 'site-settings.php'],
            ['key' => 'header', 'label' => 'Set up a header', 'done' => $headerDone, 'href' => 'index.php'],
            ['key' => 'homepage', 'label' => 'Publish a homepage', 'done' => $homeDone, 'href' => 'index.php'],
        ];
    }

    public static function checklistAllDone(): bool
    {
        foreach (self::checklist() as $item) {
            if (!$item['done']) {
                return false;
            }
        }
        return true;
    }
}
