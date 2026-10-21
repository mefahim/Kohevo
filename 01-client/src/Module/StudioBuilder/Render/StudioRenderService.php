<?php
/**
 * Kohevo Studio (studio-builder) — Render service (the one pipeline, three runtimes).
 *
 * Preview, Editor and Public all run the identical sequence
 *
 *     compile (static, dynamic deferred) -> fill dynamic nodes -> assemble
 *
 * and differ only in (a) which revision is the source and (b) whether the
 * compiled artifact is persisted:
 *
 *   renderRevision()  — Preview/Editor. Any revision of the page (already
 *                       authorized by StudioApplicationService). Compiled in
 *                       memory and discarded: no write of any kind.
 *   renderPublished() — Public. The page's `published_revision_id` ONLY —
 *                       there is no code path here that reads
 *                       `active_draft_revision_id`. Uses/refreshes the stored
 *                       `published` compilation.
 *
 * This service does not authorize actors; its authoring entry points are only
 * reachable through StudioApplicationService, and its public entry point only
 * through StudioPublicRuntime.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

use Slate\Module\StudioBuilder\Meta\PageMeta;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Cache\StudioCacheKey;
use Slate\Module\StudioBuilder\Render\Cache\StudioCachePolicy;
use Slate\Module\StudioBuilder\Render\Compile\CompiledPage;
use Slate\Module\StudioBuilder\Render\Compile\DynamicSlotResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;

final class StudioRenderService
{
    public const PUBLIC_PAGE_TYPES = ['page', 'landing'];

    private readonly \Closure $siteFactory;

    public function __construct(
        private readonly RevisionRepository $revisions,
        private readonly StudioCompiler $compiler,
        private readonly DynamicSlotResolver $slots,
        private readonly PageDocumentAssembler $assembler,
        ?\Closure $siteFactory = null,
        private readonly ?ThemeTemplateResolver $templates = null,
    ) {
        $this->siteFactory = $siteFactory ?? static fn(): SiteContext => SiteContext::fromEnvironment();
    }

    /** The server-side site context for the CURRENT tenant (never request-derived). */
    public function siteContext(): SiteContext
    {
        return ($this->siteFactory)();
    }

    /**
     * Authoring render of a specific revision (Preview / Editor). No persistence.
     *
     * @param array<string, mixed> $revision a revision row of $page
     */
    public function renderRevision(PageAddress $page, array $revision, RenderContext $context): RenderResult
    {
        if ($context->mode->isPublic()) {
            throw new \LogicException('renderRevision() serves authoring contexts only; public output comes from renderPublished().');
        }
        $context = $this->withPageMeta($page, $context);
        $compiled = $this->compiler->compile($page, $revision, $context);
        $html = $this->assembler->assemble($compiled, $this->slots->fill($compiled, $context), $context);
        return new RenderResult($context->mode, $html, StudioCachePolicy::headersFor($context->mode));
    }

    /**
     * Public render of the page's published revision, or null when the page is
     * not publicly renderable (not published, wrong type, revision missing).
     */
    public function renderPublished(PageAddress $page, RenderContext $context): ?RenderResult
    {
        if (!$context->mode->isPublic()) {
            throw new \LogicException('renderPublished() serves the public context only.');
        }
        if ($page->id === null || !$page->isPublished() || !in_array($page->pageType, ['page', 'landing', 'system'], true)) {
            return null;
        }

        $revision = $this->revisions->findByIdForPage($page->id, (int) $page->publishedRevisionId);
        if ($revision === null) {
            return null;
        }

        $context = $this->withPageMeta($page, $context);
        $compiled = $this->compiler->publishedArtifact($page, $revision, $context);
        $html = $this->assembler->assemble($compiled, $this->slots->fill($compiled, $context), $context);
        if ($this->templates !== null) {
            $html = $this->injectPopups($html, $page, $context);
        }

        $etag = StudioCacheKey::etag(
            StudioCacheKey::published($context->tenantId, $context->site->siteKey, $page->id, $compiled->revisionId, $compiled->contentHash),
            $html,
        );
        return new RenderResult($context->mode, $html, StudioCachePolicy::headersFor($context->mode, $etag), $etag);
    }

    /**
     * Append the popups whose conditions match this page, rendered per request
     * (the platform-signature precedent): popup edits, unpublishes and
     * condition changes take effect on the next request without invalidating
     * any page artifact. A failing popup is skipped, never fatal to the page.
     */
    private function injectPopups(string $html, PageAddress $page, RenderContext $context): string
    {
        try {
            $popups = $this->templates->resolvePopups($page);
        } catch (\Throwable) {
            return $html;
        }
        if ($popups === []) {
            return $html;
        }

        $markup = '';
        $css    = '';
        foreach ($popups as $popup) {
            try {
                $compiled = $this->compiler->compileFragment(PageAddress::fromRow($popup['page']), $popup['revision'], $context);
                $filled   = $this->slots->fill($compiled, $context);
                $t        = $popup['trigger'];
                $key      = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($popup['page']['slug'] ?? $popup['page']['id']));
                $css     .= $compiled->css . $filled->extraCss;
                $markup  .= '<div class="sb-modal sb-modal--' . $t['size'] . ' sb-popup" id="sb-popup-' . $key . '" role="dialog" aria-modal="true" aria-hidden="true"'
                    . ' data-sb-popup data-sb-popup-trigger="' . $t['type'] . '" data-sb-popup-delay="' . $t['delay_ms'] . '" data-sb-popup-scroll="' . $t['scroll_percent'] . '" data-sb-popup-frequency="' . $t['frequency'] . '">'
                    . '<a href="#" class="sb-modal__backdrop" aria-label="Close" data-sb-modal-close></a>'
                    . '<div class="sb-modal__dialog"><button type="button" class="sb-popup__close" data-sb-modal-close aria-label="Close">&times;</button>'
                    . '<div class="sb-modal__body">' . $filled->html . '</div></div></div>';
            } catch (\Throwable) {
                continue;
            }
        }
        if ($markup === '') {
            return $html;
        }
        $inject = ($css !== '' ? '<style data-sb="popup-css">' . str_ireplace('</style', '<\/style', $css) . '</style>' : '') . $markup;
        $pos    = strrpos($html, '</body>');

        return $pos === false ? $html . $inject : substr($html, 0, $pos) . $inject . substr($html, $pos);
    }

    /**
     * Compile + persist the published artifact for a just-published revision
     * (called by StudioApplicationService::publish() INSIDE the publish
     * transaction, so a compilation failure rolls the publish back).
     *
     * @param array<string, mixed> $revision
     */
    public function compilePublished(PageAddress $page, array $revision, RenderContext $context): CompiledPage
    {
        return $this->compiler->compileAndStorePublished($page, $revision, $context);
    }

    /**
     * The page's content type and custom field values, for `theme.field_value`. A block is rendered per request (it is
     * dynamic), so editing a value never needs the compiled page rebuilt. A template shown for another page names that
     * page in the `meta_page_id` attribute.
     */
    private function withPageMeta(PageAddress $page, RenderContext $context): RenderContext
    {
        $id = (int) $context->attribute('meta_page_id', $page->id ?? 0);
        if ($id <= 0 || $context->attribute('page_meta') !== null) {
            return $context;
        }
        try {
            $meta = PageMeta::read($context->tenantId, $id);
            $fields = $meta['type'] === '' ? [] : PageMeta::fieldsFor($context->tenantId, $meta['type']);
        } catch (\Throwable) {
            return $context;
        }
        return $context->withAttributes(['page_meta' => $meta + ['fields' => $fields]]);
    }
}
