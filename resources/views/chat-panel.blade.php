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
                <x-filament::button
                    color="gray"
                    size="sm"
                    icon="heroicon-m-plus"
                    wire:click="newConversation"
                    wire:loading.attr="disabled"
                    wire:target="newConversation"
                    title="New conversation"
                ><span class="fi-sr-only">New conversation</span></x-filament::button>
            @endif

            {{-- Close (shown only in overlay mode, where the topbar toggle is covered). --}}
            <x-filament::icon-button
                icon="heroicon-m-x-mark"
                color="gray"
                label="Close assistant"
                class="sidekick-close"
                x-data
                x-on:click="$store.sidekick.set(false)"
            />
        </span>
    </div>

    <div class="sidekick-log" x-data="sidekickLog" x-on:scroll="onScroll" x-on:sidekick-jump-to-end.window="jump">
        @if ($messages->isEmpty() && ! $activeRun)
            <div class="sidekick-empty">
                <div class="sidekick-empty-avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor" aria-hidden="true">
                        <path d="M173.79,51.48a221.25,221.25,0,0,0-41.67-34.34,8,8,0,0,0-8.24,0A221.25,221.25,0,0,0,82.21,51.48C54.59,80.48,40,112.47,40,144a88,88,0,0,0,176,0C216,112.47,201.41,80.48,173.79,51.48ZM96,184c0-27.67,22.53-47.28,32-54.3,9.48,7,32,26.63,32,54.3a32,32,0,0,1-64,0Zm77.27,15.93A47.8,47.8,0,0,0,176,184c0-44-42.09-69.79-43.88-70.86a8,8,0,0,0-8.24,0C122.09,114.21,80,140,80,184a47.8,47.8,0,0,0,2.73,15.93A71.88,71.88,0,0,1,56,144c0-34.41,20.4-63.15,37.52-81.19A216.21,216.21,0,0,1,128,33.54a215.77,215.77,0,0,1,34.48,29.27C193.49,95.5,200,125,200,144A71.88,71.88,0,0,1,173.27,199.93Z" />
                    </svg>
                </div>
                <p class="sidekick-empty-title">
                    {{ auth()->user()?->first_name ? 'Hi '.auth()->user()->first_name.' — I\'m '.$assistantName : 'I\'m '.$assistantName }}
                </p>
                @if ($assistantDescription)
                    <p class="sidekick-empty-text">{{ $assistantDescription }}</p>
                @endif
                @if ($canResume)
                    <button
                        type="button"
                        class="sidekick-resume-link"
                        wire:click="resumeConversation"
                        wire:loading.attr="disabled"
                        wire:target="resumeConversation"
                    >
                        <x-filament::loading-indicator
                            wire:loading
                            wire:target="resumeConversation"
                        />
                        <span wire:loading.remove wire:target="resumeConversation">Resume last conversation</span>
                        <span wire:loading wire:target="resumeConversation">Loading conversation…</span>
                    </button>
                @endif
            </div>
        @endif

        @foreach ($timeline as $entry)
            @if ($entry['kind'] === 'action')
                @php $action = $entry['model']; @endphp
                <div class="sidekick-action-outcome sidekick-action-outcome-{{ $action->status }}" wire:key="sidekick-action-{{ $action->id }}">
                    {{ $action->summary }} —
                    @if ($action->status === \Devletes\Sidekick\Models\PendingAction::STATUS_EXECUTED)
                        {{ $action->result ?: 'done' }}
                    @elseif ($action->status === \Devletes\Sidekick\Models\PendingAction::STATUS_FAILED)
                        failed: {{ $action->result }}
                    @elseif ($action->status === \Devletes\Sidekick\Models\PendingAction::STATUS_CANCELLED)
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
                            <span class="sidekick-chip">
                                <x-filament::icon icon="heroicon-m-paper-clip" class="sidekick-chip-icon" />
                                <span class="sidekick-chip-name">{{ $attachment['name'] }}</span>
                                @if (($attachment['size'] ?? 0) > 0)
                                    <span class="sidekick-chip-size">{{ \Devletes\Sidekick\Models\Attachment::formatBytes((int) $attachment['size']) }}</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                @endif
            @elseif ($message->role === 'assistant')
                @foreach ($message->decodedToolCalls() as $call)
                    @continue(in_array($call['name'] ?? '', ['PresentActions'], true))
                    <div class="sidekick-activity sidekick-activity-done">
                        <svg class="sidekick-activity-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                        <span>{{ app(\Devletes\Sidekick\Support\ToolRegistry::class)->labelFor($call['name'] ?? '') ?? ($call['name'] ?? 'tool') }}</span>
                    </div>
                @endforeach
                @if (trim($message->content) !== '')
                    <div class="sidekick-bubble sidekick-bubble-assistant sidekick-prose">
                        {!! \Illuminate\Support\Str::markdown($message->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                @endif
                @php $sidekickActions = $this->messageActions($message); @endphp
                @if ($sidekickActions !== [])
                    <div class="sidekick-msg-actions">
                        @foreach ($sidekickActions as $action)
                            <a
                                href="{{ $action['url'] }}"
                                class="sidekick-action-btn"
                                @if ($action['external']) target="_blank" rel="noopener" @endif
                            >{{ $action['label'] }}</a>
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
                        <span class="sidekick-chip">
                            <x-filament::icon icon="heroicon-m-paper-clip" class="sidekick-chip-icon" />
                            <span class="sidekick-chip-name">{{ $attachment->name }}</span>
                            <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

            @foreach ($activeActivity as $entry)
                <div class="sidekick-activity {{ $entry['done'] ? 'sidekick-activity-done' : '' }}">
                    @if ($entry['done'])
                        <svg class="sidekick-activity-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    @else
                        <span class="sidekick-spinner" aria-hidden="true"></span>
                    @endif
                    <span>{{ $entry['label'] }}</span>
                </div>
            @endforeach

            @if (filled($activeRun->partial_content))
                {{-- Plain text while streaming (the typewriter owns the text node);
                     markdown formatting arrives with the persisted message. --}}
                <div
                    class="sidekick-bubble sidekick-bubble-assistant sidekick-stream-text"
                    x-data="sidekickStream"
                >{{ $activeRun->partial_content }}</div>
            @else
                <div class="sidekick-thinking" aria-label="Thinking">
                    <span></span><span></span><span></span>
                </div>
            @endif
        @endif

        @if ($failedRun)
            <div class="sidekick-error">
                <p>Something went wrong — the assistant couldn't finish that reply.</p>
                <button type="button" wire:click="retry">Try again</button>
            </div>
        @endif

    </div>

    @if ($activeAction)
        {{-- The card replaces the composer: answering it is the only way forward. --}}
        <div class="sidekick-action-dock" wire:key="sidekick-dock-{{ $activeAction->id }}">
            <div class="sidekick-action-card">
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
                    {{-- One "Attachments" set: files the proposal already
                         references (from chat) and files added right here wear
                         the same chips — only the latter are removable. --}}
                    <div class="sidekick-card-upload">
                        <p class="sidekick-card-upload-label">
                            Attachments
                            @if ($activeAction->requiresUpload() && $cardAttachments->isEmpty())
                                <span class="sidekick-upload-required">(required)</span>
                            @endif
                        </p>
                        @if ($cardPayloadAttachments->isNotEmpty() || $cardAttachments->isNotEmpty())
                            <div class="sidekick-chips">
                                @foreach ($cardPayloadAttachments as $attachment)
                                    {{-- Referenced from chat: removing detaches it
                                         from the proposal, the chat file stays. --}}
                                    <span class="sidekick-chip" wire:key="sidekick-card-ref-{{ $attachment->id }}">
                                        <x-filament::icon icon="heroicon-m-paper-clip" class="sidekick-chip-icon" />
                                        <span class="sidekick-chip-name">{{ $attachment->name }}</span>
                                        <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                                        <button type="button" class="sidekick-chip-remove" wire:click="removeProposalAttachment('{{ $activeAction->id }}', '{{ $attachment->id }}')" aria-label="Remove {{ $attachment->name }}">
                                            <x-filament::icon icon="heroicon-m-x-mark" />
                                        </button>
                                    </span>
                                @endforeach
                                @foreach ($cardAttachments as $attachment)
                                    <span class="sidekick-chip" wire:key="sidekick-card-chip-{{ $attachment->id }}">
                                        <x-filament::icon icon="heroicon-m-paper-clip" class="sidekick-chip-icon" />
                                        <span class="sidekick-chip-name">{{ $attachment->name }}</span>
                                        <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                                        <button type="button" class="sidekick-chip-remove" wire:click="removeCardAttachment('{{ $attachment->id }}')" aria-label="Remove {{ $attachment->name }}">
                                            <x-filament::icon icon="heroicon-m-x-mark" />
                                        </button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                        @if ($activeAction->acceptsUpload() && $attachmentsEnabled)
                            {{-- The transparent input covers the whole field —
                                 clicking anywhere on it opens the file dialog. --}}
                            <label class="sidekick-upload-field">
                                <span>Browse to select your file</span>
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
                    >Cancel</x-filament::button>
                    <x-filament::button
                        color="primary"
                        size="sm"
                        wire:click="confirmAction('{{ $activeAction->id }}')"
                        wire:loading.attr="disabled"
                        :disabled="$activeAction->requiresUpload() && $cardAttachments->isEmpty()"
                    >Confirm</x-filament::button>
                </div>
                <p class="sidekick-action-card-note">Nothing is submitted until you confirm.</p>
            </div>
        </div>
    @else
    <div class="sidekick-composer">
        @if ($uploadError)
            <p class="sidekick-upload-error">{{ $uploadError }}</p>
        @endif

        @if ($stagedAttachments->isNotEmpty())
            <div class="sidekick-chips">
                @foreach ($stagedAttachments as $attachment)
                    <span class="sidekick-chip" wire:key="sidekick-chip-{{ $attachment->id }}">
                        <x-filament::icon icon="heroicon-m-paper-clip" class="sidekick-chip-icon" />
                        <span class="sidekick-chip-name">{{ $attachment->name }}</span>
                        <span class="sidekick-chip-size">{{ $attachment->humanSize() }}</span>
                        <button type="button" class="sidekick-chip-remove" wire:click="removeAttachment('{{ $attachment->id }}')" aria-label="Remove {{ $attachment->name }}">
                            <x-filament::icon icon="heroicon-m-x-mark" />
                        </button>
                    </span>
                @endforeach
            </div>
        @endif

        <div class="sidekick-composer-row">
            {{-- Same structure as Filament's own textarea field: fi-fo-textarea
                 wrapper + bare textarea. Deliberately NOT disabled while a run
                 is active — disabling a focused element drops focus to <body>,
                 which cost a click after every message. The send button carries
                 the busy state; send() ignores mid-run submits server-side. --}}
            <x-filament::input.wrapper class="sidekick-composer-field fi-fo-textarea">
                <textarea
                    wire:model="draft"
                    rows="2"
                    placeholder="{{ $activeRun ? 'Waiting for '.$assistantName.'…' : 'Message '.$assistantName.'…' }}"
                    maxlength="{{ config('sidekick.max_prompt_length', 4000) }}"
                    x-data
                    x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 120) + 'px'"
                    x-on:keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); $wire.send().then(() => { $el.style.height = 'auto'; }); }"
                    x-on:sidekick-focus-composer.window="$el.focus()"
                ></textarea>
            </x-filament::input.wrapper>

            {{-- Icon-only column (attach over send); the textarea's min-height
                 matches the stack so the composer sits flush at rest. Both are
                 standard fi-btn buttons (label-sr-only, side padding trimmed
                 square in CSS) — the attach one rendered as a <label> for the
                 visually-hidden file input beside it, so clicking it opens the
                 native file dialog with zero JS while keeping the component's
                 exact chrome, colors, and loading indicator. --}}
            <div class="sidekick-composer-buttons">
                @if ($attachmentsEnabled)
                    <x-filament::button
                        tag="label"
                        for="sidekick-uploads-{{ $this->getId() }}"
                        icon="heroicon-m-paper-clip"
                        color="gray"
                        :label-sr-only="true"
                        wire:target="uploads"
                        class="sidekick-attach-btn"
                    >Attach files</x-filament::button>
                    {{-- Stays enabled during a run: staged files simply ride
                         the next message. --}}
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
                    icon="heroicon-m-arrow-up"
                    :label-sr-only="true"
                    class="sidekick-send-btn"
                    wire:click="send"
                    :disabled="(bool) $activeRun"
                >Send</x-filament::button>
            </div>
        </div>
    </div>
    @endif
</div>
