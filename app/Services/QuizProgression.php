<?php

namespace App\Services;

use App\Enums\QuizScoringMode;
use App\Models\Question;
use App\Models\QuizEntry;
use Illuminate\Support\Facades\DB;

class QuizProgression
{
    public function advance(QuizEntry $entry): void
    {
        DB::transaction(function () use ($entry): void {
            $lockedEntry = $entry->newQuery()->lockForUpdate()->findOrFail($entry->id);

            if ($lockedEntry->completed_at || $lockedEntry->quiz->scoring_mode !== QuizScoringMode::QuestionAnswer) {
                return;
            }

            $questions = $lockedEntry->assignedQuestions();
            abort_if($questions->isEmpty(), 409);

            if ($lockedEntry->current_question_position !== null) {
                $answer = $lockedEntry->answers()->where('position', $lockedEntry->current_question_position)->first();

                if (! $answer || $answer->canRetry($lockedEntry->quiz)) {
                    return;
                }

                $answer->update(['reviewed_at' => $answer->reviewed_at ?? now()]);
            }

            $answers = $lockedEntry->answers()->whereNotNull('reviewed_at')->get();
            $nextQuestion = $questions->first(fn (Question $question): bool => ! $answers->contains('question_id', $question->id));

            if ($nextQuestion) {
                $lockedEntry->update([
                    'current_question_position' => $nextQuestion->position,
                    'question_released_at' => now(),
                ]);

                return;
            }

            $lockedEntry->update([
                'score' => $answers->whereIn('question_id', $questions->pluck('id')->all())->where('is_correct', true)->count(),
                'elapsed_ms' => $answers->whereIn('question_id', $questions->pluck('id')->all())->sum('elapsed_ms'),
                'completed_at' => now(),
                'current_question_position' => null,
                'question_released_at' => null,
            ]);
        }, attempts: 5);
    }
}
