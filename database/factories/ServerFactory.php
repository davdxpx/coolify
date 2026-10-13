<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Names must pass ValidationPatterns::nameRules(); Faker names such as "Dean O'Hara MD"
            // do not, and tests that save the server form then failed at random.
            'name' => str_replace("'", '', fake()->unique()->name()),
            'ip' => fake()->unique()->ipv4(),
            'private_key_id' => 1,
        ];
    }
}
