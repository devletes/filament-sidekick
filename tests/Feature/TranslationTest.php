<?php

it('resolves every packaged message key', function () {
    $keys = data_get(require __DIR__.'/../../resources/lang/en/messages.php', null);

    $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($items as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $flat = [...$flat, ...(is_array($value) ? $flatten($value, $path) : [$path])];
        }

        return $flat;
    };

    foreach ($flatten($keys) as $key) {
        expect(__("sidekick::messages.{$key}"))->not->toBe("sidekick::messages.{$key}", "Missing translation for [{$key}]");
    }
});

it('substitutes placeholders rather than printing them', function () {
    expect(__('sidekick::messages.composer.placeholder', ['assistant' => 'Sidekick']))->toBe('Message Sidekick…')
        ->and(__('sidekick::messages.empty_state.greeting_named', ['name' => 'Aria', 'assistant' => 'Sidekick']))
        ->toContain('Aria')
        ->and(__('sidekick::messages.attachments.too_many', ['count' => 3]))->toBe('You can attach up to 3 files.');
});

it('falls back to the packaged english when a locale has no override', function () {
    app()->setLocale('fr');

    expect(__('sidekick::messages.card.confirm'))->toBe('Confirm');
});

it('uses a host translation when one is published for the active locale', function () {
    app('translator')->addLines(['messages.card.confirm' => 'Bestätigen'], 'de', 'sidekick');

    app()->setLocale('de');

    expect(__('sidekick::messages.card.confirm'))->toBe('Bestätigen');
});

it('leaves no hardcoded user-facing strings in the panel views', function () {
    $offenders = [];

    foreach (glob(__DIR__.'/../../resources/views/**/*.blade.php') + glob(__DIR__.'/../../resources/views/*.blade.php') as $file) {
        $contents = file_get_contents($file);

        // Prose in a label/heading/placeholder/tooltip attribute should always be a translation call.
        preg_match_all('/\s(?:label|heading|description|tooltip|placeholder|aria-label)="([A-Z][a-z][^"{]*)"/', $contents, $matches);

        foreach ($matches[1] ?? [] as $literal) {
            $offenders[] = basename($file).': '.$literal;
        }
    }

    expect($offenders)->toBe([]);
});
