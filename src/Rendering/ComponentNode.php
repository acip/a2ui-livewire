<?php

namespace A2uiLivewire\Rendering;

use A2uiLivewire\Catalog\ComponentDefinition;
use A2uiLivewire\Evaluation\DataContext;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\State\Surface;
use A2uiLivewire\Support\UrlSanitizer;
use A2uiLivewire\Support\Values;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use Throwable;

/**
 * The view model handed to every component Blade view as `$node`.
 *
 * Resolves dynamic properties against the data model (reporting rather than
 * throwing on evaluation errors), renders children, and wires inputs and
 * actions to the Livewire component.
 */
final class ComponentNode
{
    /** @var list<array{valid: bool, message?: string, severity?: string, code?: string}>|null */
    private ?array $checkResults = null;

    public function __construct(
        private readonly Renderer $renderer,
        private readonly RenderSession $session,
        private readonly Surface $surface,
        private readonly array $component,
        private readonly ComponentDefinition $definition,
        private readonly DataContext $context,
        private readonly ?string $parentType,
        private readonly array $ancestors,
    ) {}

    // -- identity ------------------------------------------------------------

    public function id(): string
    {
        return $this->component['id'];
    }

    public function type(): string
    {
        return $this->definition->name;
    }

    public function surface(): Surface
    {
        return $this->surface;
    }

    public function surfaceId(): string
    {
        return $this->surface->id;
    }

    public function context(): DataContext
    {
        return $this->context;
    }

    public function session(): RenderSession
    {
        return $this->session;
    }

    public function ancestors(): array
    {
        return $this->ancestors;
    }

    public function parentType(): ?string
    {
        return $this->parentType;
    }

    public function isInside(string $type): bool
    {
        foreach (array_reverse(array_slice($this->ancestors, 0, -1)) as $ancestorId) {
            if (($this->surface->components[$ancestorId]['component'] ?? null) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * A DOM-safe id unique to this component instance (surface + scope + id).
     */
    public function domId(string $suffix = ''): string
    {
        return 'a2ui-'.substr(hash('xxh128', $this->surface->id.'|'.$this->context->basePath.'|'.$this->id()), 0, 16).($suffix !== '' ? '-'.$suffix : '');
    }

    public function key(): string
    {
        return 'a2ui:'.$this->surface->id.':'.$this->context->basePath.':'.$this->id();
    }

    // -- properties ----------------------------------------------------------

    public function has(string $prop): bool
    {
        return array_key_exists($prop, $this->component);
    }

    public function raw(string $prop, mixed $default = null): mixed
    {
        return $this->component[$prop] ?? $default;
    }

    public function isBound(string $prop): bool
    {
        return DataContext::isBinding($this->component[$prop] ?? null);
    }

    public function value(string $prop, mixed $default = null): mixed
    {
        if (! $this->has($prop)) {
            return $default;
        }

        return $this->guard(fn () => $this->context->resolve($this->component[$prop]), $default);
    }

    public function string(string $prop, string $default = ''): string
    {
        if (! $this->has($prop)) {
            return $default;
        }

        return $this->guard(fn () => $this->context->string($this->component[$prop]), $default);
    }

    public function number(string $prop, int|float|null $default = null): int|float|null
    {
        if (! $this->has($prop)) {
            return $default;
        }

        return $this->guard(fn () => $this->context->number($this->component[$prop]), $default) ?? $default;
    }

    public function bool(string $prop, bool $default = false): bool
    {
        if (! $this->has($prop)) {
            return $default;
        }

        return $this->guard(fn () => $this->context->boolean($this->component[$prop]), $default);
    }

    /**
     * @return list<string>
     */
    public function stringList(string $prop): array
    {
        if (! $this->has($prop)) {
            return [];
        }

        return $this->guard(fn () => $this->context->stringList($this->component[$prop]), []);
    }

    /**
     * An enum property, falling back to `$default` for unknown values.
     *
     * @param  list<string>  $allowed
     */
    public function enum(string $prop, array $allowed, string $default): string
    {
        $value = $this->component[$prop] ?? $default;

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Resolves a nested dynamic value (e.g. `tabs[i].title`).
     */
    public function resolve(mixed $value, mixed $default = null): mixed
    {
        return $this->guard(fn () => $this->context->resolve($value), $default);
    }

    public function resolveString(mixed $value, string $default = ''): string
    {
        return $this->guard(fn () => $this->context->string($value), $default);
    }

    // -- children ------------------------------------------------------------

    public function child(string $prop = 'child'): HtmlString
    {
        $id = $this->component[$prop] ?? null;

        return is_string($id) ? $this->renderChild($id) : new HtmlString('');
    }

    public function renderChild(string $id): HtmlString
    {
        return $this->renderer->renderComponent($id, $this->surface, $this->context, $this->session, $this->type(), $this->ancestors);
    }

    /**
     * @return list<array{key: string, html: HtmlString}>
     */
    public function children(string $prop = 'children'): array
    {
        return $this->renderer->renderChildList($this->component[$prop] ?? [], $this);
    }

    // -- layout & accessibility ----------------------------------------------

    /**
     * Inline style for `weight` (flex-grow), honoured only for direct
     * children of Row and Column.
     */
    public function weightStyle(): ?string
    {
        $weight = $this->component['weight'] ?? null;

        if (! is_int($weight) && ! is_float($weight)) {
            return null;
        }

        if (! in_array($this->parentType, ['Row', 'Column'], true)) {
            return null;
        }

        $weight = max(0, (float) $weight);

        return 'flex: '.Values::formatFloat($weight).' 1 0%; min-width: 0;';
    }

    /**
     * WAI-ARIA attributes from `@accessibility`, overriding inferred defaults.
     *
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public function aria(array $defaults = []): array
    {
        $a11y = $this->component['@accessibility'] ?? null;
        $attributes = $defaults;

        if (! is_array($a11y)) {
            return $attributes;
        }

        if (array_key_exists('label', $a11y)) {
            $label = $this->resolveString($a11y['label']);
            if ($label !== '') {
                $attributes['aria-label'] = $label;
            }
        }

        if (array_key_exists('description', $a11y)) {
            $description = $this->resolveString($a11y['description']);
            if ($description !== '') {
                $attributes['aria-description'] = $description;
                $attributes['title'] ??= $description;
            }
        }

        if (in_array($a11y['live'] ?? null, ['polite', 'assertive'], true)) {
            $attributes['aria-live'] = $a11y['live'];
        }

        if (array_key_exists('hidden', $a11y) && $this->guard(fn () => $this->context->boolean($a11y['hidden']), false)) {
            $attributes['aria-hidden'] = 'true';
        }

        return $attributes;
    }

    /**
     * The accessible label: `@accessibility.label`, else the given fallback.
     */
    public function accessibleLabel(string $fallback = ''): string
    {
        return $this->aria()['aria-label'] ?? $fallback;
    }

    /**
     * Root element attributes: morph key, data attributes, ARIA and weight.
     */
    public function attributes(array $extra = [], bool $withAria = true): ComponentAttributeBag
    {
        $attributes = [
            'wire:key' => $this->key(),
            'data-a2ui-component' => $this->type(),
            'data-a2ui-id' => $this->id(),
            ...($withAria ? $this->aria() : []),
        ];

        $bag = new ComponentAttributeBag($attributes);

        if (($style = $this->weightStyle()) !== null) {
            $bag = $bag->merge(['style' => $style]);
        }

        return $bag->merge($extra);
    }

    // -- inputs --------------------------------------------------------------

    /**
     * Registers a two-way binding for `$prop` and returns the Livewire model
     * expression (`a2uiInputs.<token>`).
     *
     * A literal (unbound) value gets a local-only slot: edits stay in the
     * browser state but are never written to the data model.
     */
    public function model(string $prop = 'value', string $kind = 'text'): string
    {
        $raw = $this->component[$prop] ?? null;
        $pointer = DataContext::isBinding($raw) ? $this->context->resolvePath($raw['@path']) : null;
        $value = $this->inputValue($prop, $kind);
        $token = $this->session->registerInput($this->surface->id, $pointer, $this->key().'#'.$prop, $kind, $value);

        return $this->session->inputsProperty.'.'.$token;
    }

    public function wireModel(string $prop = 'value', string $kind = 'text'): string
    {
        $modifiers = in_array($kind, ['text', 'number', 'slider'], true)
            ? $this->session->textModelModifiers
            : $this->session->choiceModelModifiers;

        return 'wire:model'.$modifiers;
    }

    /**
     * Attribute bag for an input control: the wire:model binding, ARIA
     * attributes from `@accessibility`, and any extra attributes.
     */
    public function inputAttributes(string $prop = 'value', string $kind = 'text', array $extra = []): ComponentAttributeBag
    {
        $attributes = [$this->wireModel($prop, $kind) => $this->model($prop, $kind), ...$this->aria()];

        if (! $this->isValid() && $this->showValidation($prop)) {
            $attributes['aria-invalid'] = 'true';
        }

        return (new ComponentAttributeBag($attributes))->merge($extra);
    }

    private function inputValue(string $prop, string $kind): mixed
    {
        $value = $this->value($prop);

        return match ($kind) {
            'bool' => Values::toBoolean($value),
            'choice-multi' => Values::toStringList($value),
            'choice-single' => Values::toStringList($value)[0] ?? '',
            'slider' => Values::toNumber($value) ?? ($this->number('min') ?? 0),
            'date', 'time', 'datetime' => DateTimeValues::toInput(Values::toString($value), $kind),
            default => Values::toString($value),
        };
    }

    // -- validation checks ---------------------------------------------------

    /**
     * Evaluates `@checks` and returns every rule result.
     *
     * @return list<array{valid: bool, message?: string, severity?: string, code?: string}>
     */
    public function checks(): array
    {
        if ($this->checkResults !== null) {
            return $this->checkResults;
        }

        $results = [];

        foreach ($this->component['@checks'] ?? [] as $rule) {
            if (! is_array($rule) || ! array_key_exists('condition', $rule)) {
                continue;
            }

            $message = is_string($rule['message'] ?? null) ? $rule['message'] : null;

            try {
                $value = $this->context->resolve($rule['condition']);
                $results[] = Values::toValidationResult($value, $message, ! DataContext::isBinding($rule['condition']));
            } catch (A2uiException $e) {
                $this->session->report($e->withSurface($this->surface->id));
                $results[] = ['valid' => false, 'message' => $message ?? $e->getMessage(), 'severity' => 'error'];
            }
        }

        return $this->checkResults = $results;
    }

    /**
     * @return list<array{valid: bool, message?: string, severity?: string, code?: string}>
     */
    public function failedChecks(): array
    {
        return array_values(array_filter($this->checks(), static fn (array $result) => ! $result['valid']));
    }

    public function isValid(): bool
    {
        foreach ($this->failedChecks() as $result) {
            if (($result['severity'] ?? 'error') === 'error') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether validation messages should be shown: after the user edited the
     * bound value, or after an action was attempted on the surface.
     */
    public function showValidation(string $prop = 'value'): bool
    {
        if ($this->surface->submitted) {
            return true;
        }

        $raw = $this->component[$prop] ?? null;

        if (! DataContext::isBinding($raw)) {
            return false;
        }

        return $this->surface->isTouched($this->context->resolvePath($raw['@path']));
    }

    /**
     * The first visible validation message, if any.
     */
    public function validationMessage(string $prop = 'value'): ?string
    {
        if (! $this->showValidation($prop)) {
            return null;
        }

        foreach ($this->failedChecks() as $result) {
            if (! empty($result['message'])) {
                return $result['message'];
            }
        }

        return null;
    }

    // -- actions -------------------------------------------------------------

    /**
     * Registers the component's action and returns the Livewire call
     * expression for `wire:click`, or null when there is no action.
     */
    public function actionCall(string $prop = 'action'): ?string
    {
        $action = $this->component[$prop] ?? null;

        if (! is_array($action) || (! isset($action['event']) && ! isset($action['functionCall']))) {
            return null;
        }

        $token = $this->session->registerAction($this->surface->id, $this->id(), $this->context->basePath, $this->context->indexes, $prop);

        return $this->session->actionMethod."('{$token}')";
    }

    public function actionName(string $prop = 'action'): ?string
    {
        $name = $this->component[$prop]['event']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    // -- content helpers -----------------------------------------------------

    public function markdown(string $text, bool $inline = false): HtmlString
    {
        return Markdown::render($text, $inline);
    }

    public function mediaUrl(string $prop = 'url'): ?string
    {
        return UrlSanitizer::media($this->value($prop));
    }

    /**
     * Resolves the Icon `name` property.
     *
     * @return array{type: 'flux', name: string, variant: string}|array{type: 'svg', path: string}|array{type: 'none'}
     */
    public function icon(string $prop = 'name'): array
    {
        $raw = $this->component[$prop] ?? null;

        if (is_array($raw) && array_key_exists('svgPath', $raw)) {
            $path = $this->resolveString($raw['svgPath']);

            return IconMap::isSafeSvgPath($path) ? ['type' => 'svg', 'path' => $path] : ['type' => 'none'];
        }

        $name = $this->string($prop);

        if ($name === '') {
            return ['type' => 'none'];
        }

        return ['type' => 'named', 'name' => $name];
    }

    /**
     * Inner SVG markup (24x24 viewBox) for the Icon `name`, from the bundled
     * Material icons, or a custom `svgPath`. Null when there is no icon.
     */
    public function iconSvg(string $prop = 'name'): ?string
    {
        $icon = $this->icon($prop);

        return match ($icon['type']) {
            'svg' => '<path d="'.e($icon['path']).'"/>',
            'named' => IconMap::material($icon['name']),
            default => null,
        };
    }

    /**
     * Runs a resolver, reporting evaluation errors and returning `$default`.
     */
    private function guard(callable $resolver, mixed $default): mixed
    {
        try {
            return $resolver();
        } catch (A2uiException $e) {
            $this->session->report($e->withSurface($this->surface->id));
        } catch (Throwable $e) {
            $this->session->report(A2uiException::execution($e->getMessage(), $this->surface->id, $e));
        }

        return $default;
    }
}
