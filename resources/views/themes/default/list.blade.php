<div {{ $node->attributes([
    'class' => 'a2ui-list',
    'role' => 'list',
    'data-direction' => $node->enum('direction', ['vertical', 'horizontal'], 'vertical'),
    'data-align' => $node->enum('align', ['start', 'center', 'end', 'stretch'], 'stretch'),
]) }}>
    @foreach ($node->children() as $child)
        <div role="listitem" class="a2ui-list-item" wire:key="{{ $node->key() }}:{{ $child['key'] }}">
            {{ $child['html'] }}
        </div>
    @endforeach
</div>
