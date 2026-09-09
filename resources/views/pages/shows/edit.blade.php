<?php

use App\Enums\QuizScoringMode;
use App\Enums\QuestionAnswerType;
use App\Enums\ShowActivationMode;
use App\Models\Customer;
use App\Models\Question;
use App\Models\Show;
use App\Services\SafeRichText;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Configure show')] class extends Component {
    use WithFileUploads;

    public Show $show;
    public string $name;
    public string $activationMode;
    public bool $isActive;
    public ?string $startDate = null;
    public ?string $startTime = null;
    public ?string $endDate = null;
    public ?string $endTime = null;
    public string $scoringMode;
    public ?int $customerId = null;
    public ?int $maximumScore = null;
    public string $registrationMessage = '';
    public ?TemporaryUploadedFile $registrationImage = null;
    public ?TemporaryUploadedFile $perfectScoreImage = null;
    public string $leaderboardMessage = '';
    public string $advertisementEmbedUrl = '';
    public string $newQuestion = '';
    public string $newCorrectAnswer = '';
    public string $newSalesNotes = '';
    public string $activeTab = 'quiz';
    public ?int $editingNotesQuestionId = null;
    public string $notesEditorContent = '';
    public ?int $editingAnswerQuestionId = null;
    public string $answerEditorType = 'free_text';
    public string $answerEditorCorrectAnswer = '';
    public string $answerEditorAcceptedAnswers = '';
    public string $answerEditorOptions = '';
    /** @var array<int, string> */
    public array $questionPrompts = [];
    /** @var array<int, string> */
    public array $correctAnswers = [];
    /** @var array<int, string> */
    public array $salesNotes = [];
    /** @var array<int, string> */
    public array $answerTypes = [];

    /** @return Collection<int, Customer> */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->orderBy('name')->get();
    }

    public function mount(Show $show): void
    {
        $this->show = $show->loadMissing('quiz.questions', 'quiz.customer');
        $this->name = $show->name;
        $this->activationMode = $show->activation_mode->value;
        $this->isActive = $show->is_active;
        $this->startDate = $show->starts_at?->format('Y-m-d');
        $this->startTime = $show->starts_at?->format('H:i');
        $this->endDate = $show->ends_at?->format('Y-m-d');
        $this->endTime = $show->ends_at?->format('H:i');
        $this->scoringMode = $show->quiz->scoring_mode->value;
        $this->customerId = $show->quiz->customer_id;
        $this->maximumScore = $show->quiz->maximum_score;
        $this->registrationMessage = $show->quiz->registration_message ?? '';
        $this->leaderboardMessage = $show->quiz->leaderboard_message ?? '';
        $this->advertisementEmbedUrl = $show->quiz->advertisement_embed_url ?? '';
        $this->refreshQuestions();
    }

    public function save(): void
    {
        $validated = $this->validate($this->configurationRules());
        $startsAt = $this->dateTime($validated['startDate'], $validated['startTime']);
        $endsAt = $this->dateTime($validated['endDate'], $validated['endTime']);

        if ($startsAt && $endsAt && $endsAt->lte($startsAt)) {
            $this->addError('endDate', __('The show must end after it starts.'));
            return;
        }

        if ($validated['scoringMode'] !== 'summary' && $this->show->quiz->questions()->doesntExist()) {
            $this->addError('newQuestion', $validated['scoringMode'] === 'per_answer'
                ? __('Add at least one question for per-answer scoring.')
                : __('Add at least one question for question/answer mode.'));
            return;
        }

        if ($validated['scoringMode'] === 'question_answer' && $this->show->quiz->questions()->whereNull('correct_answer')->exists()) {
            $this->addError('newCorrectAnswer', __('Every question needs a correct answer for question/answer mode.'));
            return;
        }

        $previousRegistrationImagePath = $this->show->quiz->registration_image_path;
        $registrationImagePath = $this->registrationImage?->store('registration-images', 'public');
        $previousPerfectScoreImagePath = $this->show->quiz->perfect_score_image_path;
        $perfectScoreImagePath = $this->perfectScoreImage?->store('perfect-score-images', 'public');

        DB::transaction(function () use ($validated, $startsAt, $endsAt, $registrationImagePath, $perfectScoreImagePath): void {
            $this->show->update([
                'name' => $validated['name'],
                'activation_mode' => $validated['activationMode'],
                'is_active' => $validated['activationMode'] === 'manual' && $validated['isActive'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            $this->show->quiz->update([
                'customer_id' => $validated['customerId'],
                'scoring_mode' => $validated['scoringMode'],
                'maximum_score' => $validated['scoringMode'] === 'summary' ? $validated['maximumScore'] : null,
                'registration_message' => trim($validated['registrationMessage']) ?: null,
                'registration_image_path' => $registrationImagePath ?? $this->show->quiz->registration_image_path,
                'perfect_score_image_path' => $perfectScoreImagePath ?? $this->show->quiz->perfect_score_image_path,
                'leaderboard_message' => trim($validated['leaderboardMessage']) ?: null,
                'advertisement_embed_url' => trim($validated['advertisementEmbedUrl']) ?: null,
            ]);
        });

        if ($registrationImagePath && $previousRegistrationImagePath) {
            Storage::disk('public')->delete($previousRegistrationImagePath);
        }

        if ($perfectScoreImagePath && $previousPerfectScoreImagePath) {
            Storage::disk('public')->delete($previousPerfectScoreImagePath);
        }

        $this->registrationImage = null;
        $this->perfectScoreImage = null;
        $this->show->quiz->refresh();

        Flux::toast(variant: 'success', text: __('Show configuration saved.'));
    }

    public function addQuestion(): void
    {
        $validated = $this->validate([
            'newQuestion' => ['required', 'string', 'max:1000'],
            'newCorrectAnswer' => [Rule::requiredIf($this->scoringMode === 'question_answer'), 'nullable', 'string', 'max:1000'],
            'newSalesNotes' => ['nullable', 'string', 'max:5000'],
        ]);
        $position = ((int) $this->show->quiz->questions()->max('position')) + 1;
        $this->show->quiz->questions()->create([
            'prompt' => trim($validated['newQuestion']),
            'correct_answer' => trim($validated['newCorrectAnswer'] ?? '') ?: null,
            'answer_type' => QuestionAnswerType::FreeText,
            'sales_notes' => SafeRichText::sanitize(trim($validated['newSalesNotes'])) ?: null,
            'position' => $position,
        ]);
        $this->newQuestion = '';
        $this->newCorrectAnswer = '';
        $this->newSalesNotes = '';
        $this->refreshQuestions();
    }

    public function updateQuestion(int $questionId): void
    {
        $question = $this->question($questionId);
        $validated = $this->validate([
            "questionPrompts.{$questionId}" => ['required', 'string', 'max:1000'],
            "correctAnswers.{$questionId}" => [Rule::requiredIf($this->scoringMode === 'question_answer'), 'nullable', 'string', 'max:1000'],
            "salesNotes.{$questionId}" => ['nullable', 'string', 'max:5000'],
        ]);
        $question->update([
            'prompt' => trim($validated['questionPrompts'][$questionId]),
            'correct_answer' => trim($validated['correctAnswers'][$questionId] ?? '') ?: null,
        ]);

        Flux::toast(variant: 'success', text: __('Question saved.'));
    }

    public function removeQuestion(int $questionId): void
    {
        $this->question($questionId)->delete();
        $this->normalizePositions();
        $this->refreshQuestions();
    }

    public function clearPerfectScoreImage(): void
    {
        if ($this->perfectScoreImage) {
            $this->perfectScoreImage = null;
            $this->resetValidation('perfectScoreImage');

            return;
        }

        $quiz = $this->show->quiz;
        $perfectScoreImagePath = $quiz->perfect_score_image_path;

        if (! $perfectScoreImagePath) {
            return;
        }

        $quiz->update(['perfect_score_image_path' => null]);
        Storage::disk('public')->delete($perfectScoreImagePath);
        $this->show->setRelation('quiz', $quiz->fresh());

        Flux::toast(variant: 'success', text: __('Perfect score image removed.'));
    }

    public function sortQuestion(int $questionId, int $position): void
    {
        $question = $this->question($questionId);
        $questions = $this->show->quiz->questions()->get();
        $position = min(max($position, 0), $questions->count() - 1);
        $currentPosition = $question->position - 1;

        if ($position === $currentPosition) {
            return;
        }

        DB::transaction(function () use ($question, $position, $currentPosition): void {
            $question->update(['position' => 0]);

            if ($position < $currentPosition) {
                $this->show->quiz->questions()
                    ->whereBetween('position', [$position + 1, $currentPosition])
                    ->orderByDesc('position')
                    ->get()
                    ->each(fn (Question $question): bool => $question->update(['position' => $question->position + 1]));
            } else {
                $this->show->quiz->questions()
                    ->whereBetween('position', [$currentPosition + 2, $position + 1])
                    ->orderBy('position')
                    ->get()
                    ->each(fn (Question $question): bool => $question->update(['position' => $question->position - 1]));
            }

            $question->update(['position' => $position + 1]);
        });

        $this->refreshQuestions();
    }

    public function editNotes(?int $questionId = null): void
    {
        $this->editingNotesQuestionId = $questionId;
        $this->notesEditorContent = $questionId === null
            ? $this->newSalesNotes
            : ($this->salesNotes[$this->question($questionId)->id] ?? '');
        $this->resetValidation('notesEditorContent');

        Flux::modal('question-notes')->show();
    }

    public function saveNotes(): void
    {
        $validated = $this->validate([
            'notesEditorContent' => ['nullable', 'string', 'max:5000'],
        ]);
        $notes = SafeRichText::sanitize(trim($validated['notesEditorContent'])) ?: null;

        if ($this->editingNotesQuestionId === null) {
            $this->newSalesNotes = $notes ?? '';
        } else {
            $question = $this->question($this->editingNotesQuestionId);
            $question->update(['sales_notes' => $notes]);
            $this->salesNotes[$question->id] = $notes ?? '';
        }

        Flux::modal('question-notes')->close();
        Flux::toast(variant: 'success', text: __('Sales notes saved.'));
    }

    public function editAnswer(int $questionId): void
    {
        $question = $this->question($questionId);
        $this->editingAnswerQuestionId = $question->id;
        $this->answerEditorType = $question->answer_type->value;
        $this->answerEditorCorrectAnswer = $question->correct_answer ?? '';
        $this->answerEditorAcceptedAnswers = implode("\n", $question->accepted_answers ?? []);
        $this->answerEditorOptions = implode("\n", $question->answer_options ?? []);
        $this->resetValidation([
            'answerEditorType',
            'answerEditorCorrectAnswer',
            'answerEditorAcceptedAnswers',
            'answerEditorOptions',
        ]);

        Flux::modal('question-answer')->show();
    }

    public function saveAnswer(): void
    {
        $validated = $this->validate([
            'answerEditorType' => ['required', Rule::enum(QuestionAnswerType::class)],
            'answerEditorCorrectAnswer' => ['required', 'string', 'max:1000'],
            'answerEditorAcceptedAnswers' => ['nullable', 'string', 'max:10000'],
            'answerEditorOptions' => ['nullable', 'string', 'max:10000'],
        ]);
        $question = $this->question($this->editingAnswerQuestionId ?? 0);
        $answerType = QuestionAnswerType::from($validated['answerEditorType']);
        $acceptedAnswers = $this->answerLines($validated['answerEditorAcceptedAnswers']);
        $answerOptions = $this->answerLines($validated['answerEditorOptions']);

        if ($answerType === QuestionAnswerType::MultipleChoice && count($answerOptions) < 2) {
            $this->addError('answerEditorOptions', __('Add at least two answer choices.'));

            return;
        }

        if ($answerType === QuestionAnswerType::MultipleChoice && ! in_array(trim($validated['answerEditorCorrectAnswer']), $answerOptions, true)) {
            $this->addError('answerEditorCorrectAnswer', __('Choose the correct answer from the available choices.'));

            return;
        }

        $question->update([
            'answer_type' => $answerType,
            'correct_answer' => trim($validated['answerEditorCorrectAnswer']),
            'accepted_answers' => $answerType === QuestionAnswerType::FreeText ? $acceptedAnswers ?: null : null,
            'answer_options' => $answerType === QuestionAnswerType::MultipleChoice ? $answerOptions : null,
        ]);
        $this->refreshQuestions();

        Flux::modal('question-answer')->close();
        Flux::toast(variant: 'success', text: __('Answer settings saved.'));
    }

    /** @return array<string, array<int, mixed>> */
    private function configurationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'activationMode' => ['required', Rule::enum(ShowActivationMode::class)],
            'isActive' => ['boolean'],
            'startDate' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:Y-m-d'],
            'startTime' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:H:i'],
            'endDate' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:Y-m-d'],
            'endTime' => [Rule::requiredIf($this->activationMode === 'scheduled'), 'nullable', 'date_format:H:i'],
            'scoringMode' => ['required', Rule::enum(QuizScoringMode::class)],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'maximumScore' => [Rule::requiredIf($this->scoringMode === 'summary'), 'nullable', 'integer', 'min:1', 'max:65535'],
            'registrationMessage' => ['nullable', 'string', 'max:2000'],
            'registrationImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'perfectScoreImage' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'leaderboardMessage' => ['nullable', 'string', 'max:160'],
            'advertisementEmbedUrl' => ['nullable', 'string', 'url', 'starts_with:https://', 'max:2048'],
        ];
    }

    private function question(int $questionId): Question
    {
        return $this->show->quiz->questions()->findOrFail($questionId);
    }

    private function refreshQuestions(): void
    {
        $this->questionPrompts = $this->show->quiz->questions()->pluck('prompt', 'id')->all();
        $this->correctAnswers = $this->show->quiz->questions()->pluck('correct_answer', 'id')->map(fn (?string $answer): string => $answer ?? '')->all();
        $this->salesNotes = $this->show->quiz->questions()->pluck('sales_notes', 'id')->map(fn (?string $notes): string => $notes ?? '')->all();
        $this->answerTypes = $this->show->quiz->questions()->pluck('answer_type', 'id')->map(fn (QuestionAnswerType $type): string => $type->value)->all();
    }

    private function normalizePositions(): void
    {
        $this->show->quiz->questions()->get()->each(fn (Question $question, int $index) => $question->update(['position' => $index + 1]));
    }

    /** @return array<int, string> */
    private function answerLines(string $answers): array
    {
        return collect(preg_split('/\R/', $answers) ?: [])
            ->map(fn (string $answer): string => trim($answer))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function dateTime(?string $date, ?string $time): ?Carbon
    {
        return $date && $time ? Carbon::createFromFormat('Y-m-d H:i', "{$date} {$time}") : null;
    }
}; ?>

<section class="w-full space-y-8">
    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('shows.index')" wire:navigate>{{ __('Shows') }}</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $show->name }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    <form wire:submit="save" class="space-y-6">
        <flux:tab.group>
            <flux:tabs wire:model="activeTab">
                <flux:tab name="quiz" icon="list-bullet">{{ __('Quiz') }}</flux:tab>
                <flux:tab name="settings" icon="cog-6-tooth">{{ __('Settings') }}</flux:tab>
            </flux:tabs>

            <flux:tab.panel name="quiz" class="space-y-6">
                <flux:card class="space-y-5">
                    <flux:heading size="lg">{{ __('Show details') }}</flux:heading>
                    <div class="grid gap-4 lg:grid-cols-2">
                        <flux:input wire:model="name" :label="__('Show name')" required />
                        <flux:select wire:model="customerId" :label="__('Customer')">
                            <flux:select.option value="">{{ __('No customer') }}</flux:select.option>
                            @foreach ($this->customers as $customer)
                                <flux:select.option :value="$customer->id" wire:key="customer-{{ $customer->id }}">{{ $customer->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:radio.group wire:model.live="activationMode" variant="cards" :label="__('Activation')" class="grid sm:grid-cols-2">
                        <flux:radio value="manual" :label="__('Manual')" :description="__('Staff controls activation.')" />
                        <flux:radio value="scheduled" :label="__('Scheduled')" :description="__('Activates during its schedule.')" />
                    </flux:radio.group>
                    @if ($activationMode === 'manual')
                        <flux:switch wire:model="isActive" :label="__('Show is active')" />
                    @else
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <flux:date-picker wire:model="startDate" type="input" :label="__('Start date')" />
                            <flux:time-picker wire:model="startTime" type="input" :label="__('Start time')" />
                            <flux:date-picker wire:model="endDate" type="input" :label="__('End date')" />
                            <flux:time-picker wire:model="endTime" type="input" :label="__('End time')" />
                        </div>
                    @endif
                </flux:card>

                <flux:card class="space-y-5">
                    <flux:heading size="lg">{{ __('Quiz scoring') }}</flux:heading>
                    <flux:radio.group wire:model.live="scoringMode" variant="cards" class="grid lg:grid-cols-3">
                        <flux:radio value="per_answer" :label="__('Per-answer scoring')" :description="__('Record each answer individually.')" />
                        <flux:radio value="summary" :label="__('Summary scoring')" :description="__('Enter one final score.')" />
                        <flux:radio value="question_answer" :label="__('Question/answer quiz')" :description="__('Contestants answer released questions on their phone.')" />
                    </flux:radio.group>
                    @if ($scoringMode === 'summary')
                        <flux:input wire:model="maximumScore" type="number" min="1" max="65535" :label="__('Maximum score')" />
                        @if ($questionPrompts)
                            <flux:callout>{{ __('Questions are retained if you switch back to per-answer scoring.') }}</flux:callout>
                        @endif
                    @else
                        <div class="space-y-3">
                            <div wire:sort="sortQuestion" class="space-y-2">
                                @foreach ($questionPrompts as $questionId => $prompt)
                                    <div
                                        wire:key="question-{{ $questionId }}"
                                        wire:sort:item="{{ $questionId }}"
                                        class="grid items-end gap-2 rounded-xl border border-zinc-200 bg-white p-3 shadow-xs dark:border-white/10 dark:bg-white/5 lg:grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)_auto]"
                                    >
                                        <div wire:sort:handle class="flex h-10 cursor-grab items-center justify-center px-1 text-zinc-400 active:cursor-grabbing" aria-label="{{ __('Drag to reorder question') }}">
                                            <flux:icon name="bars-3" class="size-5" />
                                        </div>
                                        <flux:input wire:model="questionPrompts.{{ $questionId }}" :label="__('Question')" />
                                        @if (($answerTypes[$questionId] ?? 'free_text') === 'multiple_choice')
                                            <div class="flex h-10 items-center gap-2 rounded-lg border border-zinc-200 px-3 text-sm dark:border-white/10">
                                                <flux:badge color="blue">{{ __('Multiple choice') }}</flux:badge>
                                                <span class="truncate text-zinc-600 dark:text-zinc-300">{{ $correctAnswers[$questionId] }}</span>
                                            </div>
                                        @else
                                            <flux:input wire:model="correctAnswers.{{ $questionId }}" :label="__('Correct answer')" />
                                        @endif
                                        <div wire:sort:ignore class="flex flex-wrap gap-2">
                                            <flux:button type="button" icon="adjustments-horizontal" wire:click="editAnswer({{ $questionId }})">
                                                {{ __('Answer') }}
                                            </flux:button>
                                            <flux:button type="button" icon="document-text" wire:click="editNotes({{ $questionId }})">
                                                {{ __('Notes') }}
                                            </flux:button>
                                            <flux:button type="button" icon="check" square :tooltip="__('Save question')" wire:click="updateQuestion({{ $questionId }})" />
                                            <flux:button type="button" icon="trash" square variant="danger" :tooltip="__('Remove question')" wire:click="removeQuestion({{ $questionId }})" wire:confirm="{{ __('Remove this question?') }}" />
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="grid items-end gap-2 rounded-xl border border-dashed border-zinc-300 p-3 dark:border-white/15 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]">
                                <flux:input wire:model="newQuestion" :label="__('New question')" />
                                <flux:input wire:model="newCorrectAnswer" :label="__('Correct answer')" />
                                <div class="flex flex-wrap gap-2">
                                    <flux:button type="button" icon="document-text" wire:click="editNotes">{{ __('Notes') }}</flux:button>
                                    <flux:button type="button" icon="plus" variant="primary" wire:click="addQuestion">{{ __('Add') }}</flux:button>
                                </div>
                            </div>
                            <flux:error name="newQuestion" />
                            <flux:error name="newCorrectAnswer" />
                            <flux:error name="newSalesNotes" />
                        </div>
                    @endif
                </flux:card>
            </flux:tab.panel>

            <flux:tab.panel name="settings" class="space-y-6">
                <flux:card class="space-y-5">
                    <div>
                        <flux:heading size="lg">{{ __('Registration information') }}</flux:heading>
                        <flux:text>{{ __('Details and artwork attendees see before signing up.') }}</flux:text>
                    </div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="space-y-3">
                            <flux:heading size="sm">{{ __('Registration image') }}</flux:heading>
                            @if ($registrationImage?->isPreviewable())
                                <img src="{{ $registrationImage->temporaryUrl() }}" alt="{{ __('Selected registration artwork preview') }}" class="h-40 w-full rounded-xl border border-zinc-200 object-contain dark:border-zinc-700" />
                            @elseif ($show->quiz->registration_image_path)
                                <img src="{{ Storage::disk('public')->url($show->quiz->registration_image_path) }}" alt="{{ __('Current registration artwork') }}" class="h-40 w-full rounded-xl border border-zinc-200 object-contain dark:border-zinc-700" />
                            @endif
                            <flux:file-upload wire:model="registrationImage">
                                <flux:file-upload.dropzone :heading="__('Drop an image or browse')" :text="__('JPG, PNG, or WebP up to 5 MB')" with-progress />
                            </flux:file-upload>
                        </div>
                        <flux:textarea wire:model="registrationMessage" :label="__('Registration message')" :description="__('Optional information shown above the registration form.')" rows="8" maxlength="2000" />
                    </div>

                    <flux:separator />

                    <div>
                        <flux:heading size="lg">{{ __('Leaderboard settings') }}</flux:heading>
                        <flux:text>{{ __('Configure the leaderboard message, advertisement, and perfect-score artwork.') }}</flux:text>
                    </div>
                    <div class="grid gap-6 lg:grid-cols-2">
                        <div class="space-y-4">
                            <flux:textarea wire:model="leaderboardMessage" :label="__('Leaderboard message')" :description="__('Optional information shown in the bottom bar.')" rows="3" maxlength="160" />
                            <flux:input wire:model="advertisementEmbedUrl" type="url" :label="__('Advertisement embed URL')" :description="__('Optional HTTPS autoplay embed URL used by Play Ad.')" placeholder="https://www.youtube.com/embed/...?autoplay=1" />
                        </div>
                        <div class="space-y-3">
                            <div>
                                <flux:heading size="sm">{{ __('Perfect score icon') }}</flux:heading>
                                <flux:text>{{ __('Artwork shown when someone earns a perfect score.') }}</flux:text>
                            </div>
                            @if ($perfectScoreImage?->isPreviewable())
                                <img src="{{ $perfectScoreImage->temporaryUrl() }}" alt="{{ __('Selected perfect score artwork preview') }}" class="h-40 w-full rounded-xl border border-zinc-200 object-contain dark:border-zinc-700" />
                                <flux:text>{{ __('New image ready to save.') }}</flux:text>
                            @elseif ($perfectScoreImage)
                                <flux:callout variant="warning">{{ __('The selected file cannot be previewed.') }}</flux:callout>
                            @elseif ($show->quiz->perfect_score_image_path)
                                <img src="{{ Storage::disk('public')->url($show->quiz->perfect_score_image_path) }}" alt="{{ __('Current perfect score artwork') }}" class="h-40 w-full rounded-xl border border-zinc-200 object-contain dark:border-zinc-700" />
                            @endif
                            @if ($perfectScoreImage || $show->quiz->perfect_score_image_path)
                                <flux:button type="button" variant="danger" icon="x-mark" wire:click="clearPerfectScoreImage">{{ __('Clear image') }}</flux:button>
                            @else
                                <flux:file-upload wire:model="perfectScoreImage">
                                    <flux:file-upload.dropzone :heading="__('Drop a perfect score icon or browse')" :text="__('JPG, PNG, or WebP up to 5 MB')" with-progress />
                                </flux:file-upload>
                            @endif
                        </div>
                    </div>
                </flux:card>
            </flux:tab.panel>
        </flux:tab.group>

        <div class="flex justify-end"><flux:button variant="primary" icon="check" type="submit">{{ __('Save configuration') }}</flux:button></div>
    </form>

    <flux:modal name="question-notes" class="md:w-[46rem]">
        <form wire:submit="saveNotes" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Sales notes') }}</flux:heading>
                <flux:text>{{ __('Add staff-only talking points for this question.') }}</flux:text>
            </div>
            <flux:editor wire:model="notesEditorContent" :label="__('Notes')" class="min-h-64" />
            <flux:error name="notesEditorContent" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save notes') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="question-answer" class="md:w-[42rem]">
        <form wire:submit="saveAnswer" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Answer settings') }}</flux:heading>
                <flux:text>{{ __('Choose how contestants answer and what should count as correct.') }}</flux:text>
            </div>

            <flux:radio.group wire:model.live="answerEditorType" :label="__('Answer type')" variant="cards" class="grid sm:grid-cols-2">
                <flux:radio value="free_text" :label="__('Free text')" :description="__('Accept typed answers with controlled fuzzy matching.')" />
                <flux:radio value="multiple_choice" :label="__('Multiple choice')" :description="__('Contestants select one answer on their phone.')" />
            </flux:radio.group>

            @if ($answerEditorType === 'multiple_choice')
                <flux:textarea
                    wire:model.live.debounce.300ms="answerEditorOptions"
                    :label="__('Answer choices')"
                    :description="__('Enter one choice per line. Add at least two.')"
                    rows="6"
                />
                @php($availableAnswerOptions = collect(preg_split('/\R/', $answerEditorOptions) ?: [])->map(fn ($answer) => trim($answer))->filter()->unique())
                <flux:select wire:model="answerEditorCorrectAnswer" :label="__('Correct answer')">
                    <flux:select.option value="">{{ __('Choose an answer') }}</flux:select.option>
                    @foreach ($availableAnswerOptions as $option)
                        <flux:select.option :value="$option" wire:key="answer-option-{{ md5($option) }}">{{ $option }}</flux:select.option>
                    @endforeach
                </flux:select>
            @else
                <flux:input wire:model="answerEditorCorrectAnswer" :label="__('Primary correct answer')" />
                <flux:textarea
                    wire:model="answerEditorAcceptedAnswers"
                    :label="__('Additional accepted answers')"
                    :description="__('Enter one approved alternative per line. Number words, punctuation, capitalization, and leading articles are normalized automatically.')"
                    rows="5"
                />
                <flux:callout icon="sparkles">
                    {{ __('Longer responses containing an accepted multi-word phrase and conservative spelling mistakes can also match automatically.') }}
                </flux:callout>
            @endif

            <flux:error name="answerEditorType" />
            <flux:error name="answerEditorCorrectAnswer" />
            <flux:error name="answerEditorAcceptedAnswers" />
            <flux:error name="answerEditorOptions" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" icon="check">{{ __('Save answer settings') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
