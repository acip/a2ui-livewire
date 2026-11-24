<?php

namespace A2uiLivewire\Evaluation;

use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Catalog\FunctionDefinition;
use A2uiLivewire\State\Surface;

/**
 * What a function implementation can see: the calling data context, the
 * catalog the call resolved to, and helpers for side effects.
 */
final class FunctionContext
{
    public function __construct(
        public readonly DataContext $data,
        public readonly Catalog $catalog,
        public readonly FunctionDefinition $definition,
    ) {}

    public function surface(): Surface
    {
        return $this->data->surface;
    }

    public function locale(): string
    {
        return $this->data->evaluator->locale();
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->data->evaluator->options[$key] ?? $default;
    }

    public function isUserActivated(): bool
    {
        return $this->data->activation;
    }

    /**
     * Queues a client-side effect (e.g. opening a URL in the browser).
     */
    public function effect(array $effect): void
    {
        $this->data->evaluator->effect($effect);
    }
}
