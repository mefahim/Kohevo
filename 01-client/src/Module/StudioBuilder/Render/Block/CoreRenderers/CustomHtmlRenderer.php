<?php
/**
 * Kohevo Studio — `core.html` renderer (custom HTML / embed block).
 *
 * The author's markup reaches the page only through EmbedHtmlSanitizer:
 * scripts and event handlers never survive, and iframes are limited to
 * allowlisted embed hosts. In the editor the sanitizer swaps iframes for a
 * placeholder card, matching the embed blocks' canvas policy.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\EmbedHtmlSanitizer;
use Slate\Module\StudioBuilder\Render\RenderMode;

final class CustomHtmlRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.html';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        $html = $scope->string('html');
        if (trim($html) === '') {
            return $scope->mode() === RenderMode::Editor
                ? '<div class="sb-embed-frame__placeholder">Custom HTML — add markup in the inspector.</div>'
                : '';
        }

        return EmbedHtmlSanitizer::sanitize($html, $scope->mode() === RenderMode::Editor);
    }
}
