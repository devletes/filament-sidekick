<p class="sidekick-action-card-title">{{ $activeAction->summary }}</p>

<dl class="sidekick-action-card-rows">
    @foreach ($activeAction->preview as $row)
        <div>
            <dt>{{ $row['label'] ?? '' }}</dt>
            <dd>{{ $row['value'] ?? '' }}</dd>
        </div>
    @endforeach
</dl>

@if (($activeAction->acceptsUpload() && $attachmentsEnabled) || $cardPayloadAttachments->isNotEmpty())
    {{-- One "Attachments" set: files the proposal already references (from chat) plus files added here; both removable. --}}
    <div class="sidekick-card-upload">
        <p class="sidekick-card-upload-label">
            {{ __('sidekick::messages.attachments.heading') }}
            @if ($activeAction->requiresUpload() && $cardAttachments->isEmpty())
                <span class="sidekick-upload-required">{{ __('sidekick::messages.attachments.required') }}</span>
            @endif
        </p>

        @if ($cardPayloadAttachments->isNotEmpty() || $cardAttachments->isNotEmpty())
            <div class="sidekick-chips">
                @foreach ($cardPayloadAttachments as $attachment)
                    {{-- Referenced from chat: removing detaches it from the proposal, the chat file stays. --}}
                    <x-filament::badge color="gray" :icon="$icons['attach']" wire:key="sidekick-card-ref-{{ $attachment->id }}">
                        {{ $attachment->name }}
                        <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                        <x-slot
                            name="deleteButton"
                            :label="__('sidekick::messages.composer.remove', ['name' => $attachment->name])"
                            wire:click="removeProposalAttachment('{{ $activeAction->id }}', '{{ $attachment->id }}')"
                        ></x-slot>
                    </x-filament::badge>
                @endforeach

                @foreach ($cardAttachments as $attachment)
                    <x-filament::badge color="gray" :icon="$icons['attach']" wire:key="sidekick-card-chip-{{ $attachment->id }}">
                        {{ $attachment->name }}
                        <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                        <x-slot
                            name="deleteButton"
                            :label="__('sidekick::messages.composer.remove', ['name' => $attachment->name])"
                            wire:click="removeCardAttachment('{{ $attachment->id }}')"
                        ></x-slot>
                    </x-filament::badge>
                @endforeach
            </div>
        @endif

        @if ($activeAction->acceptsUpload() && $attachmentsEnabled)
            {{-- The transparent input covers the whole field — clicking anywhere opens the file dialog. --}}
            <label class="sidekick-upload-field">
                <span>{{ __('sidekick::messages.attachments.browse') }}</span>
                <x-filament::loading-indicator wire:loading wire:target="cardUploads" class="sidekick-upload-spinner" />
                <input
                    type="file"
                    wire:model="cardUploads"
                    @if ($activeAction->upload['multiple'] ?? true) multiple @endif
                    @if ($attachmentsAccept !== '') accept="{{ $attachmentsAccept }}" @endif
                >
            </label>
            @if ($uploadError)
                <p class="sidekick-upload-error">{{ $uploadError }}</p>
            @endif
        @endif
    </div>
@endif

<div class="sidekick-action-card-buttons">
    <x-filament::button
        color="gray"
        size="sm"
        wire:click="cancelAction('{{ $activeAction->id }}')"
        wire:loading.attr="disabled"
    >{{ __('sidekick::messages.card.cancel') }}</x-filament::button>
    <x-filament::button
        color="primary"
        size="sm"
        wire:click="confirmAction('{{ $activeAction->id }}')"
        wire:loading.attr="disabled"
        :disabled="$activeAction->requiresUpload() && $cardAttachments->isEmpty()"
    >{{ __('sidekick::messages.card.confirm') }}</x-filament::button>
</div>

<p class="sidekick-action-card-note">{{ __('sidekick::messages.card.note') }}</p>
