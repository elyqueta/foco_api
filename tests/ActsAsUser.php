<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;

trait ActsAsUser
{
    protected ?User $actingUser = null;

    protected function actingAsUser(?array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        return $this->authenticateAs($user);
    }

    protected function authenticateAs(User $user): User
    {
        $this->actingUser = $user;

        $token = $user->createToken('test', ['*'], now()->addMinutes(10080))->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]);

        return $user;
    }

    protected function actingUser(): ?User
    {
        return $this->actingUser;
    }
}
