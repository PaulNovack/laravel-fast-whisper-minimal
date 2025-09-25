<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('recordings.{sessionId}', function ($user, $sessionId) {
    return ['id' => $sessionId];
});
