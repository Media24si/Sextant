<?php

use App\Support\Hostname;

test('hostnames are stored lowercase, without a trailing dot, in punycode', function (string $input, string $stored) {
    expect(Hostname::normalize($input))->toBe($stored);
})->with([
    ['Svet24.SI', 'svet24.si'],
    ['svet24.si.', 'svet24.si'],
    ['  podjetje.svet24.si  ', 'podjetje.svet24.si'],
    ['šola.si', 'xn--ola-zza.si'],
    ['ŠOLA.si.', 'xn--ola-zza.si'],
    ['xn--ola-zza.si', 'xn--ola-zza.si'],
]);

test('punycode hostnames are shown in Unicode', function () {
    expect(Hostname::display('xn--ola-zza.si'))->toBe('šola.si')
        ->and(Hostname::display('svet24.si'))->toBe('svet24.si');
});
