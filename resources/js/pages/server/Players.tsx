import { Head, Link } from '@inertiajs/react';
import { PageState } from '@/components/craftkeeper/PageState';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import type { StatusBadgeStatus } from '@/components/craftkeeper/StatusBadge';
import { AppShell } from '@/layouts/AppShell';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import type { PlayerDTO, ServerPlayersProps } from '@/types/server';

/**
 * Every action link below is built from `player.username` — the EXACT
 * identity string App\Server\PlayerService has ever observed for this
 * player (Task 11's ambiguity resolution #4; this task's own resolution
 * #3). CraftKeeper has no Java/Bedrock UUID for any player anywhere in its
 * data model (see database/migrations/..._create_server_observation_
 * tables.php's own comment on `players.username`) — there is nothing to
 * infer or fabricate one FROM, so this is the only identity that could
 * ever be used here. Each link pre-fills (but does not compose or
 * propose) the Console command text — the SAME elevated-command approval
 * gate (App\Http\Controllers\ConsoleController) reviews and gates it from
 * there, rather than a second, parallel action pipeline.
 */
function playerStatus(online: boolean | null): {
    status: StatusBadgeStatus;
    label: string;
} {
    if (online === null) {
        return { status: 'unknown', label: 'Unknown' };
    }

    return online
        ? { status: 'online', label: 'Online' }
        : { status: 'offline', label: 'Offline' };
}

function PlayerCard({ player }: { player: PlayerDTO }) {
    const { status, label } = playerStatus(player.online);
    const encoded = encodeURIComponent(player.username);

    return (
        <div
            className="flex flex-col gap-[8px] rounded-[10px] border p-[14px]"
            style={{
                backgroundColor: 'var(--ck-surface)',
                borderColor: 'var(--ck-border)',
            }}
            data-test="player-row"
        >
            <div className="flex items-center justify-between gap-[8px]">
                <span
                    className="truncate font-mono text-[13px] font-semibold"
                    style={{ color: 'var(--ck-text)' }}
                    data-test="player-username"
                >
                    {player.username}
                </span>
                <StatusText status={status} label={label} />
            </div>
            <p className="text-[11.5px]" style={{ color: 'var(--ck-text-2)' }}>
                {player.platform === 'bedrock' ? 'Bedrock (Floodgate)' : 'Java'}
                {' · '}
                Last seen {player.lastSeenAt ?? 'unknown'}
            </p>
            <div className="flex flex-wrap gap-[8px] text-[11.5px] font-semibold">
                <Link
                    href={`/server/console?command=${encodeURIComponent(`kick ${player.username}`)}`}
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                    data-test={`player-action-kick-${encoded}`}
                >
                    Kick…
                </Link>
                <Link
                    href={`/server/console?command=${encodeURIComponent(`op ${player.username}`)}`}
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                    data-test={`player-action-op-${encoded}`}
                >
                    Op…
                </Link>
                <Link
                    href={`/server/console?command=${encodeURIComponent(`deop ${player.username}`)}`}
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                    data-test={`player-action-deop-${encoded}`}
                >
                    Deop…
                </Link>
                <Link
                    href={`/server/console?command=${encodeURIComponent(`ban ${player.username}`)}`}
                    className="underline"
                    style={{ color: 'var(--ck-accent)' }}
                    data-test={`player-action-ban-${encoded}`}
                >
                    Ban…
                </Link>
            </div>
        </div>
    );
}

export default function ServerPlayers({
    rconAvailable,
    rconReason,
    players,
}: ServerPlayersProps) {
    return (
        <AppShell>
            <Head title="Players" />

            <header className="mb-[18px]">
                <h1
                    className="text-[20px] font-bold"
                    style={{ color: 'var(--ck-text)' }}
                >
                    Players
                </h1>
            </header>

            {!rconAvailable && (
                <div
                    role="status"
                    className="mb-[16px] rounded-[8px] border px-[12px] py-[9px] text-[12px] leading-[1.5]"
                    style={ckSubtleSurfaceStyle('warning')}
                >
                    <strong className="font-bold">
                        Online status is unknown:
                    </strong>{' '}
                    {rconReason ?? 'RCON is unavailable, so who is currently online cannot be confirmed. Player history below is unaffected.'}
                </div>
            )}

            {players.length === 0 ? (
                <PageState
                    state="empty"
                    title="No players observed yet"
                    description="Players appear here once CraftKeeper observes a join, leave, kick, or chat line in the server log."
                />
            ) : (
                <div className="grid gap-[12px] sm:grid-cols-2 lg:grid-cols-3">
                    {players.map((player) => (
                        <PlayerCard key={player.username} player={player} />
                    ))}
                </div>
            )}
        </AppShell>
    );
}
