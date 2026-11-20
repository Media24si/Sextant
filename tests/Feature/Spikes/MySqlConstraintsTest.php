<?php

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 0 spike (SPEC §3.11, §3.17): MySQL 9.7 behaviour the data model relies on.
 * The tables are throwaway copies of the real shapes, created and dropped here,
 * so no migration is needed. Findings: docs/spikes/mysql-9.7.md.
 */

beforeEach(function () {
    Schema::create('spike_servers', function (Blueprint $table) {
        $table->id();
        $table->string('name', 100);
        $table->softDeletes();
        $table->string('active_name', 100)->nullable()
            ->storedAs('IF(deleted_at IS NULL, name, NULL)')
            ->unique();
    });

    Schema::create('spike_monitors', function (Blueprint $table) {
        $table->id();
    });

    Schema::create('spike_incidents', function (Blueprint $table) {
        $table->id();
        $table->foreignId('monitor_id')->constrained('spike_monitors')->restrictOnDelete();
        $table->timestamp('resolved_at')->nullable();
        $table->unsignedBigInteger('open_monitor_id')->nullable()
            ->storedAs('IF(resolved_at IS NULL, monitor_id, NULL)')
            ->unique();
    });
});

afterEach(function () {
    Schema::dropIfExists('spike_incidents_cascade');
    Schema::dropIfExists('spike_incidents');
    Schema::dropIfExists('spike_monitors');
    Schema::dropIfExists('spike_servers');
});

test('runs on MySQL 9.7', function () {
    expect(DB::scalar('select version()'))->toStartWith('9.7');
});

test('a name is unique among non-deleted rows only', function () {
    DB::table('spike_servers')->insert(['name' => 'web01']);

    expect(fn () => DB::table('spike_servers')->insert(['name' => 'web01']))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('spike_servers')->where('name', 'web01')->update(['deleted_at' => now()]);
    DB::table('spike_servers')->insert(['name' => 'web01']);
    DB::table('spike_servers')->where('active_name', 'web01')->update(['deleted_at' => now()]);
    DB::table('spike_servers')->insert(['name' => 'web01']);

    expect(DB::table('spike_servers')->where('name', 'web01')->count())->toBe(3)
        ->and(DB::table('spike_servers')->whereNotNull('active_name')->count())->toBe(1);
});

test('restoring a soft-deleted row fails while another live row has its name', function () {
    DB::table('spike_servers')->insert(['name' => 'web01', 'deleted_at' => now()]);
    DB::table('spike_servers')->insert(['name' => 'web01']);

    expect(fn () => DB::table('spike_servers')->whereNotNull('deleted_at')->update(['deleted_at' => null]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('uniqueness follows the column collation, so it ignores case', function () {
    DB::table('spike_servers')->insert(['name' => 'web01']);

    expect(fn () => DB::table('spike_servers')->insert(['name' => 'WEB01']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a monitor has at most one open incident', function () {
    $monitorId = DB::table('spike_monitors')->insertGetId([]);

    DB::table('spike_incidents')->insert(['monitor_id' => $monitorId]);

    expect(fn () => DB::table('spike_incidents')->insert(['monitor_id' => $monitorId]))
        ->toThrow(UniqueConstraintViolationException::class);

    DB::table('spike_incidents')->where('monitor_id', $monitorId)->update(['resolved_at' => now()]);
    DB::table('spike_incidents')->insert(['monitor_id' => $monitorId]);
    DB::table('spike_incidents')->whereNull('resolved_at')->update(['resolved_at' => now()]);
    DB::table('spike_incidents')->insert(['monitor_id' => $monitorId]);

    expect(DB::table('spike_incidents')->where('monitor_id', $monitorId)->count())->toBe(3)
        ->and(DB::table('spike_incidents')->whereNotNull('open_monitor_id')->count())->toBe(1);
});

test('a restrict foreign key blocks deleting a monitor that has incidents', function () {
    $monitorId = DB::table('spike_monitors')->insertGetId([]);
    DB::table('spike_incidents')->insert(['monitor_id' => $monitorId, 'resolved_at' => now()]);

    expect(fn () => DB::table('spike_monitors')->delete($monitorId))
        ->toThrow(QueryException::class, 'foreign key constraint fails');
});

test('a foreign key on a generated column\'s base column cannot cascade or set null', function (string $action) {
    expect(fn () => Schema::create('spike_incidents_cascade', function (Blueprint $table) use ($action) {
        $table->id();
        $table->foreignId('monitor_id')->nullable()->constrained('spike_monitors')->onDelete($action);
        $table->timestamp('resolved_at')->nullable();
        $table->unsignedBigInteger('open_monitor_id')->nullable()
            ->storedAs('IF(resolved_at IS NULL, monitor_id, NULL)')
            ->unique();
    }))->toThrow(QueryException::class, 'Cannot add foreign key constraint');
})->with(['cascade', 'set null']);

test('the same cascading foreign key is accepted without the generated column', function () {
    Schema::create('spike_incidents_cascade', function (Blueprint $table) {
        $table->id();
        $table->foreignId('monitor_id')->nullable()->constrained('spike_monitors')->cascadeOnDelete();
        $table->timestamp('resolved_at')->nullable();
    });

    expect(Schema::getForeignKeys('spike_incidents_cascade'))->toHaveCount(1);
});
