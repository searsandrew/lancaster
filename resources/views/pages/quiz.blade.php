<?php

use App\Enums\QuizScoringMode;
use App\Models\Participant;
use App\Models\QuizEntry;
use App\Models\Show;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Run quiz')] class extends Component
{
    public ?Show $show = null;
    public string $search = '';
    public ?int $entryId = null;
    public ?int $summaryScore = null;
    public ?string $summarySeconds = null;
    /** @var array<int, bool> */
    public array $answerCorrect = [];
    /** @var array<int, string> */
    public array $answerSeconds = [];

    public function mount(): void
    {
        $this->show = $this->currentShow();
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

        $this->show = $show;
        $this->entryId = $entry->id;
        $this->summaryScore = $entry->score;
        $this->summarySeconds = $entry->elapsed_ms ? (string) ($entry->elapsed_ms / 1000) : null;
        $this->answerCorrect = [];
        $this->answerSeconds = [];

        foreach ($show->quiz->questions as $question) {
            $answer = $entry->answers->firstWhere('question_id', $question->id);
            $this->answerCorrect[$question->id] = $answer?->is_correct ?? false;
            $this->answerSeconds[$question->id] = $answer ? (string) ($answer->elapsed_ms / 1000) : '';
        }

        unset($this->participants, $this->entry);
    }

    public function complete(): void
    {
        $show = $this->currentShow();
        $entry = $this->entryForActiveShow($show);

        if (! $show || ! $entry) {
            $this->addError('show', __('This quiz is no longer available.'));

            return;
        }

        if ($entry->completed_at) {
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

        $this->entryId = null;
        $this->reset('summaryScore', 'summarySeconds', 'answerCorrect', 'answerSeconds');
        unset($this->participants, $this->entry);
        Flux::toast(variant: 'success', text: __('Quiz entry completed.'));
    }

    public function cancel(): void
    {
        $this->entryId = null;
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
            'score' => $score,
            'elapsed_ms' => $elapsedMs,
            'completed_at' => now(),
        ]);
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
    <div>
        <flux:heading size="xl">{{ __('Run quiz') }}</flux:heading>
        <flux:subheading>{{ $show ? $show->name : __('No single active show') }}</flux:subheading>
    </div>

    @if (! $show)
        <flux:callout variant="warning" icon="exclamation-triangle">
            {{ __('Activate exactly one show before running its quiz.') }}
        </flux:callout>
    @elseif ($this->entry)
        <flux:card class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $this->entry->participant->first_name }} {{ $this->entry->participant->last_name }}</flux:heading>
                <flux:text>{{ $this->entry->participant->email }}</flux:text>
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
                    <flux:button type="submit" variant="primary">{{ __('Complete quiz') }}</flux:button>
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
                                    <flux:badge :color="$participant->quizEntry?->completed_at ? 'green' : ($participant->quizEntry ? 'amber' : 'zinc')" size="sm">
                                        {{ $participant->quizEntry?->completed_at ? __('Completed') : ($participant->quizEntry ? __('In progress') : __('Waiting')) }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-end">
                                    <flux:button
                                        type="button"
                                        size="sm"
                                        wire:click="start({{ $participant->id }})"
                                        :disabled="$participant->quizEntry?->completed_at !== null"
                                    >
                                        {{ $participant->quizEntry ? __('Continue') : __('Start') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    @endif
</section>
