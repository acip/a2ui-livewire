<?php

namespace A2uiLivewire\Catalog;

/**
 * The catalogs this renderer supports, keyed by catalog ID.
 */
final class CatalogRegistry
{
    /** @var array<string, Catalog> */
    private array $catalogs = [];

    /** @var array<string, string> alias => catalogId */
    private array $aliases = [];

    public function register(Catalog $catalog, array $aliases = []): static
    {
        $this->catalogs[$catalog->id] = $catalog;

        foreach ($aliases as $alias) {
            $this->aliases[$alias] = $catalog->id;
        }

        return $this;
    }

    /**
     * Applies a modification to a registered catalog.
     *
     * @param  callable(Catalog): Catalog  $callback
     */
    public function extend(string $catalogId, callable $callback): static
    {
        $id = $this->aliases[$catalogId] ?? $catalogId;

        if (isset($this->catalogs[$id])) {
            $this->catalogs[$id] = $callback($this->catalogs[$id]);
        }

        return $this;
    }

    public function get(?string $catalogId): ?Catalog
    {
        if ($catalogId === null) {
            return null;
        }

        return $this->catalogs[$this->aliases[$catalogId] ?? $catalogId] ?? null;
    }

    public function has(string $catalogId): bool
    {
        return $this->get($catalogId) !== null;
    }

    /**
     * @return array<string, Catalog>
     */
    public function all(): array
    {
        return $this->catalogs;
    }

    /**
     * Catalog IDs advertised in `a2uiRendererCapabilities.supportedCatalogIds`.
     *
     * @return list<string>
     */
    public function supportedCatalogIds(): array
    {
        return array_values(array_map(
            static fn (Catalog $catalog) => $catalog->id,
            array_filter($this->catalogs, static fn (Catalog $catalog) => ! $catalog->inline),
        ));
    }

    /**
     * Definitions advertised in `a2uiRendererCapabilities.inlineCatalogs`.
     *
     * @return list<array>
     */
    public function inlineCatalogs(): array
    {
        return array_values(array_map(
            static fn (Catalog $catalog) => $catalog->toDefinition(),
            array_filter($this->catalogs, static fn (Catalog $catalog) => $catalog->inline),
        ));
    }
}
