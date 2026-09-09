<?php

namespace App\Models;

use App\Enums\QuestionAnswerType;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['quiz_id', 'prompt', 'correct_answer', 'answer_type', 'accepted_answers', 'answer_options', 'sales_notes', 'position'])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    /**
     * Get the quiz that owns the question.
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return HasMany<QuizAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'answer_type' => QuestionAnswerType::class,
            'accepted_answers' => 'array',
            'answer_options' => 'array',
        ];
    }
}
