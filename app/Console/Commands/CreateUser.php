<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class CreateUser extends Command
{
    protected $signature = 'app:create-user {--role=admin : admin, it, accounting, finance, or rso}';
    protected $description = 'Create a workspace account with a securely entered password';

    public function handle(): int
    {
        $data = [
            'name' => trim((string) $this->ask('Full name')),
            'email' => Str::lower(trim((string) $this->ask('Email address'))),
            'password' => $this->secret('Password (at least 12 characters)'),
            'role' => $this->option('role'),
        ];
        $data['password_confirmation'] = $this->secret('Confirm password');
        $validator = Validator::make($data, [
            'role' => ['required', 'in:admin,it,accounting,finance,rso'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:254', 'unique:users'],
            'password' => ['required', 'confirmed', Password::min(12), 'max:128'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) $this->error($error);
            return self::FAILURE;
        }
        $user = new User(collect($data)->only('name', 'email', 'password')->all());
        \App\Services\SecurityAudit::console(fn () => \Illuminate\Support\Facades\DB::transaction(fn () => $user->forceFill(['role' => $data['role']])->save()));
        $this->info('Account created. You can now sign in.');
        return self::SUCCESS;
    }
}
