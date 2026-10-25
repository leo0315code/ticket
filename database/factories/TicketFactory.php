<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'no' => 'TK-TEST-'.Str::uuid(),
            'title' => fake()->sentence(),
            'content' => fake()->paragraph(),
            'status' => 'open',
            'priority' => 'medium',
            'user_id' => User::factory(),
            'sla_due_at' => now()->addHours(24),
        ];
    }
}
