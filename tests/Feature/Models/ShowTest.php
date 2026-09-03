<?php

use App\Enums\ShowActivationMode;
use App\Models\Show;

test('manual activation determines whether a manual show is active', function (bool $isActive) {
    $show = Show::factory()->make([
        'activation_mode' => ShowActivationMode::Manual,
        'is_active' => $isActive,
    ]);

    expect($show->isActiveAt())->toBe($isActive);
})->with([
    'active' => true,
    'inactive' => false,
]);

test('scheduled shows are active only within their inclusive schedule', function (string $moment, bool $isActive) {
    $show = Show::factory()->scheduled()->make();

    expect($show->isActiveAt(new DateTimeImmutable($moment)))->toBe($isActive);
})->with([
    'before start' => ['2026-09-03 08:59:59', false],
    'at start' => ['2026-09-03 09:00:00', true],
    'during show' => ['2026-09-03 12:00:00', true],
    'at end' => ['2026-09-03 17:00:00', true],
    'after end' => ['2026-09-03 17:00:01', false],
]);

test('active show queries include manual and scheduled shows active at that time', function () {
    $manualShow = Show::factory()->active()->create();
    $scheduledShow = Show::factory()->scheduled()->create();
    Show::factory()->create();
    Show::factory()->scheduled('2026-09-04 09:00:00', '2026-09-04 17:00:00')->create();

    $activeShows = Show::query()
        ->activeAt(new DateTimeImmutable('2026-09-03 12:00:00'))
        ->get();

    expect($activeShows->modelKeys())->toEqualCanonicalizing([
        $manualShow->id,
        $scheduledShow->id,
    ]);
});
