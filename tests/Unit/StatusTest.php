<?php

use App\Enums\Status;

test('statuses rank error over warning over unknown over ok', function () {
    expect(array_map(fn (Status $status) => $status->rank(), [Status::Ok, Status::Unknown, Status::Warning, Status::Error]))
        ->toBe([0, 1, 2, 3]);
});

test('only warning and error are failures', function (Status $status, bool $isFailure) {
    expect($status->isFailure())->toBe($isFailure);
})->with([
    [Status::Ok, false],
    [Status::Unknown, false],
    [Status::Warning, true],
    [Status::Error, true],
]);

test('the worst status wins, and nothing at all is unknown', function () {
    expect(Status::worst(Status::Ok, Status::Unknown))->toBe(Status::Unknown)
        ->and(Status::worst(Status::Warning, Status::Ok, Status::Unknown))->toBe(Status::Warning)
        ->and(Status::worst(Status::Warning, Status::Error))->toBe(Status::Error)
        ->and(Status::worst())->toBe(Status::Unknown);
});
