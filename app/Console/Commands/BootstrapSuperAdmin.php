<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\Organizations\GlobalRoleService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'organizations:bootstrap-super-admin {user_id : Existing user public UUID} {--reason= : Required operator reason}';

    protected $description = 'Initialize the first global SuperAdmin once, with an append-only operator audit';

    public function handle(GlobalRoleService $roles): int
    {
        try {
            $roles->bootstrap((string) $this->argument('user_id'), (string) $this->option('reason'));
        } catch (ApiException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('The initial SuperAdmin grant was recorded.');

        return self::SUCCESS;
    }
}
