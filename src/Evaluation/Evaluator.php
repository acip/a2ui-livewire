<?php

namespace A2uiLivewire\Evaluation;

use A2uiLivewire\Catalog\CatalogRegistry;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\State\Surface;
use A2uiLivewire\State\SurfaceGroup;
use A2uiLivewire\Support\Json;

/**
 * Per-request evaluation environment: catalogs, surface state, and the
 * collectors for errors, side effects and agent function calls produced
 * while resolving bindings.
 */
final class Evaluator
{
    /** @var array<string, A2uiException> */
    private array $errors = [];

    /** @var list<array{type: string, ...}> */
    private array $effects = [];

    /**
     * @param  array{locale?: string, openUrlSchemes?: list<string>, baseUrl?: ?string}  $options
     */
    public function __construct(
        public readonly CatalogRegistry $catalogs,
        public readonly SurfaceGroup $group,
        public readonly array $options = [],
    ) {}

    public function context(Surface $surface, string $basePath = '/', array $indexes = [], bool $activation = false): DataContext
    {
        return new DataContext($this, $surface, $basePath, $indexes, $activation);
    }

    /**
     * A surface-independent context with an empty data model (used by
     * `callRendererFunction` without a surfaceId).
     */
    public function detachedContext(): DataContext
    {
        return new DataContext($this, new Surface('__detached__'), '/', [], false);
    }

    public function locale(): string
    {
        return $this->options['locale'] ?? 'en-US';
    }

    public function report(A2uiException $error): void
    {
        $key = $error->errorCode.'|'.($error->surfaceId ?? '').'|'.($error->functionCallId ?? '').'|'.$error->getMessage();
        $this->errors[$key] = $error;
    }

    /**
     * @return list<A2uiException>
     */
    public function errors(): array
    {
        return array_values($this->errors);
    }

    public function clearErrors(): void
    {
        $this->errors = [];
    }

    public function effect(array $effect): void
    {
        $this->effects[] = $effect;
    }

    /**
     * @return list<array>
     */
    public function effects(): array
    {
        return $this->effects;
    }

    /**
     * Routes a call to an agent-side function (`callAgentFunction`).
     *
     * Reactive evaluations (bindings, checks) share one in-flight call per
     * surface and reuse its settled answer; imperative evaluations (actions)
     * always send a new call.
     */
    public function callAgent(Surface $surface, string $catalogId, string $name, array $args, bool $imperative): mixed
    {
        $call = ['@call' => $name, 'catalogId' => $catalogId, 'args' => Json::object($args)];

        if ($imperative) {
            $this->enqueueAgentCall($surface, $call, null);

            return null;
        }

        $key = hash('xxh128', Json::encode([$catalogId, $name, $args]));
        $entry = $surface->functionCache[$key] ?? null;

        if ($entry !== null) {
            return match ($entry['state']) {
                'done' => $entry['value'] ?? null,
                'error' => throw new A2uiException(
                    A2uiException::EXPRESSION_ERROR,
                    "Agent function '{$name}' failed: ".($entry['error']['message'] ?? 'unknown error'),
                    null,
                    null,
                    $entry['callId'] ?? null,
                ),
                default => null,
            };
        }

        $callId = $this->enqueueAgentCall($surface, $call, $key);
        $surface->functionCache[$key] = ['state' => 'pending', 'callId' => $callId];

        return null;
    }

    private function enqueueAgentCall(Surface $surface, array $call, ?string $key): string
    {
        $callId = $this->group->nextId('call');
        $this->group->agentCalls[$callId] = ['surfaceId' => $surface->id, 'key' => $key];
        $this->group->outbox[] = [
            'version' => '1.0',
            'callAgentFunction' => [
                'surfaceId' => $surface->id,
                'functionCallId' => $callId,
                'callFunction' => $call,
            ],
        ];

        return $callId;
    }
}
