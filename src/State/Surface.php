<?php

namespace A2uiLivewire\State;

use A2uiLivewire\Support\JsonPointer;

/**
 * The state of one A2UI surface: its flat component map (adjacency list),
 * data model and renderer-side bookkeeping.
 */
final class Surface
{
    /**
     * @param  array<string, array>  $components  component id => component definition
     * @param  array<string, mixed>  $dataModel
     * @param  array<string, array{state: string, value?: mixed, error?: array, callId?: string}>  $functionCache
     * @param  array<string, true>  $touched  pointers the user has edited
     */
    public function __construct(
        public readonly string $id,
        public ?string $catalogId = null,
        public bool $sendDataModel = false,
        public array $components = [],
        public mixed $dataModel = [],
        public ?array $metadata = null,
        public array $functionCache = [],
        public array $touched = [],
        public bool $submitted = false,
        public int $revision = 0,
    ) {}

    public function hasRoot(): bool
    {
        return isset($this->components['root']);
    }

    public function component(string $id): ?array
    {
        return $this->components[$id] ?? null;
    }

    /**
     * Adds or replaces components (a replacement is a full replacement,
     * including `@metadata`).
     *
     * @param  list<array>  $components
     */
    public function upsertComponents(array $components): void
    {
        foreach ($components as $component) {
            $this->components[$component['id']] = $component;
        }

        $this->revision++;
    }

    public function getData(string $path = '/', mixed $default = null): mixed
    {
        return JsonPointer::get($this->dataModel, $path, $default);
    }

    /**
     * Upsert semantics: `null` deletes the key, `/` replaces the whole model.
     */
    public function setData(string $path, mixed $value): void
    {
        $segments = JsonPointer::segments($path);

        if ($segments === []) {
            $this->dataModel = is_array($value) ? $value : [];
        } elseif ($value === null) {
            $this->dataModel = JsonPointer::remove($this->dataModel, $path);
        } else {
            $this->dataModel = JsonPointer::set($this->dataModel, $path, $value);
        }

        // Cached agent function results may depend on the data model.
        $this->functionCache = array_filter($this->functionCache, static fn (array $entry) => $entry['state'] === 'pending');
        $this->revision++;
    }

    public function touch(string $pointer): void
    {
        $this->touched[$pointer] = true;
    }

    public function isTouched(string $pointer): bool
    {
        return $this->submitted || isset($this->touched[$pointer]);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'catalogId' => $this->catalogId,
            'sendDataModel' => $this->sendDataModel,
            'components' => $this->components,
            'dataModel' => $this->dataModel,
            'metadata' => $this->metadata,
            'functionCache' => $this->functionCache,
            'touched' => $this->touched,
            'submitted' => $this->submitted,
            'revision' => $this->revision,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            catalogId: $data['catalogId'] ?? null,
            sendDataModel: (bool) ($data['sendDataModel'] ?? false),
            components: (array) ($data['components'] ?? []),
            dataModel: $data['dataModel'] ?? [],
            metadata: $data['metadata'] ?? null,
            functionCache: (array) ($data['functionCache'] ?? []),
            touched: (array) ($data['touched'] ?? []),
            submitted: (bool) ($data['submitted'] ?? false),
            revision: (int) ($data['revision'] ?? 0),
        );
    }
}
