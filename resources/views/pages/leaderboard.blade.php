<?php

use App\Enums\QuizScoringMode;
use App\Models\QuizEntry;
use App\Models\Show;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Leaderboard')] class extends Component
{
    public ?Show $show = null;

    public function mount(): void
    {
        $this->refreshShow();
    }

    public function refreshShow(): void
    {
        $activeShows = Show::query()->activeAt()->with('quiz')->limit(2)->get();
        $this->show = $activeShows->count() === 1 ? $activeShows->first() : null;
        unset($this->entries, $this->maximumScore);
    }

    /** @return Collection<int, QuizEntry> */
    #[Computed]
    public function entries(): Collection
    {
        if (! $this->show?->quiz) {
            return new Collection;
        }

        return $this->show->quiz->entries()
            ->with('participant')
            ->whereNotNull('completed_at')
            ->whereNotNull('score')
            ->whereNotNull('elapsed_ms')
            ->orderByDesc('score')
            ->orderBy('elapsed_ms')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function maximumScore(): int
    {
        if (! $this->show?->quiz) {
            return 0;
        }

        if ($this->show->quiz->scoring_mode === QuizScoringMode::Summary) {
            return $this->show->quiz->maximum_score ?? 0;
        }

        return $this->show->quiz->questions()->count();
    }

    public function formattedTime(int $milliseconds): string
    {
        if ($milliseconds < 60000) {
            return number_format($milliseconds / 1000, 3).'s';
        }

        $minutes = intdiv($milliseconds, 60000);
        $seconds = ($milliseconds % 60000) / 1000;

        return sprintf('%d:%06.3f', $minutes, $seconds);
    }
};
?>

<main wire:poll.3s="refreshShow" class="min-h-screen overflow-hidden bg-[radial-gradient(circle_at_top,_rgba(39,39,42,0.8),_rgb(9,9,11)_58%)] px-8 py-10 lg:px-16 lg:py-12">
    @if (! $show)
        <div class="flex min-h-[calc(100vh-5rem)] items-center justify-center text-center">
            <div class="space-y-5">
                <div class="text-sm font-semibold uppercase tracking-[0.35em] text-amber-400">{{ config('app.name') }}</div>
                <h1 class="text-5xl font-bold tracking-tight lg:text-7xl">{{ __('Leaderboard coming soon') }}</h1>
                <p class="text-xl text-zinc-400">{{ __('The next quiz will appear here when the show begins.') }}</p>
            </div>
        </div>
    @else
        <div class="mx-auto max-w-7xl space-y-10">
            <header class="flex items-end justify-between gap-8 border-b border-white/10 pb-8">
                <div>
                    <div class="mb-3 text-sm font-semibold uppercase tracking-[0.35em] text-amber-400">{{ $show->name }}</div>
                    <h1 class="text-5xl font-black tracking-tight lg:text-7xl">{{ __('Quiz leaderboard') }}</h1>
                </div>
                <div class="shrink-0 text-right">
                    <div class="text-4xl font-black text-amber-400">{{ $this->entries->count() }}</div>
                    <div class="text-sm uppercase tracking-widest text-zinc-500">{{ __('Completed') }}</div>
                </div>
            </header>

            @if ($this->entries->isEmpty())
                <div class="flex min-h-[50vh] items-center justify-center text-center">
                    <div>
                        <div class="text-3xl font-bold">{{ __('Ready for the first challenger') }}</div>
                        <div class="mt-3 text-xl text-zinc-500">{{ __('Completed quizzes will appear automatically.') }}</div>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    <div class="grid grid-cols-[6rem_1fr_11rem_12rem] gap-6 px-6 text-sm font-semibold uppercase tracking-widest text-zinc-500">
                        <div>{{ __('Rank') }}</div>
                        <div>{{ __('Participant') }}</div>
                        <div class="text-right">{{ __('Score') }}</div>
                        <div class="text-right">{{ __('Time') }}</div>
                    </div>

                    @foreach ($this->entries as $entry)
                        <div
                            wire:key="leaderboard-entry-{{ $entry->id }}"
                            class="grid grid-cols-[6rem_1fr_11rem_12rem] items-center gap-6 rounded-2xl border px-6 py-5 shadow-2xl {{ $loop->first ? 'border-amber-400/50 bg-amber-400/10' : 'border-white/10 bg-white/[0.04]' }}"
                        >
                            <div class="text-3xl font-black {{ $loop->first ? 'text-amber-400' : 'text-zinc-500' }}">#{{ $loop->iteration }}</div>
                            <div class="truncate text-3xl font-bold lg:text-4xl">{{ $entry->participant->first_name }} {{ $entry->participant->last_name }}</div>
                            <div class="text-right text-3xl font-black lg:text-4xl">
                                {{ $entry->score }}<span class="text-lg font-medium text-zinc-500">/{{ $this->maximumScore }}</span>
                            </div>
                            <div class="text-right font-mono text-3xl font-semibold tabular-nums lg:text-4xl">{{ $this->formattedTime($entry->elapsed_ms) }}</div>
                        </div>
                    @endforeach
                </div>
            @endif

            <footer class="flex items-center justify-between pt-2 text-sm uppercase tracking-widest text-zinc-600">
                <span>{{ __('Higher score wins. Fastest time breaks ties.') }}</span>
                <span>{{ __('Updates automatically') }}</span>
            </footer>
        </div>
    @endif
</main>
