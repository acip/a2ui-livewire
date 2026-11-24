{{--
    Modal: only the trigger is visible; activating it opens a native <dialog>.
    wire:ignore.self keeps the dialog's open state across Livewire morphs while
    its content still updates.
--}}
<div {{ $node->attributes(['class' => 'a2ui-modal']) }} x-data>
    <div
        class="a2ui-modal-trigger"
        x-on:click="$el.querySelector('button[disabled]') || $refs.dialog.showModal()"
    >
        {{ $node->child('trigger') }}
    </div>

    <dialog
        x-ref="dialog"
        id="{{ $node->domId('dialog') }}"
        class="a2ui-dialog"
        wire:ignore.self
        x-on:click="if ($event.target === $el) $el.close()"
    >
        <div class="a2ui-dialog-body">
            <button type="button" class="a2ui-dialog-close" x-on:click="$refs.dialog.close()" aria-label="{{ __('Close') }}">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
            {{ $node->child('content') }}
        </div>
    </dialog>
</div>
