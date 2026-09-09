<?php

namespace App\Models;

use App\Enums\EmailMarketingProvider;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property EmailMarketingProvider|null $email_marketing_provider
 * @property string|null $email_marketing_api_key
 * @property string|null $email_marketing_list_id
 * @property string|null $mailchimp_server_prefix
 */
#[Fillable(['name', 'email_marketing_provider', 'email_marketing_api_key', 'email_marketing_list_id', 'mailchimp_server_prefix'])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $hidden = ['email_marketing_api_key'];

    /** @return HasMany<Quiz, $this> */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    public function hasEmailMarketingConnection(): bool
    {
        return $this->email_marketing_provider !== null
            && filled($this->email_marketing_api_key)
            && filled($this->email_marketing_list_id)
            && ($this->email_marketing_provider !== EmailMarketingProvider::Mailchimp || filled($this->mailchimp_server_prefix));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_marketing_provider' => EmailMarketingProvider::class,
            'email_marketing_api_key' => 'encrypted',
        ];
    }
}
