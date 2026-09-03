<?php

namespace App\Models;

use App\Enums\LeaderboardDisplayMode;
use App\Enums\QuizScoringMode;
use Database\Factories\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['show_id', 'scoring_mode', 'maximum_score', 'registration_message', 'registration_image_path', 'perfect_score_image_path', 'leaderboard_message', 'leaderboard_display_mode', 'advertisement_embed_url', 'confetti_flash_sequence', 'perfect_score_flash_sequence'])]
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory;

    /**
     * Get the show that owns the quiz.
     */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * Get the quiz questions in their configured order.
     */
    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }

    /** @return HasMany<QuizEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(QuizEntry::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scoring_mode' => QuizScoringMode::class,
            'maximum_score' => 'integer',
            'leaderboard_display_mode' => LeaderboardDisplayMode::class,
            'confetti_flash_sequence' => 'integer',
            'perfect_score_flash_sequence' => 'integer',
        ];
    }
}
