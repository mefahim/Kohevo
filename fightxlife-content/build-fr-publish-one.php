<?php
/**
 * FightxLife CONTENT BUILD — publish ONE page by id (CLI arg).
 * For targeted republishes (e.g. p41 about after its fix, p10 footer
 * after its fix) WITHOUT re-running the full publish script (which
 * would re-archive already-archived pages). Verifies is_published +
 * no unpublished changes; publish() mints a NEW revision id, so the
 * published id is reported, never compared to the draft id.
 * Usage: php /tmp/build-fr-publish-one.php <pageId>
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

$pid = (int) ($argv[1] ?? 0);
if ($pid <= 0) {
    frFail('usage: php build-fr-publish-one.php <pageId>');
}
$st = $frApp->pageStatus($frActor, $pid);
if (empty($st['has_unpublished_changes'])) {
    echo "PUB-ONE-SKIP page=$pid (no unpublished changes)\n";
    exit(0);
}
$draftRev = (int) $st['active_draft_revision_id'];
$frApp->publish($frActor, $pid, $draftRev, 'FightxLife targeted republish');
$after = $frApp->pageStatus($frActor, $pid);
if (empty($after['is_published']) || !empty($after['has_unpublished_changes'])) {
    frFail("publish-one verify failed page=$pid draft=$draftRev published=" . ($after['published_revision_id'] ?? '?'));
}
echo "PUB-ONE-OK page=$pid draft=$draftRev published=" . ($after['published_revision_id'] ?? '?') . "\n";
