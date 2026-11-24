<?php

namespace A2uiLivewire\Protocol;

/**
 * Protocol constants and helpers for A2UI v1.0 envelopes.
 */
final class Envelope
{
    public const VERSION = '1.0';

    public const MIME_TYPE = 'application/a2ui+json';

    public const A2A_EXTENSION_URI = 'https://a2ui.org/a2a-extension/a2ui/v1.0';

    public const AGENT_TO_RENDERER = [
        'createSurface',
        'updateComponents',
        'updateDataModel',
        'deleteSurface',
        'callRendererFunction',
        'agentFunctionResponse',
    ];

    public const RENDERER_TO_AGENT = ['action', 'callAgentFunction', 'rendererFunctionResponse', 'error'];

    /** Component envelope mixin keys reserved in the `@` namespace. */
    public const COMPONENT_MIXINS = ['@catalogId', '@accessibility', '@metadata', '@checks'];

    public static function isEnvelope(array $message): bool
    {
        foreach (self::AGENT_TO_RENDERER as $key) {
            if (array_key_exists($key, $message)) {
                return true;
            }
        }

        foreach (self::RENDERER_TO_AGENT as $key) {
            if (array_key_exists($key, $message)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The message type key of an agent-to-renderer envelope, or null.
     */
    public static function type(array $message): ?string
    {
        foreach (self::AGENT_TO_RENDERER as $key) {
            if (array_key_exists($key, $message)) {
                return $key;
            }
        }

        return null;
    }

    public static function make(string $type, array $payload): array
    {
        return ['version' => self::VERSION, $type => $payload];
    }

    public static function action(string $name, string $surfaceId, string $sourceComponentId, array $context = [], ?string $userMessage = null, ?array $metadata = null): array
    {
        $action = [
            'name' => $name,
            'surfaceId' => $surfaceId,
            'sourceComponentId' => $sourceComponentId,
            'timestamp' => gmdate('Y-m-d\TH:i:s').'.'.sprintf('%03d', (int) (microtime(true) * 1000) % 1000).'Z',
            'context' => $context === [] ? new \stdClass : $context,
        ];

        if ($userMessage !== null && $userMessage !== '') {
            $action['userMessage'] = $userMessage;
        }

        if ($metadata !== null && $metadata !== []) {
            $action['metadata'] = $metadata;
        }

        return self::make('action', $action);
    }

    public static function error(array $payload): array
    {
        return self::make('error', $payload);
    }
}
