<?php

namespace A2uiLivewire\Catalog;

use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Support\Uax31;
use Closure;

/**
 * A component and function catalog (spec: "The component catalog").
 *
 * Catalogs are immutable; the `with*` methods return modified copies.
 */
final class Catalog
{
    /**
     * @param  array<string, ComponentDefinition>  $components
     * @param  array<string, FunctionDefinition>  $functions
     */
    public function __construct(
        public readonly string $id,
        public readonly array $components = [],
        public readonly array $functions = [],
        public readonly ?string $instructions = null,
        public readonly string $protocolVersion = '1.0',
        public readonly ?string $title = null,
        public readonly bool $inline = false,
    ) {}

    /**
     * Builds a catalog from a catalog definition document (catalog.json).
     *
     * @param  array<string, string>  $views  component name => Blade view
     *
     * @throws A2uiException when the definition breaks catalog rules.
     */
    public static function fromDefinition(array $definition, array $views = [], bool $inline = false): self
    {
        $id = $definition['catalogId'] ?? $definition['$id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw new A2uiException(A2uiException::CATALOG_ERROR, 'Catalog definition is missing a catalogId.');
        }

        $components = [];
        foreach ($definition['components'] ?? [] as $name => $schema) {
            self::assertIdentifier((string) $name, $id);

            if ($name === 'Surface') {
                throw new A2uiException(A2uiException::CATALOG_ERROR, "Catalog '{$id}' may not define the reserved 'Surface' component.");
            }

            $components[$name] = new ComponentDefinition(
                name: (string) $name,
                view: $views[$name] ?? null,
                schema: is_array($schema) ? $schema : [],
                allowedParents: is_array($schema['allowedParents'] ?? null) ? $schema['allowedParents'] : null,
                allowedChildren: is_array($schema['allowedChildren'] ?? null) ? $schema['allowedChildren'] : null,
            );
        }

        $functions = [];
        foreach ($definition['functions'] ?? [] as $name => $schema) {
            self::assertIdentifier((string) $name, $id);

            $requiresActivation = (bool) ($schema['requiresUserActivation'] ?? false);
            $allowedCallers = $schema['allowedCallers'] ?? FunctionDefinition::RENDERER_ONLY;

            if ($requiresActivation && $allowedCallers !== FunctionDefinition::RENDERER_ONLY) {
                throw new A2uiException(A2uiException::CATALOG_ERROR, "Function '{$name}' requires user activation and must be rendererOnly.");
            }

            $functions[$name] = new FunctionDefinition(
                name: (string) $name,
                returnType: (string) ($schema['returnType'] ?? 'any'),
                allowedCallers: $allowedCallers,
                requiresUserActivation: $requiresActivation,
                schema: is_array($schema) ? $schema : [],
            );
        }

        return new self(
            id: $id,
            components: $components,
            functions: $functions,
            instructions: is_string($definition['instructions'] ?? null) ? $definition['instructions'] : null,
            protocolVersion: (string) ($definition['protocolVersion'] ?? '0.9'),
            title: is_string($definition['title'] ?? null) ? $definition['title'] : null,
            inline: $inline,
        );
    }

    public function component(string $name): ?ComponentDefinition
    {
        return $this->components[$name] ?? null;
    }

    public function function(string $name): ?FunctionDefinition
    {
        return $this->functions[$name] ?? null;
    }

    /**
     * Registers (or overrides) a component and the Blade view that renders it.
     */
    public function withComponent(string $name, string $view, array $schema = [], ?array $allowedParents = null, ?array $allowedChildren = null): self
    {
        self::assertIdentifier($name, $this->id);

        $existing = $this->components[$name] ?? null;
        $components = $this->components;
        $components[$name] = new ComponentDefinition(
            $name,
            $view,
            $schema ?: ($existing?->schema ?? ['type' => 'object', 'properties' => ['component' => ['const' => $name]]]),
            $allowedParents ?? $existing?->allowedParents,
            $allowedChildren ?? $existing?->allowedChildren,
        );

        return new self($this->id, $components, $this->functions, $this->instructions, $this->protocolVersion, $this->title, $this->inline);
    }

    /**
     * Registers (or overrides) a renderer-side function implementation.
     *
     * @param  Closure(array<string, mixed>, \A2uiLivewire\Evaluation\FunctionContext): mixed  $implementation
     */
    public function withFunction(
        string $name,
        Closure $implementation,
        ?string $returnType = null,
        ?string $allowedCallers = null,
        ?bool $requiresUserActivation = null,
        array $schema = [],
        bool $rawArguments = false,
    ): self {
        self::assertIdentifier($name, $this->id);

        $existing = $this->functions[$name] ?? null;
        $functions = $this->functions;
        $functions[$name] = new FunctionDefinition(
            name: $name,
            implementation: $implementation,
            returnType: $returnType ?? $existing?->returnType ?? 'any',
            allowedCallers: $allowedCallers ?? $existing?->allowedCallers ?? FunctionDefinition::RENDERER_ONLY,
            requiresUserActivation: $requiresUserActivation ?? $existing?->requiresUserActivation ?? false,
            schema: $schema ?: ($existing?->schema ?? []),
            rawArguments: $rawArguments,
        );

        return new self($this->id, $this->components, $functions, $this->instructions, $this->protocolVersion, $this->title, $this->inline);
    }

    /**
     * Declares an agent-side function (schema only); calls are routed to the agent.
     */
    public function withAgentFunction(string $name, array $schema = [], string $returnType = 'any'): self
    {
        self::assertIdentifier($name, $this->id);

        $functions = $this->functions;
        $functions[$name] = new FunctionDefinition($name, null, $returnType, FunctionDefinition::RENDERER_ONLY, false, $schema);

        return new self($this->id, $this->components, $functions, $this->instructions, $this->protocolVersion, $this->title, $this->inline);
    }

    public function withId(string $id): self
    {
        return new self($id, $this->components, $this->functions, $this->instructions, $this->protocolVersion, $this->title, $this->inline);
    }

    public function asInline(bool $inline = true): self
    {
        return new self($this->id, $this->components, $this->functions, $this->instructions, $this->protocolVersion, $this->title, $inline);
    }

    /**
     * Serialises the catalog as a v1.0 catalog definition (for inlineCatalogs).
     */
    public function toDefinition(): array
    {
        $definition = [
            '$id' => $this->id,
            'catalogId' => $this->id,
            'protocolVersion' => $this->protocolVersion,
        ];

        if ($this->title !== null) {
            $definition['title'] = $this->title;
        }

        if ($this->instructions !== null) {
            $definition['instructions'] = $this->instructions;
        }

        $definition['components'] = array_map(static function (ComponentDefinition $component) {
            $schema = $component->schema;
            if ($component->allowedParents !== null) {
                $schema['allowedParents'] = $component->allowedParents;
            }
            if ($component->allowedChildren !== null) {
                $schema['allowedChildren'] = $component->allowedChildren;
            }

            return $schema;
        }, $this->components);

        $definition['functions'] = array_map(static fn (FunctionDefinition $function) => $function->toDefinition(), $this->functions);

        if ($definition['components'] === []) {
            $definition['components'] = new \stdClass;
        }

        if ($definition['functions'] === []) {
            $definition['functions'] = new \stdClass;
        }

        return $definition;
    }

    private static function assertIdentifier(string $name, string $catalogId): void
    {
        if (! Uax31::isIdentifier($name)) {
            throw new A2uiException(A2uiException::CATALOG_ERROR, "Catalog '{$catalogId}' entity name '{$name}' is not a valid UAX #31 identifier.");
        }
    }
}
