<?php

use App\Enums\QuizScoringMode;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\Show;
use Illuminate\Database\QueryException;

test('a show can have only one quiz', function () {
    $show = Show::factory()->create();
    Quiz::factory()->for($show)->create();

    expect(fn () => Quiz::factory()->for($show)->create())
        ->toThrow(QueryException::class);
});

test('quiz scoring modes are cast and have staff-facing labels', function (QuizScoringMode $mode, string $label) {
    $quiz = Quiz::factory()->make(['scoring_mode' => $mode]);

    expect($quiz)
        ->scoring_mode->toBe($mode)
        ->and($quiz->scoring_mode->label())->toBe($label);
})->with([
    'per answer' => [QuizScoringMode::PerAnswer, 'Per-answer scoring'],
    'summary' => [QuizScoringMode::Summary, 'Summary scoring'],
]);

test('quiz questions are returned in configured order', function () {
    $quiz = Quiz::factory()->create();
    Question::factory()->for($quiz)->create(['prompt' => 'Third question', 'position' => 3]);
    Question::factory()->for($quiz)->create(['prompt' => 'First question', 'position' => 1]);
    Question::factory()->for($quiz)->create(['prompt' => 'Second question', 'position' => 2]);

    $prompts = $quiz->questions()->pluck('prompt')->all();

    expect($prompts)->toBe([
        'First question',
        'Second question',
        'Third question',
    ]);
});
