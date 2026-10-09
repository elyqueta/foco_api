<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\ActsAsUser;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use ActsAsUser;
    use RefreshDatabase;

    #[Test]
    public function settings_return_the_contract_shape(): void
    {
        $this->actingAsUser([
            'name' => 'Zua',
            'email' => 'admin@todo.ao',
            'theme' => 'light',
            'timezone' => 'Africa/Luanda',
        ]);

        $this->getJson('/api/v1/settings')
            ->assertOk()
            ->assertJsonStructure([
                'userName',
                'theme',
                'timezone',
                'email',
                'notifications' => [
                    'emailEnabled',
                    'inAppEnabled',
                    'digestHour',
                    'dueSoonHours',
                    'dueImminentMinutes',
                    'timerLongHours',
                    'types',
                ],
            ])
            ->assertJson([
                'userName' => 'Zua',
                'theme' => 'light',
                'timezone' => 'Africa/Luanda',
                'email' => 'admin@todo.ao',
                'notifications' => [
                    'emailEnabled' => true,
                    'inAppEnabled' => true,
                    'digestHour' => 8,
                    'dueSoonHours' => 24,
                    'dueImminentMinutes' => 60,
                    'timerLongHours' => 4,
                ],
            ]);
    }

    #[Test]
    public function settings_update_user_fields(): void
    {
        $user = $this->actingAsUser([
            'name' => 'Zua',
            'theme' => 'light',
            'timezone' => 'Africa/Luanda',
        ]);

        $this->patchJson('/api/v1/settings', [
            'userName' => 'Zua Manuel',
            'theme' => 'dark',
            'timezone' => 'Europe/Lisbon',
        ])
            ->assertOk()
            ->assertJson([
                'userName' => 'Zua Manuel',
                'theme' => 'dark',
                'timezone' => 'Europe/Lisbon',
            ]);

        $user->refresh();

        $this->assertSame('Zua Manuel', $user->name);
        $this->assertSame('dark', $user->theme);
        $this->assertSame('Europe/Lisbon', $user->timezone);
    }

    #[Test]
    public function settings_update_is_partial(): void
    {
        $user = $this->actingAsUser([
            'name' => 'Zua',
            'theme' => 'dark',
            'timezone' => 'Africa/Luanda',
        ]);

        $this->patchJson('/api/v1/settings', ['theme' => 'light'])
            ->assertOk()
            ->assertJson([
                'userName' => 'Zua',
                'theme' => 'light',
                'timezone' => 'Africa/Luanda',
            ]);

        $user->refresh();

        $this->assertSame('light', $user->theme);
        $this->assertSame('Zua', $user->name);
        $this->assertSame('Africa/Luanda', $user->timezone);
    }

    #[Test]
    public function settings_reject_invalid_timezone(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/v1/settings', ['timezone' => 'Nao/Existe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['timezone']);
    }

    #[Test]
    public function settings_reject_invalid_theme(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/v1/settings', ['theme' => 'azul'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['theme']);
    }

    #[Test]
    public function settings_validate_user_name_length(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/v1/settings', ['userName' => 'Z'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['userName']);
    }

    #[Test]
    public function notification_preferences_can_be_updated(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/v1/settings', [
            'notifications' => [
                'emailEnabled' => false,
                'inAppEnabled' => true,
                'digestHour' => 21,
                'dueSoonHours' => 48,
                'dueImminentMinutes' => 30,
                'timerLongHours' => 6,
                'types' => [
                    'task_completed' => ['email' => true, 'inApp' => false],
                ],
            ],
        ])
            ->assertOk()
            ->assertJson([
                'notifications' => [
                    'emailEnabled' => false,
                    'inAppEnabled' => true,
                    'digestHour' => 21,
                    'dueSoonHours' => 48,
                    'dueImminentMinutes' => 30,
                    'timerLongHours' => 6,
                ],
            ])
            ->assertJsonPath('notifications.types.task_completed', ['email' => true, 'inApp' => false]);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'email_enabled' => false,
            'in_app_enabled' => true,
            'digest_hour' => 21,
            'due_soon_hours' => 48,
            'due_imminent_minutes' => 30,
            'timer_long_hours' => 6,
        ]);

        $preference = $user->fresh()->notificationPreference;

        $this->assertSame(['email' => true, 'inApp' => false], $preference->types['task_completed']);
        $this->assertCount(count(NotificationPreference::defaultTypes()), $preference->typesWithDefaults());
    }

    #[Test]
    public function notification_types_override_keeps_other_channels(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/v1/settings', [
            'notifications' => [
                'types' => ['task_completed' => ['email' => false]],
            ],
        ])->assertOk();

        $preference = $user->fresh()->notificationPreference;

        $this->assertSame(['email' => false], $preference->types['task_completed']);
        $this->assertSame(['email' => false, 'inApp' => true], $preference->typesWithDefaults()['task_completed']);
    }

    #[Test]
    public function settings_update_rejects_when_nothing_changes(): void
    {
        $user = $this->actingAsUser([
            'name' => 'Zua',
            'theme' => 'dark',
            'timezone' => 'Africa/Luanda',
        ]);

        // Valores idênticos aos actuais: nenhuma alteração.
        $this->patchJson('/api/v1/settings', [
            'userName' => 'Zua',
            'theme' => 'dark',
            'timezone' => 'Africa/Luanda',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES')
            ->assertJsonPath('message', 'Nenhuma alteração detetada.');

        // Corpo vazio também não atualiza.
        $this->patchJson('/api/v1/settings', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_CHANGES');

        $user->refresh();

        $this->assertSame('dark', $user->theme);
        $this->assertSame('Zua', $user->name);

        // Uma mudança real continua a funcionar.
        $this->patchJson('/api/v1/settings', ['theme' => 'light'])
            ->assertOk()
            ->assertJsonPath('theme', 'light');
    }

    #[Test]
    public function settings_reject_unknown_notification_type(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/v1/settings', [
            'notifications' => [
                'types' => ['tipo_inexistente' => ['email' => true]],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notifications.types.tipo_inexistente']);
    }

    #[Test]
    public function notification_types_drop_unknown_channels(): void
    {
        $user = $this->actingAsUser();

        $this->patchJson('/api/v1/settings', [
            'notifications' => [
                'types' => [
                    'task_completed' => ['email' => true, 'inApp' => false, 'sms' => true],
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('notifications.types.task_completed', ['email' => true, 'inApp' => false]);

        $preference = $user->fresh()->notificationPreference;

        $this->assertSame(['email' => true, 'inApp' => false], $preference->types['task_completed']);
    }

    #[Test]
    public function settings_validate_notification_ranges(): void
    {
        $this->actingAsUser();

        $this->patchJson('/api/v1/settings', [
            'notifications' => [
                'digestHour' => 25,
                'dueSoonHours' => 0,
                'dueImminentMinutes' => 1,
                'timerLongHours' => 99,
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'notifications.digestHour',
                'notifications.dueSoonHours',
                'notifications.dueImminentMinutes',
                'notifications.timerLongHours',
            ]);
    }

    #[Test]
    public function settings_require_authentication(): void
    {
        $this->getJson('/api/v1/settings')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');

        $this->patchJson('/api/v1/settings', ['theme' => 'dark'])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Não autenticado.');
    }
}
