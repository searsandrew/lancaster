<?php

namespace App\Models;

use App\Enums\ShowActivationMode;
use Database\Factories\ShowFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'slug', 'activation_mode', 'is_active', 'starts_at', 'ends_at'])]
class Show extends Model
{
    /** @use HasFactory<ShowFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the quiz configured for this show.
     */
    public function quiz(): HasOne
    {
        return $this->hasOne(Quiz::class);
    }

    /**
     * Get the participants registered for this show.
     *
     * @return HasMany<Participant, $this>
     */
    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    /**
     * Determine whether the show is active at the given time.
     */
    public function isActiveAt(?DateTimeInterface $moment = null): bool
    {
        if ($this->activation_mode === ShowActivationMode::Manual) {
            return $this->is_active;
        }

        $moment ??= now();

        return $this->starts_at?->lte($moment) === true
            && $this->ends_at?->gte($moment) === true;
    }

    /**
     * Limit the query to shows active at the given time.
     *
     * @param  Builder<Show>  $query
     * @return Builder<Show>
     */
    public function scopeActiveAt(Builder $query, ?DateTimeInterface $moment = null): Builder
    {
        $moment = Carbon::instance($moment ?? now());

        return $query->where(function (Builder $query) use ($moment): void {
            $query->where(function (Builder $query): void {
                $query->where('activation_mode', ShowActivationMode::Manual)
                    ->where('is_active', true);
            })->orWhere(function (Builder $query) use ($moment): void {
                $query->where('activation_mode', ShowActivationMode::Scheduled)
                    ->where('starts_at', '<=', $moment)
                    ->where('ends_at', '>=', $moment);
            });
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activation_mode' => ShowActivationMode::class,
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
