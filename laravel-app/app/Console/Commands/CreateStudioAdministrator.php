<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateStudioAdministrator extends Command
{
    protected $signature = 'studio:make-admin {email?} {--if-missing}';

    protected $description = 'Create or promote a Studio administrator from the command line.';

    public function handle(): int
    {
        if ($this->option('if-missing') && User::query()->where('is_admin', true)->exists()) {
            $this->info('A Studio administrator already exists; no changes made.');

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Administrator setup requires an interactive terminal.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) ($this->argument('email') ?: $this->ask('Email'))));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Enter a valid email address.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        if ($name === '') {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        if (mb_strlen($password) < 12) {
            $this->error('Password must contain at least 12 characters.');

            return self::FAILURE;
        }
        if (! hash_equals($password, (string) $this->secret('Confirm password'))) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'is_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->info("Administrator account ready for {$email}.");

        return self::SUCCESS;
    }
}
