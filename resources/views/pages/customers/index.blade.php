<?php

use App\Enums\EmailMarketingProvider;
use App\Models\Customer;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customers')] class extends Component
{
    public ?int $editingCustomerId = null;
    public string $name = '';
    public string $provider = '';
    public string $apiKey = '';
    public string $listId = '';
    public string $mailchimpServerPrefix = '';

    /** @return Collection<int, Customer> */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()->withCount('quizzes')->orderBy('name')->get();
    }

    public function createCustomer(): void
    {
        $this->resetForm();
        Flux::modal('customer-editor')->show();
    }

    public function editCustomer(int $customerId): void
    {
        $customer = Customer::query()->findOrFail($customerId);

        $this->editingCustomerId = $customer->id;
        $this->name = $customer->name;
        $this->provider = $customer->email_marketing_provider?->value ?? '';
        $this->apiKey = '';
        $this->listId = $customer->email_marketing_list_id ?? '';
        $this->mailchimpServerPrefix = $customer->mailchimp_server_prefix ?? '';
        $this->resetValidation();

        Flux::modal('customer-editor')->show();
    }

    public function saveCustomer(): void
    {
        $this->mailchimpServerPrefix = Str::lower(trim($this->mailchimpServerPrefix));
        $customer = $this->editingCustomerId
            ? Customer::query()->findOrFail($this->editingCustomerId)
            : null;
        $providerChanged = $customer?->email_marketing_provider?->value !== ($this->provider ?: null);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['nullable', Rule::enum(EmailMarketingProvider::class)],
            'apiKey' => [
                Rule::requiredIf($this->provider !== '' && (! $customer?->email_marketing_api_key || $providerChanged)),
                'nullable',
                'string',
                'max:1000',
            ],
            'listId' => [Rule::requiredIf($this->provider !== ''), 'nullable', 'string', 'max:255'],
            'mailchimpServerPrefix' => [
                Rule::requiredIf($this->provider === EmailMarketingProvider::Mailchimp->value),
                'nullable',
                'string',
                'regex:/^[a-z0-9-]+$/',
                'max:50',
            ],
        ]);

        $attributes = [
            'name' => trim($validated['name']),
            'email_marketing_provider' => $validated['provider'] ?: null,
            'email_marketing_list_id' => $validated['provider'] ? trim($validated['listId']) : null,
            'mailchimp_server_prefix' => $validated['provider'] === EmailMarketingProvider::Mailchimp->value
                ? Str::lower(trim($validated['mailchimpServerPrefix']))
                : null,
        ];

        if (! $validated['provider']) {
            $attributes['email_marketing_api_key'] = null;
        } elseif (filled($validated['apiKey'])) {
            $attributes['email_marketing_api_key'] = trim($validated['apiKey']);
        }

        Customer::query()->updateOrCreate(['id' => $customer?->id], $attributes);

        $this->resetForm();
        unset($this->customers);
        Flux::modal('customer-editor')->close();
        Flux::toast(variant: 'success', text: __('Customer saved.'));
    }

    private function resetForm(): void
    {
        $this->reset(['editingCustomerId', 'name', 'provider', 'apiKey', 'listId', 'mailchimpServerPrefix']);
        $this->resetValidation();
    }
};
?>

<section class="w-full space-y-6">
    <div class="flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
            <flux:subheading>{{ __('Manage the email provider connection used by each customer’s quizzes.') }}</flux:subheading>
        </div>
        <flux:button variant="primary" wire:click="createCustomer">{{ __('New customer') }}</flux:button>
    </div>

    @if ($this->customers->isEmpty())
        <flux:card class="text-center">
            <flux:heading>{{ __('No customers yet') }}</flux:heading>
            <flux:text class="mt-2">{{ __('Create a customer, connect Mailchimp or Klaviyo, then assign it to a quiz.') }}</flux:text>
        </flux:card>
    @else
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Email provider') }}</flux:table.column>
                <flux:table.column>{{ __('Quizzes') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->customers as $customer)
                    <flux:table.row :key="$customer->id">
                        <flux:table.cell variant="strong">{{ $customer->name }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($customer->hasEmailMarketingConnection())
                                <flux:badge color="green" size="sm">{{ $customer->email_marketing_provider->label() }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Not connected') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $customer->quizzes_count }}</flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button variant="ghost" size="sm" wire:click="editCustomer({{ $customer->id }})">{{ __('Configure') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="customer-editor" class="md:w-[38rem]">
        <form wire:submit="saveCustomer" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingCustomerId ? __('Configure customer') : __('Create customer') }}</flux:heading>
                <flux:subheading>{{ __('API keys are encrypted before they are stored.') }}</flux:subheading>
            </div>

            <flux:input wire:model="name" :label="__('Customer name')" required autofocus />

            <flux:select wire:model.live="provider" :label="__('Email provider')">
                <flux:select.option value="">{{ __('None') }}</flux:select.option>
                @foreach (EmailMarketingProvider::cases() as $providerOption)
                    <flux:select.option :value="$providerOption->value">{{ $providerOption->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($provider)
                <flux:input
                    wire:model="apiKey"
                    type="password"
                    :label="__('Private API key')"
                    :description="$editingCustomerId ? __('Leave blank to keep the stored key.') : null"
                    autocomplete="new-password"
                />
                <flux:input wire:model="listId" :label="$provider === 'mailchimp' ? __('Audience ID') : __('List ID')" required />

                @if ($provider === 'mailchimp')
                    <flux:input
                        wire:model="mailchimpServerPrefix"
                        :label="__('Server prefix')"
                        :description="__('For example, us21—the suffix shown in your Mailchimp API URL.')"
                        placeholder="us21"
                        required
                    />
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit">{{ __('Save customer') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
