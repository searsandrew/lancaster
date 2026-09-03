<?php

namespace App\Models;

use Database\Factories\ParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['show_id', 'first_name', 'last_name', 'email', 'marketing_opt_in'])]
class Participant extends Model
{
    /** @use HasFactory<ParticipantFactory> */
    use HasFactory;

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
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
