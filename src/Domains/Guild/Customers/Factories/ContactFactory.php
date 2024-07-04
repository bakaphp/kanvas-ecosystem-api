<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\ContactType;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition()
    {
        $contactType = ContactType::getByName('Email');

        return [
            'contacts_types_id' => $contactType->id,
            'value' => fake()->email,
            'weight' => 1,
        ];
    }
}
