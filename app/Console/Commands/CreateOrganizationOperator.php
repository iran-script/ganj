<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Services\Organizations\OperatorService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class CreateOrganizationOperator extends Command
{
    protected $signature = 'nexo:operator:create {mobile : Existing user mobile} {--reason= : Required audit reason}';

    protected $description = 'Grant organization creation only; created organizations receive a scoped administrator membership';

    public function handle(OperatorService $operators): int
    {
        try {
            $operators->grant((string) $this->argument('mobile'), (string) $this->option('reason'));
        } catch (ApiException|ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Organization operator capability is active. No global administrator role was granted.');

        return self::SUCCESS;
    }
}
