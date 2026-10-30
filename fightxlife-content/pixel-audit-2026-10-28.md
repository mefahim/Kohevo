# FightxLife pixel-perfect audit — 2026-10-28 (in progress)

Reference: Yogist static clone (`~/workspace/yogist-review/styles.css` + GitHub Pages).
Live: client.fhf.bro.bd Studio pages (HTML + island CSS assets, fetched 2026-10-28).

## PROVEN (live evidence)

### FIX 1 — chrome full-bleed (script ready, awaiting terminal run)
- Platform inline CSS: `.sb-site-header,.sb-site-footer{padding:1rem}` +
  header `border-bottom:1px`, footer `border-top:1px`.
- Effect: black header bar + pink footer + dark bottom bar all sit 16px in
  from screen edges. Clone chrome is full-bleed.
- Fix: shell CSS override
  `.sb-site-header.sb-site-header,.sb-site-footer.sb-site-footer{padding:0!important;border-top:none!important;border-bottom:none!important}`
  (script `fix-footer-padding.php` v2, header+footer; v1 footer-only superseded
  before any run). Publish header p9 after.
- NOT a bug: page `.sb-section__inner` padding is already overridden to 0
  (live home CSS); `.container` gutters (32px desktop / 22px mobile) match
  the clone (`min(1170px, calc(100% - 64px))`).

### FINDING 2 — fonts (batch 2, not yet fixed)
- Live platform vars: `--sb-font-body:"Inter"`, `--sb-font-heading:"DM Sans","Inter"`.
- Clone: body `400 15px/1.65 Karla, color #a1a1a1`; headings Syne 700, lh 1.17,
  color ink; fonts loaded via local @font-face (Karla/Syne 400+700).
- Live page CSS declares Syne only on `.button-dark a` — all other headings
  render DM Sans, all body text Inter, platform lh 1.6/1.2.
- Fix plan (batch 2): prepend Google-Fonts @import (Syne 400/700 + Karla
  400/700) to shell CSS + override `--sb-font-body/--sb-font-heading` on
  `.sb-body` + body lh 1.65. Body color override HELD pending a check of
  live generic-text colors (page CSS may already set them per block).

### MATCHED (no action)
- Buttons desktop: live `.button a{60px, min-width 169px, pad 0 30px,
  radius 999px, 16px}` == clone `.button` exactly.
- Footer CSS values (padding 85/75, card 31px, pictures -31px, bottom bar)
  == clone (pink-mapped colors only).
- Hero h1: live `clamp(36px,4.15vw,50px)/1.15` vs clone banner
  `clamp(34px,4vw,50px)/1.16` — close, keep as audit item (hero ≠ banner).
- Class grid/cards: live == clone exactly (3col/28px, card-body
  `-35px 13px 0` + `24px 22px 23px` + radius 13 + shadow, mobile
  margin-inline/padding variants).
- Inner banner height/type: live `.banner-inner{min-height:304px;
  translateY(-17px)}` + h1 `900px/clamp(34px,4vw,50px)/1.16/-.025em` ==
  clone screenshot corrections exactly; breadcrumbs == clone
  (gap 11, mt 15, #999, links pink, #c4c4c4).

### FINDING 4 — inner banner background (batch 3, APPLIED in batch 1)
- Live banner section bg = `sb-bg--surface-primary` (white); NO `#f6f6f6`
  anywhere in the class CSS and no scoped section bg rule.
- Clone: `.page-banner{background:#f6f6f6}`.
- Fix: `.sb-section:has(.banner-inner){background:#f6f6f6}` (shell CSS).

### DETAIL + CONTACT AUDIT (2026-10-28, batch 2 applied live)
- MATCHED: detail video 410px/28/14, h2/h3 (correction values 36px,
  10/0/34, mobile 29px), detail layout 1.73fr/1fr gap 50 + pt 100/pb 92,
  class-card-body, contact grid 284/220 gap 30 max 535 (correction),
  contact card container (padding 0/transparent), map iframe 440px +
  mobile 300px + slot pb 92, map embed URL (siège social, French title),
  breadcrumbs (gap 11, mt 15, #999, pink links, #c4c4c4).
- NO CLONE RULE: `.class-sidebar` (live 2col→1col is the builders' own
  layout — nothing to match against).
- BATCH 2 FIXES (header published rev 714, shell isl_e0d1..., live-verified):
  icon tint #f0e6ff → #ffe9f5 (feature-icon + contact-icon — the rebrand
  map had missed #f0e6ff; pink follows the FightxLife brand, clone had
  it pale-purple only because the clone WAS purple); contact-icon
  margin-top:3px removed + icon-svg 27px (clone); contact card h3 →
  23px + pb 18 + mb 24 + 1px #dedede border (clone correction); map
  iframe radius 14px → 13px (clone).
- LEFT AS BRAND CHOICES (not "fixed"): contact links pink (clone #888),
  testimonial names pink (clone ink — awaiting Fahim's call).

### ABOUT + CLASS GALLERY AUDIT (2026-10-28, batch 3 applied live)
- MATCHED: trainer grid 3col 36/28 → 2col → 1col gap 25, trainer-card
  center/relative, trainer-photo 345px/radius 14/cover/.35s, class gallery
  3col gap 18 + img 245px/cover/radius 13, class-photo 255px/radius 15/
  cover/.35s/hover 1.035, content-section 92/92 + mobile 60/60 (clone base
  line 102 + 560 rule).
- NEW SECTIONS (no clone equivalent, sanity-checked): sponsors logo grid
  (flex wrap gap 32, logos max-height 80px), comité items (center, 1:1
  cover radius 14), advisory grid/cards.
- BATCH 3 FIXES (header published rev 716, shell isl_edfa..., live-verified):
  advisory cards mirror testimonial treatment (gap 22, 3-col tablets,
  padding 29/26/24, shadow, quote #888 14px/1.75); trainer-photo hover
  zoom 1.04 added (transition was already there); gallery img transition
  .3s + hover zoom 1.04 added (both were missing; clone has them).
- Audit coverage complete for all clone-derived sections. French-new pages
  (actions/soutenir/presse/privacy) reuse verified patterns (banner +
  content-section + buttons); no separate deviations found. Remaining open:
  testimonial/advisory names pink vs ink (Fahim's call) + his phone visual
  verdict on batches 1-3.

## SMALL DEVIATIONS (batch 3 candidates)
- Mobile button padding: live `0 22px` vs clone `0 19px` (52px/140px/14px match).
- Mobile feature-card max-width: live 390px vs clone 360px.
- Testimonial grid gap: live 24px vs clone 22px; live adds a 2-col step at
  1050px (clone stays 3-col until 780px, then 1-col).
- Testimonial section bg: live white (`sb-bg--surface-primary`) vs clone
  `#f7f2ff` (pink-mapped `#fff0f7`); section padding 92/92 vs 91/96 (close).
- Testimonial cards: live padding 28px uniform + shadow `0 2px 16px
  rgba(0,0,0,.06)` + quote text ink `#0c0905` + name pink vs clone padding
  `29px 26px 24px` + shadow `0 8px 25px rgba(40,23,65,.055)` + quote text
  `#888` 14px/1.75 + name ink Syne 700 15px. Pink names may be an
  intentional FightxLife choice — confirm before "fixing".
- "Register" button in Fahim's screenshot: NOT in header JS — platform
  chrome, to identify (not a page-design item until proven otherwise).

## CHECKED, NOT A BLANKET BUG (do not blanket-fix)
- Generic text color: platform fallback is `#111111`, clone body is
  `#a1a1a1` — BUT the about page CSS already carries gray tones
  (#999/#888/#777/#aaa) on many blocks, so only blocks with NO explicit
  color fall back to #111. Per-block check needed; a global gray override
  would break intentionally dark texts. Batch 2 stays fonts + lh only.

## STILL TO AUDIT
- Per-page sections: banner, feature cards, about-preview, why-section,
  gallery, team, testimonials, class grid, detail sidebar, contact cards —
  spacing/typography/colors vs clone, desktop + 390px.
- Computed-style check needs a real browser render (curl CSS comparison
  cannot prove rendered pixels); Fahim's phone verdict stays final.
