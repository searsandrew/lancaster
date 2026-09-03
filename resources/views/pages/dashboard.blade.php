<?php

use App\Enums\QuizScoringMode;
use App\Enums\LeaderboardDisplayMode;
use App\Models\Participant;
use App\Models\QuizEntry;
use App\Models\Show;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    public ?Show $show = null;
    public string $search = '';
    public ?int $entryId = null;
    public bool $editingCompletedEntry = false;
    public ?int $summaryScore = null;
    public ?string $summarySeconds = null;
    /** @var array<int, bool> */
    public array $answerCorrect = [];
    /** @var array<int, string> */
    public array $answerSeconds = [];
    public ?int $editingParticipantId = null;
    public string $participantFirstName = '';
    public string $participantLastName = '';
    public string $participantEmail = '';
    public bool $participantMarketingOptIn = true;
    public string $leaderboardDisplayMode = LeaderboardDisplayMode::Leaderboard->value;

    public function mount(): void
    {
        $this->show = $this->currentShow();
        $this->leaderboardDisplayMode = $this->show?->quiz?->leaderboard_display_mode->value
            ?? LeaderboardDisplayMode::Leaderboard->value;
    }

    public function setLeaderboardDisplayMode(string $displayMode): void
    {
        $show = $this->currentShow();
        $mode = LeaderboardDisplayMode::tryFrom($displayMode);

        abort_unless($show?->quiz && $mode, 404);

        if ($mode === LeaderboardDisplayMode::Advertisement && ! $show->quiz->advertisement_embed_url) {
            return;
        }

        $show->quiz->update(['leaderboard_display_mode' => $mode]);
        $this->leaderboardDisplayMode = $mode->value;
    }

    public function flashConfetti(): void
    {
        $show = $this->currentShow();
        abort_unless($show?->quiz, 404);

        $show->quiz->increment('confetti_flash_sequence');
        Flux::toast(variant: 'success', text: __('Confetti sent to the leaderboard.'));
    }

    public function flashPerfectScore(): void
    {
        $show = $this->currentShow();
        abort_unless($show?->quiz, 404);

        $show->quiz->increment('perfect_score_flash_sequence');
        Flux::toast(variant: 'success', text: __('Perfect score alert sent to the leaderboard.'));
    }

    /** @return Collection<int, Participant> */
    #[Computed]
    public function participants(): Collection
    {
        if (! $this->show) {
            return new Collection;
        }

        return $this->show->participants()
            ->with('quizEntry')
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.trim($this->search).'%';

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('first_name', 'like', $search)
                        ->orWhere('last_name', 'like', $search)
                        ->orWhere('email', 'like', $search);
                });
            })
            ->oldest()
            ->get();
    }

    #[Computed]
    public function entry(): ?QuizEntry
    {
        return $this->entryForActiveShow($this->show);
    }

    public function start(int $participantId): void
    {
        $show = $this->currentShow();

        if (! $show) {
            $this->show = null;
            $this->addError('show', __('There must be exactly one active show to run a quiz.'));

            return;
        }

        $participant = $show->participants()->findOrFail($participantId);
        $entry = $participant->quizEntry()->firstOrCreate([], [
            'quiz_id' => $show->quiz->id,
            'staff_user_id' => auth()->id(),
            'started_at' => now(),
        ]);
        $entry->loadMissing('answers');

        if ($entry->completed_at) {
            $this->addError('entry', __('This quiz entry has already been completed.'));

            return;
        }

        $this->loadEntry($entry, false);
    }

    public function editResult(int $participantId): void
    {
        $participant = $this->participantForActiveShow($participantId);
        $entry = $participant->quizEntry()->whereNotNull('completed_at')->firstOrFail();
        $entry->loadMissing('answers');

        $this->loadEntry($entry, true);
    }

    public function editParticipant(int $participantId): void
    {
        $participant = $this->participantForActiveShow($participantId);
        $this->editingParticipantId = $participant->id;
        $this->participantFirstName = $participant->first_name;
        $this->participantLastName = $participant->last_name;
        $this->participantEmail = $participant->email;
        $this->participantMarketingOptIn = $participant->marketing_opt_in;
        $this->resetValidation();
        Flux::modal('edit-participant')->show();
    }

    public function saveParticipant(): void
    {
        $participant = $this->participantForActiveShow($this->editingParticipantId);
        $this->participantFirstName = trim($this->participantFirstName);
        $this->participantLastName = trim($this->participantLastName);
        $this->participantEmail = mb_strtolower(trim($this->participantEmail));

        $validated = $this->validate([
            'participantFirstName' => ['required', 'string', 'max:255'],
            'participantLastName' => ['required', 'string', 'max:255'],
            'participantEmail' => [
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique('participants', 'email')
                    ->where('show_id', $participant->show_id)
                    ->ignore($participant->id),
            ],
            'participantMarketingOptIn' => ['boolean'],
        ]);

        $participant->update([
            'first_name' => $validated['participantFirstName'],
            'last_name' => $validated['participantLastName'],
            'email' => $validated['participantEmail'],
            'marketing_opt_in' => $validated['participantMarketingOptIn'],
        ]);

        $this->editingParticipantId = null;
        unset($this->participants, $this->entry);
        Flux::modal('edit-participant')->close();
        Flux::toast(variant: 'success', text: __('Contestant updated.'));
    }

    public function deleteEntry(int $participantId): void
    {
        $participant = $this->participantForActiveShow($participantId);
        $entry = $participant->quizEntry;

        if (! $entry) {
            return;
        }

        if ($this->entryId === $entry->id) {
            $this->cancel();
        }

        $entry->delete();
        unset($this->participants);
        Flux::toast(variant: 'success', text: __('Quiz entry deleted. The contestant can try again.'));
    }

    public function deleteParticipant(int $participantId): void
    {
        $participant = $this->participantForActiveShow($participantId);

        if ($this->entryId === $participant->quizEntry?->id) {
            $this->cancel();
        }

        $participant->delete();
        unset($this->participants);
        Flux::toast(variant: 'success', text: __('Contestant deleted.'));
    }

    public function complete(): void
    {
        $show = $this->currentShow();
        $entry = $this->entryForActiveShow($show);

        if (! $show || ! $entry) {
            $this->addError('show', __('This quiz is no longer available.'));

            return;
        }

        if ($entry->completed_at && ! $this->editingCompletedEntry) {
            $this->addError('entry', __('This quiz entry has already been completed.'));

            return;
        }

        if ($entry->quiz->scoring_mode === QuizScoringMode::Summary) {
            $this->completeSummaryEntry($entry);
        } else {
            $this->completePerAnswerEntry($entry);
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $wasEditing = $this->editingCompletedEntry;
        $this->entryId = null;
        $this->editingCompletedEntry = false;
        $this->reset('summaryScore', 'summarySeconds', 'answerCorrect', 'answerSeconds');
        unset($this->participants, $this->entry);
        Flux::toast(variant: 'success', text: $wasEditing ? __('Quiz result updated.') : __('Quiz entry completed.'));
    }

    public function cancel(): void
    {
        $this->entryId = null;
        $this->editingCompletedEntry = false;
        $this->reset('summaryScore', 'summarySeconds', 'answerCorrect', 'answerSeconds');
        $this->resetErrorBag();
        unset($this->entry);
    }

    private function completeSummaryEntry(QuizEntry $entry): void
    {
        $validated = $this->validate([
            'summaryScore' => ['required', 'integer', 'min:0', 'max:'.$entry->quiz->maximum_score],
            'summarySeconds' => ['required', 'numeric', 'min:0.001', 'max:86400'],
        ]);

        $this->finishEntry(
            $entry,
            $validated['summaryScore'],
            $this->milliseconds($validated['summarySeconds']),
        );
    }

    private function completePerAnswerEntry(QuizEntry $entry): void
    {
        if ($entry->quiz->questions->isEmpty()) {
            $this->addError('entry', __('This quiz does not have any questions configured.'));

            return;
        }

        $rules = [];

        foreach ($entry->quiz->questions as $question) {
            $rules["answerCorrect.{$question->id}"] = ['required', 'boolean'];
            $rules["answerSeconds.{$question->id}"] = ['required', 'numeric', 'min:0.001', 'max:86400'];
        }

        $validated = $this->validate($rules);
        $score = collect($validated['answerCorrect'])->filter()->count();
        $elapsedMs = collect($validated['answerSeconds'])->sum(
            fn (int|float|string $seconds): int => $this->milliseconds($seconds),
        );

        DB::transaction(function () use ($entry, $validated, $score, $elapsedMs): void {
            foreach ($entry->quiz->questions as $question) {
                $entry->answers()->updateOrCreate(
                    ['position' => $question->position],
                    [
                        'question_id' => $question->id,
                        'question_prompt' => $question->prompt,
                        'is_correct' => $validated['answerCorrect'][$question->id],
                        'elapsed_ms' => $this->milliseconds($validated['answerSeconds'][$question->id]),
                    ],
                );
            }

            $this->finishEntry($entry, $score, $elapsedMs);
        });
    }

    private function finishEntry(QuizEntry $entry, int $score, int $elapsedMs): void
    {
        $entry->update([
            'staff_user_id' => auth()->id(),
            'score' => $score,
            'elapsed_ms' => $elapsedMs,
            'completed_at' => $this->editingCompletedEntry ? $entry->completed_at : now(),
        ]);
    }

    private function loadEntry(QuizEntry $entry, bool $editingCompletedEntry): void
    {
        $this->show = $this->currentShow();
        $this->entryId = $entry->id;
        $this->editingCompletedEntry = $editingCompletedEntry;
        $this->summaryScore = $entry->score;
        $this->summarySeconds = $entry->elapsed_ms ? (string) ($entry->elapsed_ms / 1000) : null;
        $this->answerCorrect = [];
        $this->answerSeconds = [];

        foreach ($entry->quiz->questions as $question) {
            $answer = $entry->answers->firstWhere('question_id', $question->id);
            $this->answerCorrect[$question->id] = $answer?->is_correct ?? false;
            $this->answerSeconds[$question->id] = $answer ? (string) ($answer->elapsed_ms / 1000) : '';
        }

        $this->resetErrorBag();
        unset($this->participants, $this->entry);
    }

    private function participantForActiveShow(?int $participantId): Participant
    {
        abort_unless($participantId !== null, 404);
        $show = $this->currentShow();
        abort_unless($show, 404);

        return $show->participants()->with('quizEntry')->findOrFail($participantId);
    }

    private function entryForActiveShow(?Show $show): ?QuizEntry
    {
        if (! $show || ! $this->entryId) {
            return null;
        }

        return QuizEntry::query()
            ->with(['participant', 'quiz.questions', 'answers'])
            ->whereKey($this->entryId)
            ->whereHas('participant', fn (Builder $query): Builder => $query->whereBelongsTo($show))
            ->first();
    }

    private function currentShow(): ?Show
    {
        $activeShows = Show::query()->activeAt()->with('quiz.questions')->limit(2)->get();

        return $activeShows->count() === 1 ? $activeShows->first() : null;
    }

    private function milliseconds(int|float|string $seconds): int
    {
        return (int) round((float) $seconds * 1000);
    }
};
?>

<section class="w-full space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $show ? $show->name : __('No single active show') }}</flux:heading>
            <flux:subheading>{{ __('Manage and run the quiz from this screen. Use the toggle and flash commands to control the leaderboard screen. Entrants can be reset, removed, and edited from their contextual menu.') }}</flux:subheading>
        </div>

        <flux:tooltip position:bottom :content="__('Flash Confetti')">
            <flux:button type="button" icon="sparkles" wire:click="flashConfetti" :disabled="! $show" :aria-label="__('Flash Confetti')" />
        </flux:tooltip>
        <flux:tooltip position:bottom :content="__('Flash Perfect Score')">
            <flux:button type="button" icon="trophy" wire:click="flashPerfectScore" :disabled="! $show" :aria-label="__('Flash Perfect Score')" />
        </flux:tooltip>
        <flux:button.group>
            <flux:button :href="route('leaderboard')" icon="arrow-top-right-on-square" target="_blank">
                {{ __('Leaderboard') }}
            </flux:button>
            <flux:dropdown position="bottom" align="end">
                <flux:button icon="chevron-down"></flux:button>
                <flux:menu>
                    <flux:menu.radio.group>
                        <flux:menu.radio
                            :checked="$leaderboardDisplayMode === LeaderboardDisplayMode::Leaderboard->value"
                            wire:click="setLeaderboardDisplayMode('leaderboard')"
                        >{{ __('Leaderboard') }}</flux:menu.radio>
                        <flux:menu.radio
                            :checked="$leaderboardDisplayMode === LeaderboardDisplayMode::QrCode->value"
                            wire:click="setLeaderboardDisplayMode('qr_code')"
                        >{{ __('QR Code') }}</flux:menu.radio>
                        <flux:menu.radio
                            :checked="$leaderboardDisplayMode === LeaderboardDisplayMode::Advertisement->value"
                            :disabled="! $show?->quiz?->advertisement_embed_url"
                            wire:click="setLeaderboardDisplayMode('advertisement')"
                        >{{ __('Play Ad') }}</flux:menu.radio>
                    </flux:menu.radio.group>
                </flux:menu>
            </flux:dropdown>
        </flux:button.group>
    </div>

    @if (! $show)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('Activate exactly one show before running its quiz.') }}
        </flux:callout>
    @elseif ($this->entry)
        <flux:card class="space-y-6">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="lg">{{ $this->entry->participant->first_name }} {{ $this->entry->participant->last_name }}</flux:heading>
                    @if ($editingCompletedEntry)
                        <flux:badge color="amber" size="sm">{{ __('Editing completed result') }}</flux:badge>
                    @endif
                </div>
                <flux:text>{{ $this->entry->participant->email }}</flux:text>
                <div class="mt-3">
                    @if ($this->entry->participant->marketing_opt_in)
                        <flux:badge color="green" icon="check-circle">{{ __('Email signup accepted') }}</flux:badge>
                    @else
                        <flux:callout variant="warning" icon="envelope">
                            <flux:callout.heading>{{ __('Email signup declined') }}</flux:callout.heading>
                            <flux:callout.text>{{ __('If they reconsider, update their consent before completing the quiz.') }}</flux:callout.text>
                            <x-slot name="actions">
                                <flux:button type="button" size="sm" wire:click="editParticipant({{ $this->entry->participant->id }})">
                                    {{ __('Update consent') }}
                                </flux:button>
                            </x-slot>
                        </flux:callout>
                    @endif
                </div>
            </div>

            <form wire:submit="complete" class="space-y-6">
                @if ($this->entry->quiz->scoring_mode === QuizScoringMode::Summary)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model="summaryScore" type="number" min="0" :max="$this->entry->quiz->maximum_score" :label="__('Score (out of :maximum)', ['maximum' => $this->entry->quiz->maximum_score])" required />
                        <flux:input wire:model="summarySeconds" type="number" step="0.001" min="0.001" :label="__('Total time (seconds)')" required />
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach ($this->entry->quiz->questions as $question)
                            <flux:card wire:key="question-{{ $question->id }}" class="grid items-end gap-4 sm:grid-cols-[1fr_auto_12rem]">
                                <div>
                                    <flux:text class="text-xs">{{ __('Question :position', ['position' => $question->position]) }}</flux:text>
                                    <flux:heading>{{ $question->prompt }}</flux:heading>
                                </div>
                                <flux:switch wire:model="answerCorrect.{{ $question->id }}" :label="__('Correct')" />
                                <flux:input wire:model="answerSeconds.{{ $question->id }}" type="number" step="0.001" min="0.001" :label="__('Time (seconds)')" required />
                            </flux:card>
                        @endforeach
                    </div>
                @endif

                <flux:error name="entry" />
                <flux:error name="show" />

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="ghost" wire:click="cancel">{{ __('Back to queue') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ $editingCompletedEntry ? __('Save result') : __('Complete quiz') }}</flux:button>
                </div>
            </form>
        </flux:card>
    @else
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <flux:input wire:model.live.debounce.300ms="search" type="search" :label="__('Find participant')" :placeholder="__('Search name or email')" class="w-full sm:max-w-md" />
            <flux:text>{{ trans_choice(':count participant|:count participants', $this->participants->count(), ['count' => $this->participants->count()]) }}</flux:text>
        </div>

        <div wire:poll.5s>
            @if ($this->participants->isEmpty())
                <flux:card class="text-center">
                    <flux:heading>{{ __('No participants found') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('New registrations will appear here automatically.') }}</flux:text>
                </flux:card>
            @else
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Participant') }}</flux:table.column>
                        <flux:table.column>{{ __('Registered') }}</flux:table.column>
                        <flux:table.column>{{ __('Email signup') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->participants as $participant)
                            <flux:table.row :key="$participant->id">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $participant->first_name }} {{ $participant->last_name }}</div>
                                    <div class="text-sm text-zinc-500">{{ $participant->email }}</div>
                                </flux:table.cell>
                                <flux:table.cell>{{ $participant->created_at->diffForHumans() }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$participant->marketing_opt_in ? 'green' : 'red'" size="sm">
                                        {{ $participant->marketing_opt_in ? __('Accepted') : __('Declined') }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$participant->quizEntry?->completed_at ? 'green' : ($participant->quizEntry ? 'amber' : 'zinc')" size="sm">
                                        {{ $participant->quizEntry?->completed_at ? __('Completed') : ($participant->quizEntry ? __('In progress') : __('Waiting')) }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-end">
                                    <div class="flex items-center justify-end gap-2">
                                        @if (! $participant->quizEntry?->completed_at)
                                            <flux:button type="button" size="sm" wire:click="start({{ $participant->id }})">
                                                {{ $participant->quizEntry ? __('Continue') : __('Start') }}
                                            </flux:button>
                                        @endif

                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button type="button" size="sm" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Manage contestant')" />

                                            <flux:menu>
                                                <flux:menu.item as="button" type="button" wire:click="editParticipant({{ $participant->id }})">
                                                    {{ __('Edit contestant') }}
                                                </flux:menu.item>
                                                @if ($participant->quizEntry?->completed_at)
                                                    <flux:menu.item as="button" type="button" wire:click="editResult({{ $participant->id }})">
                                                        {{ __('Edit result') }}
                                                    </flux:menu.item>
                                                @endif
                                                @if ($participant->quizEntry)
                                                    <flux:menu.item
                                                        as="button"
                                                        type="button"
                                                        variant="danger"
                                                        wire:click="deleteEntry({{ $participant->id }})"
                                                        wire:confirm="{{ __('Delete this quiz result? The contestant will return to the waiting queue.') }}"
                                                    >
                                                        {{ __('Delete result') }}
                                                    </flux:menu.item>
                                                @endif
                                                <flux:menu.separator />
                                                <flux:menu.item
                                                    as="button"
                                                    type="button"
                                                    variant="danger"
                                                    wire:click="deleteParticipant({{ $participant->id }})"
                                                    wire:confirm="{{ __('Delete this contestant and all of their quiz data?') }}"
                                                >
                                                    {{ __('Delete contestant') }}
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    @endif

    <flux:modal name="edit-participant" class="md:w-[34rem]">
        <form wire:submit="saveParticipant" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Edit contestant') }}</flux:heading>
                <flux:subheading>{{ __('Update registration details and email consent.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="participantFirstName" :label="__('First name')" required />
                <flux:input wire:model="participantLastName" :label="__('Last name')" required />
            </div>
            <flux:input wire:model="participantEmail" type="email" :label="__('Email address')" required />
            <flux:checkbox wire:model="participantMarketingOptIn" :label="__('Keep them subscribed to email updates')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save contestant') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
