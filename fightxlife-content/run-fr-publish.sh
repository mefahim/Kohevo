#!/usr/bin/env bash
# FightxLife content build STEP 3 (publish + archive) via SSH.
# Runs ONLY after PAGES-DONE (all 10 page scripts succeeded). Publishes
# 13 pages (7 existing + 6 new) and archives 8 (FAQ/Trainer/Blog/5
# articles). Gated on PUBLISH-DONE plus a PUB-OK count of 13 — a lower
# count means a page had no draft to publish and the run must be
# investigated before any live-verify or report. Full output is saved
# to fr-publish-out.txt in the content dir (workspace-persistent).
# THIS IS THE ONLY RUNNER THAT PUBLISHES. It runs only when the
# operator runs it explicitly.
set -u
SSH_RUN="$HOME/workspace/ssh-helpers/ssh-run.exp"
SCP_EXP="$HOME/workspace/ssh-helpers/ssh-scp.exp"
CD="$HOME/workspace/fightxlife-content"
OUT=/tmp/fr-publish-out.txt
: > "$OUT"
cp "$OUT" "$CD/fr-publish-out.txt" 2>/dev/null || true

try_ssh() { # $1 = remote command, $2 = whole-line marker pattern (grep -E)
  local i out
  for i in 1 2 3 4 5 6 7 8; do
    out=$(expect "$SSH_RUN" "$1" 2>&1 | tr -d '\r')
    if echo "$out" | grep -qxE "$2"; then
      echo "SSH-OK try=$i marker=$2"
      printf '%s\n' "$out" >> "$OUT"
      cp "$OUT" "$CD/fr-publish-out.txt" 2>/dev/null || true
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

scp_verify "$CD/fr-page-common.php" /tmp/ || exit 1
scp_verify "$CD/build-fr-publish.php" /tmp/ || exit 1

try_ssh "docker cp /tmp/fr-page-common.php kohevo-client:/tmp/fr-page-common.php && docker cp /tmp/build-fr-publish.php kohevo-client:/tmp/build-fr-publish.php && docker exec kohevo-client php -l /tmp/build-fr-publish.php && echo PREP-PUBLISH-DONE" "PREP-PUBLISH-DONE" || { echo PREP-PUBLISH-FAILED; exit 1; }

try_ssh "docker exec kohevo-client php /tmp/build-fr-publish.php" "PUBLISH-DONE" || { echo PUBLISH-FAILED; exit 1; }

PUBOK=$(grep -c '^PUB-OK' "$OUT")
PUBSKIP=$(grep -c '^PUB-SKIP' "$OUT")
echo "PUB-OK: $PUBOK, PUB-SKIP: $PUBSKIP (sum expected 13)"
if [ "$((PUBOK + PUBSKIP))" != "13" ]; then
  echo "PUBLISH-COUNT-MISMATCH — investigate PUB-SKIP lines before live-verify"
  exit 1
fi

cp "$OUT" "$CD/fr-publish-out.txt"
echo "PUBLISH-RUN-DONE"
