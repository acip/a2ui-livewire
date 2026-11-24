<?php

namespace A2uiLivewire\Catalog;

/**
 * A catalog component: its JSON schema plus the Blade view that renders it.
 */
final class ComponentDefinition
{
    /**
     * Locations of child references inside a component instance, derived from
     * the schema's `ComponentId` / `Child` / `ChildList` refs. Each entry is a
     * list of keys where `*` means "every item of an array".
     *
     * @var list<array{path: list<string>, type: 'child'|'list'}>
     */
    public readonly array $childRefs;

    /** @var list<string> */
    public readonly array $required;

    /**
     * @param  array<string, mixed>  $schema
     * @param  list<string>|null  $allowedParents
     * @param  list<string>|null  $allowedChildren
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $view,
        public readonly array $schema = [],
        public readonly ?array $allowedParents = null,
        public readonly ?array $allowedChildren = null,
    ) {
        $this->childRefs = self::discoverChildRefs($schema);
        $this->required = array_values(array_diff(self::collectRequired($schema), ['component']));
    }

    /**
     * Property names declared by the schema (including those in `allOf`).
     *
     * @return array<string, array>
     */
    public function properties(): array
    {
        return self::collectProperties($this->schema);
    }

    public function isCheckable(): bool
    {
        $json = json_encode($this->schema);

        return is_string($json) && str_contains($json, '/Checkable');
    }

    /**
     * Child component IDs referenced by a component instance (static children
     * and list-template component IDs).
     *
     * @return list<string>
     */
    public function childIds(array $component): array
    {
        $ids = [];

        foreach ($this->childRefs as $ref) {
            foreach (self::valuesAt($component, $ref['path']) as $value) {
                if ($ref['type'] === 'child' && is_string($value)) {
                    $ids[] = $value;
                } elseif ($ref['type'] === 'list') {
                    if (is_array($value) && array_is_list($value)) {
                        foreach ($value as $id) {
                            if (is_string($id)) {
                                $ids[] = $id;
                            }
                        }
                    } elseif (is_array($value) && isset($value['componentId']) && is_string($value['componentId'])) {
                        $ids[] = $value['componentId'];
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<string>  $path
     * @return list<mixed>
     */
    private static function valuesAt(mixed $data, array $path): array
    {
        if ($path === []) {
            return [$data];
        }

        if (! is_array($data)) {
            return [];
        }

        $key = array_shift($path);

        if ($key === '*') {
            $out = [];
            foreach (array_is_list($data) ? $data : [] as $item) {
                array_push($out, ...self::valuesAt($item, $path));
            }

            return $out;
        }

        return array_key_exists($key, $data) ? self::valuesAt($data[$key], $path) : [];
    }

    private static function discoverChildRefs(array $schema, array $prefix = [], int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        $refs = [];

        foreach (self::collectProperties($schema) as $name => $propSchema) {
            if (! is_array($propSchema)) {
                continue;
            }

            $path = [...$prefix, $name];
            $type = self::refType($propSchema);

            if ($type !== null) {
                $refs[] = ['path' => $path, 'type' => $type];

                continue;
            }

            if (($propSchema['type'] ?? null) === 'array' && is_array($propSchema['items'] ?? null)) {
                $itemType = self::refType($propSchema['items']);
                if ($itemType === 'child') {
                    $refs[] = ['path' => $path, 'type' => 'list'];
                } else {
                    array_push($refs, ...self::discoverChildRefs($propSchema['items'], [...$path, '*'], $depth + 1));
                }
            } elseif (($propSchema['type'] ?? null) === 'object' || isset($propSchema['properties'])) {
                array_push($refs, ...self::discoverChildRefs($propSchema, $path, $depth + 1));
            }
        }

        return $refs;
    }

    private static function refType(array $schema): ?string
    {
        $ref = $schema['$ref'] ?? null;

        if (! is_string($ref)) {
            foreach ($schema['allOf'] ?? [] as $sub) {
                if (is_array($sub) && ($type = self::refType($sub)) !== null) {
                    return $type;
                }
            }

            return null;
        }

        return match (true) {
            str_ends_with($ref, '/ChildList') => 'list',
            str_ends_with($ref, '/ComponentId'), str_ends_with($ref, '/Child') => 'child',
            default => null,
        };
    }

    private static function collectProperties(array $schema): array
    {
        $props = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ($schema['allOf'] ?? [] as $sub) {
            if (is_array($sub)) {
                $props += self::collectProperties($sub);
            }
        }

        return $props;
    }

    private static function collectRequired(array $schema): array
    {
        $required = is_array($schema['required'] ?? null) ? $schema['required'] : [];

        foreach ($schema['allOf'] ?? [] as $sub) {
            if (is_array($sub)) {
                array_push($required, ...self::collectRequired($sub));
            }
        }

        return array_values(array_unique(array_filter($required, 'is_string')));
    }
}
