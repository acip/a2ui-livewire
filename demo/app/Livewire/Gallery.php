<?php

namespace App\Livewire;

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Gallery of the official A2UI Basic Catalog examples, with a message
 * stepper, live data-model inspector and action log (the layout the A2UI
 * SDK guide recommends for renderer playgrounds).
 */
#[Title('A2UI Gallery')]
class Gallery extends Component
{
    #[Url]
    public string $example = 'contact-card';

    /** Number of messages applied (null = all). */
    #[Url]
    public ?int $step = null;

    /** @var list<array{time: string, message: array}> */
    public array $actions = [];

    #[Computed]
    public function examples(): array
    {
        $examples = [];

        foreach (glob(self::directory().'/*.json') as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $name = basename($file, '.json');
            $examples[$name] = [
                'title' => $data['name'] ?? Str::headline($name),
                'description' => $data['description'] ?? '',
                'group' => str_starts_with($name, 'scaffold-') ? 'Scaffold' : 'Examples',
            ];
        }

        uasort($examples, fn ($a, $b) => [$b['group'], $a['title']] <=> [$a['group'], $b['title']]);

        return $examples;
    }

    #[Computed]
    public function messages(): array
    {
        $file = self::directory().'/'.basename($this->example).'.json';

        if (! is_file($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true)['messages'] ?? [];
    }

    public function select(string $example): void
    {
        $this->example = $example;
        $this->step = null;
        $this->actions = [];
    }

    public function stepTo(?int $step): void
    {
        $total = count($this->messages);
        $this->step = $step === null || $step >= $total ? null : max(0, $step);
    }

    #[On('a2ui-action')]
    public function logAction(array $message): void
    {
        array_unshift($this->actions, ['time' => now()->format('H:i:s'), 'message' => $message]);
        $this->actions = array_slice($this->actions, 0, 20);
    }

    public function render()
    {
        $messages = $this->messages;
        $applied = $this->step === null ? $messages : array_slice($messages, 0, $this->step);

        return view('livewire.gallery', [
            'applied' => $applied,
            'total' => count($messages),
            'current' => $this->step ?? count($messages),
        ]);
    }

    private static function directory(): string
    {
        return base_path('vendor/acip/a2ui-livewire/resources/examples');
    }
}
