@props(['icon'])

@if (blank($icon))
@elseif ($icon instanceof \Illuminate\Contracts\Support\Htmlable || str_contains((string) $icon, '<svg'))
    {{-- Host-supplied markup (plugin API / config), same trust level as a view. --}}
    <span {{ $attributes->class(['fi-icon']) }} aria-hidden="true">{!! $icon !!}</span>
@else
    <x-filament::icon :icon="$icon" {{ $attributes }} />
@endif
