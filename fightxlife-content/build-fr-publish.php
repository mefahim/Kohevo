<?php
/**
 * FightxLife CONTENT BUILD — PUBLISH + ARCHIVE, run ONLY after every
 * page script printed its DONE marker (the runner gates on PAGES-DONE).
 * Publishes: header p9, footer p10, home p40, about p41, class p42,
 * detail-boxe p43, contact p47, plus the 6 new pages read from their
 * guard files (2 courses + actions + soutenir + presse + privacy).
 * Each publish is verified (is_published=true, no unpublished changes).
 * NOTE (proven 2026-10-28, first publish attempt): publish() mints a NEW
 * revision id for the published copy — published_revision_id is NEVER
 * equal to the draft rev (header p9 draft 675 published as 692). An
 * earlier version of this script wrongly required published == draft
 * and aborted at p9 even though p9 had published successfully. Verify
 * only is_published + no unpublished changes, and record both ids.
 * Pages with no unpublished changes are SKIPPED (PUB-SKIP) — the runner
 * requires PUB-OK + PUB-SKIP == 13, so a skipped page is fine only
 * when it is already published (as p9 was after the first attempt).
 * Archives (reversible, revisions intact): FAQ p44, Trainer p45,
 * Blog p46, articles p48–p52.
 * THIS IS THE ONLY SCRIPT THAT PUBLISHES. Nothing here runs unless the
 * operator runs it explicitly.
 */
declare(strict_types=1);

require '/tmp/fr-page-common.php';

$publishIds = [9, 10, 40, 41, 42, 43, 47];
$guardFiles = ['/tmp/fr-new-course-pages.json', '/tmp/fr-new-actions.json',
    '/tmp/fr-new-soutenir.json', '/tmp/fr-new-presse.json', '/tmp/fr-new-privacy.json'];
foreach ($guardFiles as $gf) {
    if (!file_exists($gf)) {
        frFail("publish guard file missing: $gf (page script did not run?)");
    }
    $data = json_decode(file_get_contents($gf), true);
    if ($data === null) {
        frFail("publish guard file unreadable: $gf");
    }
    $entries = isset($data['page_id']) ? [$data] : $data;
    foreach ($entries as $e) {
        $publishIds[] = (int) $e['page_id'];
    }
}
$publishIds = array_values(array_unique($publishIds));
echo 'PUBLISH-IDS ' . implode(',', $publishIds) . "\n";

$ok = 0;
foreach ($publishIds as $pid) {
    $st = $frApp->pageStatus($frActor, $pid);
    if (empty($st['has_unpublished_changes'])) {
        echo "PUB-SKIP page=$pid (no unpublished changes)\n";
        continue;
    }
    $draftRev = (int) $st['active_draft_revision_id'];
    $frApp->publish($frActor, $pid, $draftRev, 'FightxLife French content');
    $after = $frApp->pageStatus($frActor, $pid);
    // publish() mints a NEW revision id for the published copy — never
    // compare published_revision_id to the draft rev (see header note).
    if (empty($after['is_published']) || !empty($after['has_unpublished_changes'])) {
        frFail("publish verify failed page=$pid draft=$draftRev published=" . ($after['published_revision_id'] ?? '?') . ' unpublished=' . (!empty($after['has_unpublished_changes']) ? 'yes' : 'no'));
    }
    $ok++;
    echo "PUB-OK page=$pid draft=$draftRev published=" . ($after['published_revision_id'] ?? '?') . "\n";
}
echo "PUB-COUNT $ok\n";

$archiveIds = [44, 45, 46, 48, 49, 50, 51, 52];
foreach ($archiveIds as $pid) {
    $res = $frApp->archivePage($frActor, $pid);
    echo "ARCH-OK page=$pid\n";
}
echo "PUBLISH-DONE\n";
