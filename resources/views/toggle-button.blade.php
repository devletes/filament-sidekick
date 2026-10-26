@auth
    @php
        $label = config('sidekick.assistant.name', 'Assistant');
        // Normally passed by the render hook; defaulted for standalone use.
        $icon ??= config('sidekick.icons.assistant', 'heroicon-o-sparkles');
    @endphp

    <button
        type="button"
        x-data
        x-on:click="$store.sidekick.toggle()"
        x-bind:class="{ 'sidekick-toggle-open': $store.sidekick?.open }"
        class="fi-icon-btn sidekick-toggle"
        title="{{ $label }}"
        aria-label="{{ $label }}"
    >
        <x-sidekick::icon :icon="$icon" class="sidekick-toggle-icon" />
    </button>
@endauth
