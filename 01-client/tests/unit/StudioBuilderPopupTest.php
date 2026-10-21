<?php
/**
 * Unit tests for Kohevo Studio (studio-builder) — Popup Builder resolution.
 *
 * Autoloader only, no database: the resolver is exercised against in-memory
 * page/revision fakes. Pins PopupTrigger normalization and the condition
 * semantics popups inherit from theme templates (unconditional = everywhere,
 * conditions present but unmatched = nowhere, drafts never resolve, a page
 * is never its own popup).
 */

declare(strict_types=1);

if (!defined('SLATE_TESTING')) {
    define('SLATE_TESTING', true);
}
if (!defined('SLATE_ROOT')) {
    define('SLATE_ROOT', dirname(__DIR__, 2));
}
require_once SLATE_ROOT . '/src/autoload.php';
if (!function_exists('unit')) {
    require_once __DIR__ . '/harness.php';
}

use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Theme\PopupTrigger;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;

function sbpop_pages(array $rows): object
{
    return new class($rows) {
        public function __construct(private array $rows) {}
        public function allOfType(string $type, int $limit): array
        {
            return array_values(array_filter($this->rows, static fn(array $r): bool => ($r['page_type'] ?? '') === $type));
        }
        public function find(int $id): ?array
        {
            foreach ($this->rows as $r) {
                if ((int) $r['id'] === $id) {
                    return $r;
                }
            }
            return null;
        }
    };
}

function sbpop_revisions(): object
{
    return new class {
        public function findByIdForPage(int $pageId, int $revId): ?array
        {
            return ['id' => $revId, 'page_id' => $pageId, 'document_json' => '{}'];
        }
    };
}

function sbpop_row(int $id, array $settings, string $status = 'published'): array
{
    return [
        'id' => $id, 'slug' => 'template-pop-' . $id, 'page_type' => 'system', 'status' => $status,
        'published_revision_id' => 100 + $id, 'settings_json' => CanonicalJson::encode($settings),
    ];
}

function sbpop_target(): PageAddress
{
    return new PageAddress(id: 1, uuid: '11111111-1111-4111-8111-111111111111', title: 'Home', slug: 'home', pageType: 'page', status: 'published', routeMode: 'standalone', activeDraftRevisionId: null, publishedRevisionId: 5);
}

unit('popup trigger: defaults and clamps', function (): void {
    $d = PopupTrigger::normalize(null);
    assert_eq(['type' => 'load', 'delay_ms' => 2000, 'scroll_percent' => 50, 'frequency' => 'session', 'size' => 'md'], $d);
    $c = PopupTrigger::normalize(['type' => 'scroll', 'delay_ms' => 999999, 'scroll_percent' => 0, 'frequency' => 'bogus', 'size' => 'xl']);
    assert_eq('scroll', $c['type']);
    assert_eq(60000, $c['delay_ms'], 'delay clamped to 60s');
    assert_eq(1, $c['scroll_percent'], 'scroll clamped to >= 1');
    assert_eq('session', $c['frequency'], 'unknown frequency falls back');
    assert_eq('md', $c['size'], 'unknown size falls back');
});

unit('popup resolver: unconditional published popup matches, draft and non-popup do not', function (): void {
    $rows = [
        sbpop_row(10, ['template_type' => 'popup', 'trigger' => ['type' => 'delay', 'delay_ms' => 3000]]),
        sbpop_row(11, ['template_type' => 'popup'], 'draft'),
        sbpop_row(12, ['template_type' => 'single']),
        ['id' => 1, 'slug' => 'home', 'page_type' => 'page', 'status' => 'published', 'published_revision_id' => 5, 'settings_json' => CanonicalJson::encode([])],
    ];
    $resolver = new ThemeTemplateResolver(sbpop_pages($rows), sbpop_revisions());
    $out = $resolver->resolvePopups(sbpop_target());
    assert_eq(1, count($out), 'only the published popup resolves');
    assert_eq(10, (int) $out[0]['page']['id']);
    assert_eq('delay', $out[0]['trigger']['type']);
    assert_eq(3000, $out[0]['trigger']['delay_ms']);
});

unit('popup resolver: conditions gate matching and order by specificity', function (): void {
    $rows = [
        sbpop_row(20, ['template_type' => 'popup']),
        sbpop_row(21, ['template_type' => 'popup', 'conditions' => ['rules' => [['type' => 'include', 'condition' => 'slug', 'value' => 'pricing']]]]),
        sbpop_row(22, ['template_type' => 'popup', 'conditions' => ['rules' => [['type' => 'include', 'condition' => 'front_page'], ['type' => 'include', 'condition' => 'slug', 'value' => 'home']]]]),
    ];
    $resolver = new ThemeTemplateResolver(sbpop_pages($rows), sbpop_revisions());
    $ids = array_map(static fn(array $m): int => (int) $m['page']['id'], $resolver->resolvePopups(sbpop_target()));
    assert_eq([22, 20], $ids, 'slug-mismatched popup excluded; specific match outranks the global one');
});

unit('popup resolver: a popup page never resolves itself', function (): void {
    $rows = [sbpop_row(30, ['template_type' => 'popup'])];
    $self = new PageAddress(id: 30, uuid: '33333333-3333-4333-8333-333333333333', title: 'P', slug: 'template-pop-30', pageType: 'system', status: 'published', routeMode: 'standalone', activeDraftRevisionId: null, publishedRevisionId: 130);
    $resolver = new ThemeTemplateResolver(sbpop_pages($rows), sbpop_revisions());
    assert_eq([], $resolver->resolvePopups($self));
});
