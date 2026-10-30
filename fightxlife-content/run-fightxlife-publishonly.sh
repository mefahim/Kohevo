#!/usr/bin/env bash
# FightxLife PUBLISH-ONLY fix run — runs ON THE VPS (local bash).
# Usage: bash run-fightxlife-publishonly.sh <fixed-publish-script-url>
# Re-runs ONLY the publish + archive step after the first attempt failed
# on a wrong verification (it required published == draft, but publish()
# mints a NEW revision id — header p9 draft 675 published as 692 and is
# already live). Pages already published are skipped; success =
# PUB-OK + PUB-SKIP == 13, then 8 archives. Log: /tmp/fightxlife-publish.log
# Do NOT re-run the full bundle: the new-page scripts refuse duplicate
# creates (guard files), so a full re-run would fail at NEW-COURSES.
set -u
PUBLISH_URL="${1:-}"
if [ -z "$PUBLISH_URL" ]; then
  echo "USAGE: bash $0 <fixed-publish-script-url>"
  exit 1
fi
LOG=/tmp/fightxlife-publish.log
: > "$LOG"
curl -sL "$PUBLISH_URL" -o /tmp/build-fr-publish.php || { echo "DOWNLOAD-FAILED"; exit 1; }
docker exec kohevo-client test -f /tmp/fr-page-common.php || { echo "FR-PAGE-COMMON-MISSING — re-run the full bundle first"; exit 1; }
docker cp /tmp/build-fr-publish.php kohevo-client:/tmp/build-fr-publish.php || { echo "DOCKER-CP-FAILED"; exit 1; }
docker exec kohevo-client php -l /tmp/build-fr-publish.php >> "$LOG" 2>&1 || { echo "LINT-FAILED"; exit 1; }
docker exec kohevo-client php /tmp/build-fr-publish.php 2>&1 | tee -a "$LOG"
PUBOK=$(grep -c '^PUB-OK' "$LOG")
PUBSKIP=$(grep -c '^PUB-SKIP' "$LOG")
echo "PUB-OK: $PUBOK, PUB-SKIP: $PUBSKIP (sum expected 13)" | tee -a "$LOG"
if [ "$((PUBOK + PUBSKIP))" != "13" ]; then
  echo "PUBLISH-COUNT-MISMATCH — check PUB-SKIP/PUB-FAIL lines in $LOG"
  exit 1
fi
echo "FIGHTXLIFE-PUBLISH-DONE" | tee -a "$LOG"
