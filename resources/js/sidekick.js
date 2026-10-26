// Subscribed from JS because Livewire's `echo-` listeners silently no-op when window.Echo boots late.
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

// wire:navigate swaps <body> without re-running the inline boot script, so re-apply its classes.
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
    boot.topbar?.();
    document.body.classList.add('sidekick-ready');
});

window.addEventListener('sidekick-navigate', (event) => {
    const url = event.detail?.url ?? event.detail?.[0]?.url;

    if (! url) {
        return;
    }

    if (window.Livewire?.navigate) {
        window.Livewire.navigate(url);
    } else {
        window.location.assign(url);
    }
});

document.addEventListener('alpine:init', () => {
    if (! window.Alpine.store('sidekick')) {
        window.Alpine.store('sidekick', {
            // Adopt the state the inline boot script applied before first paint.
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
                    // Storage unavailable (private mode).
                }
            },
        });
    }

    window.Alpine.data('sidekickEcho', () => ({
        init() {
            sidekickEchoSubscribe(this.$el);
        },
    }));

    window.Alpine.data('sidekickLog', () => ({
        stick: true,

        init() {
            this.toBottom();

            new MutationObserver(() => {
                if (this.stick) {
                    this.toBottom();
                }
            }).observe(this.$el, { childList: true, characterData: true, subtree: true });

            // Size changes move the bottom edge without a DOM mutation.
            new ResizeObserver(() => {
                if (this.stick) {
                    this.toBottom();
                }
            }).observe(this.$el);

            // A message may have landed during the navigate swap.
            document.addEventListener('livewire:navigated', () => {
                if (this.stick) {
                    this.toBottom();
                }
            });
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
                    this.shown = this.target = full;
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

                // Drain the backlog over ~one poll interval; the fractional carry smooths sub-1-char frames.
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
