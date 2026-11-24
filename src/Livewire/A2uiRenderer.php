<?php

namespace A2uiLivewire\Livewire;

use A2uiLivewire\A2ui;
use A2uiLivewire\Evaluation\DataContext;
use A2uiLivewire\Evaluation\Evaluator;
use A2uiLivewire\Events\ActionDispatched;
use A2uiLivewire\Events\MessagesProcessed;
use A2uiLivewire\Events\RendererError;
use A2uiLivewire\Exceptions\A2uiException;
use A2uiLivewire\Processing\ProcessResult;
use A2uiLivewire\Protocol\Envelope;
use A2uiLivewire\Protocol\MessageParser;
use A2uiLivewire\Rendering\DateTimeValues;
use A2uiLivewire\Rendering\RenderSession;
use A2uiLivewire\State\Surface;
use A2uiLivewire\State\SurfaceGroup;
use A2uiLivewire\Support\Values;
use A2uiLivewire\Transport\AgentRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

/**
 * Renders A2UI surfaces and relays user interaction to the agent.
 *
 *     <livewire:a2ui :messages="$messages" transport="a2a" />
 *
 * State (components, data models) lives server-side in the Livewire snapshot.
 * Inputs are two-way bound through `a2uiInputs`; their tokens, and the action
 * targets of buttons, are kept in locked properties so the browser cannot
 * redirect a write or an action to another path or component.
 */
class A2uiRenderer extends Component
{
    #[Locked]
    public SurfaceGroup $group;

    /** @var array<string, mixed> token => current input value */
    public array $a2uiInputs = [];

    /** @var array<string, array{surfaceId: string, pointer: ?string, kind: string}> */
    #[Locked]
    public array $a2uiInputMap = [];

    /** @var array<string, array{surfaceId: string, componentId: string, basePath: string, indexes: list<int>, prop: string}> */
    #[Locked]
    public array $a2uiActions = [];

    #[Locked]
    public ?string $transport = null;

    /** Render only this surface (others are still processed). */
    #[Locked]
    public ?string $surface = null;

    /** Server-side message channel to pull from (see A2ui::channel()). */
    #[Locked]
    public ?string $channel = null;

    #[Locked]
    public int $channelCursor = 0;

    /** Poll interval for the channel, e.g. "1s" (null = only on a2ui-sync events). */
    #[Locked]
    public ?string $poll = null;

    /** Accept messages pushed from the browser (a2ui-messages event). */
    #[Locked]
    public bool $acceptClientMessages = false;

    #[Locked]
    public ?string $initialPrompt = null;

    /** Last text reply from the agent. */
    public ?string $agentText = null;

    /** @var list<array{code: string, message: string, surfaceId?: string}> recent errors */
    public array $a2uiErrors = [];

    /** @var array<string, true> errors already reported to the agent */
    #[Locked]
    public array $reportedErrors = [];

    /** Shown when there is no surface yet. */
    #[Locked]
    public ?string $emptyText = null;

    /** Emit an `a2ui-inspect` browser event with every data model after each render (devtools). */
    #[Locked]
    public bool $inspect = false;

    /**
     * @param  array|string  $messages  initial agent-to-renderer messages (trusted)
     */
    public function mount(
        array|string $messages = [],
        ?string $transport = null,
        ?string $surface = null,
        ?string $channel = null,
        ?string $poll = null,
        bool $acceptClientMessages = false,
        ?string $prompt = null,
        ?string $emptyText = null,
        bool $inspect = false,
    ): void {
        $this->group = new SurfaceGroup;
        $this->transport = $transport;
        $this->surface = $surface;
        $this->channel = $channel;
        $this->poll = $poll;
        $this->acceptClientMessages = $acceptClientMessages;
        $this->initialPrompt = $prompt;
        $this->emptyText = $emptyText;
        $this->inspect = $inspect;

        if ($messages !== [] && $messages !== '') {
            $this->ingest($messages, trusted: true, exchange: false);
        }

        if ($channel !== null) {
            $this->syncChannel();
        }
    }

    // ------------------------------------------------------------------
    // Public actions
    // ------------------------------------------------------------------

    /**
     * Sends a free-text prompt to the agent (chat-style usage).
     */
    public function sendPrompt(string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $this->exchange([], $text);
    }

    /**
     * `$dispatch('a2ui-prompt', { text, renderer? })` from anywhere on the page
     * (e.g. a chat input) sends a prompt to this renderer's agent.
     */
    #[On('a2ui-prompt')]
    public function receivePrompt(string $text = '', ?string $renderer = null): void
    {
        if ($renderer === null || $renderer === $this->getId()) {
            $this->sendPrompt($text);
        }
    }

    /**
     * Sends the `prompt` given at mount time (triggered by wire:init).
     */
    public function sendInitialPrompt(): void
    {
        if ($this->initialPrompt !== null) {
            $prompt = $this->initialPrompt;
            $this->initialPrompt = null;
            $this->sendPrompt($prompt);
        }
    }

    /**
     * Applies messages delivered by the browser. Only enabled with
     * `accept-client-messages`; such messages may never invoke renderer
     * functions.
     */
    #[On('a2ui-messages')]
    public function receiveMessages(array|string $messages = [], ?string $renderer = null): void
    {
        if (! $this->acceptClientMessages || ($renderer !== null && $renderer !== $this->getId())) {
            return;
        }

        $this->ingest($messages, trusted: false);
    }

    /**
     * Pulls new messages from the server-side channel.
     */
    #[On('a2ui-sync')]
    public function syncChannel(?string $channel = null): void
    {
        if ($this->channel === null || ($channel !== null && $channel !== $this->channel)) {
            return;
        }

        [$messages, $cursor] = $this->a2ui()->channel($this->channel)->since($this->channelCursor);
        $this->channelCursor = $cursor;

        if ($messages !== []) {
            $this->ingest($messages, trusted: true);
        }
    }

    /**
     * Handles a Button (or other component) action.
     */
    public function a2uiAction(string $token): void
    {
        $target = $this->a2uiActions[$token] ?? null;

        if ($target === null) {
            return;
        }

        $surface = $this->group->get($target['surfaceId']);
        $component = $surface?->component($target['componentId']);
        $action = $component[$target['prop']] ?? null;

        if ($surface === null || ! is_array($action)) {
            return;
        }

        $evaluator = $this->evaluator();
        $context = $evaluator->context($surface, $target['basePath'], $target['indexes'], activation: true);

        // A failing @check disables the component; never dispatch through it.
        if (! $this->checksPass($component, $context)) {
            $surface->submitted = true;
            $this->flushEvaluatorErrors($evaluator);

            return;
        }

        $surface->submitted = true;

        if (isset($action['event']) && is_array($action['event'])) {
            $this->dispatchEvent($surface, $component, $action['event'], $context, $evaluator);
        } elseif (isset($action['functionCall']) && is_array($action['functionCall'])) {
            $this->runLocalAction($surface, $action['functionCall'], $context, $evaluator);
        }
    }

    /**
     * Sends queued renderer-to-agent messages (agent function calls created
     * while rendering, and errors).
     */
    public function a2uiFlush(): void
    {
        if ($this->group->outbox !== []) {
            $this->exchange([]);
        }
    }

    /**
     * Removes every surface.
     */
    public function resetSurfaces(): void
    {
        $this->group = new SurfaceGroup(session: $this->group->session);
        $this->a2uiInputs = [];
        $this->a2uiErrors = [];
        $this->agentText = null;
    }

    /**
     * Two-way binding writes: `a2uiInputs.<token>` → data model.
     */
    public function updatedA2uiInputs(mixed $value, ?string $key = null): void
    {
        if ($key === null) {
            return;
        }

        $token = explode('.', $key)[0];
        $binding = $this->a2uiInputMap[$token] ?? null;

        if ($binding === null || $binding['pointer'] === null) {
            return;
        }

        $surface = $this->group->get($binding['surfaceId']);

        if ($surface === null) {
            return;
        }

        $current = $this->a2uiInputs[$token] ?? $value;

        try {
            $surface->setData($binding['pointer'], self::convertInput($current, $binding['kind']));
            $surface->touch($binding['pointer']);
        } catch (A2uiException $e) {
            $this->recordError($e->withSurface($surface->id));
        }
    }

    // ------------------------------------------------------------------
    // Programmatic API (usable from subclasses and tests)
    // ------------------------------------------------------------------

    /**
     * Applies agent-to-renderer messages and, when `$exchange` is true, sends
     * any replies (function responses, errors) back to the agent.
     */
    public function ingest(array|string $messages, bool $trusted = true, bool $exchange = true): ProcessResult
    {
        try {
            $parsed = MessageParser::parse($messages);
        } catch (A2uiException $e) {
            $this->recordError($e);

            return new ProcessResult;
        }

        $result = $this->a2ui()->processor()->process($this->group, $parsed, $trusted);
        $this->afterProcessing($parsed, $result);

        $outbound = $this->pendingOutbound($result);

        if ($outbound !== []) {
            if ($exchange) {
                $this->exchange($outbound);
            } else {
                // Sent right after the page loads (see a2uiFlush).
                array_push($this->group->outbox, ...$outbound);
            }
        }

        return $result;
    }

    /**
     * @return array<string, Surface>
     */
    public function surfaces(): array
    {
        return $this->group->surfaces;
    }

    // ------------------------------------------------------------------
    // Agent exchange
    // ------------------------------------------------------------------

    /**
     * Sends messages (and/or text) to the agent and applies the reply,
     * continuing while the reply requires a response (renderer function
     * calls, agent function calls created along the way).
     *
     * @param  list<array>  $messages
     */
    protected function exchange(array $messages, ?string $text = null): void
    {
        $a2ui = $this->a2ui();
        $maxTrips = (int) $a2ui->config('max_round_trips', 5);
        $outbound = [...$messages, ...$this->drainOutbox()];

        if (! $this->agentReachable()) {
            // No agent: actions are only raised as events.
            return;
        }

        for ($trip = 0; $trip < $maxTrips; $trip++) {
            if ($outbound === [] && ($text === null || $text === '')) {
                break;
            }

            $request = new AgentRequest($outbound, $text, $this->metadata(), $this->group->session);

            try {
                $response = $a2ui->transport($this->transport)->send($request);
                $result = $this->consume($response->messages);
            } catch (A2uiException $e) {
                $this->recordError($e);
                break;
            } catch (Throwable $e) {
                report($e);
                $this->recordError(A2uiException::execution('Agent exchange failed: '.$e->getMessage()));
                break;
            }

            $this->group->session = $response->session;

            if ($response->text !== null) {
                $this->agentText = $response->text;
                $this->dispatch('a2ui-agent-text', text: $response->text, renderer: $this->getId());
            }

            $text = null;
            $outbound = $this->pendingOutbound($result);
        }
    }

    /**
     * Applies streamed or batched agent messages, pushing intermediate HTML to
     * the browser while a stream is in progress.
     *
     * @param  iterable<array>  $messages
     */
    protected function consume(iterable $messages): ProcessResult
    {
        $processor = $this->a2ui()->processor();
        $total = new ProcessResult;
        $all = [];
        // Streaming writes straight to the HTTP response; never in the console.
        $progressive = ! is_array($messages) && $this->a2ui()->config('progressive_rendering', true) && ! app()->runningInConsole();
        $lastFlush = microtime(true);

        foreach ($messages as $message) {
            $all[] = $message;
            $total->merge($processor->process($this->group, [$message]));

            if ($progressive && microtime(true) - $lastFlush > 0.08) {
                $this->streamPreview();
                $lastFlush = microtime(true);
            }
        }

        $this->afterProcessing($all, $total);

        return $total;
    }

    /**
     * Streams the current surfaces' HTML while the request is still running.
     */
    protected function streamPreview(): void
    {
        try {
            $session = $this->renderSession();
            $html = $this->renderSurfaces($session)->toHtml();
            $this->stream(content: $html, replace: true, to: $this->streamTarget());
        } catch (Throwable) {
            // Streaming is best effort; the final render always follows.
        }
    }

    /**
     * @return list<array>
     */
    protected function pendingOutbound(ProcessResult $result): array
    {
        $outbound = [];

        foreach ($result->outbound as $message) {
            if (isset($message['error']) && ! $this->a2ui()->config('report_errors_to_agent', true)) {
                continue;
            }

            if (isset($message['error'])) {
                $key = hash('xxh128', json_encode($message['error']) ?: '');
                if (isset($this->reportedErrors[$key])) {
                    continue;
                }
                $this->reportedErrors[$key] = true;
            }

            $outbound[] = $message;
        }

        return [...$outbound, ...$this->drainOutbox()];
    }

    /**
     * @return list<array>
     */
    protected function drainOutbox(): array
    {
        $outbox = $this->group->outbox;
        $this->group->outbox = [];

        return $outbox;
    }

    protected function metadata(): array
    {
        $metadata = ['a2uiRendererCapabilities' => $this->a2ui()->capabilities()];

        if (($dataModel = $this->group->rendererDataModel()) !== null) {
            $metadata['a2uiRendererDataModel'] = $dataModel;
        }

        return $metadata;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    protected function dispatchEvent(Surface $surface, array $component, array $event, DataContext $context, Evaluator $evaluator): void
    {
        $name = $event['name'] ?? null;

        if (! is_string($name) || $name === '') {
            $this->recordError(A2uiException::validation('Action event is missing a name.', $surface->id, "/components/{$component['id']}/action/event/name"));

            return;
        }

        try {
            $resolvedContext = [];
            foreach (is_array($event['context'] ?? null) ? $event['context'] : [] as $key => $value) {
                $resolvedContext[$key] = $context->resolve($value);
            }

            $userMessage = array_key_exists('userMessage', $event) ? $context->string($event['userMessage']) : null;
        } catch (A2uiException $e) {
            // Action pipeline failure: stop, report, keep the surface mounted.
            $this->recordError($e->withSurface($surface->id));
            $this->flushEvaluatorErrors($evaluator);

            return;
        }

        $this->flushEvaluatorErrors($evaluator);

        $message = Envelope::action($name, $surface->id, (string) $component['id'], $resolvedContext, $userMessage);
        $dataModel = $this->group->rendererDataModel();

        $this->dispatch('a2ui-action', message: $message, renderer: $this->getId());
        event(new ActionDispatched($message, $this->getId(), $dataModel));

        $this->exchange([$message]);
    }

    protected function runLocalAction(Surface $surface, array $functionCall, DataContext $context, Evaluator $evaluator): void
    {
        if (! DataContext::isCall($functionCall)) {
            $this->recordError(A2uiException::validation('Action functionCall is missing "@call".', $surface->id));

            return;
        }

        try {
            $context->call($functionCall);
        } catch (A2uiException $e) {
            $code = $e->errorCode === A2uiException::EXPRESSION_ERROR ? A2uiException::EXECUTION_ERROR : $e->errorCode;
            $this->recordError(new A2uiException($code, $e->getMessage(), $surface->id));
        }

        $this->flushEvaluatorErrors($evaluator);
        $this->applyEffects($evaluator->effects());

        if ($this->group->outbox !== []) {
            $this->exchange([]);
        }
    }

    protected function checksPass(array $component, DataContext $context): bool
    {
        foreach ($component['@checks'] ?? [] as $rule) {
            if (! is_array($rule) || ! array_key_exists('condition', $rule)) {
                continue;
            }

            try {
                $result = Values::toValidationResult($context->withActivation(false)->resolve($rule['condition']));
            } catch (A2uiException) {
                return false;
            }

            if (! $result['valid'] && ($result['severity'] ?? 'error') === 'error') {
                return false;
            }
        }

        return true;
    }

    protected function applyEffects(array $effects): void
    {
        foreach ($effects as $effect) {
            if (($effect['type'] ?? null) === 'openUrl') {
                // Runs only for this component, right after the response.
                $this->js('window.open('.json_encode($effect['url'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).', "_blank", "noopener,noreferrer")');
                $this->dispatch('a2ui-open-url', url: $effect['url'], renderer: $this->getId());
            } else {
                $this->dispatch('a2ui-effect', effect: $effect);
            }
        }
    }

    // ------------------------------------------------------------------
    // Bookkeeping
    // ------------------------------------------------------------------

    protected function afterProcessing(array $messages, ProcessResult $result): void
    {
        foreach ($result->errors as $error) {
            $this->recordError($error, reportToAgent: false);
        }

        $this->applyEffects($result->effects);

        foreach ($result->created as $surfaceId) {
            $this->dispatch('a2ui-surface-created', surfaceId: $surfaceId, renderer: $this->getId());
        }

        foreach ($result->deleted as $surfaceId) {
            $this->dispatch('a2ui-surface-deleted', surfaceId: $surfaceId, renderer: $this->getId());
        }

        if ($messages !== []) {
            event(new MessagesProcessed($messages, $result, $this->getId()));
        }
    }

    protected function recordError(A2uiException $error, bool $reportToAgent = true): void
    {
        $payload = $error->toErrorPayload();
        $this->a2uiErrors = array_slice([...$this->a2uiErrors, $payload], -20);

        event(new RendererError($error, $this->getId()));
        $this->dispatch('a2ui-error', error: $payload, renderer: $this->getId());

        if ($reportToAgent && $this->a2ui()->config('report_errors_to_agent', true) && $this->agentReachable()) {
            $key = hash('xxh128', json_encode($payload) ?: '');

            if (! isset($this->reportedErrors[$key]) && ($error->surfaceId !== null || $error->functionCallId !== null)) {
                $this->reportedErrors[$key] = true;
                $this->group->outbox[] = Envelope::error($payload);
            }
        }
    }

    protected function flushEvaluatorErrors(Evaluator $evaluator): void
    {
        foreach ($evaluator->errors() as $error) {
            $this->recordError($error);
        }

        $evaluator->clearErrors();
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    public function render(): View
    {
        $session = $this->renderSession();
        $html = $this->renderSurfaces($session);

        $inputs = [];
        $map = [];

        foreach ($session->inputs as $token => $binding) {
            $map[$token] = ['surfaceId' => $binding['surfaceId'], 'pointer' => $binding['pointer'], 'kind' => $binding['kind']];
            $inputs[$token] = $binding['pointer'] === null && array_key_exists($token, $this->a2uiInputs)
                ? $this->a2uiInputs[$token]
                : $binding['value'];
        }

        $this->a2uiInputs = $inputs;
        $this->a2uiInputMap = $map;
        $this->a2uiActions = $session->actions;

        // Errors found while rendering are recorded once and, if configured,
        // queued for the agent; queued messages are flushed right after load.
        foreach ($session->evaluator->errors() as $error) {
            $key = 'render:'.hash('xxh128', json_encode($error->toErrorPayload()) ?: '');
            if (! isset($this->reportedErrors[$key])) {
                $this->reportedErrors[$key] = true;
                $this->recordError($error);
            }
        }

        $inspection = null;

        if ($this->inspect) {
            $inspection = array_map(
                static fn (Surface $surface) => ['dataModel' => \A2uiLivewire\Support\Json::object(is_array($surface->dataModel) ? $surface->dataModel : []), 'components' => count($surface->components)],
                $this->group->surfaces,
            );

            // The event can fire before listeners on the page exist (first
            // load), so the same payload is also embedded in the DOM.
            $this->dispatch('a2ui-inspect', renderer: $this->getId(), surfaces: $inspection);
        }

        return view('a2ui::livewire.renderer', [
            'surfacesHtml' => $html,
            'hasSurfaces' => ! $this->group->isEmpty(),
            'needsFlush' => $this->group->outbox !== [],
            'flushKey' => count($this->group->outbox).':'.$this->group->sequence,
            'streamTarget' => $this->streamTarget(),
            'debug' => (bool) $this->a2ui()->config('debug', false),
            'inspection' => $inspection === null ? null : json_encode(
                ['renderer' => $this->getId(), 'surfaces' => $inspection === [] ? new \stdClass : $inspection],
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        ]);
    }

    protected function renderSurfaces(RenderSession $session): HtmlString
    {
        $renderer = $this->a2ui()->renderer();
        $html = '';

        foreach ($this->group->surfaces as $surface) {
            if ($this->surface !== null && $surface->id !== $this->surface) {
                continue;
            }

            $html .= view('a2ui::surface', [
                'surface' => $surface,
                'content' => $renderer->renderSurface($surface, $session),
            ])->render();
        }

        return new HtmlString($html);
    }

    protected function renderSession(): RenderSession
    {
        $a2ui = $this->a2ui();
        $debounce = (int) $a2ui->config('debounce', 300);

        [$text, $choice] = match ($a2ui->config('input_sync', 'live')) {
            'blur' => ['.blur', '.live'],
            'deferred' => ['', ''],
            default => [".live.debounce.{$debounce}ms", '.live'],
        };

        return new RenderSession(
            evaluator: $this->evaluator(),
            textModelModifiers: $text,
            choiceModelModifiers: $choice,
            debug: (bool) $a2ui->config('debug', false),
        );
    }

    protected function evaluator(): Evaluator
    {
        $a2ui = $this->a2ui();

        return new Evaluator($a2ui->catalogs(), $this->group, $a2ui->evaluationOptions());
    }

    protected function agentReachable(): bool
    {
        try {
            return ! $this->a2ui()->transport($this->transport) instanceof \A2uiLivewire\Transport\NullTransport;
        } catch (Throwable) {
            // A misconfigured transport must surface as an error when used,
            // not silently swallow the user's action.
            return true;
        }
    }

    protected function streamTarget(): string
    {
        return 'a2ui-'.$this->getId();
    }

    protected function a2ui(): A2ui
    {
        return app(A2ui::class);
    }

    /**
     * Converts an HTML input value into the data model representation.
     */
    public static function convertInput(mixed $value, string $kind): mixed
    {
        return match ($kind) {
            'bool' => Values::toBoolean($value),
            'number' => is_string($value) && is_numeric(trim($value)) ? trim($value) + 0 : $value,
            'slider' => Values::toNumber($value) ?? 0,
            'choice-single' => is_string($value) && $value !== '' ? [$value] : [],
            'choice-multi' => array_values(array_map(static fn ($item) => Values::toString($item), is_array($value) ? $value : [])),
            'date', 'time', 'datetime' => DateTimeValues::fromInput($value, $kind),
            default => is_scalar($value) || $value === null ? Values::toString($value) : $value,
        };
    }
}
