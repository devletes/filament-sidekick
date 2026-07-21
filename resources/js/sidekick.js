// Reverb/Pusher bridge: nudge the panel to re-render when a run row changes.
// Subscribed from JS rather than Livewire's native `echo-` listeners — those
// silently no-op when window.Echo isn't there yet, and Filament creates Echo
// in its own boot order (dispatching `EchoLoaded` when it does).
const sidekickEchoChannels = new Set();

const sidekickEchoSubscribe = (el) => {
    const channel = el.dataset.sidekickEchoChannel;
    const event = el.dataset.sidekickEchoEvent;

    if (! channel || ! event) {
        return;
    }

    const listen = () => {
        if (sidekickEchoChannels.has(channel)) {
            return;
        }

        sidekickEchoChannels.add(channel);

        window.Echo.private(channel).listen(event, () => {
            window.Livewire?.dispatch('sidekick-echo-nudge');
        });
    };

    window.Echo ? listen() : window.addEventListener('EchoLoaded', listen, { once: true });
};

// wire:navigate swaps in a fresh <body> without re-running the panel's inline
// boot script — re-apply the state classes it set at first paint. (Fires on
// the initial load too; everything here is idempotent.)
document.addEventListener('livewire:navigated', () => {
    const boot = window.__sidekickBoot;

    if (! boot) {
        return;
    }

    document.body.style.setProperty('--sidekick-width', boot.width);
    document.body.classList.toggle('sidekick-full-height', !! boot.fullHeight);
    document.body.classList.toggle(
        'sidekick-open',
        window.Alpine?.store('sidekick')?.open ?? localStorage.getItem('sidekick.open') === '1',
    );
    document.body.classList.add('sidekick-ready');
});

// One-shot client redirect requested by a completed turn (see ChatPanel::consumeNavigation).
window.addEventListener('sidekick-navigate', (event) => {
    const url = event.detail?.url ?? event.detail?.[0]?.url;

    if (! url) {
        return;
    }

    // Soft-navigate where Livewire supports it (SPA mode keeps the panel —
    // and the conversation — alive through the redirect); hard fallback otherwise.
    if (window.Livewire?.navigate) {
        window.Livewire.navigate(url);
    } else {
        window.location.assign(url);
    }
});

document.addEventListener('alpine:init', () => {
    if (! window.Alpine.store('sidekick')) {
        window.Alpine.store('sidekick', {
            // The panel blade's inline script applies the persisted state to the
            // body before first paint; the store adopts it as the source of truth.
            open: document.body.classList.contains('sidekick-open'),

            toggle() {
                this.set(! this.open);
            },

            set(open) {
                this.open = open;
                document.body.classList.toggle('sidekick-open', open);

                try {
                    localStorage.setItem('sidekick.open', open ? '1' : '0');
                } catch (e) {
                    // Storage unavailable (private mode) — state just won't persist.
                }
            },
        });
    }

    // Echo bridge host — sits on the panel root so the subscription happens
    // whenever the (lazy) component actually lands in the DOM.
    window.Alpine.data('sidekickEcho', () => ({
        init() {
            sidekickEchoSubscribe(this.$el);
        },
    }));

    // Sticky auto-scroll: follows new content only while the user is already
    // near the bottom, so reading scrollback is never hijacked. The end is the
    // default position — sending (or resolving a card) always jumps back to it
    // via the `sidekick-jump-to-end` event.
    window.Alpine.data('sidekickLog', () => ({
        stick: true,

        init() {
            this.toBottom();

            new MutationObserver(() => {
                if (this.stick) {
                    this.toBottom();
                }
            }).observe(this.$el, { childList: true, characterData: true, subtree: true });

            // Size changes move the bottom edge without any DOM mutation
            // inside the log — the panel opening from zero width, and the
            // composer/card swap changing the log's height. Re-stick on both.
            new ResizeObserver(() => {
                if (this.stick) {
                    this.toBottom();
                }
            }).observe(this.$el);
        },

        toBottom() {
            this.$el.scrollTop = this.$el.scrollHeight;
        },

        jump() {
            this.stick = true;
            this.toBottom();
        },

        onScroll() {
            this.stick = this.$el.scrollHeight - this.$el.scrollTop - this.$el.clientHeight < 80;
        },
    }));

    // Typewriter for the streaming bubble: server polls land text in chunks;
    // this re-truncates each chunk and reveals it letter by letter, pacing up
    // with the backlog so it never falls behind the stream.
    window.Alpine.data('sidekickStream', () => ({
        shown: '',
        target: '',
        typing: false,

        init() {
            // Adopt pre-rendered text (page load mid-run) without animating it.
            this.shown = this.target = this.$el.textContent;

            new MutationObserver(() => {
                const full = this.$el.textContent;

                if (full === this.shown) {
                    return; // our own typewriter write echoing back
                }

                if (full.startsWith(this.shown)) {
                    this.target = full;
                    this.$el.textContent = this.shown; // hand content back to the typewriter
                    this.type();
                } else {
                    this.shown = this.target = full; // replaced wholesale — adopt as-is
                }
            }).observe(this.$el, { childList: true, characterData: true, subtree: true });
        },

        type() {
            if (this.typing) {
                return;
            }

            this.typing = true;
            this.carry = 0;

            const step = () => {
                if (this.shown.length >= this.target.length) {
                    this.typing = false;
                    this.carry = 0;

                    return;
                }

                // Spread the backlog across roughly one poll interval so the
                // reveal is a steady letter-by-letter flow instead of a
                // sprint-then-stall per chunk. The fractional carry keeps
                // sub-1-char-per-frame speeds smooth.
                const backlog = this.target.length - this.shown.length;
                this.carry += Math.min(Math.max(backlog / 90, 0.45), 8);

                const take = Math.floor(this.carry);

                if (take > 0) {
                    this.carry -= take;
                    this.shown = this.target.slice(0, this.shown.length + take);
                    this.$el.textContent = this.shown;
                }

                requestAnimationFrame(step);
            };

            requestAnimationFrame(step);
        },
    }));
});
