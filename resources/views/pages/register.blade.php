<?php

use App\Models\Show;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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

    public function mount(): void
    {
        $this->show = $this->currentShow();
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

        $show->participants()->create([
            'first_name' => $validated['firstName'],
            'last_name' => $validated['lastName'],
            'email' => $validated['email'],
            'marketing_opt_in' => $validated['marketingOptIn'],
        ]);

        $this->registered = true;
    }

    private function currentShow(): ?Show
    {
        $activeShows = Show::query()->activeAt()->with('quiz')->limit(2)->get();

        return $activeShows->count() === 1 ? $activeShows->first() : null;
    }
};
?>

<div class="flex flex-col gap-6">
    @if ($registered)
        <div class="space-y-6 text-center">
            <flux:heading size="xl">{{ __('You’re in, :name!', ['name' => $firstName]) }}</flux:heading>
            <flux:text>{{ __('Head to the quiz table when you’re ready to play.') }}</flux:text>
            <flux:callout variant="success" icon="check-circle">
                {{ __('You’re registered for :show.', ['show' => $show->name]) }}
            </flux:callout>
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
