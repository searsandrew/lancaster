<?php

use App\Enums\QuizScoringMode;
use App\Jobs\SubscribeParticipantToEmailList;
use App\Models\Participant;
use App\Models\Question;
use App\Models\QuizAnswer;
use App\Models\Show;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Join the quiz')] class extends Component
{
    public ?Show $show = null;
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public bool $marketingOptIn = true;
    public bool $registered = false;
    public bool $recovering = false;
    public string $recoveryCode = '';
    public string $submittedAnswer = '';

    public function mount(): void
    {
        $this->show = $this->currentShow();

        if ($this->show) {
            $participantId = session($this->sessionKey($this->show));
            $this->registered = $participantId && $this->show->participants()->whereKey($participantId)->exists();
        }
    }

    public function register(): void
    {
        $show = $this->currentShow();

        if (! $show) {
            $this->show = null;
            $this->addError('show', __('Registration is not currently available.'));

            return;
        }

        $this->show = $show;
        $this->firstName = trim($this->firstName);
        $this->lastName = trim($this->lastName);
        $this->email = mb_strtolower(trim($this->email));

        $validated = $this->validate([
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email:rfc',
                'max:255',
                Rule::unique('participants', 'email')->where(
                    fn (Builder $query): Builder => $query->where('show_id', $show->id),
                ),
            ],
            'marketingOptIn' => ['boolean'],
        ]);

        $participant = $show->participants()->create([
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'email' => $validated['email'],
            'marketing_opt_in' => $validated['marketingOptIn'],
        ]);
        $participant->refreshRecoveryCode();

        if ($participant->marketing_opt_in && $show->quiz?->customer?->hasEmailMarketingConnection()) {
            SubscribeParticipantToEmailList::dispatch($participant);
        }

        session()->put($this->sessionKey($show), $participant->id);
        $this->registered = true;
    }

    public function recover(): void
    {
        $show = $this->currentShow();

        if (! $show) {
            $this->addError('recoveryCode', __('Quiz recovery is not currently available.'));
            return;
        }

        $validated = $this->validate(['recoveryCode' => ['required', 'digits:6']]);
        $rateLimitKey = 'quiz-recovery:'.request()->ip().':'.$show->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $this->addError('recoveryCode', __('Too many recovery attempts. Please wait a minute and try again.'));
            return;
        }

        RateLimiter::hit($rateLimitKey, 60);
        $participant = $show->participants()->where('recovery_code', $validated['recoveryCode'])->first();

        if (! $participant) {
            $this->addError('recoveryCode', __('That recovery code was not found.'));
            return;
        }

        session()->put($this->sessionKey($show), $participant->id);
        $this->registered = true;
        $this->recovering = false;
    }

    public function submitAnswer(): void
    {
        $participant = $this->contestant;
        $entry = $participant?->quizEntry;
        $question = $entry?->quiz->questions->firstWhere('position', $entry->current_question_position);

        abort_unless($participant && $entry && $question && $entry->question_released_at && ! $entry->completed_at, 404);

        $canonicalQuestion = Question::query()->whereBelongsTo($entry->quiz)->findOrFail($question->id);
        $validated = $this->validate(['submittedAnswer' => ['required', 'string', 'max:1000']]);
        $answerText = trim($validated['submittedAnswer']);
        $elapsedMs = max(1, (int) $entry->question_released_at->diffInMilliseconds(now()));

        DB::transaction(function () use ($entry, $canonicalQuestion, $answerText, $elapsedMs): void {
            $lockedEntry = $entry->newQuery()->lockForUpdate()->findOrFail($entry->id);
            abort_unless($lockedEntry->current_question_position === $canonicalQuestion->position, 409);
            abort_if($lockedEntry->answers()->where('position', $canonicalQuestion->position)->exists(), 409);

            QuizAnswer::query()->create([
                'quiz_entry_id' => $lockedEntry->id,
                'question_id' => $canonicalQuestion->id,
                'question_prompt' => $canonicalQuestion->prompt,
                'submitted_answer' => $answerText,
                'position' => $canonicalQuestion->position,
                'is_correct' => mb_strtolower($answerText) === mb_strtolower(trim($canonicalQuestion->correct_answer ?? '')),
                'elapsed_ms' => $elapsedMs,
                'submitted_at' => now(),
            ]);
        });

        $this->submittedAnswer = '';
        unset($this->contestant);
    }

    #[Computed]
    public function contestant(): ?Participant
    {
        if (! $this->show || ! $this->registered) {
            return null;
        }

        $participantId = session($this->sessionKey($this->show));

        return $this->show->participants()
            ->with([
                'quizEntry.quiz.questions' => fn ($query) => $query->select('id', 'quiz_id', 'prompt', 'position'),
                'quizEntry.answers',
            ])
            ->find($participantId);
    }

    private function currentShow(): ?Show
    {
        $activeShows = Show::query()->activeAt()->with('quiz.customer')->limit(2)->get();

        return $activeShows->count() === 1 ? $activeShows->first() : null;
    }

    private function sessionKey(Show $show): string
    {
        return "quiz_participant_{$show->id}";
    }

};
?>

<div class="flex flex-col gap-6">
    @if ($registered && $show?->quiz?->scoring_mode === QuizScoringMode::QuestionAnswer)
        <div wire:poll.1s class="space-y-6 text-center">
            @php($contestant = $this->contestant)
            <div>
                <flux:heading size="xl">{{ __('Hi, :name!', ['name' => $contestant?->first_name ?? $firstName]) }}</flux:heading>
                <flux:text>{{ $show->name }}</flux:text>
            </div>

            @if ($contestant?->recovery_code)
                <flux:callout icon="key">
                    <flux:callout.heading>{{ __('Your recovery code') }}</flux:callout.heading>
                    <flux:callout.text><span class="font-mono text-2xl tracking-[0.3em]">{{ $contestant->recovery_code }}</span></flux:callout.text>
                </flux:callout>
            @endif

            @if ($contestant?->quizEntry?->completed_at)
                <flux:callout variant="success" icon="check-circle">{{ __('Quiz complete! Your result is on the leaderboard.') }}</flux:callout>
            @elseif ($contestant?->quizEntry?->current_question_position)
                @php($question = $contestant->quizEntry->quiz->questions->firstWhere('position', $contestant->quizEntry->current_question_position))
                @php($answer = $contestant->quizEntry->answers->firstWhere('position', $contestant->quizEntry->current_question_position))
                @if ($answer)
                    <flux:callout icon="clock">{{ __('Answer received. Waiting for staff to accept it.') }}</flux:callout>
                @elseif ($question)
                    <flux:card class="space-y-5 text-left">
                        <flux:text class="text-xs font-semibold uppercase tracking-widest">{{ __('Question :current of :total', ['current' => $question->position, 'total' => $contestant->quizEntry->quiz->questions->count()]) }}</flux:text>
                        <flux:heading size="xl">{{ $question->prompt }}</flux:heading>
                        <form wire:submit="submitAnswer" class="space-y-4">
                            <flux:textarea wire:model="submittedAnswer" :label="__('Your answer')" rows="3" maxlength="1000" required autofocus />
                            <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">{{ __('Submit answer') }}</flux:button>
                        </form>
                    </flux:card>
                @endif
            @else
                <flux:callout icon="clock">{{ $contestant?->quizEntry ? __('Waiting for the next question…') : __('Waiting for the quiz to start…') }}</flux:callout>
            @endif
        </div>
    @elseif ($registered)
        <div class="space-y-6 text-center">
            <flux:heading size="xl">{{ __('You’re in, :name!', ['name' => $firstName]) }}</flux:heading>
            <flux:text>{{ __('Head to the quiz table when you’re ready to play.') }}</flux:text>
            <flux:callout variant="success" icon="check-circle">
                {{ __('You’re registered for :show.', ['show' => $show->name]) }}
            </flux:callout>
        </div>
    @elseif ($show && $recovering)
        <div class="space-y-4">
            <flux:heading size="xl">{{ __('Recover your quiz') }}</flux:heading>
            <form wire:submit="recover" class="space-y-4">
                <flux:input wire:model="recoveryCode" inputmode="numeric" maxlength="6" :label="__('Six-digit recovery code')" required autofocus />
                <flux:button type="submit" variant="primary" class="w-full">{{ __('Recover quiz') }}</flux:button>
                <flux:button type="button" variant="ghost" class="w-full" wire:click="$set('recovering', false)">{{ __('Back to registration') }}</flux:button>
            </form>
        </div>
    @elseif ($show)
        <div class="space-y-2 text-center">
            <flux:heading size="xl">{{ __('Join the quiz') }}</flux:heading>
            <flux:text>{{ $show->name }}</flux:text>
        </div>

        @if ($show->quiz?->registration_image_path || $show->quiz?->registration_message)
            <div class="space-y-4">
                @if ($show->quiz?->registration_image_path)
                    <img
                        src="{{ Storage::disk('public')->url($show->quiz->registration_image_path) }}"
                        alt="{{ __('Quiz registration information') }}"
                        class="mx-auto max-h-64 w-full rounded-xl object-contain"
                    />
                @endif

                @if ($show->quiz?->registration_message)
                    <flux:callout>
                        <div class="whitespace-pre-line text-sm">{{ $show->quiz->registration_message }}</div>
                    </flux:callout>
                @endif
            </div>
        @endif

        <form wire:submit="register" class="flex flex-col gap-6">
            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="firstName" :label="__('First name')" autocomplete="given-name" required autofocus />
                <flux:input wire:model="lastName" :label="__('Last name')" autocomplete="family-name" required />
            </div>

            <flux:input wire:model="email" type="email" :label="__('Email address')" autocomplete="email" required />

            <flux:checkbox wire:model="marketingOptIn" :label="__('Keep me updated by email')" :description="__('You can unsubscribe at any time.')" />

            <flux:error name="show" />

            <flux:button variant="primary" type="submit" class="w-full">
                {{ __('Join the quiz') }}
            </flux:button>
            @if ($show->quiz?->scoring_mode === QuizScoringMode::QuestionAnswer)
                <flux:button type="button" variant="ghost" class="w-full" wire:click="$set('recovering', true)">{{ __('Recover an existing quiz') }}</flux:button>
            @endif
        </form>
    @else
        <div class="space-y-6 text-center">
            <flux:heading size="xl">{{ __('Quiz registration') }}</flux:heading>
            <flux:callout variant="warning" icon="clock">
                {{ __('Registration is not currently available. Please check with the event staff.') }}
            </flux:callout>
        </div>
    @endif

    <flux:text class="text-center text-xs">
        <flux:link :href="route('login')" wire:navigate>{{ __('Staff sign in') }}</flux:link>
    </flux:text>
</div>
