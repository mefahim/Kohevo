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

require_once dirname(__DIR__) . '/lib/PageForgeRenderer.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['error' => 'csrf_failed']);
    exit;
}

if (($_POST['action'] ?? '') !== 'preview') {
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
echo json_encode(PageForgeRenderer::compile($document, $pageId));
