<?php
/**
 * FightxLife CONTENT BUILD — shared helpers for the French page scripts.
 * Every page script requires this file, then loads its page doc, edits
 * blocks in place (text / links / images only — layout, classes and CSS
 * assets are never touched), validates, and saves a DRAFT.
 * Media ids/urls come from /tmp/fxl-media-manifest.json, written by the
 * media-upload step into the container. No publish happens here.
 */
declare(strict_types=1);

require '/var/www/html/config.php';
$GLOBALS['SLATE_TENANT_OVERRIDE'] = 1;

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Document\DocumentValidator;
use Slate\Module\StudioBuilder\Render\IslandAssets;
use Slate\Module\StudioBuilder\StudioPermissions;

$frRuntime = \Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory::build();
$frApp = $frRuntime->app;
$frActor = StudioActor::authenticated(1, [StudioPermissions::VIEW, StudioPermissions::EDIT, StudioPermissions::PUBLISH]);

function frFail(string $msg): void
{
    fwrite(STDERR, "FR-PAGE-FAIL: $msg\n");
    exit(1);
}

/** Manifest: orig filename => [id, url]; also slug => [id, url]. */
function frManifest(): array
{
    $raw = @file_get_contents('/tmp/fxl-media-manifest.json');
    if ($raw === false) {
        frFail('media manifest /tmp/fxl-media-manifest.json missing — run the media step first');
    }
    $rows = json_decode($raw, true);
    if (!is_array($rows)) {
        frFail('media manifest is not valid JSON');
    }
    $byOrig = [];
    $bySlug = [];
    foreach ($rows as $r) {
        $byOrig[$r['orig']] = [(int) $r['id'], $r['url']];
        $bySlug[$r['slug']] = [(int) $r['id'], $r['url']];
    }
    return [$byOrig, $bySlug];
}

/** Path-based finder over a block tree (never a reference alias). */
function frFindPath(array $blocks, callable $pred, array $prefix = []): ?array
{
    foreach ($blocks as $i => $b) {
        if ($pred($b)) {
            return array_merge($prefix, [$i]);
        }
        if (!empty($b['children']) && is_array($b['children'])) {
            $p = frFindPath($b['children'], $pred, array_merge($prefix, [$i, 'children']));
            if ($p !== null) {
                return $p;
            }
        }
    }
    return null;
}

function frDocPath(array $doc, callable $pred): ?array
{
    foreach ($doc['sections'] as $si => $section) {
        if (!empty($section['blocks'])) {
            $p = frFindPath($section['blocks'], $pred, ['sections', $si, 'blocks']);
            if ($p !== null) {
                return $p;
            }
        }
    }
    return null;
}

function &frNavRef(array &$doc, array $path): array
{
    $ref = &$doc;
    foreach ($path as $k) {
        $ref = &$ref[$k];
    }
    return $ref;
}

/** Apply $fn to every block in the tree (recursively, by reference). */
function frWalk(array &$blocks, callable $fn): void
{
    foreach ($blocks as &$b) {
        $fn($b);
        if (!empty($b['children']) && is_array($b['children'])) {
            frWalk($b['children'], $fn);
        }
    }
    unset($b);
}

function frWalkDoc(array &$doc, callable $fn): void
{
    foreach ($doc['sections'] as &$s) {
        if (!empty($s['blocks'])) {
            frWalk($s['blocks'], $fn);
        }
    }
    unset($s);
}

function frValidate(array $doc, $runtime, string $name): void
{
    $errs = DocumentValidator::validate($doc, $runtime->registry)->errors();
    echo strtoupper($name) . '-ERRORS: ' . count($errs) . "\n";
    foreach ($errs as $e) {
        echo '  ' . json_encode($e) . "\n";
    }
    if (count($errs) > 0) {
        frFail("$name validation failed");
    }
}

function frSaveDraft($app, $actor, int $pageId, array $doc): int
{
    $status = $app->pageStatus($actor, $pageId);
    $draft = $app->saveDraft($actor, $pageId, $doc, (int) $status['active_draft_revision_id'], 'manual');
    $rev = (int) ($draft['revision']['id'] ?? 0);
    echo 'DRAFT page=' . $pageId . ' rev=' . $rev . "\n";
    return $rev;
}

function frLoadDoc($app, $actor, int $pageId): array
{
    $loaded = $app->loadEditorDocument($actor, $pageId);
    if (empty($loaded['document'])) {
        frFail("no document for page $pageId");
    }
    return $loaded['document'];
}

/** Store an island asset (js/css) with web-readable perms; returns key. */
function frStoreAsset(string $content, string $ext): string
{
    $key = IslandAssets::store(1, $content, $ext);
    $p = '/var/www/html/plugins/studio-builder/assets/islands/' . $key . '.' . $ext;
    chmod($p, 0644);
    @chown($p, 'www-data');
    @chgrp($p, 'www-data');
    return $key;
}

/** Recursively drop blocks matching $pred from a block tree. */
function frDeleteBlocks(array &$blocks, callable $pred): int
{
    $n = 0;
    $kept = [];
    foreach ($blocks as $b) {
        if ($pred($b)) {
            $n++;
            continue;
        }
        if (!empty($b['children']) && is_array($b['children'])) {
            $n += frDeleteBlocks($b['children'], $pred);
        }
        $kept[] = $b;
    }
    $blocks = $kept;
    return $n;
}
