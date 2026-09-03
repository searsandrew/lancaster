<?php

use App\Enums\QuizScoringMode;
use App\Enums\LeaderboardDisplayMode;
use App\Models\QuizEntry;
use App\Models\Show;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.display')] #[Title('Leaderboard')] class extends Component
{
    public ?Show $show = null;
    public ?int $leaderId = null;
    /** @var array<int, int> */
    public array $knownPerfectEntryIds = [];
    public int $knownConfettiFlashSequence = 0;
    public int $knownPerfectScoreFlashSequence = 0;

    public function mount(): void
    {
        $this->refreshShow(false);
    }

    public function refreshShow(bool $celebrate = true): void
    {
        $previousShowId = $this->show?->id;
        $activeShows = Show::query()->activeAt()->with('quiz')->limit(2)->get();
        $this->show = $activeShows->count() === 1 ? $activeShows->first() : null;
        unset($this->entries, $this->maximumScore, $this->completedCount);

        if (! $this->show?->quiz) {
            $this->leaderId = null;
            $this->knownPerfectEntryIds = [];
            $this->knownConfettiFlashSequence = 0;
            $this->knownPerfectScoreFlashSequence = 0;

            return;
        }

        $leader = $this->entries->first();
        $perfectEntries = $this->show->quiz->entries()
            ->with('participant')
            ->whereNotNull('completed_at')
            ->where('score', $this->maximumScore)
            ->oldest('completed_at')
            ->oldest('id')
            ->get();
        $perfectEntryIds = $perfectEntries->modelKeys();

        if ($celebrate && $previousShowId === $this->show->id) {
            if ($this->show->quiz->confetti_flash_sequence > $this->knownConfettiFlashSequence) {
                $this->dispatch('leaderboard-confetti');
            }

            if ($this->show->quiz->perfect_score_flash_sequence > $this->knownPerfectScoreFlashSequence) {
                $this->dispatch(
                    'perfect-score',
                    name: __('Perfect score!'),
                    imageUrl: $this->show->quiz->perfect_score_image_path
                        ? Storage::disk('public')->url($this->show->quiz->perfect_score_image_path)
                        : null,
                );
            }

            if ($leader && $leader->id !== $this->leaderId) {
                $this->dispatch('leaderboard-confetti');
            }

            $newPerfectEntry = $perfectEntries
                ->whereNotIn('id', $this->knownPerfectEntryIds)
                ->last();

            if ($newPerfectEntry) {
                $this->dispatch(
                    'perfect-score',
                    name: $newPerfectEntry->participant->first_name.' '.$newPerfectEntry->participant->last_name,
                    imageUrl: $this->show->quiz->perfect_score_image_path
                        ? Storage::disk('public')->url($this->show->quiz->perfect_score_image_path)
                        : null,
                );
            }
        }

        $this->leaderId = $leader?->id;
        $this->knownPerfectEntryIds = $perfectEntryIds;
        $this->knownConfettiFlashSequence = $this->show->quiz->confetti_flash_sequence;
        $this->knownPerfectScoreFlashSequence = $this->show->quiz->perfect_score_flash_sequence;
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

    #[Computed]
    public function completedCount(): int
    {
        return $this->show?->quiz?->entries()
            ->whereNotNull('completed_at')
            ->count() ?? 0;
    }

    #[Computed]
    public function registrationQrCode(): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(600, 4),
            new SvgImageBackEnd,
        );
        $svg = (new Writer($renderer))->writeString(route('home'));

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
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

<main
    wire:poll.3s="refreshShow"
    x-data="{
        confetti: [],
        perfectScoreVisible: false,
        perfectScoreName: '',
        perfectScoreImage: null,
        confettiTimer: null,
        perfectScoreTimer: null,
        burstConfetti() {
            clearTimeout(this.confettiTimer)
            const colors = ['#fbbf24', '#f97316', '#22c55e', '#38bdf8', '#e879f9', '#ffffff']
            this.confetti = Array.from({ length: 90 }, (_, id) => ({
                id: `${Date.now()}-${id}`,
                color: colors[id % colors.length],
                x: `${Math.round((Math.random() - 0.5) * 180)}vw`,
                rotation: `${Math.round(Math.random() * 1080)}deg`,
                delay: `${Math.random() * 0.35}s`,
                duration: `${2.2 + Math.random() * 1.2}s`,
            }))
            this.confettiTimer = setTimeout(() => this.confetti = [], 3800)
        },
        showPerfectScore(detail) {
            clearTimeout(this.perfectScoreTimer)
            this.perfectScoreName = detail.name
            this.perfectScoreImage = detail.imageUrl
            this.perfectScoreVisible = true
            this.perfectScoreTimer = setTimeout(() => this.perfectScoreVisible = false, 5000)
        },
    }"
    x-on:leaderboard-confetti.window="burstConfetti()"
    x-on:perfect-score.window="showPerfectScore($event.detail)"
    class="min-h-screen overflow-x-hidden bg-[radial-gradient(circle_at_top,_rgba(39,39,42,0.8),_rgb(9,9,11)_58%)] px-8 pb-32 pt-10 lg:px-16 lg:pb-36 lg:pt-12"
>
    <div class="pointer-events-none fixed inset-0 z-[60] overflow-hidden" aria-hidden="true">
        <template x-for="piece in confetti" :key="piece.id">
            <span
                class="leaderboard-confetti absolute left-1/2 top-0 h-4 w-2 rounded-sm"
                :style="`--confetti-x: ${piece.x}; --confetti-rotation: ${piece.rotation}; background: ${piece.color}; animation-delay: ${piece.delay}; animation-duration: ${piece.duration}`"
            ></span>
        </template>
    </div>

    @if ($show?->quiz?->leaderboard_display_mode === LeaderboardDisplayMode::QrCode)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-zinc-950 p-12 text-center">
            <div class="flex max-w-4xl flex-col items-center gap-7">
                <div class="text-sm font-black uppercase tracking-[0.45em] text-amber-400">{{ $show->name }}</div>
                <h2 class="text-5xl font-black tracking-tight text-white lg:text-7xl">{{ __('Scan to join the quiz') }}</h2>
                <div class="rounded-3xl bg-white p-6 shadow-2xl">
                    <img src="{{ $this->registrationQrCode }}" alt="{{ __('QR code for quiz registration') }}" class="size-[min(55vh,34rem)]" />
                </div>
                <div class="text-2xl font-semibold text-zinc-300">{{ route('home') }}</div>
            </div>
        </div>
    @elseif ($show?->quiz?->leaderboard_display_mode === LeaderboardDisplayMode::Advertisement && $show->quiz->advertisement_embed_url)
        <div class="fixed inset-0 z-40 flex items-center justify-center bg-black p-8">
            <div class="aspect-video max-h-full w-full max-w-[90rem] overflow-hidden rounded-2xl bg-zinc-950 shadow-2xl">
                <iframe
                    wire:key="advertisement-{{ md5($show->quiz->advertisement_embed_url) }}"
                    src="{{ $show->quiz->advertisement_embed_url }}"
                    title="{{ __('Quiz advertisement') }}"
                    class="size-full border-0"
                    allow="autoplay; encrypted-media; picture-in-picture"
                    sandbox="allow-scripts allow-same-origin allow-presentation"
                    allowfullscreen
                ></iframe>
            </div>
        </div>
    @endif

    <div
        x-cloak
        x-show="perfectScoreVisible"
        x-transition:enter="transition duration-500 ease-out"
        x-transition:enter-start="scale-75 opacity-0"
        x-transition:enter-end="scale-100 opacity-100"
        x-transition:leave="transition duration-500 ease-in"
        x-transition:leave-start="scale-100 opacity-100"
        x-transition:leave-end="scale-110 opacity-0"
        class="pointer-events-none fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/90 p-12 text-center"
        aria-live="polite"
    >
        <div class="flex max-w-5xl flex-col items-center gap-7">
            <img x-show="perfectScoreImage" :src="perfectScoreImage" alt="" class="max-h-[42vh] max-w-3xl object-contain drop-shadow-2xl" />
            <div class="text-lg font-black uppercase tracking-[0.45em] text-amber-400">{{ __('Perfect score') }}</div>
            <div class="text-6xl font-black tracking-tight text-white lg:text-8xl" x-text="perfectScoreName"></div>
            <div class="text-2xl font-semibold text-zinc-300">{{ __('Come claim your perfect-score sticker!') }}</div>
        </div>
    </div>

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
                <div class="text-4xl font-black text-amber-400">{{ $this->completedCount }}</div>
                <div class="text-sm uppercase tracking-widest text-zinc-500">{{ __('Completed') }}</div>
                <div class="mt-1 flex items-center justify-end gap-1 text-[0.65rem] font-semibold uppercase tracking-wider text-emerald-400">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-emerald-400"></span>
                    </span>
                    {{ __('Updates automatically') }}
                </div>
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

        </div>

        <footer class="fixed inset-x-0 bottom-0 z-30 border-t border-white/15 bg-zinc-950 px-8 py-5 shadow-[0_-20px_45px_rgba(0,0,0,0.55)] lg:px-16">
            <div class="mx-auto grid max-w-7xl items-center gap-8 md:grid-cols-[1fr_minmax(18rem,0.45fr)]">
                <div class="text-base font-bold uppercase tracking-[0.18em] text-zinc-300">
                    {{ __('Higher score wins. Fastest time breaks ties.') }}
                </div>
                <div class="min-h-6 border-white/15 text-right text-sm font-medium text-amber-300 md:border-l md:pl-8">
                    {{ $show->quiz->leaderboard_message }}
                </div>
            </div>
        </footer>
    @endif
</main>
