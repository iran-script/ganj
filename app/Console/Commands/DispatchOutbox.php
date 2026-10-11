<?php

namespace App\Console\Commands;

use App\Services\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100 : Maximum records per poll}';

    protected $description = 'Publish pending PostgreSQL outbox events to the durable internal Redis stream';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10000) {
            $this->error('The limit must be between 1 and 10000.');

            return self::INVALID;
        }
        $counts = $dispatcher->dispatch($limit);
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
