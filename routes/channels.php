<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('private-user.{userId}', function ($user, string $userId): bool {
    return (string) $user->getAuthIdentifier() === $userId;
});
