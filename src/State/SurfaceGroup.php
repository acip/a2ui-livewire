<?php

namespace A2uiLivewire\State;

use A2uiLivewire\Support\Json;
use Livewire\Wireable;

/**
 * All surfaces owned by one renderer instance, plus the in-flight
 * renderer-to-agent function calls and the transport session.
 */
final class SurfaceGroup implements Wireable
{
    /**
     * @param  array<string, Surface>  $surfaces
     * @param  array<string, array{surfaceId: ?string, key: ?string}>  $agentCalls  functionCallId => origin
     * @param  list<array>  $outbox  renderer-to-agent messages waiting to be sent
     * @param  array<string, mixed>  $session  transport session state (e.g. A2A contextId)
     */
    public function __construct(
        public array $surfaces = [],
        public array $agentCalls = [],
        public array $outbox = [],
        public array $session = [],
        public int $sequence = 0,
    ) {}

    public function get(string $surfaceId): ?Surface
    {
        return $this->surfaces[$surfaceId] ?? null;
    }

    public function has(string $surfaceId): bool
    {
        return isset($this->surfaces[$surfaceId]);
    }

    public function add(Surface $surface): void
    {
        $this->surfaces[$surface->id] = $surface;
    }

    public function remove(string $surfaceId): void
    {
        unset($this->surfaces[$surfaceId]);

        foreach ($this->agentCalls as $callId => $origin) {
            if ($origin['surfaceId'] === $surfaceId) {
                unset($this->agentCalls[$callId]);
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->surfaces === [];
    }

    public function nextId(string $prefix): string
    {
        $this->sequence++;

        return $prefix.'_'.bin2hex(random_bytes(6)).'_'.$this->sequence;
    }

    /**
     * Data models of every surface created with `sendDataModel: true`
     * (`a2uiRendererDataModel` transport metadata), or null when none.
     */
    public function rendererDataModel(): ?array
    {
        $models = [];

        foreach ($this->surfaces as $surface) {
            if ($surface->sendDataModel) {
                $models[$surface->id] = Json::object(is_array($surface->dataModel) ? $surface->dataModel : []);
            }
        }

        return $models === [] ? null : ['version' => '1.0', 'surfaces' => $models];
    }

    public function toArray(): array
    {
        return [
            'surfaces' => array_map(static fn (Surface $surface) => $surface->toArray(), $this->surfaces),
            'agentCalls' => $this->agentCalls,
            'outbox' => $this->outbox,
            'session' => $this->session,
            'sequence' => $this->sequence,
        ];
    }

    public static function fromArray(array $data): self
    {
        $surfaces = [];
        foreach ($data['surfaces'] ?? [] as $id => $surface) {
            $surfaces[(string) $id] = Surface::fromArray($surface);
        }

        return new self(
            surfaces: $surfaces,
            agentCalls: (array) ($data['agentCalls'] ?? []),
            outbox: array_values((array) ($data['outbox'] ?? [])),
            session: (array) ($data['session'] ?? []),
            sequence: (int) ($data['sequence'] ?? 0),
        );
    }

    public function toLivewire()
    {
        return $this->toArray();
    }

    public static function fromLivewire($value)
    {
        return self::fromArray(is_array($value) ? $value : []);
    }
}
