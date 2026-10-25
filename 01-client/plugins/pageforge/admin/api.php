<?php
/**
 * PageForge — admin JSON API (Phase 2: live preview for the canvas editor).
 *
 * Single action in this phase: `preview` — compile a posted document with
 * the real engine and return {html, css}. Nothing is persisted here; saving
 * still goes through builder.php's form contract so there is exactly one
 * write path (PageForgeStore) and one sanitizer gate.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('pageforge.edit');
ModuleGuard::require('pageforge');

require_once dirname(__DIR__) . '/lib/PageForgeStore.php';
PageForgeStore::wireGlobalResolver();
PageForgeStore::wireKit();
PageForgeStore::wireDocumentResolver();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf_failed']);
    exit;
}

$pfAction = (string) ($_POST['action'] ?? '');

// Phase 9 — autosave: persist the working document as an 'autosave'
// revision through the one write path (the store sanitizes as always).
// Never publishes; the response carries the new revision id.
if ($pfAction === 'autosave') {
    $autoDoc = json_decode((string) ($_POST['document'] ?? ''), true);
    if (!is_array($autoDoc)) {
        http_response_code(422);
        echo json_encode(['error' => 'bad_document']);
        exit;
    }
    try {
        $revId = PageForgeStore::saveDocument(
            (int) ($_POST['page_id'] ?? 0),
            $autoDoc,
            (int) (Auth::user()['id'] ?? 0),
            'autosave'
        );
    } catch (RuntimeException $e) {
        http_response_code(404);
        echo json_encode(['error' => 'page_not_found']);
        exit;
    }
    echo json_encode(['ok' => true, 'revision_id' => $revId, 'saved_at' => gmdate('c')]);
    exit;
}

if ($pfAction !== 'preview') {
    http_response_code(400);
    echo json_encode(['error' => 'unknown_action']);
    exit;
}

$decoded = json_decode((string) ($_POST['document'] ?? ''), true);
if (!is_array($decoded)) {
    http_response_code(422);
    echo json_encode(['error' => 'bad_document']);
    exit;
}

$pageId = (int) ($_POST['page_id'] ?? 0);
$document = PageForgeRenderer::normalizeDocument($decoded);
// Phase 11: previews resolve dynamic tags against the edited page.
$pfMetaPage = $pageId > 0 ? PageForgeStore::getPage($pageId) : null;
if ($pfMetaPage !== null) {
    PageForgeRenderer::setPageMeta(['id' => $pageId, 'title' => (string) ($pfMetaPage['title'] ?? ''), 'slug' => (string) ($pfMetaPage['slug'] ?? ''), 'kind' => (string) ($pfMetaPage['kind'] ?? 'page')]);
}
try {
    echo json_encode(PageForgeRenderer::compile($document, $pageId));
} finally {
    PageForgeRenderer::setPageMeta(null);
}
