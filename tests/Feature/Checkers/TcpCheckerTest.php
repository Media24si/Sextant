<?php

use App\Checks\CheckCouldNotRun;
use App\Checks\CheckerRegistry;
use App\Checks\CheckResult;
use App\Enums\Status;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\ServerAddress;
use App\Net\TcpProbe;

/* `server.tcp` (SPEC §9.1). */
function tcpCheck(Server $server, array $overrides = []): CheckResult
{
    $monitor = Monitor::factory()->on($server, 'server.tcp')->make(['arguments' => $overrides]);
    $registry = app(CheckerRegistry::class);

    return $registry->checker('server.tcp')->run($server, $registry->settings($monitor)->arguments);
}

beforeEach(function () {
    $this->server = Server::factory()->create(['name' => 'web02']);
    ServerAddress::factory()->for($this->server)->primary()->create(['ip' => '192.0.2.10']);
    ServerAddress::factory()->for($this->server)->create(['ip' => '192.0.2.11']);
});

it('reports an open port with its connect time', function () {
    app(TcpProbe::class)->open('192.0.2.10', 443);

    expect(tcpCheck($this->server))
        ->status->toBe(Status::Ok)
        ->message->toBe('Port 443 open (12 ms)')
        ->data->toBe(['address' => '192.0.2.10', 'port' => 443, 'connect_ms' => 12]);
});

it('tells a refused port from one that timed out', function () {
    app(TcpProbe::class)->timingOut('192.0.2.10', 22);

    expect(tcpCheck($this->server, ['port' => 80]))->status->toBe(Status::Error)->message->toBe('Port 80 refused')
        ->and(tcpCheck($this->server, ['port' => 22]))->message->toBe('Port 22 timed out after 5 s');
});

it('can check another of the server\'s addresses', function () {
    app(TcpProbe::class)->open('192.0.2.11', 22);

    expect(tcpCheck($this->server, ['port' => 22, 'address' => '192.0.2.11'])->status)->toBe(Status::Ok);
});

it('cannot run without a primary address', function () {
    tcpCheck(Server::factory()->create(['name' => 'bare']));
})->throws(CheckCouldNotRun::class, 'bare has no primary address');

it('offers the server\'s addresses in the form', function () {
    $schema = collect(app(CheckerRegistry::class)->schema('server.tcp', $this->server->load('addresses')))->keyBy('name');

    expect($schema['address']['options'])->toBe(['192.0.2.10' => '192.0.2.10', '192.0.2.11' => '192.0.2.11']);
});
