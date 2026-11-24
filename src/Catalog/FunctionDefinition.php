<?php

namespace A2uiLivewire\Catalog;

use A2uiLivewire\Evaluation\FunctionContext;
use Closure;

/**
 * A catalog function. Functions without an implementation are schema-only
 * declarations: calls to them are routed to the agent (`callAgentFunction`).
 */
final class FunctionDefinition
{
    public const RENDERER_ONLY = 'rendererOnly';
    public const AGENT_ONLY = 'agentOnly';
    public const RENDERER_OR_AGENT = 'rendererOrAgent';

    /**
     * @param  (Closure(array<string, mixed>, FunctionContext): mixed)|null  $implementation
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public readonly string $name,
        public readonly ?Closure $implementation = null,
        public readonly string $returnType = 'any',
        public readonly string $allowedCallers = self::RENDERER_ONLY,
        public readonly bool $requiresUserActivation = false,
        public readonly array $schema = [],
        /** When true the implementation receives raw (unresolved) arguments. */
        public readonly bool $rawArguments = false,
    ) {}

    public function isLocal(): bool
    {
        return $this->implementation !== null;
    }

    public function agentMayCall(): bool
    {
        return in_array($this->allowedCallers, [self::AGENT_ONLY, self::RENDERER_OR_AGENT], true);
    }

    public function rendererMayCall(): bool
    {
        return $this->allowedCallers !== self::AGENT_ONLY;
    }

    /**
     * Declared argument names and their schemas.
     *
     * @return array<string, array>
     */
    public function argumentSchemas(): array
    {
        $args = $this->schema['properties']['args'] ?? [];

        return is_array($args['properties'] ?? null) ? $args['properties'] : [];
    }

    /**
     * @return list<string>
     */
    public function requiredArguments(): array
    {
        $args = $this->schema['properties']['args'] ?? [];

        return is_array($args['required'] ?? null) ? array_values($args['required']) : [];
    }

    public function withImplementation(Closure $implementation, bool $rawArguments = false): self
    {
        return new self($this->name, $implementation, $this->returnType, $this->allowedCallers, $this->requiresUserActivation, $this->schema, $rawArguments);
    }

    public function toDefinition(): array
    {
        $definition = $this->schema ?: [
            'type' => 'object',
            'properties' => ['@call' => ['const' => $this->name], 'args' => ['type' => 'object']],
            'required' => ['@call'],
        ];

        $definition['returnType'] = $this->returnType;

        if ($this->allowedCallers !== self::RENDERER_ONLY) {
            $definition['allowedCallers'] = $this->allowedCallers;
        }

        if ($this->requiresUserActivation) {
            $definition['requiresUserActivation'] = true;
        }

        return $definition;
    }
}
