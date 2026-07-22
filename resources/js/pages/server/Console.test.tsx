import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import type { ConsoleEntryDTO, ServerConsoleProps } from '@/types/server';
import ServerConsole from './Console';

// The page itself is the unit under test; its shell and side-channels are
// not. `AppShell` drags in the theme provider, command palette and an
// Inertia page object, and `CommandComposer` posts real commands — neither
// participates in the filter/empty-state logic these tests cover.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
}));

vi.mock('@laravel/echo-react', () => ({
    useEcho: () => {},
    useConnectionStatus: () => 'connected',
}));

vi.mock('@/layouts/AppShell', () => ({
    AppShell: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/features/console/CommandComposer', () => ({
    CommandComposer: () => null,
}));

function makeEntry(overrides: Partial<ConsoleEntryDTO> = {}): ConsoleEntryDTO {
    return {
        id: 1,
        line: '[12:00:00] [Server thread/INFO]: Done (1.234s)!',
        occurredAt: '2026-07-22T12:00:00Z',
        ...overrides,
    };
}

function makeProps(
    overrides: Partial<ServerConsoleProps> = {},
): ServerConsoleProps {
    return {
        rcon: { available: true, reason: null },
        logs: { available: true, reason: null },
        recentEntries: [
            makeEntry(),
            makeEntry({
                id: 2,
                line: '[12:00:01] [Server thread/INFO]: Starting minecraft server',
            }),
        ],
        predefinedActions: [],
        commandHistory: [],
        pendingOperation: null,
        composePreview: null,
        ...overrides,
    };
}

function setFilter(value: string) {
    fireEvent.change(screen.getByTestId('console-filter'), {
        target: { value },
    });
}

describe('ServerConsole empty states', () => {
    it('says output has not arrived yet only when nothing is loaded at all', () => {
        render(<ServerConsole {...makeProps({ recentEntries: [] })} />);

        expect(screen.getByText('No console output yet.')).toBeVisible();
    });

    /**
     * Regression cover: the empty state used to branch on the FILTERED
     * line count, so any filter matching nothing claimed "No console
     * output yet." over a console that had in fact loaded lines — telling
     * the operator the server was silent when it was not.
     */
    it('blames the filter, not the server, when lines are loaded but none match', () => {
        render(<ServerConsole {...makeProps()} />);

        setFilter('nothing-matches-this');

        expect(screen.queryByText('No console output yet.')).toBeNull();
        expect(screen.getByText(/No lines match this filter/)).toBeVisible();
    });

    it('restores the loaded lines when the non-matching filter is cleared', () => {
        render(<ServerConsole {...makeProps()} />);

        setFilter('nothing-matches-this');
        setFilter('');

        expect(screen.queryByText(/No lines match this filter/)).toBeNull();
        expect(screen.queryByText('No console output yet.')).toBeNull();
        expect(screen.getByText(/Done \(1\.234s\)!/)).toBeVisible();
    });

    it('shows only matching lines while a filter is productive', () => {
        render(<ServerConsole {...makeProps()} />);

        setFilter('Starting minecraft');

        expect(screen.getByText(/Starting minecraft server/)).toBeVisible();
        expect(screen.queryByText(/Done \(1\.234s\)!/)).toBeNull();
        expect(screen.queryByText('No console output yet.')).toBeNull();
    });

    it('still reports an empty console when the view is cleared under a filter', () => {
        render(<ServerConsole {...makeProps()} />);

        setFilter('Server thread');
        fireEvent.click(screen.getByTestId('console-clear'));

        expect(screen.getByText('No console output yet.')).toBeVisible();
        expect(screen.queryByText(/No lines match this filter/)).toBeNull();
    });
});
