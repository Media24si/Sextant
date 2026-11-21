<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Checks\Conditions\RespondsToPing;
use App\Enums\Status;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Net\PingProbe;
use App\Net\Results\PingResult;

/* `server.ping` (SPEC §9.2). */
function pingCheck(Server $server): CheckResult
{
    $registry = app(CheckerRegistry::class);

    return $registry->checker('server.ping')->run($server, $registry->settings(Monitor::factory()->on($server, 'server.ping')->make())->arguments);
}

beforeEach(function () {
    $this->server = Server::factory()->create(['name' => 'web02']);
});

it('is OK without packet loss', function () {
    ServerAddress::factory()->for($this->server)->primary()->create(['ip' => '192.0.2.10']);
    app(PingProbe::class)->answers('192.0.2.10');

    expect(pingCheck($this->server))
        ->status->toBe(Status::Ok)
        ->message->toBe('0% loss, 9 ms')
        ->data->toBe(['address' => '192.0.2.10', 'loss_pct' => 0.0, 'rtt_avg_ms' => 9.0]);
});

it('warns about partial and total loss', function (float $loss, string $message) {
    ServerAddress::factory()->for($this->server)->primary()->create(['ip' => '192.0.2.10']);
    $probe = Mockery::mock(PingProbe::class);
    $probe->shouldReceive('ping')->andReturn(new PingResult($loss, null));
    app()->instance(PingProbe::class, $probe);

    expect(pingCheck($this->server))->status->toBe(Status::Warning)->message->toBe($message);
})->with([
    'partial' => [33.3, '33% packet loss'],
    'total' => [100.0, '100% packet loss'],
]);

it('pings an IPv4 address when the primary one is IPv6', function () {
    ServerAddress::factory()->for($this->server)->primary()->create(['ip' => '2001:db8::10']);
    ServerAddress::factory()->for($this->server)->create(['ip' => '192.0.2.10']);
    app(PingProbe::class)->answers('192.0.2.10');

    expect(pingCheck($this->server)->data['address'])->toBe('192.0.2.10');
});

it('cannot run without an IPv4 address', function () {
    ServerAddress::factory()->for($this->server)->primary()->create(['ip' => '2001:db8::10']);

    pingCheck($this->server);
})->throws(CheckCouldNotRun::class, "web02 has no IPv4 address to ping (Sextant can't ping over IPv6)");

it('is attached only to servers that answer a ping', function () {
    ServerAddress::factory()->for($this->server)->primary()->create(['ip' => '192.0.2.10']);
    $silent = Server::factory()->create();
    ServerAddress::factory()->for($silent)->primary()->create(['ip' => '192.0.2.99']);
    app(PingProbe::class)->answers('192.0.2.10');

    $condition = app(RespondsToPing::class);

    expect($condition($this->server->fresh(), []))->toBeTrue()
        ->and($condition($silent->fresh(), []))->toBeFalse();
});
