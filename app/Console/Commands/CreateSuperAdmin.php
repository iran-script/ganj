<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\Organizations\GlobalRoleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateSuperAdmin extends Command
{
    protected $signature = 'nexo:super-admin:create {--name= : Full name} {--email= : Email address} {--mobile= : Mobile number} {--reason= : Required audit reason}';

    protected $description = 'Create an idempotent local test Super Admin without changing existing accounts';

    public function handle(GlobalRoleService $roles): int
    {
        try {
            $name = trim((string) ($this->option('name') ?: $this->ask('Full name')));
            $email = trim((string) ($this->option('email') ?: $this->ask('Email address (optional)')));
            $mobile = trim((string) ($this->option('mobile') ?: $this->ask('Mobile number (optional)')));
            $reason = trim((string) ($this->option('reason') ?: $this->ask('Audit reason')));
            $password = $this->secret('Password (minimum 12 characters)');

            if ($name === '' || ($email === '' && $mobile === '') || $reason === '' || ! is_string($password) || strlen($password) < 12) {
                $this->error('Name, one identifier, reason, and a password of at least 12 characters are required.');

                return self::INVALID;
            }

            $user = $roles->createLocalSuperAdmin($name, $email !== '' ? $email : null, $mobile !== '' ? $mobile : null, $password, $reason);
        } catch (ApiException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($user->wasRecentlyCreated ? 'Super Admin account created.' : 'Super Admin account already exists; no changes were made.');
        $this->line('User ID: '.$user->public_id);

        return self::SUCCESS;
    }
}
