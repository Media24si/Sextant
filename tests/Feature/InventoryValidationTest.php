<?php

use App\Models\ExternalService;
use App\Models\Server;
use App\Rules\GithubRepositoryUrl;
use App\Rules\IpAddressIsFree;
use Illuminate\Support\Facades\Validator;

function ipErrors(string $ip, ?Server $ignore = null): array
{
    return Validator::make(['ip' => $ip], ['ip' => [new IpAddressIsFree($ignore)]])->errors()->get('ip');
}

test('a free address passes', function () {
    expect(ipErrors('192.0.2.50'))->toBe([]);
});

test('an address of another live server fails, written any way', function () {
    Server::factory()->withPrimaryAddress('2001:db8::12')->create(['name' => 'web02']);

    expect(ipErrors('2001:DB8:0::12'))->toBe(['2001:db8::12 already belongs to web02.']);
});

test('the server being edited may keep its own address', function () {
    $server = Server::factory()->withPrimaryAddress('192.0.2.12')->create();

    expect(ipErrors('192.0.2.12', $server))->toBe([]);
});

test('an address of a deleted server is free again', function () {
    Server::factory()->withPrimaryAddress('192.0.2.12')->create()->delete();

    expect(ipErrors('192.0.2.12'))->toBe([]);
});

test('an address inside an external service range fails', function (string $ip) {
    ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80', '198.51.100.0/24', '2001:db8:80::/48']]);

    expect(ipErrors($ip))->toBe(["{$ip} is in the ranges of the external service Varnish, so it can't be one of our servers."]);
})->with(['203.0.113.80', '198.51.100.7', '2001:db8:80::1']);

test('github links must point at a repository', function (string $url, bool $valid) {
    $validator = Validator::make(['github_url' => $url], ['github_url' => [new GithubRepositoryUrl]]);

    expect($validator->passes())->toBe($valid);
})->with([
    ['https://github.com/media24/svet24', true],
    ['https://github.com/Media24si/Sextant', true],
    ['https://github.com/media24/svet24.si', true],
    ['http://github.com/media24/svet24', false],
    ['https://github.com/media24', false],
    ['https://github.com/media24/svet24/tree/main', false],
    ['https://gitlab.com/media24/svet24', false],
]);
