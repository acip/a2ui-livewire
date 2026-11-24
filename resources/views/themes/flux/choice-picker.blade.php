{{--
    Flux theme ChoicePicker. The "chips" display style uses the package's own
    chip markup (a2ui.css): Flux's "pills" group variant is a Flux Pro feature.
--}}
@php
    $multiple = $node->enum('variant', ['multipleSelection', 'mutuallyExclusive'], 'mutuallyExclusive') === 'multipleSelection';
    $chips = $node->enum('displayStyle', ['checkbox', 'chips'], 'checkbox') === 'chips';
@endphp
@if ($chips)
    @include('a2ui::themes.default.choice-picker')
@else
    @php
        $filterable = $node->raw('filterable') === true;
        $label = $node->string('label');
        $error = $node->validationMessage('value');

        $options = [];
        foreach ((array) $node->raw('options', []) as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $optionLabel = $node->resolveString($option['label'] ?? $option['value']);
                $options[] = [
                    'value' => $option['value'],
                    'label' => $optionLabel,
                    'show' => $filterable
                        ? "q === '' || ".\Illuminate\Support\Js::from(mb_strtolower($optionLabel))->toHtml().'.includes(q.toLowerCase())'
                        : 'true',
                ];
            }
        }

        // Blade only spreads a bag into a component tag when it is named $attributes.
        $attributes = $node->inputAttributes('value', $multiple ? 'choice-multi' : 'choice-single');
    @endphp
    <div {{ $node->attributes(['class' => 'a2ui-choice-picker w-full min-w-0'], withAria: false) }} x-data="{ q: '' }">
        <flux:field>
            @if ($label !== '')
                <flux:label>{{ $label }}</flux:label>
            @endif

            @if ($filterable)
                <flux:input size="sm" icon="magnifying-glass" x-model="q" placeholder="{{ __('Filter options…') }}" aria-label="{{ __('Filter options') }}" class="mb-2" />
            @endif

            @if ($multiple)
                <flux:checkbox.group {{ $attributes }}>
                    @foreach ($options as $option)
                        <flux:checkbox :value="$option['value']" :label="$option['label']" wire:key="{{ $node->key() }}:opt:{{ $option['value'] }}" x-show="{{ $option['show'] }}" />
                    @endforeach
                </flux:checkbox.group>
            @else
                <flux:radio.group {{ $attributes }}>
                    @foreach ($options as $option)
                        <flux:radio :value="$option['value']" :label="$option['label']" wire:key="{{ $node->key() }}:opt:{{ $option['value'] }}" x-show="{{ $option['show'] }}" />
                    @endforeach
                </flux:radio.group>
            @endif

            @if ($error)
                <flux:error :message="$error" />
            @endif
        </flux:field>
    </div>
@endif
