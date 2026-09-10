<?php

namespace App\Models;

use Database\Factories\QuizEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['participant_id', 'quiz_id', 'question_ids', 'staff_user_id', 'score', 'elapsed_ms', 'started_at', 'completed_at', 'current_question_position', 'question_released_at'])]
class QuizEntry extends Model
{
    /** @use HasFactory<QuizEntryFactory> */
    use HasFactory;

    /** @return BelongsTo<Participant, $this> */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return BelongsTo<User, $this> */
    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }

    /** @return HasMany<QuizAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class)->orderBy('position');
    }

    /** @return Collection<int, Question> */
    public function assignedQuestions(): Collection
    {
        $questions = $this->quiz->questions;
        $questionIds = $this->getAttribute('question_ids');

        if (! is_array($questionIds)) {
            return $questions;
        }

        return collect($questionIds)
            ->map(fn (mixed $questionId): ?Question => $questions->firstWhere('id', (int) $questionId))
            ->filter()
            ->values();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'elapsed_ms' => 'integer',
            'question_ids' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'current_question_position' => 'integer',
            'question_released_at' => 'datetime',
        ];
    }
}
