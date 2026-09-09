<?php

namespace App\Services;

use App\Enums\AnswerMatchMethod;
use App\Enums\QuestionAnswerType;
use App\Models\Question;
use Illuminate\Support\Str;

class QuizAnswerMatcher
{
    public function match(Question $question, string $submittedAnswer): AnswerMatchMethod
    {
        $submittedAnswer = $this->normalize($submittedAnswer);
        $acceptedAnswers = collect([$question->correct_answer, ...($question->accepted_answers ?? [])])
            ->filter()
            ->map(fn (string $answer): string => $this->normalize($answer))
            ->filter()
            ->unique();

        if ($acceptedAnswers->contains($submittedAnswer)) {
            return AnswerMatchMethod::Exact;
        }

        if ($question->answer_type === QuestionAnswerType::MultipleChoice) {
            return AnswerMatchMethod::None;
        }

        if ($acceptedAnswers->contains(fn (string $answer): bool => $this->containsAcceptedPhrase($submittedAnswer, $answer))) {
            return AnswerMatchMethod::AcceptedPhrase;
        }

        if ($acceptedAnswers->contains(fn (string $answer): bool => $this->isCloseSpelling($submittedAnswer, $answer))) {
            return AnswerMatchMethod::Spelling;
        }

        return AnswerMatchMethod::None;
    }

    private function normalize(string $answer): string
    {
        $numberWords = [
            'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
            'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
            'ten' => '10', 'eleven' => '11', 'twelve' => '12',
        ];
        $answer = Str::lower(Str::ascii($answer));
        $answer = preg_replace('/[^a-z0-9]+/', ' ', $answer) ?? '';
        $words = preg_split('/\s+/', trim($answer), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if (isset($words[0]) && in_array($words[0], ['a', 'an', 'the'], true)) {
            array_shift($words);
        }

        return implode(' ', array_map(fn (string $word): string => $numberWords[$word] ?? $word, $words));
    }

    private function containsAcceptedPhrase(string $submittedAnswer, string $acceptedAnswer): bool
    {
        return count(explode(' ', $acceptedAnswer)) >= 2
            && str_contains(" {$submittedAnswer} ", " {$acceptedAnswer} ");
    }

    private function isCloseSpelling(string $submittedAnswer, string $acceptedAnswer): bool
    {
        $longestLength = max(strlen($submittedAnswer), strlen($acceptedAnswer));

        if ($longestLength < 5 || count(explode(' ', $submittedAnswer)) !== count(explode(' ', $acceptedAnswer))) {
            return false;
        }

        $maximumDistance = match (true) {
            $longestLength <= 7 => 1,
            $longestLength <= 15 => 2,
            default => 3,
        };
        $distance = levenshtein($submittedAnswer, $acceptedAnswer);

        return $distance <= $maximumDistance && (1 - ($distance / $longestLength)) >= 0.8;
    }
}
