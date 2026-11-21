<?php

use App\Checks\Argument;
use App\Checks\Arguments;

/* SPEC §4.5: monitor overrides win over registry defaults, key by key. */
$schema = fn () => [
    Argument::string('path', 'Path'),
    Argument::intList('expected_status', 'Expected'),
    Argument::bool('verify_tls', 'Verify'),
    Argument::string('contains', 'Contains')->nullable(),
];

it('takes overrides key by key and defaults for the rest', function () use ($schema) {
    $arguments = Arguments::resolve($schema(), ['path' => '/', 'expected_status' => [200], 'verify_tls' => true, 'contains' => null], ['path' => '/novice', 'contains' => '']);

    expect($arguments->string('path'))->toBe('/novice')
        ->and($arguments->intList('expected_status'))->toBe([200])
        ->and($arguments->bool('verify_tls'))->toBeTrue()
        ->and($arguments->nullableString('contains'))->toBeNull();
});

it('ignores overrides that are not in the schema', function () use ($schema) {
    $arguments = Arguments::resolve($schema(), ['path' => '/'], ['retired' => 5]);

    expect($arguments->all())->not->toHaveKey('retired');
});

it('throws for a key that is not in the schema', function () use ($schema) {
    Arguments::resolve($schema(), ['path' => '/'], null)->string('pth');
})->throws(InvalidArgumentException::class, 'Argument `pth` is not in this checker\'s schema.');

it('serialises the schema with defaults for the form', function () {
    expect(Argument::int('port', 'Port')->help('TCP port')->toArray(null, 443))->toBe([
        'type' => 'int', 'name' => 'port', 'label' => 'Port', 'help' => 'TCP port',
        'nullable' => false, 'options' => null, 'default' => 443,
    ]);
});
