<?php

namespace A2uiLivewire\Events;

use A2uiLivewire\Exceptions\A2uiException;

/**
 * Fired for every renderer-side error (validation, expression, transport).
 */
final class RendererError
{
    public function __construct(
        public readonly A2uiException $error,
        public readonly string $rendererId,
    ) {}
}
