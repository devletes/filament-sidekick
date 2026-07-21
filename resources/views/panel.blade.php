@auth
    @persist('sidekick-panel')
        <aside class="sidekick-aside">
            <div class="sidekick-card">
                @livewire('sidekick.chat-panel')
            </div>
        </aside>
    @endpersist

    {{-- Runs during parse so the open state applies before first paint (no
         slide-in flash on load). Under wire:navigate the swapped-in body does
         NOT re-run this script — sidekick.js re-applies from the stash on
         livewire:navigated. --}}
    <script>
        (function () {
            window.__sidekickBoot = {
                width: @js(config('sidekick.panel.width', '23rem')),
                fullHeight: @js((bool) config('sidekick.panel.full_height')),
            };
            document.body.style.setProperty('--sidekick-width', window.__sidekickBoot.width);
            if (window.__sidekickBoot.fullHeight) {
                document.body.classList.add('sidekick-full-height');
            }
            if (localStorage.getItem('sidekick.open') === '1') {
                document.body.classList.add('sidekick-open');
            }
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    document.body.classList.add('sidekick-ready');
                });
            });
        })();
    </script>
@endauth
