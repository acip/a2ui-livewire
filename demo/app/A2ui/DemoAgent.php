<?php

namespace App\A2ui;

use A2uiLivewire\Catalog\Basic\BasicCatalog;
use A2uiLivewire\Transport\AgentRequest;
use A2uiLivewire\Transport\AgentResponse;
use Generator;
use Illuminate\Support\Str;

/**
 * A small deterministic agent that speaks A2UI v1.0. It stands in for an
 * LLM-backed agent so the playground works without API keys; swap the
 * renderer's transport to "http" or "a2a" to talk to a real agent.
 */
class DemoAgent
{
    public const SUGGESTIONS = [
        'Book a table for dinner',
        'Show my todo list',
        "What's the weather in Lisbon?",
        'Get a shipping quote',
    ];

    public function handle(AgentRequest $request): AgentResponse
    {
        $out = [];

        foreach ($request->messages as $message) {
            if (isset($message['action'])) {
                array_push($out, ...$this->onAction($message['action'], $request));
            } elseif (isset($message['callAgentFunction'])) {
                $out[] = $this->onFunctionCall($message['callAgentFunction']);
            }
        }

        if ($request->text !== null) {
            $prompt = trim($request->text);

            // The weather flow streams its reply to show progressive rendering.
            if (Str::contains(Str::lower($prompt), ['weather', 'forecast'])) {
                return new AgentResponse($this->weather($prompt), null, $request->session);
            }

            array_push($out, ...$this->onPrompt($prompt));
        }

        return new AgentResponse($out, null, $request->session);
    }

    // -- prompts -------------------------------------------------------------

    private function onPrompt(string $prompt): array
    {
        $lower = Str::lower($prompt);

        $reply = match (true) {
            Str::contains($lower, ['book', 'table', 'restaurant', 'dinner']) => $this->bookingForm(),
            Str::contains($lower, ['todo', 'task']) => $this->todoList(),
            Str::contains($lower, ['ship', 'quote', 'parcel']) => $this->shippingQuote(),
            default => $this->welcome(),
        };

        return [...$this->userBubble($prompt), ...$reply];
    }

    private function welcome(): array
    {
        $id = $this->surfaceId('welcome');
        $components = [
            $this->c('root', 'Card', ['child' => 'col']),
            $this->c('col', 'Column', ['children' => ['title', 'body', 'chips']]),
            $this->c('title', 'Text', ['text' => '### Hi! I render UI with A2UI.']),
            $this->c('body', 'Text', ['text' => 'Everything on this page is an **A2UI surface** streamed from a PHP agent and rendered by Livewire. Try one of these:']),
            $this->c('chips', 'Row', ['children' => ['path' => '/suggestions', 'componentId' => 'chip']]),
            $this->c('chip', 'Button', ['child' => 'chip_label', 'variant' => 'default', 'action' => ['event' => [
                'name' => 'suggest', 'context' => ['prompt' => ['@path' => 'text']],
                'userMessage' => ['@path' => 'text'],
            ]]]),
            $this->c('chip_label', 'Text', ['text' => ['@path' => 'text']]),
        ];

        return $this->create($id, $components, ['suggestions' => array_map(fn ($s) => ['text' => $s], self::SUGGESTIONS)]);
    }

    private function bookingForm(): array
    {
        $id = $this->surfaceId('booking');

        $components = [
            $this->c('root', 'Card', ['child' => 'form']),
            $this->c('form', 'Column', ['children' => ['header', 'restaurant', 'name', 'when', 'party_row', 'seating', 'notes', 'terms', 'divider', 'actions']]),
            $this->c('header', 'Row', ['children' => ['icon', 'title'], 'align' => 'center']),
            $this->c('icon', 'Icon', ['name' => 'event']),
            $this->c('title', 'Text', ['text' => '### Book a table']),
            $this->c('restaurant', 'Text', ['variant' => 'caption', 'text' => ['@call' => 'formatString', 'args' => ['value' => '${/restaurant/name} · ${/restaurant/cuisine} · ${/restaurant/area}']]]),
            $this->c('name', 'TextField', ['label' => 'Name on the booking', 'value' => ['@path' => '/booking/name'], 'placeholder' => 'Ada Lovelace',
                '@checks' => [['condition' => ['@call' => 'required', 'args' => ['value' => ['@path' => '/booking/name']]], 'message' => 'Please tell us who the table is for.']]]),
            $this->c('when', 'DateTimeInput', ['label' => 'Date & time', 'value' => ['@path' => '/booking/when'], 'enableDate' => true, 'enableTime' => true, 'min' => now()->format('Y-m-d\TH:i')]),
            $this->c('party_row', 'Row', ['children' => ['party', 'party_text'], 'align' => 'center']),
            $this->c('party', 'Slider', ['label' => 'Party size', 'min' => 1, 'max' => 12, 'steps' => 11, 'value' => ['@path' => '/booking/party'], 'weight' => 3]),
            $this->c('party_text', 'Text', ['weight' => 1, 'text' => ['@call' => 'pluralize', 'args' => [
                'value' => ['@path' => '/booking/party'], 'one' => '1 guest', 'other' => ['@call' => 'formatString', 'args' => ['value' => '${/booking/party} guests']],
            ]]]),
            $this->c('seating', 'ChoicePicker', ['label' => 'Seating', 'displayStyle' => 'chips', 'value' => ['@path' => '/booking/seating'], 'options' => [
                ['label' => 'Indoor', 'value' => 'indoor'], ['label' => 'Terrace', 'value' => 'terrace'], ['label' => 'Bar', 'value' => 'bar'],
            ]]),
            $this->c('notes', 'TextField', ['label' => 'Notes for the restaurant', 'variant' => 'longText', 'value' => ['@path' => '/booking/notes'], 'placeholder' => 'Allergies, occasions…']),
            $this->c('terms', 'CheckBox', ['label' => 'I agree to the 24h cancellation policy', 'value' => ['@path' => '/booking/terms']]),
            $this->c('divider', 'Divider'),
            $this->c('actions', 'Row', ['children' => ['policy', 'submit'], 'justify' => 'spaceBetween', 'align' => 'center']),
            $this->c('policy', 'Modal', ['trigger' => 'policy_btn', 'content' => 'policy_text']),
            $this->c('policy_btn', 'Button', ['child' => 'policy_btn_label', 'variant' => 'borderless', 'action' => ['event' => ['name' => 'view_policy']]]),
            $this->c('policy_btn_label', 'Text', ['text' => 'Cancellation policy']),
            $this->c('policy_text', 'Text', ['text' => "### Cancellation policy\n\nFree cancellation up to **24 hours** before your booking. Later cancellations are charged €10 per guest."]),
            $this->c('submit', 'Button', [
                'child' => 'submit_label',
                'variant' => 'primary',
                'action' => ['event' => [
                    'name' => 'book_table',
                    'userMessage' => ['@call' => 'formatString', 'args' => ['value' => 'Book a table for ${/booking/party}']],
                    'context' => [
                        'name' => ['@path' => '/booking/name'],
                        'when' => ['@path' => '/booking/when'],
                        'party' => ['@path' => '/booking/party'],
                        'seating' => ['@path' => '/booking/seating'],
                        'notes' => ['@path' => '/booking/notes'],
                        'restaurant' => ['@path' => '/restaurant/name'],
                    ],
                ]],
                '@checks' => [[
                    'condition' => ['@call' => 'and', 'args' => ['values' => [
                        ['@call' => 'required', 'args' => ['value' => ['@path' => '/booking/name']]],
                        ['@call' => 'required', 'args' => ['value' => ['@path' => '/booking/when']]],
                        ['@path' => '/booking/terms'],
                    ]]],
                    'message' => 'Fill in your name, a date and accept the policy.',
                ]],
            ]),
            $this->c('submit_label', 'Text', ['text' => 'Reserve']),
        ];

        return $this->create($id, $components, [
            'restaurant' => ['name' => 'Tasca do Chico', 'cuisine' => 'Portuguese', 'area' => 'Bairro Alto'],
            'booking' => ['name' => '', 'party' => 2, 'seating' => ['indoor'], 'terms' => false],
        ], sendDataModel: true);
    }

    private function todoList(): array
    {
        $id = $this->surfaceId('todos');

        $components = [
            $this->c('root', 'Card', ['child' => 'col']),
            $this->c('col', 'Column', ['children' => ['title', 'summary', 'list', 'add_row']]),
            $this->c('title', 'Text', ['text' => '### Todo']),
            $this->c('summary', 'Text', ['variant' => 'caption', 'text' => ['@call' => 'pluralize', 'args' => [
                'value' => ['@path' => '/count'], 'zero' => 'Nothing left to do 🎉', 'one' => '1 task', 'other' => ['@call' => 'formatString', 'args' => ['value' => '${/count} tasks']],
            ]]]),
            $this->c('list', 'List', ['children' => ['path' => '/items', 'componentId' => 'item']]),
            $this->c('item', 'Row', ['children' => ['index', 'item_done', 'item_remove'], 'align' => 'center']),
            $this->c('index', 'Text', ['variant' => 'caption', 'text' => ['@call' => 'formatString', 'args' => ['value' => '${@index(offset: 1)}.']]]),
            $this->c('item_done', 'CheckBox', ['label' => ['@path' => 'title'], 'value' => ['@path' => 'done'], 'weight' => 1]),
            $this->c('item_remove', 'Button', ['child' => 'remove_icon', 'variant' => 'borderless', '@accessibility' => ['label' => 'Remove task'],
                'action' => ['event' => ['name' => 'remove_todo', 'context' => ['index' => ['@call' => '@index'], 'items' => ['@path' => '/items']]]]]),
            $this->c('remove_icon', 'Icon', ['name' => 'delete']),
            $this->c('add_row', 'Row', ['children' => ['new_title', 'add'], 'align' => 'end']),
            $this->c('new_title', 'TextField', ['label' => 'New task', 'value' => ['@path' => '/draft'], 'weight' => 1]),
            $this->c('add', 'Button', ['child' => 'add_label', 'variant' => 'primary',
                'action' => ['event' => ['name' => 'add_todo', 'context' => ['title' => ['@path' => '/draft'], 'items' => ['@path' => '/items']]]],
                '@checks' => [['condition' => ['@call' => 'required', 'args' => ['value' => ['@path' => '/draft']]]]]]),
            $this->c('add_label', 'Text', ['text' => 'Add']),
        ];

        $items = [['title' => 'Try the A2UI gallery', 'done' => true], ['title' => 'Wire a real agent over A2A', 'done' => false], ['title' => 'Ship it', 'done' => false]];

        return $this->create($id, $components, ['items' => $items, 'count' => count(array_filter($items, fn ($i) => ! $i['done'])), 'draft' => '']);
    }

    /**
     * Streams the weather card piece by piece (progressive rendering).
     */
    private function weather(string $prompt): Generator
    {
        foreach ($this->userBubble($prompt) as $message) {
            yield $message;
        }

        $city = Str::of($prompt)->after(' in ')->before('?')->trim()->title()->value() ?: 'Lisbon';
        $id = $this->surfaceId('weather');

        yield ['version' => '1.0', 'createSurface' => ['surfaceId' => $id, 'catalogId' => BasicCatalog::ID, 'dataModel' => ['city' => $city]]];
        yield $this->update($id, [
            $this->c('root', 'Card', ['child' => 'col']),
            $this->c('col', 'Column', ['children' => ['head', 'temp', 'details', 'refresh']]),
            $this->c('head', 'Row', ['children' => ['icon', 'city'], 'align' => 'center']),
            $this->c('icon', 'Icon', ['name' => 'locationOn']),
            $this->c('city', 'Text', ['text' => ['@call' => 'formatString', 'args' => ['value' => '### ${/city}']]]),
        ]);
        usleep(400_000);

        yield $this->update($id, [
            $this->c('temp', 'Text', ['text' => ['@call' => 'formatString', 'args' => ['value' => '# ${formatNumber(value: ${/now/temp}, decimals: 0)}°C']]]),
            $this->c('details', 'Row', ['children' => ['path' => '/hours', 'componentId' => 'hour'], 'justify' => 'spaceBetween']),
            $this->c('hour', 'Column', ['children' => ['hour_label', 'hour_temp'], 'align' => 'center']),
            $this->c('hour_label', 'Text', ['variant' => 'caption', 'text' => ['@path' => 'label']]),
            $this->c('hour_temp', 'Text', ['text' => ['@call' => 'formatString', 'args' => ['value' => '**${temp}°**']]]),
        ]);
        usleep(400_000);

        yield $this->data($id, '/now', ['temp' => 21 + crc32($city) % 8]);
        yield $this->data($id, '/hours', array_map(fn ($h) => ['label' => sprintf('%02d:00', $h), 'temp' => 18 + ($h + crc32($city)) % 9], [12, 15, 18, 21]));
        usleep(300_000);

        yield $this->update($id, [
            $this->c('refresh', 'Button', ['child' => 'refresh_label', 'action' => ['event' => ['name' => 'refresh_weather', 'context' => ['city' => ['@path' => '/city']]]]]),
            $this->c('refresh_label', 'Text', ['text' => 'Refresh']),
        ]);
    }

    private function shippingQuote(): array
    {
        $id = $this->surfaceId('shipping');

        $components = [
            $this->c('root', 'Card', ['child' => 'col']),
            $this->c('col', 'Column', ['children' => ['title', 'explain', 'zip', 'weight', 'quote']]),
            $this->c('title', 'Text', ['text' => '### Shipping quote']),
            $this->c('explain', 'Text', ['variant' => 'caption', 'text' => 'The price below comes from an **agent-side function** (`estimateShipping`): the renderer sends `callAgentFunction` and re-renders when the answer arrives.']),
            $this->c('zip', 'TextField', ['label' => 'Postcode', 'value' => ['@path' => '/zip'],
                '@checks' => [['condition' => ['@call' => 'regex', 'args' => ['value' => ['@path' => '/zip'], 'pattern' => '^\d{4}(-\d{3})?$']], 'message' => 'Use a postcode like 1100-148.']]]),
            $this->c('weight', 'Slider', ['label' => 'Parcel weight (kg)', 'min' => 1, 'max' => 30, 'value' => ['@path' => '/kg']]),
            $this->c('quote', 'Text', ['text' => ['@call' => 'estimateShipping', 'args' => ['zip' => ['@path' => '/zip'], 'kg' => ['@path' => '/kg']]]]),
        ];

        return $this->create($id, $components, ['zip' => '1100-148', 'kg' => 3]);
    }

    // -- actions & functions -------------------------------------------------

    private function onAction(array $action, AgentRequest $request): array
    {
        $context = (array) $action['context'];
        $surfaceId = $action['surfaceId'];

        return match ($action['name']) {
            'suggest' => $this->onPrompt((string) ($context['prompt'] ?? '')),
            'book_table' => $this->confirmBooking($surfaceId, $context, $request),
            'add_todo' => $this->setTodos($surfaceId, [...(array) ($context['items'] ?? []), ['title' => (string) $context['title'], 'done' => false]], clearDraft: true),
            'remove_todo' => $this->setTodos($surfaceId, array_values(array_filter((array) ($context['items'] ?? []), fn ($_, $i) => $i !== (int) $context['index'], ARRAY_FILTER_USE_BOTH))),
            'refresh_weather' => [$this->data($surfaceId, '/now', ['temp' => random_int(15, 30)])],
            default => [],
        };
    }

    private function confirmBooking(string $surfaceId, array $context, AgentRequest $request): array
    {
        // sendDataModel: the full form state arrives in the transport metadata too.
        $model = $request->metadata['a2uiRendererDataModel']['surfaces'][$surfaceId] ?? [];
        $id = $this->surfaceId('confirmation');

        return [
            ['version' => '1.0', 'deleteSurface' => ['surfaceId' => $surfaceId]],
            ...$this->create($id, [
                $this->c('root', 'Card', ['child' => 'col']),
                $this->c('col', 'Column', ['children' => ['head', 'when', 'party', 'seating', 'ref']]),
                $this->c('head', 'Row', ['children' => ['icon', 'title'], 'align' => 'center']),
                $this->c('icon', 'Icon', ['name' => 'check']),
                $this->c('title', 'Text', ['text' => ['@call' => 'formatString', 'args' => ['value' => '### You\'re booked, ${/name}!']]]),
                $this->c('when', 'Text', ['text' => ['@call' => 'formatString', 'args' => ['value' => '**${/restaurant}** · ${formatDate(value: ${/when}, format: \'EEEE, d MMMM · HH:mm\')}']]]),
                $this->c('party', 'Text', ['text' => ['@call' => 'pluralize', 'args' => ['value' => ['@path' => '/party'], 'one' => 'Table for 1', 'other' => ['@call' => 'formatString', 'args' => ['value' => 'Table for ${/party}']]]]]),
                $this->c('seating', 'Text', ['variant' => 'caption', 'text' => ['@call' => 'formatString', 'args' => ['value' => 'Seating: ${/seating/0}']]]),
                $this->c('ref', 'Text', ['variant' => 'caption', 'text' => 'Reference **'.strtoupper(Str::random(6)).'**'.($model ? ' · data model synced ✓' : '')]),
            ], $context),
        ];
    }

    private function setTodos(string $surfaceId, array $items, bool $clearDraft = false): array
    {
        $messages = [$this->data($surfaceId, '/items', $items), $this->data($surfaceId, '/count', count(array_filter($items, fn ($i) => empty($i['done']))))];

        if ($clearDraft) {
            $messages[] = $this->data($surfaceId, '/draft', '');
        }

        return $messages;
    }

    private function onFunctionCall(array $call): array
    {
        $name = $call['callFunction']['@call'];
        $args = (array) ($call['callFunction']['args'] ?? []);

        if ($name !== 'estimateShipping') {
            return ['version' => '1.0', 'agentFunctionResponse' => ['functionCallId' => $call['functionCallId'], 'error' => ['code' => 'UNKNOWN_FUNCTION', 'message' => "Unknown function {$name}."]]];
        }

        $kg = (float) ($args['kg'] ?? 1);
        $price = 4.9 + $kg * 0.85 + (str_starts_with((string) ($args['zip'] ?? ''), '1') ? 0 : 3);

        return ['version' => '1.0', 'agentFunctionResponse' => [
            'functionCallId' => $call['functionCallId'],
            'value' => sprintf('**€%.2f** · arrives in %d business days', $price, $kg > 10 ? 3 : 2),
        ]];
    }

    // -- helpers -------------------------------------------------------------

    private function userBubble(string $prompt): array
    {
        $id = $this->surfaceId('user');

        // ChatBubble lives in the demo's custom catalog; @catalogId mixes it
        // into a surface whose default catalog is the Basic Catalog.
        return $this->create($id, [
            $this->c('root', 'ChatBubble', ['@catalogId' => \App\Providers\AppServiceProvider::CHAT_CATALOG, 'from' => 'user', 'text' => ['@path' => '/prompt']]),
        ], ['prompt' => $prompt]);
    }

    private function c(string $id, string $component, array $props = []): array
    {
        return ['id' => $id, 'component' => $component, ...$props];
    }

    private function create(string $id, array $components, array $data = [], bool $sendDataModel = false): array
    {
        return [['version' => '1.0', 'createSurface' => array_filter([
            'surfaceId' => $id,
            'catalogId' => BasicCatalog::ID,
            'sendDataModel' => $sendDataModel ?: null,
            'dataModel' => $data ?: null,
            'components' => $components,
        ], fn ($v) => $v !== null)]];
    }

    private function update(string $id, array $components): array
    {
        return ['version' => '1.0', 'updateComponents' => ['surfaceId' => $id, 'components' => $components]];
    }

    private function data(string $id, string $path, mixed $value): array
    {
        return ['version' => '1.0', 'updateDataModel' => ['surfaceId' => $id, 'path' => $path, 'value' => $value]];
    }

    private function surfaceId(string $prefix): string
    {
        return $prefix.'_'.Str::lower(Str::random(8));
    }
}
