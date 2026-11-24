@php
    $multiple = $node->enum('variant', ['multipleSelection', 'mutuallyExclusive'], 'mutuallyExclusive') === 'multipleSelection';
    $chips = $node->enum('displayStyle', ['checkbox', 'chips'], 'checkbox') === 'chips';
    $filterable = $node->raw('filterable') === true;
    $label = $node->string('label');
    $error = $node->validationMessage('value');
    $kind = $multiple ? 'choice-multi' : 'choice-single';

    // One binding shared by every option (a checkbox group binds to an array).
    $wire = [$node->wireModel('value', $kind) => $node->model('value', $kind)];
    $name = $node->domId('choice');

    $options = [];
    foreach ((array) $node->raw('options', []) as $option) {
        if (! is_array($option) || ! is_string($option['value'] ?? null)) {
            continue;
        }
        $optionLabel = $node->resolveString($option['label'] ?? $option['value']);
        $options[] = [
            'label' => $optionLabel,
            'input' => new \Illuminate\View\ComponentAttributeBag([
                'type' => $multiple ? 'checkbox' : 'radio',
                'name' => $multiple ? null : $name,
                'value' => $option['value'],
                ...$wire,
            ]),
            // Case-insensitive substring filter on the option label.
            'show' => $filterable
                ? "q === '' || ".\Illuminate\Support\Js::from(mb_strtolower($optionLabel))->toHtml().'.includes(q.toLowerCase())'
                : 'true',
        ];
    }

    $invalid = ! $node->isValid() && $node->showValidation('value');
@endphp
<fieldset {{ $node->attributes(['class' => 'a2ui-field', 'aria-invalid' => $invalid ? 'true' : null]) }} x-data="{ q: '' }">
    @if ($label !== '')
        <legend class="a2ui-label">{{ $label }}</legend>
    @endif

    @if ($filterable)
        <input type="search" class="a2ui-input a2ui-input-sm" x-model="q" placeholder="{{ __('Filter options…') }}" aria-label="{{ __('Filter options') }}">
    @endif

    <div class="{{ $chips ? 'a2ui-chips' : 'a2ui-options' }}">
        @foreach ($options as $option)
            <label class="{{ $chips ? 'a2ui-chip' : 'a2ui-check' }}" wire:key="{{ $node->key() }}:opt:{{ $loop->index }}" x-show="{{ $option['show'] }}">
                <input {{ $option['input'] }}>
                <span>{{ $option['label'] }}</span>
            </label>
        @endforeach
    </div>

    @if ($error)
        <p class="a2ui-error" role="alert">{{ $error }}</p>
    @endif
</fieldset>
