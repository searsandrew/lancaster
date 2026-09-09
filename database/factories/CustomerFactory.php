<?php

namespace Database\Factories;

use App\Enums\EmailMarketingProvider;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'email_marketing_provider' => null,
            'email_marketing_api_key' => null,
            'email_marketing_list_id' => null,
            'mailchimp_server_prefix' => null,
        ];
    }

    public function mailchimp(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_marketing_provider' => EmailMarketingProvider::Mailchimp,
            'email_marketing_api_key' => 'mailchimp-test-key',
            'email_marketing_list_id' => 'mailchimp-list-id',
            'mailchimp_server_prefix' => 'us1',
        ]);
    }

    public function klaviyo(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_marketing_provider' => EmailMarketingProvider::Klaviyo,
            'email_marketing_api_key' => 'klaviyo-test-key',
            'email_marketing_list_id' => 'klaviyo-list-id',
            'mailchimp_server_prefix' => null,
        ]);
    }
}
