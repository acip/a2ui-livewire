<?php

namespace Tests\Feature;

use A2uiLivewire\Livewire\A2uiRenderer;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PlaygroundTest extends TestCase
{
    private function renderer(): Testable
    {
        return Livewire::test(A2uiRenderer::class, ['transport' => 'demo']);
    }

    private function action(Testable $c, string $componentId, ?string $basePath = null): string
    {
        foreach ($c->get('a2uiActions') as $token => $target) {
            if ($target['componentId'] === $componentId && ($basePath === null || $target['basePath'] === $basePath)) {
                return $token;
            }
        }
        $this->fail("No action for {$componentId}");
    }

    private function input(Testable $c, string $suffix): string
    {
        foreach ($c->get('a2uiInputMap') as $token => $binding) {
            if (str_ends_with((string) $binding['pointer'], $suffix)) {
                return $token;
            }
        }
        $this->fail("No input for {$suffix}");
    }

    public function test_pages_load(): void
    {
        $this->get('/')->assertOk()->assertSee('Contact');
        $this->get('/agent')->assertOk();
    }

    public function test_welcome_and_suggestion_chips(): void
    {
        $c = $this->renderer()->call('sendPrompt', 'Hello')->assertSee('I render UI with A2UI');
        $c->call('a2uiAction', $this->action($c, 'chip', null))->assertSee('Book a table');
        $this->assertSame([], $c->get('a2uiErrors'));
    }

    public function test_booking_flow(): void
    {
        $c = $this->renderer()->call('sendPrompt', 'Book a table for dinner')->assertSee('Tasca do Chico');

        $c->set('a2uiInputs.'.$this->input($c, '/booking/name'), 'Ada')
            ->set('a2uiInputs.'.$this->input($c, '/booking/when'), '2026-12-24T20:00')
            ->set('a2uiInputs.'.$this->input($c, '/booking/party'), '4')
            ->set('a2uiInputs.'.$this->input($c, '/booking/terms'), true)
            ->assertSee('4 guests')
            ->call('a2uiAction', $this->action($c, 'submit'))
            ->assertSeeText('booked, Ada!')
            ->assertSeeText('Thursday, 24 December · 20:00')
            ->assertSeeText('Table for 4')
            ->assertSeeText('data model synced');

        $this->assertSame([], $c->get('a2uiErrors'));
    }

    public function test_todo_list_flow(): void
    {
        $c = $this->renderer()->call('sendPrompt', 'todo')->assertSee('Ship it')->assertSee('2 tasks');

        $c->set('a2uiInputs.'.$this->input($c, '/draft'), 'Write docs')
            ->call('a2uiAction', $this->action($c, 'add'))
            ->assertSee('Write docs')->assertSee('3 tasks');

        $c->call('a2uiAction', $this->action($c, 'item_remove', null))->assertDontSee('Try the A2UI gallery');
        $this->assertSame([], $c->get('a2uiErrors'));
    }

    public function test_shipping_quote_uses_agent_function(): void
    {
        $c = $this->renderer()->call('sendPrompt', 'shipping quote');
        $c->call('a2uiFlush')->assertSee('€7.45');

        $c->set('a2uiInputs.'.$this->input($c, '/kg'), '20')->call('a2uiFlush')->assertSee('arrives in 3 business days');
        $this->assertSame([], $c->get('a2uiErrors'));
    }

    public function test_weather_is_streamed(): void
    {
        $c = $this->renderer()->call('sendPrompt', "What's the weather in Porto?")->assertSee('Porto')->assertSee('15:00');
        $c->call('a2uiAction', $this->action($c, 'refresh'));
        $this->assertSame([], $c->get('a2uiErrors'));
    }
}
