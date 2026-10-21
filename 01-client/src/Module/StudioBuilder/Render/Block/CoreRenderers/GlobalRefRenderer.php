<?php
/**
 * Kohevo Studio — `core.global` renderer (fallback).
 *
 * The real expansion happens in DocumentRenderer, which owns the resolved
 * components map: it splices the referenced component's blocks into this
 * block's slot. This renderer is the safety net for any path that renders a
 * block without that map — it never invents content.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block\CoreRenderers;

use Slate\Module\StudioBuilder\Render\Block\BlockRendererInterface;
use Slate\Module\StudioBuilder\Render\Block\BlockRenderScope;
use Slate\Module\StudioBuilder\Render\Html;

final class GlobalRefRenderer implements BlockRendererInterface
{
    public function type(): string
    {
        return 'core.global';
    }

    public function isDynamic(): bool
    {
        return false;
    }

    public function render(BlockRenderScope $scope): string
    {
        if (!$scope->showsDiagnostics()) {
            return '';
        }
        $ref = trim($scope->string('ref'));

        return '<div class="sb-unavailable" role="note">' . Html::e(Html::t('studio_block_unavailable', 'This block is unavailable')) . ' (component_unavailable' . ($ref !== '' ? ': ' . Html::e($ref) : '') . ')</div>';
    }
}
