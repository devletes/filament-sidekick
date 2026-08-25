@php
    use Devletes\Sidekick\Models\Attachment;
    use Devletes\Sidekick\Models\PendingAction;
    use Devletes\Sidekick\Support\ToolRegistry;

    $icons = config('sidekick.icons');
@endphp

<div
    class="sidekick-chat"
    @if ($echoChannel)
        x-data="sidekickEcho"
        data-sidekick-echo-channel="{{ $echoChannel }}"
        data-sidekick-echo-event=".sidekick.run.updated"
    @endif
    @if ($activeRun) wire:poll.{{ $pollInterval }}.visible="$refresh" @endif
>
    <div class="sidekick-header">
        <span class="sidekick-header-title">{{ $assistantName }}</span>

        <span class="sidekick-header-actions">
            @if ($messages->isNotEmpty() || $activeRun)
                <x-filament::icon-button
                    :icon="$icons['new_conversation']"
                    color="gray"
                    size="sm"
                    label="New conversation"
                    tooltip="New conversation"
                    wire:click="newConversation"
                    wire:loading.attr="disabled"
                    wire:target="newConversation"
                />
            @endif

            {{-- Shown only in overlay mode, where the topbar toggle is covered. --}}
            <x-filament::icon-button
                :icon="$icons['close']"
                color="gray"
                size="sm"
                label="Close assistant"
                class="sidekick-close"
                x-data
                x-on:click="$store.sidekick.set(false)"
            />
        </span>
    </div>

    <div class="sidekick-log" x-data="sidekickLog" x-on:scroll="onScroll" x-on:sidekick-jump-to-end.window="jump">
        @if ($messages->isEmpty() && ! $activeRun)
            <x-filament::empty-state
                :icon="$icons['assistant']"
                :heading="$greeting"
                :description="$assistantDescription"
                :contained="false"
                class="sidekick-empty"
            >
                @if ($canResume)
                    <x-slot name="footer">
                        <x-filament::link
                            tag="button"
                            size="sm"
                            wire:click="resumeConversation"
                            wire:loading.attr="disabled"
                            wire:target="resumeConversation"
                        >
                            <span wire:loading.remove wire:target="resumeConversation">Resume last conversation</span>
                            <span wire:loading wire:target="resumeConversation">Loading conversation…</span>
                        </x-filament::link>
                    </x-slot>
                @endif
            </x-filament::empty-state>
        @endif

        @foreach ($timeline as $entry)
            @if ($entry['kind'] === 'action')
                @php $action = $entry['model']; @endphp
                <div class="sidekick-action-outcome sidekick-action-outcome-{{ $action->status }}" wire:key="sidekick-action-{{ $action->id }}">
                    {{ $action->summary }} —
                    @if ($action->status === PendingAction::STATUS_EXECUTED)
                        {{ $action->result ?: 'done' }}
                    @elseif ($action->status === PendingAction::STATUS_FAILED)
                        failed: {{ $action->result }}
                    @elseif ($action->status === PendingAction::STATUS_CANCELLED)
                        {{ $action->result ?: 'cancelled — nothing was done.' }}
                    @else
                        card expired without a response — nothing was done.
                    @endif
                </div>
                @continue
            @endif

            @php $message = $entry['model']; @endphp

            @if ($message->role === 'user')
                @if (trim($message->content) !== '')
                    <div class="sidekick-bubble sidekick-bubble-user">{{ $message->content }}</div>
                @endif
                @if (is_array($message->attachments) && $message->attachments !== [])
                    <div class="sidekick-chips sidekick-chips-user">
                        @foreach ($message->attachments as $attachment)
                            @continue(! is_array($attachment) || blank($attachment['name'] ?? null))
                            <x-filament::badge color="gray" :icon="$icons['attach']">
                                {{ $attachment['name'] }}
                                @if (($attachment['size'] ?? 0) > 0)
                                    <span class="sidekick-chip-size">{{ Attachment::formatBytes((int) $attachment['size']) }}</span>
                                @endif
                            </x-filament::badge>
                        @endforeach
                    </div>
                @endif
            @elseif ($message->role === 'assistant')
                @foreach ($message->decodedToolCalls() as $call)
                    @continue(($call['name'] ?? '') === 'PresentActions')
                    <div class="sidekick-activity">
                        <x-filament::icon :icon="$icons['tool_done']" class="sidekick-activity-icon" />
                        <span>{{ app(ToolRegistry::class)->labelFor($call['name'] ?? '') ?? ($call['name'] ?? 'tool') }}</span>
                    </div>
                @endforeach

                @if (trim($message->content) !== '')
                    <div class="sidekick-bubble sidekick-bubble-assistant sidekick-prose">
                        {!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                @endif

                @php $messageActions = $this->messageActions($message); @endphp
                @if ($messageActions !== [])
                    <div class="sidekick-msg-actions">
                        @foreach ($messageActions as $action)
                            <x-filament::button
                                tag="a"
                                size="xs"
                                outlined
                                :href="$action['url']"
                                :target="$action['external'] ? '_blank' : null"
                            >{{ $action['label'] }}</x-filament::button>
                        @endforeach
                    </div>
                @endif
            @endif
        @endforeach

        @if ($activeRun)
            @if (trim($activeRun->prompt) !== '')
                <div class="sidekick-bubble sidekick-bubble-user">{{ $activeRun->prompt }}</div>
            @endif

            @if ($activeRunAttachments->isNotEmpty())
                <div class="sidekick-chips sidekick-chips-user">
                    @foreach ($activeRunAttachments as $attachment)
                        <x-filament::badge color="gray" :icon="$icons['attach']">
                            {{ $attachment->name }}
                            <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                        </x-filament::badge>
                    @endforeach
                </div>
            @endif

            @foreach ($activeActivity as $entry)
                <div class="sidekick-activity">
                    @if ($entry['done'])
                        <x-filament::icon :icon="$icons['tool_done']" class="sidekick-activity-icon" />
                    @else
                        <x-filament::loading-indicator class="sidekick-activity-icon" />
                    @endif
                    <span>{{ $entry['label'] }}</span>
                </div>
            @endforeach

            @if (filled($activeRun->partial_content))
                {{-- Plain text while streaming (the typewriter owns the text node); markdown arrives with the persisted message. --}}
                <div class="sidekick-bubble sidekick-bubble-assistant sidekick-stream-text" x-data="sidekickStream">{{ $activeRun->partial_content }}</div>
            @else
                <div class="sidekick-thinking" aria-label="Thinking">
                    <span></span><span></span><span></span>
                </div>
            @endif
        @endif

        @if ($failedRun)
            {{-- Limiter denials show their own message; real errors stay generic so provider details never leak. --}}
            <x-filament::callout
                :color="$failedRun->denied ? 'warning' : 'danger'"
                :icon="$failedRun->denied ? 'heroicon-m-hand-raised' : 'heroicon-m-exclamation-triangle'"
                :heading="$failedRun->denied ? 'Not available right now' : 'Something went wrong'"
                :description="$failedRun->denied ? $failedRun->error : 'The assistant couldn\'t finish that reply.'"
                class="sidekick-callout"
            >
                @unless ($failedRun->denied)
                    <x-slot name="footer">
                        <x-filament::link tag="button" size="sm" color="danger" wire:click="retry">Try again</x-filament::link>
                    </x-slot>
                @endunless
            </x-filament::callout>
        @endif
    </div>

    @if ($activeAction && ! $activeAction->rendersInModal())
        {{-- Inline: the card takes the composer's place until it is answered. --}}
        <div class="sidekick-action-dock" wire:key="sidekick-dock-{{ $activeAction->id }}">
            <div class="sidekick-action-card">
                @include('sidekick::partials.action-card')
            </div>
        </div>
    @elseif ($activeAction)
        {{-- Modal: the dock keeps a way back in, since a reload dismisses the modal. --}}
        <div class="sidekick-action-dock" wire:key="sidekick-dock-{{ $activeAction->id }}">
            <x-filament::callout
                color="primary"
                :icon="$icons['assistant']"
                heading="A confirmation is waiting"
                :description="$activeAction->summary"
            >
                <x-slot name="footer">
                    <x-filament::link tag="button" size="sm" wire:click="openActionModal">Review it</x-filament::link>
                </x-slot>
            </x-filament::callout>
        </div>
    @else
        <div class="sidekick-composer">
            @if ($uploadError)
                <p class="sidekick-upload-error">{{ $uploadError }}</p>
            @endif

            @if ($stagedAttachments->isNotEmpty())
                <div class="sidekick-chips">
                    @foreach ($stagedAttachments as $attachment)
                        <x-filament::badge color="gray" :icon="$icons['attach']" wire:key="sidekick-chip-{{ $attachment->id }}">
                            {{ $attachment->name }}
                            <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                            <x-slot
                                name="deleteButton"
                                :label="'Remove '.$attachment->name"
                                wire:click="removeAttachment('{{ $attachment->id }}')"
                            ></x-slot>
                        </x-filament::badge>
                    @endforeach
                </div>
            @endif

            <div class="sidekick-composer-row">
                {{-- Deliberately NOT disabled mid-run: disabling a focused element drops focus to <body>. send() ignores mid-run submits server-side. --}}
                <x-filament::input.wrapper class="sidekick-composer-field fi-fo-textarea">
                    <textarea
                        wire:model="draft"
                        rows="2"
                        placeholder="{{ $activeRun ? 'Waiting for '.$assistantName.'…' : 'Message '.$assistantName.'…' }}"
                        maxlength="{{ $maxPromptLength }}"
                        x-data
                        x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                        x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send().then(() => { $el.style.height = 'auto'; }); }"
                        x-on:sidekick-focus-composer.window="$el.focus()"
                    ></textarea>
                </x-filament::input.wrapper>

                <div class="sidekick-composer-buttons">
                    @if ($attachmentsEnabled)
                        {{-- Rendered as a <label> for the hidden input: native file dialog, zero JS, stock button chrome. --}}
                        <x-filament::button
                            tag="label"
                            for="sidekick-uploads-{{ $this->getId() }}"
                            :icon="$icons['attach']"
                            color="gray"
                            :label-sr-only="true"
                            wire:target="uploads"
                            class="sidekick-attach-btn"
                        >Attach files</x-filament::button>
                        <input
                            id="sidekick-uploads-{{ $this->getId() }}"
                            type="file"
                            class="fi-sr-only"
                            wire:model="uploads"
                            multiple
                            @if ($attachmentsAccept !== '') accept="{{ $attachmentsAccept }}" @endif
                        >
                    @endif

                    <x-filament::button
                        :icon="$icons['send']"
                        :label-sr-only="true"
                        class="sidekick-send-btn"
                        wire:click="send"
                        :disabled="(bool) $activeRun"
                    >Send</x-filament::button>
                </div>
            </div>
        </div>
    @endif

    @if ($activeAction && $activeAction->rendersInModal())
        {{-- Not dismissible: answering it is the only way out. A reload still escapes it, which is what the dock link is for. --}}
        <x-filament::modal
            :id="$actionModalId"
            teleport="body"
            width="lg"
            alignment="start"
            :close-button="false"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
            :heading="$activeAction->summary"
            wire:key="sidekick-modal-{{ $activeAction->id }}"
        >
            <div class="sidekick-action-card sidekick-action-card-modal">
                @include('sidekick::partials.action-card')
            </div>
        </x-filament::modal>

        @if ($actionModalOpen)
            <div
                wire:key="sidekick-modal-open-{{ $activeAction->id }}"
                x-data
                x-init="$dispatch('open-modal', { id: @js($actionModalId) })"
            ></div>
        @endif
    @endif
</div>
