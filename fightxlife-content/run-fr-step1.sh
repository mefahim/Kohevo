#!/usr/bin/env bash
# FightxLife content build STEP 1 via SSH (retry-on-reset).
# Failures observed so far are all handshake-time proxy resets: once ssh
# connects, the remote command runs to completion. So every phase is
# gated on a whole-line marker in \r-stripped output, and a missing
# marker means "nothing ran" -> safe to retry the whole phase.
# Phases: scp files -> prep (unzip + docker cp + lint) -> media upload
# (draft-free, inert rows) -> partials drafts (header + footer, DRAFT ONLY).
# No publish here. Full ssh output is saved to step1-out.txt in the
# content dir (the MEDIA-OK manifest lives there for the page scripts).
set -u
SSH_RUN="$HOME/workspace/ssh-helpers/ssh-run.exp"
SCP_EXP="$HOME/workspace/ssh-helpers/ssh-scp.exp"
CD="$HOME/workspace/fightxlife-content"
OUT=/tmp/fr-step1-out.txt
# Progress is snapshotted to $CD/fr-step1-out.txt after every phase, so a
# runtime restart can no longer wipe the tracking log (VM /tmp is lost).
: > "$OUT"
cp "$OUT" "$CD/fr-step1-out.txt" 2>/dev/null || true

try_ssh() { # $1 = remote command, $2 = whole-line marker pattern (grep -E)
  local i out
  for i in 1 2 3 4 5 6 7 8; do
    out=$(expect "$SSH_RUN" "$1" 2>&1 | tr -d '\r')
    if echo "$out" | grep -qxE "$2"; then
      echo "SSH-OK try=$i marker=$2"
      printf '%s\n' "$out" >> "$OUT"
      cp "$OUT" "$CD/fr-step1-out.txt" 2>/dev/null || true
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

scp_verify "$CD/fxl-images.zip" /tmp/ || exit 1
scp_verify "$CD/fxl-logo-white.jpg" /tmp/ || exit 1
scp_verify "$CD/fxl-footer-pic1.jpg" /tmp/ || exit 1
scp_verify "$CD/fxl-footer-pic2.jpg" /tmp/ || exit 1
scp_verify "$CD/build-fr-media.php" /tmp/ || exit 1
scp_verify "$CD/build-fr-partials.php" /tmp/ || exit 1

try_ssh "rm -rf /tmp/fxl-images-host && mkdir -p /tmp/fxl-images-host && unzip -q -o /tmp/fxl-images.zip -d /tmp/fxl-images-host && docker exec kohevo-client mkdir -p /tmp/fxl-images && for f in /tmp/fxl-images-host/*; do docker cp \"\$f\" kohevo-client:/tmp/fxl-images/ || exit 1; done && docker cp /tmp/fxl-logo-white.jpg kohevo-client:/tmp/fxl-logo-white.jpg && docker cp /tmp/fxl-footer-pic1.jpg kohevo-client:/tmp/fxl-footer-pic1.jpg && docker cp /tmp/fxl-footer-pic2.jpg kohevo-client:/tmp/fxl-footer-pic2.jpg && docker cp /tmp/build-fr-media.php kohevo-client:/tmp/build-fr-media.php && docker cp /tmp/build-fr-partials.php kohevo-client:/tmp/build-fr-partials.php && docker exec kohevo-client php -l /tmp/build-fr-media.php && docker exec kohevo-client php -l /tmp/build-fr-partials.php && echo PREP-DONE" "PREP-DONE" || { echo PREP-FAILED; exit 1; }

try_ssh "docker exec kohevo-client php /tmp/build-fr-media.php" "MEDIA-DONE.*" || { echo MEDIA-FAILED; exit 1; }
echo "MEDIA lines: $(grep -c '^MEDIA-OK' "$OUT")"

try_ssh "docker exec kohevo-client php /tmp/build-fr-partials.php" "PARTIALS-DONE" || { echo PARTIALS-FAILED; exit 1; }

cp "$OUT" "$CD/step1-out.txt"
echo "STEP1-DONE"
