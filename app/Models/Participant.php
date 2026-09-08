<?php

namespace App\Models;

use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['show_id', 'first_name', 'last_name', 'email', 'marketing_opt_in', 'recovery_code'])]
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $hidden = ['recovery_code'];

    /** @return BelongsTo<Show, $this> */
    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /** @return HasOne<QuizEntry, $this> */
    public function quizEntry(): HasOne
    {
        return $this->hasOne(QuizEntry::class);
    }

    public function refreshRecoveryCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (self::query()->where('show_id', $this->show_id)->where('recovery_code', $code)->exists());

        $this->update(['recovery_code' => $code]);

        return $code;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'marketing_opt_in' => 'boolean',
        ];
    }
}
