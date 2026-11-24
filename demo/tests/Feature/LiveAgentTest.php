<?php

namespace Tests\Feature;

use A2uiLivewire\Livewire\A2uiRenderer;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Talks to a real A2A agent (e.g. the official A2UI restaurant finder sample).
 * Opt-in: LIVE_AGENT_URL=http://localhost:10002/a2a/json-rpc php artisan test --filter LiveAgent
 */
class LiveAgentTest extends TestCase
{
    public static function modes(): array
    {
        return ['message/send' => [false], 'message/stream' => [true]];
    }

    #[DataProvider('modes')]
    public function test_prompt_and_action_round_trip(bool $stream): void
    {
        $url = getenv('LIVE_AGENT_URL');

        if (! $url) {
            $this->markTestSkipped('Set LIVE_AGENT_URL to run against a live agent.');
        }

        config()->set('a2ui.transports.live', ['driver' => 'a2a', 'url' => $url, 'stream' => $stream, 'timeout' => 180]);
        config()->set('a2ui.report_errors_to_agent', false);
        $this->app->forgetInstance(\A2uiLivewire\A2ui::class);

        $c = Livewire::test(A2uiRenderer::class, ['transport' => 'live'])
            ->call('sendPrompt', 'Find me 3 Italian restaurants in New York');

        $this->assertNoRendererErrors($c->get('a2uiErrors'), 'prompt turn');
        $this->assertNotEmpty($c->get('group')->surfaces, 'the agent created a surface');
        $this->assertNotEmpty($c->get('a2uiActions'), 'the UI has actionable buttons');

        fwrite(STDERR, "\n[".($stream ? 'stream' : 'send')."] surfaces after prompt: ".implode(', ', array_keys($c->get('group')->surfaces))."\n");

        // Click the first button that sends an event to the agent (e.g. "Book Now").
        $token = array_key_first($c->get('a2uiActions'));
        $target = $c->get('a2uiActions')[$token];
        $before = json_encode($c->get('group')->surfaces);

        $c->call('a2uiAction', $token);

        fwrite(STDERR, "[".($stream ? 'stream' : 'send')."] clicked {$target['componentId']} @ {$target['basePath']}; surfaces now: ".implode(', ', array_keys($c->get('group')->surfaces))."\n");
        fwrite(STDERR, '['.($stream ? 'stream' : 'send').'] visible: '.mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($c->html()))), 0, 400)."\n");

        $this->assertNoRendererErrors($c->get('a2uiErrors'), 'action turn');
        $this->assertNotSame($before, json_encode($c->get('group')->surfaces), 'the agent changed the UI in response to the action');
    }

    /**
     * LLM output is not deterministic: invalid agent messages (VALIDATION_FAILED) are
     * reported back to the agent by design, so only renderer-side failures fail the test.
     */
    private function assertNoRendererErrors(array $errors, string $turn): void
    {
        foreach ($errors as $error) {
            if ($error['code'] === 'VALIDATION_FAILED') {
                fwrite(STDERR, "[{$turn}] agent sent an invalid message (reported back to it): {$error['message']}\n");

                continue;
            }

            $this->fail("{$turn}: renderer error ".json_encode($error));
        }
    }
}
