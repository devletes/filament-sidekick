@auth
    @persist('sidekick-panel')
        <aside class="sidekick-aside">
            <div class="sidekick-card">
                @livewire('sidekick.chat-panel')
            </div>
        </aside>
    @endpersist

    {{-- Runs during parse so the open state applies before first paint; wire:navigate does NOT re-run this — sidekick.js re-applies on livewire:navigated. --}}
    <script>
        (function () {
            window.__sidekickBoot = {
                width: @js(config('sidekick.panel.width', '23rem')),
                fullHeight: @js((bool) config('sidekick.panel.full_height')),

                // Native Filament renders the topbar outside the flex row the panel
                // squeezes; themes that render it inside .fi-main-ctn (Orbit) already
                // shrink it for free and must not be pushed twice.
                topbar: function () {
                    var topbar = document.querySelector('.fi-topbar-ctn');
                    var column = document.querySelector('.fi-main-ctn');
                    var outside = !! topbar && ! (column && column.contains(topbar));

                    document.body.classList.toggle('sidekick-topbar-outside', outside);

                    if (outside) {
                        document.body.style.setProperty('--sidekick-topbar-height', topbar.offsetHeight + 'px');
                    }
                },
            };

            document.body.style.setProperty('--sidekick-width', window.__sidekickBoot.width);
            if (window.__sidekickBoot.fullHeight) {
                document.body.classList.add('sidekick-full-height');
            }
            if (localStorage.getItem('sidekick.open') === '1') {
                document.body.classList.add('sidekick-open');
            }
            window.__sidekickBoot.topbar();
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    document.body.classList.add('sidekick-ready');
                });
            });
        })();
    </script>
@endauth
