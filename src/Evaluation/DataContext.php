<?php

namespace A2uiLivewire\Evaluation;

use A2uiLivewire\Catalog\Catalog;
use A2uiLivewire\Catalog\FunctionDefinition;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\State\Surface;
use A2uiLivewire\Support\JsonPointer;
use A2uiLivewire\Support\Values;
use Throwable;

/**
 * An evaluation scope: a surface, the current collection scope (base path and
 * template indexes) and whether the evaluation was triggered by a user gesture.
 *
 * Resolves `DynamicValue`s: literals, `{"@path": ...}` data bindings and
 * `{"@call": ...}` function calls.
 */
final class DataContext
{
    public const MAX_CALL_DEPTH = 32;

    /**
     * @param  list<int>  $indexes  template iteration indexes, innermost last
     */
    public function __construct(
        public readonly Evaluator $evaluator,
        public readonly Surface $surface,
        public readonly string $basePath = '/',
        public readonly array $indexes = [],
        public readonly bool $activation = false,
        public readonly ?string $callCatalogId = null,
        public readonly bool $agentRouting = true,
        private readonly int $depth = 0,
    ) {}

    /**
     * Enters a list-template item scope.
     */
    public function child(string $basePath, int $index): self
    {
        return new self($this->evaluator, $this->surface, $basePath, [...$this->indexes, $index], $this->activation, $this->callCatalogId, $this->agentRouting, $this->depth);
    }

    public function withActivation(bool $activation = true): self
    {
        return new self($this->evaluator, $this->surface, $this->basePath, $this->indexes, $activation, $this->callCatalogId, $this->agentRouting, $this->depth);
    }

    /**
     * Function calls nested in a `formatString` template resolve against the
     * catalog of the enclosing call and are never routed to the agent.
     */
    public function forTemplate(string $catalogId): self
    {
        return new self($this->evaluator, $this->surface, $this->basePath, $this->indexes, $this->activation, $catalogId, false, $this->depth);
    }

    public function resolvePath(string $path): string
    {
        return JsonPointer::resolve($path, $this->basePath);
    }

    public function get(string $path): mixed
    {
        return JsonPointer::get($this->surface->dataModel, $this->resolvePath($path));
    }

    public static function isBinding(mixed $value): bool
    {
        return is_array($value) && isset($value['@path']) && is_string($value['@path']);
    }

    public static function isCall(mixed $value): bool
    {
        return is_array($value) && isset($value['@call']) && is_string($value['@call']);
    }

    public static function isDynamic(mixed $value): bool
    {
        return self::isBinding($value) || self::isCall($value);
    }

    /**
     * Resolves a DynamicValue to a plain value. Absent data resolves to null.
     *
     * @throws A2uiException
     */
    public function resolve(mixed $value): mixed
    {
        if (self::isBinding($value)) {
            return $this->get($value['@path']);
        }

        if (self::isCall($value)) {
            return $this->call($value);
        }

        return $value;
    }

    /**
     * Resolves dynamic values recursively inside lists (function arguments,
     * `and`/`or` value lists, action context).
     */
    public function resolveDeep(mixed $value): mixed
    {
        if (self::isDynamic($value)) {
            return $this->resolve($value);
        }

        if (is_array($value) && array_is_list($value)) {
            return array_map(fn ($item) => $this->resolveDeep($item), $value);
        }

        return $value;
    }

    public function string(mixed $value): string
    {
        return Values::toString($this->resolve($value));
    }

    public function boolean(mixed $value): bool
    {
        return Values::toBoolean($this->resolve($value));
    }

    public function number(mixed $value): int|float|null
    {
        return Values::toNumber($this->resolve($value));
    }

    /**
     * @return list<string>
     */
    public function stringList(mixed $value): array
    {
        return Values::toStringList($this->resolve($value));
    }

    /**
     * Evaluates a function call, routing it locally or to the agent.
     *
     * @throws A2uiException
     */
    public function call(array $call): mixed
    {
        $name = $call['@call'];
        $rawArgs = $call['args'] ?? [];

        if (! is_array($rawArgs)) {
            throw A2uiException::expression("Function '{$name}' arguments must be an object.", $this->surface->id);
        }

        if ($this->depth >= self::MAX_CALL_DEPTH) {
            throw A2uiException::expression('Maximum function call depth exceeded.', $this->surface->id);
        }

        if (str_starts_with($name, '@')) {
            return $this->callSystemFunction($name, $rawArgs);
        }

        $catalogId = $call['catalogId'] ?? $this->callCatalogId ?? $this->surface->catalogId;

        if (! is_string($catalogId) || $catalogId === '') {
            throw A2uiException::expression("Function '{$name}' has no catalogId and the surface declares no default catalog.", $this->surface->id);
        }

        $catalog = $this->evaluator->catalogs->get($catalogId);

        if ($catalog === null) {
            throw A2uiException::expression("Function '{$name}' references unknown catalog '{$catalogId}'.", $this->surface->id);
        }

        $definition = $catalog->function($name);
        $inner = $this->deeper();

        if ($definition !== null && $definition->requiresUserActivation && ! $this->activation) {
            throw A2uiException::expression("Function '{$name}' requires user activation and cannot be used in a binding or check.", $this->surface->id);
        }

        if ($definition !== null && $definition->allowedCallers === FunctionDefinition::AGENT_ONLY) {
            throw A2uiException::expression("Function '{$name}' is agentOnly and cannot be evaluated by the renderer.", $this->surface->id);
        }

        if ($definition !== null && $definition->isLocal()) {
            return $inner->invokeLocal($catalog, $definition, $rawArgs);
        }

        if (! $this->agentRouting) {
            throw A2uiException::expression("Function '{$name}' is not implemented by the renderer and cannot be called from a template.", $this->surface->id);
        }

        $args = [];
        foreach ($rawArgs as $key => $arg) {
            $args[$key] = $inner->resolveDeep($arg);
        }

        return $this->evaluator->callAgent($this->surface, $catalog->id, $name, $args, $this->activation);
    }

    /**
     * Invokes a renderer-side implementation with resolved arguments.
     */
    public function invokeLocal(Catalog $catalog, FunctionDefinition $definition, array $rawArgs): mixed
    {
        foreach ($definition->requiredArguments() as $required) {
            if (! array_key_exists($required, $rawArgs)) {
                throw A2uiException::expression("Function '{$definition->name}' is missing required argument '{$required}'.", $this->surface->id);
            }
        }

        $declared = $definition->argumentSchemas();
        if ($declared !== [] && ($definition->schema['properties']['args']['unevaluatedProperties'] ?? null) === false) {
            foreach (array_keys($rawArgs) as $key) {
                if (! array_key_exists($key, $declared)) {
                    throw A2uiException::expression("Unknown argument '{$key}' for function '{$definition->name}'.", $this->surface->id);
                }
            }
        }

        if ($definition->rawArguments) {
            $args = $rawArgs;
        } else {
            $args = [];
            foreach ($rawArgs as $key => $arg) {
                $args[$key] = $this->resolveDeep($arg);
            }
        }

        $context = new FunctionContext($this, $catalog, $definition);

        try {
            return ($definition->implementation)($args, $context);
        } catch (A2uiException $e) {
            throw $e->withSurface($this->surface->id === '__detached__' ? null : $this->surface->id);
        } catch (Throwable $e) {
            throw A2uiException::execution("Function '{$definition->name}' failed: {$e->getMessage()}", $this->surface->id, $e);
        }
    }

    private function callSystemFunction(string $name, array $rawArgs): mixed
    {
        if ($name !== '@index') {
            throw A2uiException::expression("Unknown system function '{$name}'.", $this->surface->id);
        }

        if ($this->indexes === []) {
            throw A2uiException::expression('@index can only be used inside a list template.', $this->surface->id);
        }

        $offset = array_key_exists('offset', $rawArgs) ? Values::toNumber($this->resolve($rawArgs['offset'])) : 0;

        return $this->indexes[count($this->indexes) - 1] + ($offset ?? 0);
    }

    private function deeper(): self
    {
        return new self($this->evaluator, $this->surface, $this->basePath, $this->indexes, $this->activation, $this->callCatalogId, $this->agentRouting, $this->depth + 1);
    }
}
