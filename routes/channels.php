<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| CraftKeeper is single-admin: there is never more than one App\Models\User
| row (see App\Support\InstallationState). Reaching this callback at all
| already means the request carries a valid, authenticated session for
| that one admin — Laravel's private-channel auth flow (BroadcastController)
| rejects unauthenticated requests before any channel callback runs. So
| "authorize to the admin user only" is satisfied by simply requiring an
| authenticated $user; there is no second admin to distinguish from.
|
*/

Broadcast::channel('operations.{id}', fn (User $user, string $id): bool => true);

/*
| Task 11: the realtime console feed. Same reasoning as above — the only
| gate that matters is "is there an authenticated session at all", which
| Laravel's private-channel auth flow already enforces before this
| callback ever runs.
*/
Broadcast::channel('server.console', fn (User $user): bool => true);

/*
| Task 16: one PRIVATE channel per AI conversation, streaming partial
| answer text and tool progress (App\Events\AiAssistantStreamEvent) plus
| the final persisted message (App\Events\AiMessageStreamed). Same
| single-admin reasoning as every other channel above — CraftKeeper never
| has more than one App\Models\User, so "an authenticated session exists"
| is the entire authorization model, admin-only by construction.
*/
Broadcast::channel('ai.conversations.{id}', fn (User $user, string $id): bool => true);
