<?php

namespace A2uiLivewire\Processing;

use A2uiLivewire\Catalog\CatalogRegistry;
use A2uiLivewire\Evaluation\Evaluator;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Protocol\Envelope;
use A2uiLivewire\State\Surface;
use A2uiLivewire\State\SurfaceGroup;
use A2uiLivewire\Support\Json;
use A2uiLivewire\Support\JsonPointer;
use A2uiLivewire\Support\Uax31;
use A2uiLivewire\Support\Values;

/**
 * Applies agent-to-renderer messages to a {@see SurfaceGroup}.
 *
 * Messages are processed sequentially; a message that fails validation is
 * reported and skipped while the remaining messages are still applied
 * (spec: A2A "Processing Rules").
 */
final class MessageProcessor
{
    /**
     * @param  array{strict?: bool, locale?: string, openUrlSchemes?: list<string>, baseUrl?: ?string}  $options
     */
    public function __construct(
        private readonly CatalogRegistry $catalogs,
        private readonly array $options = [],
    ) {}

    /**
     * @param  iterable<mixed>  $messages
     * @param  bool  $trusted  false for messages that came from the browser;
     *                         untrusted batches may not invoke renderer functions.
     */
    public function process(SurfaceGroup $group, iterable $messages, bool $trusted = true): ProcessResult
    {
        $result = new ProcessResult;

        foreach ($messages as $index => $message) {
            try {
                $this->processOne($group, $message, $trusted, $result);
                $result->processed++;
            } catch (A2uiException $e) {
                $result->errors[] = $e;
                $result->outbound[] = Envelope::error($e->toErrorPayload());
            }
        }

        $result->created = array_values(array_unique($result->created));
        $result->updated = array_values(array_unique($result->updated));
        $result->deleted = array_values(array_unique($result->deleted));

        return $result;
    }

    private function processOne(SurfaceGroup $group, mixed $message, bool $trusted, ProcessResult $result): void
    {
        if (! is_array($message) || array_is_list($message)) {
            throw A2uiException::validation('Each A2UI message must be a JSON object.');
        }

        $type = $this->validateEnvelope($message);
        $payload = $message[$type];

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw A2uiException::validation("'{$type}' must be an object.", null, "/{$type}");
        }

        match ($type) {
            'createSurface' => $this->createSurface($group, $payload, $result),
            'updateComponents' => $this->updateComponents($group, $payload, $result),
            'updateDataModel' => $this->updateDataModel($group, $payload, $result),
            'deleteSurface' => $this->deleteSurface($group, $payload, $result),
            'callRendererFunction' => $this->callRendererFunction($group, $payload, $trusted, $result),
            'agentFunctionResponse' => $this->agentFunctionResponse($group, $payload, $result),
        };
    }

    private function validateEnvelope(array $message): string
    {
        $version = $message['version'] ?? null;

        if ($version !== Envelope::VERSION) {
            $shown = is_scalar($version) ? (string) $version : 'missing';

            throw A2uiException::validation("Unsupported A2UI protocol version '{$shown}'; this renderer accepts version '1.0'.", $this->surfaceIdOf($message), '/version');
        }

        $types = array_values(array_intersect(Envelope::AGENT_TO_RENDERER, array_keys($message)));
        $extra = array_diff(array_keys($message), ['version', ...Envelope::AGENT_TO_RENDERER]);

        if (count($types) !== 1 || $extra !== []) {
            throw A2uiException::validation('An A2UI message must contain "version" and exactly one of: '.implode(', ', Envelope::AGENT_TO_RENDERER).'.', $this->surfaceIdOf($message));
        }

        return $types[0];
    }

    private function surfaceIdOf(array $message): ?string
    {
        foreach (Envelope::AGENT_TO_RENDERER as $type) {
            if (isset($message[$type]['surfaceId']) && is_string($message[$type]['surfaceId'])) {
                return $message[$type]['surfaceId'];
            }
        }

        return null;
    }

    // -- createSurface -------------------------------------------------------

    private function createSurface(SurfaceGroup $group, array $payload, ProcessResult $result): void
    {
        $this->assertKeys($payload, ['surfaceId', 'catalogId', 'sendDataModel', 'components', 'dataModel', 'metadata'], 'createSurface', $payload['surfaceId'] ?? null);
        $surfaceId = $this->requireSurfaceId($payload, 'createSurface');

        if ($group->has($surfaceId)) {
            throw A2uiException::integrity("Surface '{$surfaceId}' already exists; delete it before creating it again.", $surfaceId);
        }

        $catalogId = $payload['catalogId'] ?? null;
        if ($catalogId !== null && (! is_string($catalogId) || $catalogId === '')) {
            throw A2uiException::validation('createSurface.catalogId must be a non-empty string.', $surfaceId, '/createSurface/catalogId');
        }

        if (array_key_exists('sendDataModel', $payload) && ! is_bool($payload['sendDataModel'])) {
            throw A2uiException::validation('createSurface.sendDataModel must be a boolean.', $surfaceId, '/createSurface/sendDataModel');
        }

        if (array_key_exists('dataModel', $payload) && ! Values::isObject($payload['dataModel'])) {
            throw A2uiException::validation('createSurface.dataModel must be an object.', $surfaceId, '/createSurface/dataModel');
        }

        $components = [];
        if (array_key_exists('components', $payload)) {
            $components = $this->validateComponents($payload['components'], $surfaceId, $catalogId, '/createSurface/components', $result);
        }

        $surface = new Surface(
            id: $surfaceId,
            catalogId: $catalogId,
            sendDataModel: (bool) ($payload['sendDataModel'] ?? false),
            metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
        );

        if ($catalogId !== null && ! $this->catalogs->has($catalogId)) {
            $result->errors[] = new A2uiException(A2uiException::VALIDATION_FAILED, "Catalog '{$catalogId}' is not supported by this renderer.", $surfaceId, '/createSurface/catalogId');
        }

        // The initial data model is applied before components so bindings
        // resolve against it on first render.
        if (isset($payload['dataModel'])) {
            $surface->dataModel = $payload['dataModel'];
        }

        if ($components !== []) {
            $surface->upsertComponents($components);
        }

        $group->add($surface);
        $result->created[] = $surfaceId;
    }

    // -- updateComponents ----------------------------------------------------

    private function updateComponents(SurfaceGroup $group, array $payload, ProcessResult $result): void
    {
        $this->assertKeys($payload, ['surfaceId', 'components'], 'updateComponents', $payload['surfaceId'] ?? null);
        $surface = $this->requireSurface($group, $payload, 'updateComponents');

        if (! array_key_exists('components', $payload)) {
            throw A2uiException::validation('updateComponents.components is required.', $surface->id, '/updateComponents/components');
        }

        $components = $this->validateComponents($payload['components'], $surface->id, $surface->catalogId, '/updateComponents/components', $result);
        $surface->upsertComponents($components);
        $result->updated[] = $surface->id;
    }

    /**
     * @return list<array>
     */
    private function validateComponents(mixed $components, string $surfaceId, ?string $catalogId, string $path, ProcessResult $result): array
    {
        if (! is_array($components) || ! array_is_list($components) || $components === []) {
            throw A2uiException::validation('components must be a non-empty array.', $surfaceId, $path);
        }

        $valid = [];

        foreach ($components as $i => $component) {
            $componentPath = "{$path}/{$i}";

            if (! is_array($component) || array_is_list($component)) {
                throw A2uiException::validation('Each component must be an object.', $surfaceId, $componentPath);
            }

            $id = $component['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw A2uiException::validation('Component is missing a string "id".', $surfaceId, "{$componentPath}/id");
            }

            $type = $component['component'] ?? null;
            if (! is_string($type) || $type === '') {
                throw A2uiException::validation("Component '{$id}' is missing a string \"component\" type.", $surfaceId, "{$componentPath}/component");
            }

            if ($type === 'Surface') {
                throw A2uiException::validation("Component '{$id}' uses the reserved type 'Surface', which cannot be modified with updateComponents.", $surfaceId, "{$componentPath}/component");
            }

            if ($this->strict() && ! Uax31::isIdentifier($id)) {
                throw A2uiException::validation("Component id '{$id}' is not a valid UAX #31 identifier.", $surfaceId, "{$componentPath}/id");
            }

            $component = $this->normaliseMixins($component, $surfaceId, $componentPath, $catalogId);
            $this->checkAgainstCatalog($component, $surfaceId, $catalogId, $componentPath, $result);
            $valid[] = $component;
        }

        return $valid;
    }

    /**
     * Validates `@`-prefixed keys and upgrades legacy bare mixin keys
     * (`checks`, `accessibility`, `metadata`, `catalogId`) when the component
     * schema does not declare them (spec: "Component envelope mixin keys").
     */
    private function normaliseMixins(array $component, string $surfaceId, string $path, ?string $surfaceCatalogId): array
    {
        foreach (array_keys($component) as $key) {
            if (is_string($key) && str_starts_with($key, '@') && ! in_array($key, Envelope::COMPONENT_MIXINS, true)) {
                throw A2uiException::validation("Component '{$component['id']}' uses the reserved key '{$key}'.", $surfaceId, "{$path}/{$key}");
            }
        }

        $catalog = $this->catalogs->get($component['@catalogId'] ?? $surfaceCatalogId);
        $declared = $catalog?->component($component['component'])?->properties() ?? [];

        foreach (['checks', 'accessibility', 'metadata', 'catalogId'] as $legacy) {
            if (! array_key_exists($legacy, $component) || array_key_exists($legacy, $declared)) {
                continue;
            }

            if (array_key_exists('@'.$legacy, $component)) {
                throw A2uiException::validation("Component '{$component['id']}' carries both '{$legacy}' and '@{$legacy}'.", $surfaceId, "{$path}/{$legacy}");
            }

            $component['@'.$legacy] = $component[$legacy];
            unset($component[$legacy]);
        }

        if (isset($component['@checks']) && (! is_array($component['@checks']) || ! array_is_list($component['@checks']))) {
            throw A2uiException::validation("Component '{$component['id']}' @checks must be an array.", $surfaceId, "{$path}/@checks");
        }

        return $component;
    }

    /**
     * Lightweight catalog validation: known component type, required
     * properties and enum values. Issues are reported; in strict mode they
     * reject the message.
     */
    private function checkAgainstCatalog(array $component, string $surfaceId, ?string $surfaceCatalogId, string $path, ProcessResult $result): void
    {
        $catalogId = $component['@catalogId'] ?? $surfaceCatalogId;
        $catalog = $this->catalogs->get($catalogId);

        if ($catalog === null) {
            return;
        }

        $definition = $catalog->component($component['component']);
        $issues = [];

        if ($definition === null) {
            $issues[] = ["Unknown component type '{$component['component']}' in catalog '{$catalog->id}'.", "{$path}/component"];
        } else {
            foreach ($definition->required as $property) {
                if (! array_key_exists($property, $component)) {
                    $issues[] = ["Component '{$component['id']}' ({$component['component']}) is missing required property '{$property}'.", "{$path}/{$property}"];
                }
            }

            foreach ($definition->properties() as $property => $schema) {
                if (isset($schema['enum'], $component[$property]) && is_string($component[$property]) && ! in_array($component[$property], $schema['enum'], true)) {
                    $issues[] = ["Component '{$component['id']}' property '{$property}' must be one of: ".implode(', ', $schema['enum']).'.', "{$path}/{$property}"];
                }
            }
        }

        foreach ($issues as [$message, $issuePath]) {
            $error = A2uiException::validation($message, $surfaceId, $issuePath);

            if ($this->strict()) {
                throw $error;
            }

            $result->errors[] = $error;
            $result->outbound[] = Envelope::error($error->toErrorPayload());
        }
    }

    // -- updateDataModel -----------------------------------------------------

    private function updateDataModel(SurfaceGroup $group, array $payload, ProcessResult $result): void
    {
        $this->assertKeys($payload, ['surfaceId', 'path', 'value'], 'updateDataModel', $payload['surfaceId'] ?? null);
        $surface = $this->requireSurface($group, $payload, 'updateDataModel');

        if (! array_key_exists('value', $payload)) {
            if ($this->strict()) {
                throw A2uiException::validation('updateDataModel.value is required (use null to delete).', $surface->id, '/updateDataModel/value');
            }

            // Streaming agents (e.g. the official @a2ui/agent SDK) emit growing
            // snapshots of each message; the first snapshot of an update can
            // arrive before its value. Nothing to apply yet.
            return;
        }

        $path = $payload['path'] ?? '/';
        if (! is_string($path)) {
            throw A2uiException::validation('updateDataModel.path must be a string.', $surface->id, '/updateDataModel/path');
        }

        if ($path !== '' && ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $value = $payload['value'];

        if (JsonPointer::segments($path) === [] && $value !== null && ! Values::isObject($value)) {
            throw A2uiException::validation('Replacing the whole data model requires an object value.', $surface->id, '/updateDataModel/value');
        }

        try {
            $surface->setData($path, $value);
        } catch (A2uiException $e) {
            throw A2uiException::validation($e->getMessage(), $surface->id, '/updateDataModel/path');
        }

        $result->updated[] = $surface->id;
    }

    // -- deleteSurface -------------------------------------------------------

    private function deleteSurface(SurfaceGroup $group, array $payload, ProcessResult $result): void
    {
        $this->assertKeys($payload, ['surfaceId'], 'deleteSurface', $payload['surfaceId'] ?? null);
        $surface = $this->requireSurface($group, $payload, 'deleteSurface');
        $group->remove($surface->id);
        $result->deleted[] = $surface->id;
    }

    // -- callRendererFunction ------------------------------------------------

    private function callRendererFunction(SurfaceGroup $group, array $payload, bool $trusted, ProcessResult $result): void
    {
        $callId = $payload['functionCallId'] ?? null;

        if (! is_string($callId) || $callId === '') {
            throw A2uiException::invalidFunctionCall('Malformed callRendererFunction payload: functionCallId is required.');
        }

        $respond = static function (array $body) use ($callId, $result): void {
            $result->outbound[] = Envelope::make('rendererFunctionResponse', ['functionCallId' => $callId, ...$body]);
        };

        $fail = static function (string $code, string $message) use ($respond): void {
            $respond(['error' => ['code' => $code, 'message' => $message]]);
        };

        $call = $payload['callFunction'] ?? null;

        if (! is_array($call) || ! is_string($call['@call'] ?? null)) {
            $fail(A2uiException::INVALID_FUNCTION_CALL, 'callFunction with an "@call" name is required.');

            return;
        }

        if (! $trusted && ! ($this->options['allowUntrustedFunctionCalls'] ?? false)) {
            $fail(A2uiException::INVALID_FUNCTION_CALL, 'Renderer functions cannot be invoked from client-supplied messages.');

            return;
        }

        $name = $call['@call'];
        $catalogId = $call['catalogId'] ?? null;

        if (! is_string($catalogId) || $catalogId === '') {
            $fail(A2uiException::INVALID_FUNCTION_CALL, 'callRendererFunction.callFunction.catalogId is required.');

            return;
        }

        $catalog = $this->catalogs->get($catalogId);

        if ($catalog === null) {
            $fail(A2uiException::INVALID_FUNCTION_CALL, "Catalog '{$catalogId}' is not registered with this renderer.");

            return;
        }

        $definition = $catalog->function($name);

        if ($definition === null || ! $definition->isLocal()) {
            $fail(A2uiException::INVALID_FUNCTION_CALL, "Function '{$name}' is not registered with this renderer.");

            return;
        }

        if (! $definition->agentMayCall()) {
            $fail(A2uiException::INVALID_FUNCTION_CALL, "Function '{$name}' cannot be called by agent (allowedCallers is {$definition->allowedCallers}).");

            return;
        }

        if ($definition->requiresUserActivation) {
            $fail(A2uiException::INVALID_FUNCTION_CALL, "Function '{$name}' requires user activation.");

            return;
        }

        $evaluator = new Evaluator($this->catalogs, $group, $this->options);
        $surfaceId = $payload['surfaceId'] ?? null;

        if ($surfaceId !== null) {
            $surface = is_string($surfaceId) ? $group->get($surfaceId) : null;

            if ($surface === null) {
                $fail(A2uiException::INVALID_FUNCTION_CALL, "No active surface '".Values::toString($surfaceId)."'.");

                return;
            }

            $context = $evaluator->context($surface);
        } else {
            $context = $evaluator->detachedContext();
        }

        try {
            $args = $call['args'] ?? [];
            $value = $context->invokeLocal($catalog, $definition, is_array($args) ? $args : []);
            $respond(['value' => $value]);
        } catch (A2uiException $e) {
            $code = $e->errorCode === A2uiException::EXPRESSION_ERROR && str_starts_with($e->getMessage(), '@index')
                ? A2uiException::EXECUTION_ERROR
                : $e->errorCode;
            $fail($code === A2uiException::VALIDATION_FAILED ? A2uiException::INVALID_FUNCTION_CALL : $code, $e->getMessage());
        }

        array_push($result->effects, ...$evaluator->effects());
        foreach ($evaluator->errors() as $error) {
            $result->errors[] = $error;
        }
    }

    // -- agentFunctionResponse -----------------------------------------------

    private function agentFunctionResponse(SurfaceGroup $group, array $payload, ProcessResult $result): void
    {
        $callId = $payload['functionCallId'] ?? null;

        if (! is_string($callId) || ! isset($group->agentCalls[$callId])) {
            throw A2uiException::validation('agentFunctionResponse references an unknown functionCallId.', null, '/agentFunctionResponse/functionCallId');
        }

        $origin = $group->agentCalls[$callId];
        unset($group->agentCalls[$callId]);

        $hasValue = array_key_exists('value', $payload);
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : null;

        if (! $hasValue && $error === null) {
            throw A2uiException::validation('agentFunctionResponse must include either value or error.', $origin['surfaceId'], '/agentFunctionResponse');
        }

        $surface = $origin['surfaceId'] !== null ? $group->get($origin['surfaceId']) : null;

        if ($origin['key'] !== null && $surface !== null) {
            $surface->functionCache[$origin['key']] = $error !== null
                ? ['state' => 'error', 'error' => $error, 'callId' => $callId]
                : ['state' => 'done', 'value' => $payload['value'], 'callId' => $callId];
            $surface->revision++;
            $result->updated[] = $surface->id;
        }

        if ($error !== null) {
            $result->errors[] = new A2uiException(
                A2uiException::EXPRESSION_ERROR,
                'Agent function failed: '.Values::toString($error['message'] ?? $error['code'] ?? 'unknown error'),
                null,
                null,
                $callId,
            );
        }
    }

    // -- helpers -------------------------------------------------------------

    private function requireSurfaceId(array $payload, string $type): string
    {
        $surfaceId = $payload['surfaceId'] ?? null;

        if (! is_string($surfaceId) || $surfaceId === '') {
            throw A2uiException::validation("{$type}.surfaceId must be a non-empty string.", null, "/{$type}/surfaceId");
        }

        return $surfaceId;
    }

    private function requireSurface(SurfaceGroup $group, array $payload, string $type): Surface
    {
        $surfaceId = $this->requireSurfaceId($payload, $type);
        $surface = $group->get($surfaceId);

        if ($surface === null) {
            throw A2uiException::integrity("{$type} references surface '{$surfaceId}', which has not been created.", $surfaceId);
        }

        return $surface;
    }

    private function assertKeys(array $payload, array $allowed, string $type, mixed $surfaceId): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);

        if ($unknown === []) {
            return;
        }

        $surfaceId = is_string($surfaceId) ? $surfaceId : null;

        if ($type === 'createSurface' && in_array('theme', $unknown, true)) {
            $error = A2uiException::validation('createSurface.theme is not part of A2UI v1.0; styling is controlled by the renderer.', $surfaceId, '/createSurface/theme');
        } else {
            $error = A2uiException::validation("{$type} contains unknown properties: ".implode(', ', $unknown).'.', $surfaceId, "/{$type}");
        }

        if ($this->strict()) {
            throw $error;
        }
    }

    private function strict(): bool
    {
        return (bool) ($this->options['strict'] ?? false);
    }

    /**
     * Encodes a value for logging.
     */
    public static function describe(mixed $value): string
    {
        return Json::encode($value);
    }
}
