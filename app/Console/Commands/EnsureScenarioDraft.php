<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Scenarios\ScenarioService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureScenarioDraft extends Command
{
    protected $signature = 'nexo:scenarios:ensure-draft {scenario* : Existing scenario public UUIDs} {--actor= : Authorized operator public UUID} {--reason= : Audit reason}';

    protected $description = 'Ensure editable drafts in place without changing published versions, games or sessions';

    public function handle(ScenarioService $scenarios): int
    {
        $actor = User::where('public_id', $this->option('actor'))->firstOrFail();
        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('An audit reason is required.');

            return self::FAILURE;
        }
        $results = DB::transaction(function () use ($scenarios, $actor, $reason): array {
            $results = [];
            foreach ($this->argument('scenario') as $id) {
                $results[] = $scenarios->execute($actor, 'ensureDraft', ['reason' => $reason], $id);
            }

            return $results;
        });
        foreach ($results as $draft) {
            $this->line($draft['scenario_id'].' '.$draft['id'].' DRAFT v'.$draft['version_no']);
        }

        return self::SUCCESS;
    }
}
