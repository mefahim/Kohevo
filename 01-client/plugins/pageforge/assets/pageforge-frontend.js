/**
 * PageForge frontend — Batch A scroll-fade.
 * Vanilla, no dependencies, inert without [data-pf-scroll-fade] elements.
 * An element fully in view (or below the fold) renders at opacity 1; as it
 * scrolls out above the viewport it fades toward 0.15. Inline opacity wins
 * over a compiled opacity rule on the same element — fade is opt-in per
 * element, so that trade is the author's choice.
 */
(function () {
    var els = [];
    function refresh() {
        els = Array.prototype.slice.call(document.querySelectorAll('[data-pf-scroll-fade]'));
    }
    function onScroll() {
        for (var i = 0; i < els.length; i++) {
            var el = els[i];
            var r = el.getBoundingClientRect();
            var o;
            if (r.bottom <= 0) o = 0.15;
            else if (r.top < 0) o = 0.15 + 0.85 * (r.bottom / Math.max(r.height, 1));
            else o = 1;
            el.style.opacity = o.toFixed(2);
        }
    }
    var ticking = false;
    function req() {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(function () { ticking = false; onScroll(); });
        }
    }
    refresh();
    if (document.readyState !== 'loading') onScroll();
    else document.addEventListener('DOMContentLoaded', function () { refresh(); onScroll(); });
    window.addEventListener('scroll', req, { passive: true });
    window.addEventListener('resize', req);
    document.addEventListener('click', req);
    if (window.MutationObserver && document.body) {
        new MutationObserver(function () { refresh(); req(); }).observe(document.body, { childList: true, subtree: true });
    }
})();

/**
 * Batch D: real slider + lightbox. Vanilla, no dependencies, inert
 * without [data-pf-carousel] / [data-pf-lightbox] roots.
 *
 * Slider: transform-based sliding over a viewport/track structure. When
 * nothing needs JS (no nav, no autoplay, no loop) the carousel is left
 * in native horizontal scroll — the no-JS fallback, not a dead slider.
 * Infinite loop clones show slides at both ends and jumps back on
 * transitionend. Autoplay honors reduced-motion and pauses on hover /
 * interaction per the author's toggles.
 */
(function () {
    function initCarousel(root) {
        var cfg;
        try { cfg = JSON.parse(root.getAttribute('data-pf-carousel') || '{}'); } catch (e) { return; }
        var viewport = root.querySelector('.pf-carousel__viewport');
        var track = root.querySelector('.pf-carousel__track');
        if (!viewport || !track) return;
        var real = Array.prototype.slice.call(track.children);
        if (real.length === 0) return;
        var show = Math.max(1, Math.min(6, parseInt(cfg.show, 10) || 3));
        var scroll = Math.max(1, Math.min(6, parseInt(cfg.scroll, 10) || 1));
        var hasNav = cfg.nav === 'arrows' || cfg.nav === 'dots' || cfg.nav === 'both';
        var loop = cfg.loop === true && real.length > show;
        if (!hasNav && !cfg.autoplay && !loop) return; // native scroll stays
        root.classList.add('pf-carousel--js');
        var gap = 16;
        var step = 0;
        var current = loop ? show : 0; // index into track children (incl. clones)
        var maxReal = Math.max(0, real.length - show);
        var animating = false;
        var timer = null;
        var dots = [];
        var navEl = null;

        function measure() {
            var cs = window.getComputedStyle(track);
            gap = parseFloat(cs.columnGap || cs.gap || '16') || 16;
            var w = (viewport.clientWidth - (show - 1) * gap) / show;
            for (var i = 0; i < track.children.length; i++) {
                track.children[i].style.flex = '0 0 ' + w + 'px';
            }
            step = w + gap;
        }
        function positions() {
            var list = [];
            for (var p = 0; p <= maxReal; p += scroll) list.push(p);
            if (list[list.length - 1] !== maxReal) list.push(maxReal);
            return list;
        }
        function pageIndex() {
            var realIdx = loop ? (current - show + real.length * 2) % real.length : current;
            var pages = positions();
            var best = 0;
            for (var i = 0; i < pages.length; i++) {
                if (pages[i] <= realIdx) best = i;
            }
            return best;
        }
        function paint(instant) {
            track.style.transition = instant ? 'none' : ('transform ' + (parseInt(cfg.anim, 10) || 500) + 'ms ease');
            track.style.transform = 'translateX(' + (-current * step) + 'px)';
            for (var i = 0; i < dots.length; i++) {
                dots[i].classList.toggle('is-active', i === pageIndex());
            }
        }
        function goTo(index, instant) {
            current = index;
            paint(instant);
        }
        function go(dir) {
            if (animating) return;
            animating = true;
            if (loop) {
                goTo(current + dir * scroll, false);
            } else {
                var pages = positions();
                var pi = pageIndex();
                var next = Math.max(0, Math.min(pages.length - 1, pi + dir));
                goTo(pages[next], false);
                if (pages[next] === pages[pi]) animating = false;
            }
        }
        track.addEventListener('transitionend', function () {
            animating = false;
            if (!loop) return;
            if (current >= show + real.length) goTo(current - real.length, true);
            else if (current < show) goTo(current + real.length, true);
        });
        // Clones for the loop.
        if (loop) {
            for (var a = 0; a < show; a++) {
                var first = real[a].cloneNode(true);
                first.setAttribute('aria-hidden', 'true');
                track.appendChild(first);
                var last = real[real.length - 1 - a].cloneNode(true);
                last.setAttribute('aria-hidden', 'true');
                track.insertBefore(last, track.firstChild);
            }
        }
        // Navigation, built by JS so no-JS pages never show dead buttons.
        if (cfg.nav === 'arrows' || cfg.nav === 'both') {
            navEl = document.createElement('div');
            navEl.className = 'pf-carousel__nav';
            var prev = document.createElement('button');
            prev.type = 'button';
            prev.className = 'pf-carousel__arrow';
            prev.setAttribute('aria-label', 'Previous slide');
            prev.textContent = '‹';
            var next = document.createElement('button');
            next.type = 'button';
            next.className = 'pf-carousel__arrow';
            next.setAttribute('aria-label', 'Next slide');
            next.textContent = '›';
            prev.addEventListener('click', function () { interact(); go(-1); });
            next.addEventListener('click', function () { interact(); go(1); });
            navEl.appendChild(prev);
            navEl.appendChild(next);
            root.appendChild(navEl);
        }
        if (cfg.nav === 'dots' || cfg.nav === 'both') {
            var dotsEl = document.createElement('div');
            dotsEl.className = 'pf-carousel__dots';
            if (!navEl) {
                navEl = document.createElement('div');
                navEl.className = 'pf-carousel__nav';
                root.appendChild(navEl);
            }
            var pages = positions();
            for (var d = 0; d < pages.length; d++) {
                (function (target) {
                    var dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'pf-carousel__dot';
                    dot.setAttribute('aria-label', 'Go to slide ' + (target + 1));
                    dot.addEventListener('click', function () { interact(); goTo(loop ? target + show : target, false); });
                    dots.push(dot);
                    dotsEl.appendChild(dot);
                })(pages[d]);
            }
            navEl.appendChild(dotsEl);
        }
        // Autoplay.
        var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        function startAuto() {
            if (!cfg.autoplay || reduced || timer) return;
            timer = setInterval(function () { go(1); }, parseInt(cfg.speed, 10) || 3000);
        }
        function stopAuto() {
            if (timer) { clearInterval(timer); timer = null; }
        }
        function interact() {
            if (cfg.pauseInteract) stopAuto();
            else { stopAuto(); startAuto(); }
        }
        if (cfg.pauseHover) {
            root.addEventListener('mouseenter', stopAuto);
            root.addEventListener('mouseleave', startAuto);
        }
        // Touch swipe.
        var tx = null;
        track.addEventListener('touchstart', function (e) {
            tx = e.changedTouches[0].clientX;
        }, { passive: true });
        track.addEventListener('touchend', function (e) {
            if (tx === null) return;
            var dx = e.changedTouches[0].clientX - tx;
            tx = null;
            if (Math.abs(dx) > 40) { interact(); go(dx < 0 ? 1 : -1); }
        }, { passive: true });
        measure();
        paint(true);
        startAuto();
        var rT;
        window.addEventListener('resize', function () {
            clearTimeout(rT);
            rT = setTimeout(function () { measure(); paint(true); }, 150);
        });
    }

    function initLightbox(root) {
        var imgs = Array.prototype.slice.call(root.querySelectorAll('img'));
        // Linked images keep their link; only plain images open the box.
        imgs = imgs.filter(function (img) { return !img.closest('a'); });
        if (imgs.length === 0) return;
        var overlay = null;
        var idx = 0;
        function captionFor(img) {
            var fig = img.closest('figure');
            var cap = fig ? fig.querySelector('figcaption') : null;
            return cap ? cap.textContent : (img.getAttribute('alt') || '');
        }
        function show(i) {
            idx = (i + imgs.length) % imgs.length;
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.className = 'pf-lightbox';
                overlay.setAttribute('role', 'dialog');
                overlay.setAttribute('aria-label', 'Image viewer');
                var prev = document.createElement('button');
                prev.type = 'button';
                prev.className = 'pf-lightbox__btn';
                prev.textContent = '‹';
                prev.setAttribute('aria-label', 'Previous image');
                prev.addEventListener('click', function (e) { e.stopPropagation(); show(idx - 1); });
                var next = document.createElement('button');
                next.type = 'button';
                next.className = 'pf-lightbox__btn';
                next.textContent = '›';
                next.setAttribute('aria-label', 'Next image');
                next.addEventListener('click', function (e) { e.stopPropagation(); show(idx + 1); });
                var close = document.createElement('button');
                close.type = 'button';
                close.className = 'pf-lightbox__btn pf-lightbox__close';
                close.textContent = '✕';
                close.setAttribute('aria-label', 'Close viewer');
                close.addEventListener('click', closeBox);
                var fig = document.createElement('figure');
                fig.style.margin = '0';
                var big = document.createElement('img');
                big.alt = '';
                var cap = document.createElement('figcaption');
                fig.appendChild(big);
                fig.appendChild(cap);
                overlay.appendChild(prev);
                overlay.appendChild(fig);
                overlay.appendChild(next);
                overlay.appendChild(close);
                overlay.addEventListener('click', function (e) { if (e.target === overlay) closeBox(); });
                document.addEventListener('keydown', onKey);
                document.body.appendChild(overlay);
                close.focus();
            }
            var bigImg = overlay.querySelector('img');
            var capEl = overlay.querySelector('figcaption');
            bigImg.src = imgs[idx].currentSrc || imgs[idx].src;
            bigImg.alt = imgs[idx].getAttribute('alt') || '';
            capEl.textContent = captionFor(imgs[idx]);
            capEl.style.display = captionFor(imgs[idx]) ? '' : 'none';
        }
        function closeBox() {
            if (!overlay) return;
            overlay.remove();
            overlay = null;
            document.removeEventListener('keydown', onKey);
        }
        function onKey(e) {
            if (e.key === 'Escape') closeBox();
            else if (e.key === 'ArrowLeft') show(idx - 1);
            else if (e.key === 'ArrowRight') show(idx + 1);
        }
        imgs.forEach(function (img, i) {
            img.style.cursor = 'zoom-in';
            img.addEventListener('click', function () { show(i); });
        });
    }

    function boot() {
        var carousels = document.querySelectorAll('[data-pf-carousel]');
        for (var i = 0; i < carousels.length; i++) initCarousel(carousels[i]);
        var lbRoots = document.querySelectorAll('[data-pf-lightbox]');
        for (var j = 0; j < lbRoots.length; j++) initLightbox(lbRoots[j]);
    }
    if (document.readyState !== 'loading') boot();
    else document.addEventListener('DOMContentLoaded', boot);
})();
