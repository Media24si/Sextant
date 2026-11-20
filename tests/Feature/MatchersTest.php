<?php

use App\Detection\ExternalServiceMatcher;
use App\Detection\PublishedRanges;
use App\Detection\ServerMatcher;
use App\Enums\RangeSource;
use App\Models\ExternalService;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Rules\IpAddressIsFree;
use Illuminate\Support\Facades\Http;

function publishCloudflareRanges(): void
{
    Http::fake([
        'www.cloudflare.com/ips-v4' => Http::response("104.16.0.0/13\n172.64.0.0/13\n"),
        'www.cloudflare.com/ips-v6' => Http::response("2606:4700::/32\n"),
        'api.fastly.com/*' => Http::response(['addresses' => ['151.101.0.0/16'], 'ipv6_addresses' => ['2a04:4e40::/32']]),
        'ip-ranges.amazonaws.com/*' => Http::response(['prefixes' => [
            ['ip_prefix' => '13.32.0.0/15', 'service' => 'CLOUDFRONT'],
            ['ip_prefix' => '3.5.140.0/22', 'service' => 'S3'],
        ], 'ipv6_prefixes' => [['ipv6_prefix' => '2600:9000::/28', 'service' => 'CLOUDFRONT']]]),
    ]);

    app(PublishedRanges::class)->refresh();
}

it('matches an address to the server that owns it', function (string $stored, string $asked) {
    $server = Server::factory()->create();
    ServerAddress::factory()->for($server)->create(['ip' => $stored]);

    expect(app(ServerMatcher::class)->forIp($asked)?->is($server))->toBeTrue();
})->with([
    'IPv4' => ['203.0.113.10', '203.0.113.10'],
    'IPv6, written differently' => ['2001:db8::10', '2001:0db8:0:0::10'],
]);

it('ignores deleted servers', function () {
    $server = Server::factory()->create();
    ServerAddress::factory()->for($server)->create(['ip' => '203.0.113.10']);
    $server->delete();

    expect(app(ServerMatcher::class)->forIp('203.0.113.10'))->toBeNull();
});

it('matches an external service by its own ranges', function (string $ip) {
    $varnish = ExternalService::factory()->create(['name' => 'Varnish', 'ranges' => ['203.0.113.80', '2001:db8:80::/48']]);

    $match = app(ExternalServiceMatcher::class)->match([$ip]);

    expect($match?->service->is($varnish))->toBeTrue()
        ->and($match?->reason)->toBe("{$ip} belongs to the external service Varnish.");
})->with(['203.0.113.80', '2001:db8:80::1']);

it('matches an external service by its published ranges', function (string $ip) {
    publishCloudflareRanges();
    $cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();

    expect(app(ExternalServiceMatcher::class)->match([$ip])?->service->is($cloudflare))->toBeTrue();
})->with(['104.21.5.9', '2606:4700::6810:1']);

it('keeps only CloudFront prefixes from the AWS list', function () {
    publishCloudflareRanges();

    expect(app(PublishedRanges::class)->for(RangeSource::Cloudfront))->toBe(['13.32.0.0/15', '2600:9000::/28'])
        ->and(app(PublishedRanges::class)->for(RangeSource::Fastly))->toBe(['151.101.0.0/16', '2a04:4e40::/32']);
});

it('matches by CNAME suffix and by response header (the seeded Cloudflare sends cf-ray)', function () {
    $fastly = ExternalService::factory()->create(['name' => 'Fastly', 'ranges' => [], 'cname_suffixes' => ['.fastly.net']]);
    $cloudflare = ExternalService::query()->where('name', 'Cloudflare')->sole();
    $matcher = app(ExternalServiceMatcher::class);

    expect($matcher->match(['198.51.100.1'], ['svet24.map.fastly.net'])?->service->is($fastly))->toBeTrue()
        ->and($matcher->match(['198.51.100.1'], [], ['cf-ray' => '8a1b'])?->service->is($cloudflare))->toBeTrue()
        ->and($matcher->match(['198.51.100.1'], ['svet24.si'], ['server' => 'nginx']))->toBeNull();
});

it('keeps the previous ranges when a download fails', function () {
    Cache::forever('published-ranges', ['cloudflare' => ['104.16.0.0/13']]);
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('proxies:refresh')->assertFailed();

    expect(app(PublishedRanges::class)->for(RangeSource::Cloudflare))->toContain('104.16.0.0/13');
});

it('refuses a published CDN address as a server address', function () {
    publishCloudflareRanges();

    $validator = Validator::make(['ip' => '104.16.1.1'], ['ip' => [new IpAddressIsFree]]);

    expect($validator->errors()->first('ip'))->toContain('Cloudflare');
});
