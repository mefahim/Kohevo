#!/usr/bin/env bash
# FightxLife content build STEP 2 (pages) via SSH (retry-on-reset).
# Runs AFTER step 1 (media + partials): the first phase verifies the
# media manifest exists in the container, and fails loudly otherwise.
# Phases: scp 11 files -> docker cp + php -l each -> run the 10 page
# scripts in order, each gated on its DONE marker (all DRAFT ONLY, no
# publish here) -> PAGES-DONE. Full output is saved to fr-pages-out.txt
# in the content dir (workspace-persistent, survives restarts).
# NOTE: fr-page-common.php is required by every page script, so it is
# copied into the container alongside them.
set -u
SSH_RUN="$HOME/workspace/ssh-helpers/ssh-run.exp"
SCP_EXP="$HOME/workspace/ssh-helpers/ssh-scp.exp"
CD="$HOME/workspace/fightxlife-content"
OUT=/tmp/fr-pages-out.txt
: > "$OUT"
cp "$OUT" "$CD/fr-pages-out.txt" 2>/dev/null || true

try_ssh() { # $1 = remote command, $2 = whole-line marker pattern (grep -E)
  local i out
  for i in 1 2 3 4 5 6 7 8; do
    out=$(expect "$SSH_RUN" "$1" 2>&1 | tr -d '\r')
    if echo "$out" | grep -qxE "$2"; then
      echo "SSH-OK try=$i marker=$2"
      printf '%s\n' "$out" >> "$OUT"
      cp "$OUT" "$CD/fr-pages-out.txt" 2>/dev/null || true
      return 0
    fi
    sleep 10
  done
  echo "SSH-FAILED marker=$2"
  echo "$out" | tail -3
  return 1
}

scp_verify() { # $1 = local file, $2 = remote path
  local i base
  base=$(basename "$1")
  for i in 1 2 3 4 5 6 7 8; do
    expect "$SCP_EXP" "$1" "$2" >/dev/null 2>&1
    if try_ssh "test -f $2$base && echo FILE-OK-$base" "FILE-OK-$base"; then return 0; fi
  done
  echo "SCP-FAILED $1"
  return 1
}

for f in fr-page-common.php build-fr-contact.php build-fr-home.php build-fr-about.php build-fr-class.php build-fr-detail-boxe.php build-fr-new-courses.php build-fr-actions.php build-fr-soutenir.php build-fr-presse.php build-fr-privacy.php; do
  scp_verify "$CD/$f" /tmp/ || exit 1
done

# Step-1 precondition: media manifest must exist in the container.
try_ssh "docker exec kohevo-client test -f /tmp/fxl-media-manifest.json && echo MANIFEST-OK" "MANIFEST-OK" || { echo STEP1-NOT-DONE; exit 1; }

# docker cp (one file per call) + lint each script.
CP_CMD=""
for f in fr-page-common.php build-fr-contact.php build-fr-home.php build-fr-about.php build-fr-class.php build-fr-detail-boxe.php build-fr-new-courses.php build-fr-actions.php build-fr-soutenir.php build-fr-presse.php build-fr-privacy.php; do
  CP_CMD="$CP_CMD docker cp /tmp/$f kohevo-client:/tmp/$f &&"
done
LINT_CMD=""
for f in build-fr-contact.php build-fr-home.php build-fr-about.php build-fr-class.php build-fr-detail-boxe.php build-fr-new-courses.php build-fr-actions.php build-fr-soutenir.php build-fr-presse.php build-fr-privacy.php; do
  LINT_CMD="$LINT_CMD docker exec kohevo-client php -l /tmp/$f &&"
done
try_ssh "${CP_CMD} ${LINT_CMD} echo PREP-PAGES-DONE" "PREP-PAGES-DONE" || { echo PREP-PAGES-FAILED; exit 1; }

# Page scripts in order, each gated on its DONE marker.
try_ssh "docker exec kohevo-client php /tmp/build-fr-contact.php" "CONTACT-DONE" || { echo CONTACT-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-home.php" "HOME-DONE" || { echo HOME-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-about.php" "ABOUT-DONE" || { echo ABOUT-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-class.php" "CLASS-DONE" || { echo CLASS-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-detail-boxe.php" "DETAIL-BOXE-DONE" || { echo DETAIL-BOXE-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-new-courses.php" "NEW-COURSES-DONE" || { echo NEW-COURSES-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-actions.php" "ACTIONS-DONE" || { echo ACTIONS-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-soutenir.php" "SOUTENIR-DONE" || { echo SOUTENIR-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-presse.php" "PRESSE-DONE" || { echo PRESSE-FAILED; exit 1; }
try_ssh "docker exec kohevo-client php /tmp/build-fr-privacy.php" "PRIVACY-DONE" || { echo PRIVACY-FAILED; exit 1; }

cp "$OUT" "$CD/fr-pages-out.txt"
echo "PAGES-DONE"
