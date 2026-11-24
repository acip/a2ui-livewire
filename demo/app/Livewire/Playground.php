<?php

namespace App\Livewire;

use App\A2ui\DemoAgent;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Agent playground')]
class Playground extends Component
{
    public function render()
    {
        return view('livewire.playground', ['suggestions' => DemoAgent::SUGGESTIONS]);
    }
}
