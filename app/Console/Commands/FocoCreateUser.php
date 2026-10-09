<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Users\ProvisionUserDefaults;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FocoCreateUser extends Command
{
    protected $signature = 'foco:create-user {email} {--name=} {--password=}';

    protected $description = 'Create a Foco user with default categories and preferences.';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email inválido: '.$email);

            return Command::FAILURE;
        }

        $name = trim((string) ($this->option('name') ?? ''));
        if ($name === '') {
            $name = Str::before($email, '@');
        }

        $password = (string) ($this->option('password') ?? '');

        if ($password === '') {
            $password = $this->secret('Nova password (mínimo 8 caracteres)');
            if ($password === false || $password === '') {
                $this->error('Password não pode ficar vazia.');

                return Command::FAILURE;
            }
        }

        if (strlen($password) < 8) {
            $this->error('A password deve ter pelo menos 8 caracteres.');

            return Command::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
            ],
        );

        app(ProvisionUserDefaults::class)->handle($user);

        $this->info("Utilizador {$user->email} criado/atualizado com sucesso.");

        return Command::SUCCESS;
    }
}
