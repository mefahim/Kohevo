#!/usr/bin/env bash
# FightxLife FULL content build — runs ON THE VPS (local bash, no SSH).
# Usage: bash run-fightxlife-bundle.sh <tarball-url>
# Downloads the bundle (images + all PHP build scripts), then runs, in
# order, stopping on the first failure:
#   1. prep: unzip images, docker cp (one file per call) + php -l all
#   2. media upload (62 images + white logo) -> MEDIA-DONE
#   3. header/footer partial drafts -> PARTIALS-DONE
#   4. 10 French page drafts (contact, home, about, class, detail-boxe,
#      2 new courses, actions, soutenir, presse, privacy) -> each DONE
#   5. publish 13 pages + archive 8 -> PUBLISH-DONE (requires 13 PUB-OK)
# Everything is DRAFT until step 5. Re-running is safe: media upload
# skips existing files, page scripts refuse duplicate creates via guard
# files, and publish skips already-published pages (but then the PUB-OK
# count check will fail — that is expected on a re-run, not an error in
# the first run). Full log: /tmp/fightxlife-build.log
set -u
BUNDLE_URL="${1:-}"
if [ -z "$BUNDLE_URL" ]; then
  echo "USAGE: bash $0 <tarball-url>"
  exit 1
fi
B=/tmp/fxl-bundle
LOG=/tmp/fightxlife-build.log
: > "$LOG"
run_phase() { # $1 = marker, rest = command
  local marker="$1"; shift
  local phase_out
  phase_out=$(mktemp)
  # NOTE: the marker is grepped ONLY in this phase's own output file.
  # An earlier version echoed the marker into the shared log header and
  # then grepped the whole log, so every phase "passed" trivially —
  # including the publish phase, which had actually failed. Never grep
  # a log that already contains the marker.
  echo "=== PHASE START ===" | tee -a "$LOG"
  "$@" > "$phase_out" 2>&1
  cat "$phase_out" >> "$LOG"
  if grep -q "$marker" "$phase_out"; then
    echo "PHASE-OK $marker" | tee -a "$LOG"
  else
    echo "PHASE-FAILED $marker — see $LOG, fix and re-run" | tee -a "$LOG"
    rm -f "$phase_out"
    exit 1
  fi
  rm -f "$phase_out"
}

rm -rf "$B" && mkdir -p "$B" && cd "$B" || exit 1
curl -sL "$BUNDLE_URL" -o bundle.tar.gz || { echo "DOWNLOAD-FAILED"; exit 1; }
tar xzf bundle.tar.gz || { echo "UNTAR-FAILED"; exit 1; }
ls -la | tee -a "$LOG"

# 1. prep: images into the container + scripts + lint
rm -rf /tmp/fxl-images-host && mkdir -p /tmp/fxl-images-host
unzip -q -o fxl-images.zip -d /tmp/fxl-images-host || { echo "UNZIP-FAILED"; exit 1; }
docker exec kohevo-client mkdir -p /tmp/fxl-images || { echo "DOCKER-FAILED"; exit 1; }
for f in /tmp/fxl-images-host/*; do docker cp "$f" kohevo-client:/tmp/fxl-images/ || { echo "DOCKER-CP-FAILED $f"; exit 1; }; done
docker cp fxl-logo-white.jpg kohevo-client:/tmp/fxl-logo-white.jpg || exit 1
docker cp fxl-footer-pic1.jpg kohevo-client:/tmp/fxl-footer-pic1.jpg || exit 1
docker cp fxl-footer-pic2.jpg kohevo-client:/tmp/fxl-footer-pic2.jpg || exit 1
for f in fr-page-common.php build-fr-media.php build-fr-partials.php build-fr-contact.php build-fr-home.php build-fr-about.php build-fr-class.php build-fr-detail-boxe.php build-fr-new-courses.php build-fr-actions.php build-fr-soutenir.php build-fr-presse.php build-fr-privacy.php build-fr-publish.php; do
  docker cp "$f" kohevo-client:/tmp/"$f" || { echo "DOCKER-CP-FAILED $f"; exit 1; }
  docker exec kohevo-client php -l /tmp/"$f" >> "$LOG" 2>&1 || { echo "LINT-FAILED $f"; exit 1; }
done
echo "PREP-DONE" | tee -a "$LOG"

# 2. media + 3. partials
run_phase "MEDIA-DONE" docker exec kohevo-client php /tmp/build-fr-media.php
run_phase "PARTIALS-DONE" docker exec kohevo-client php /tmp/build-fr-partials.php

# 4. pages (DRAFT ONLY)
run_phase "CONTACT-DONE" docker exec kohevo-client php /tmp/build-fr-contact.php
run_phase "HOME-DONE" docker exec kohevo-client php /tmp/build-fr-home.php
run_phase "ABOUT-DONE" docker exec kohevo-client php /tmp/build-fr-about.php
run_phase "CLASS-DONE" docker exec kohevo-client php /tmp/build-fr-class.php
run_phase "DETAIL-BOXE-DONE" docker exec kohevo-client php /tmp/build-fr-detail-boxe.php
run_phase "NEW-COURSES-DONE" docker exec kohevo-client php /tmp/build-fr-new-courses.php
run_phase "ACTIONS-DONE" docker exec kohevo-client php /tmp/build-fr-actions.php
run_phase "SOUTENIR-DONE" docker exec kohevo-client php /tmp/build-fr-soutenir.php
run_phase "PRESSE-DONE" docker exec kohevo-client php /tmp/build-fr-presse.php
run_phase "PRIVACY-DONE" docker exec kohevo-client php /tmp/build-fr-privacy.php

# 5. publish + archive
run_phase "PUBLISH-DONE" docker exec kohevo-client php /tmp/build-fr-publish.php
PUBOK=$(grep -c '^PUB-OK' "$LOG")
PUBSKIP=$(grep -c '^PUB-SKIP' "$LOG")
echo "PUB-OK: $PUBOK, PUB-SKIP: $PUBSKIP (sum expected 13)" | tee -a "$LOG"
if [ "$((PUBOK + PUBSKIP))" != "13" ]; then
  echo "PUBLISH-COUNT-MISMATCH — check PUB-SKIP lines in $LOG" | tee -a "$LOG"
  exit 1
fi
echo "FIGHTXLIFE-BUILD-DONE" | tee -a "$LOG"
