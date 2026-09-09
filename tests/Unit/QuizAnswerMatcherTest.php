<?php

use App\Enums\AnswerMatchMethod;
use App\Enums\QuestionAnswerType;
use App\Models\Question;
use App\Services\QuizAnswerMatcher;

test('it normalizes harmless wording differences', function (string $submittedAnswer) {
    $question = new Question([
        'correct_answer' => 'The VersaPlate',
        'accepted_answers' => [],
    ]);

    expect((new QuizAnswerMatcher)->match($question, $submittedAnswer))->toBe(AnswerMatchMethod::Exact);
})->with([
    'case and whitespace' => '  the versaplate ',
    'leading article' => 'VersaPlate',
    'punctuation' => 'The VersaPlate!',
]);

test('it matches approved alternatives inside a longer response', function () {
    $question = new Question([
        'correct_answer' => 'Sold as a four pack',
        'accepted_answers' => ['four pack'],
    ]);

    expect((new QuizAnswerMatcher)->match($question, "It's a 4 pack"))->toBe(AnswerMatchMethod::AcceptedPhrase);
});

test('it accepts conservative spelling mistakes', function () {
    $question = new Question([
        'correct_answer' => 'four pack',
        'accepted_answers' => [],
    ]);

    expect((new QuizAnswerMatcher)->match($question, 'four pac'))->toBe(AnswerMatchMethod::Spelling);
});

test('it does not fuzzy match short or structurally different answers', function (string $submittedAnswer) {
    $question = new Question([
        'correct_answer' => 'red',
        'accepted_answers' => [],
    ]);

    expect((new QuizAnswerMatcher)->match($question, $submittedAnswer))->toBe(AnswerMatchMethod::None);
})->with([
    'short answer' => 'bed',
    'additional words' => 'bright red',
]);

test('multiple choice answers require an exact normalized option', function () {
    $question = new Question([
        'answer_type' => QuestionAnswerType::MultipleChoice,
        'correct_answer' => 'Four pack',
        'answer_options' => ['Two pack', 'Four pack'],
    ]);
    $matcher = new QuizAnswerMatcher;

    expect($matcher->match($question, 'four pack'))->toBe(AnswerMatchMethod::Exact)
        ->and($matcher->match($question, 'four pac'))->toBe(AnswerMatchMethod::None);
});
