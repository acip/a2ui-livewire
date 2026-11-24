<?php

namespace A2uiLivewire\Rendering;

use A2uiLivewire\Evaluation\Evaluator;
use A2uiLivewire\Exceptions\A2uiException;

/**
 * Collects what a render pass discovers: two-way input bindings, action
 * targets and evaluation errors.
 */
final class RenderSession
{
    /**
     * token => binding.
     *
     * @var array<string, array{surfaceId: string, pointer: ?string, kind: string, value: mixed}>
     */
    public array $inputs = [];

    /**
     * token => action target.
     *
     * @var array<string, array{surfaceId: string, componentId: string, basePath: string, indexes: list<int>, prop: string}>
     */
    public array $actions = [];

    public function __construct(
        public readonly Evaluator $evaluator,
        /** wire:model modifier string for text-like inputs, e.g. ".live.debounce.300ms" */
        public readonly string $textModelModifiers = '.live.debounce.300ms',
        /** wire:model modifier string for toggles, choices, sliders and pickers */
        public readonly string $choiceModelModifiers = '.live',
        public readonly string $inputsProperty = 'a2uiInputs',
        public readonly string $actionMethod = 'a2uiAction',
        public readonly bool $debug = false,
    ) {}

    public function registerInput(string $surfaceId, ?string $pointer, string $scopeKey, string $kind, mixed $value): string
    {
        $token = 'i'.substr(hash('xxh128', $surfaceId.'|'.($pointer ?? '#local:'.$scopeKey)), 0, 20);

        $this->inputs[$token] = [
            'surfaceId' => $surfaceId,
            'pointer' => $pointer,
            'kind' => $kind,
            'value' => $value,
        ];

        return $token;
    }

    public function registerAction(string $surfaceId, string $componentId, string $basePath, array $indexes, string $prop = 'action'): string
    {
        $token = 'a'.substr(hash('xxh128', $surfaceId.'|'.$componentId.'|'.$basePath.'|'.$prop), 0, 20);

        $this->actions[$token] = [
            'surfaceId' => $surfaceId,
            'componentId' => $componentId,
            'basePath' => $basePath,
            'indexes' => $indexes,
            'prop' => $prop,
        ];

        return $token;
    }

    public function report(A2uiException $error): void
    {
        $this->evaluator->report($error);
    }
}
