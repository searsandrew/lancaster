<?php

namespace App\Models;

use App\Enums\AnswerMatchMethod;
use Database\Factories\QuizAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['quiz_entry_id', 'question_id', 'question_prompt', 'submitted_answer', 'position', 'is_correct', 'automatic_match_method', 'elapsed_ms', 'submitted_at', 'reviewed_at'])]
class QuizAnswer extends Model
{
    /** @use HasFactory<QuizAnswerFactory> */
    use HasFactory;

    /** @return BelongsTo<QuizEntry, $this> */
    public function quizEntry(): BelongsTo
    {
        return $this->belongsTo(QuizEntry::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_correct' => 'boolean',
            'automatic_match_method' => AnswerMatchMethod::class,
            'elapsed_ms' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }
}
