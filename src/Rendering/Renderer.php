<?php

namespace A2uiLivewire\Rendering;

use A2uiLivewire\Evaluation\DataContext;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\State\Surface;
use A2uiLivewire\Support\JsonPointer;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\HtmlString;
use Illuminate\Support\ViewErrorBag;
use Throwable;

/**
 * Walks a surface's adjacency list from `root` and renders each component
 * with its catalog Blade view.
 */
final class Renderer
{
    public const MAX_DEPTH = 64;

    public const MAX_TEMPLATE_ITEMS = 500;

    public function __construct(
        private readonly ViewFactory $views,
        private readonly string $placeholderView = 'a2ui::components.placeholder',
        private readonly string $unknownView = 'a2ui::components.unknown',
    ) {}

    /**
     * Renders the surface's component tree (the implicit `Surface` container
     * always has `root` as its single child).
     */
    public function renderSurface(Surface $surface, RenderSession $session): HtmlString
    {
        if (! $surface->hasRoot()) {
            return new HtmlString('');
        }

        // Flux form controls read the shared $errors bag; outside a web
        // request (or before Livewire shares it) make sure one exists.
        if (! array_key_exists('errors', $this->views->getShared())) {
            $this->views->share('errors', new ViewErrorBag);
        }

        $context = $session->evaluator->context($surface);

        return $this->renderComponent('root', $surface, $context, $session, 'Surface', []);
    }

    /**
     * @param  list<string>  $ancestors  component ids on the path from the root (cycle detection)
     */
    public function renderComponent(string $id, Surface $surface, DataContext $context, RenderSession $session, ?string $parentType, array $ancestors): HtmlString
    {
        $component = $surface->component($id);

        if ($component === null) {
            // Progressive rendering: the child has not arrived yet.
            return $this->view($this->placeholderView, ['id' => $id, 'surfaceId' => $surface->id]);
        }

        if (in_array($id, $ancestors, true)) {
            $session->report(A2uiException::validation("Component '{$id}' references itself through its descendants (cycle).", $surface->id, "/components/{$id}"));

            return new HtmlString('');
        }

        if (count($ancestors) >= self::MAX_DEPTH) {
            $session->report(A2uiException::validation('Component tree exceeds the maximum depth of '.self::MAX_DEPTH.'.', $surface->id, "/components/{$id}"));

            return new HtmlString('');
        }

        $catalogId = $component['@catalogId'] ?? $surface->catalogId;
        $catalog = $session->evaluator->catalogs->get(is_string($catalogId) ? $catalogId : null);

        if ($catalog === null) {
            $session->report(A2uiException::validation(
                $catalogId === null
                    ? "Component '{$id}' has no @catalogId and the surface declares no default catalog."
                    : "Component '{$id}' references unsupported catalog '{$catalogId}'.",
                $surface->id,
                "/components/{$id}/@catalogId",
            ));

            return new HtmlString('');
        }

        $type = (string) $component['component'];
        $definition = $catalog->component($type);

        if ($definition === null || $definition->view === null) {
            $session->report(A2uiException::validation("Unknown component type '{$type}' in catalog '{$catalog->id}'.", $surface->id, "/components/{$id}/component"));

            return $this->view($this->unknownView, ['id' => $id, 'type' => $type, 'debug' => $session->debug]);
        }

        if (! $this->compositionAllowed($definition->allowedParents, $parentType, $id, $surface, $session, A2uiException::UNALLOWED_PARENT)) {
            return new HtmlString('');
        }

        if ($parentType !== null && $parentType !== 'Surface') {
            $parentDefinition = $this->parentDefinition($ancestors, $surface, $session);
            if ($parentDefinition?->allowedChildren !== null && ! in_array($type, $parentDefinition->allowedChildren, true)) {
                $session->report(new A2uiException(A2uiException::UNALLOWED_CHILD, "Component type '{$type}' is not an allowed child of '{$parentType}'.", $surface->id, "/components/{$id}"));

                return new HtmlString('');
            }
        }

        $node = new ComponentNode($this, $session, $surface, $component, $definition, $context, $parentType, [...$ancestors, $id]);

        try {
            return $this->view($definition->view, ['node' => $node]);
        } catch (A2uiException $e) {
            $session->report($e->withSurface($surface->id));
        } catch (Throwable $e) {
            if ($session->debug) {
                throw $e;
            }
            $session->report(A2uiException::execution("Rendering component '{$id}' failed: {$e->getMessage()}", $surface->id, $e));
        }

        return new HtmlString('');
    }

    /**
     * Expands a `ChildList` (static IDs or a data-bound template).
     *
     * @return list<array{key: string, html: HtmlString}>
     */
    public function renderChildList(mixed $children, ComponentNode $parent): array
    {
        $surface = $parent->surface();
        $context = $parent->context();
        $session = $parent->session();
        $items = [];

        if (is_array($children) && array_is_list($children)) {
            foreach ($children as $childId) {
                if (! is_string($childId)) {
                    continue;
                }
                $items[] = [
                    'key' => $childId,
                    'html' => $this->renderComponent($childId, $surface, $context, $session, $parent->type(), $parent->ancestors()),
                ];
            }

            return $items;
        }

        if (! is_array($children) || ! isset($children['componentId'], $children['path']) || ! is_string($children['componentId']) || ! is_string($children['path'])) {
            return [];
        }

        $listPath = $context->resolvePath($children['path']);
        $list = JsonPointer::get($surface->dataModel, $listPath);

        if (! is_array($list)) {
            return [];
        }

        $index = 0;
        foreach ($list as $key => $_) {
            if ($index >= self::MAX_TEMPLATE_ITEMS) {
                $session->report(A2uiException::validation('List template exceeds '.self::MAX_TEMPLATE_ITEMS.' items; the rest were not rendered.', $surface->id, $listPath));
                break;
            }

            $itemPath = JsonPointer::append($listPath, (string) $key);
            $items[] = [
                'key' => $children['componentId'].'@'.$itemPath,
                'html' => $this->renderComponent($children['componentId'], $surface, $context->child($itemPath, $index), $session, $parent->type(), $parent->ancestors()),
            ];
            $index++;
        }

        return $items;
    }

    public function view(string $view, array $data): HtmlString
    {
        return new HtmlString($this->views->make($view, $data)->render());
    }

    private function compositionAllowed(?array $allowedParents, ?string $parentType, string $id, Surface $surface, RenderSession $session, string $code): bool
    {
        if ($allowedParents === null || $parentType === null || in_array($parentType, $allowedParents, true)) {
            return true;
        }

        $session->report(new A2uiException($code, "Component '{$id}' cannot be placed inside '{$parentType}'.", $surface->id, "/components/{$id}"));

        return false;
    }

    private function parentDefinition(array $ancestors, Surface $surface, RenderSession $session): ?\A2uiLivewire\Catalog\ComponentDefinition
    {
        $parentId = end($ancestors);

        if ($parentId === false) {
            return null;
        }

        $parent = $surface->component($parentId);

        if ($parent === null) {
            return null;
        }

        $catalogId = $parent['@catalogId'] ?? $surface->catalogId;

        return $session->evaluator->catalogs->get(is_string($catalogId) ? $catalogId : null)?->component((string) $parent['component']);
    }
}
