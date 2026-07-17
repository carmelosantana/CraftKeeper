import { router } from '@inertiajs/react';
import { toast } from 'sonner';
import {
    CommandDialog,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
    CommandSeparator,
} from '@/components/ui/command';

/**
 * `Design/handoff/components.json` → `CommandPalette`. Actions: "find
 * configs, open plugins, navigate, run safe predefined actions, start AI
 * question, recent activity, find commands, open player." Rule:
 * "Dangerous actions do not execute from search — they open their
 * review/confirmation interface."
 *
 * Task 3 builds only the shell — the "Quick actions" below are
 * representative placeholders (no configs/plugins/players exist yet);
 * later tasks replace them with real, searchable data while keeping this
 * same contract (safe actions run directly, dangerous ones only open a
 * review surface).
 */
export interface CommandPaletteNavItem {
    label: string;
    href: string;
}

export interface CommandPaletteProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    navigation: CommandPaletteNavItem[];
}

export function CommandPalette({
    open,
    onOpenChange,
    navigation,
}: CommandPaletteProps) {
    const navigate = (href: string) => {
        onOpenChange(false);
        router.visit(href);
    };

    const runSafeAction = (message: string) => {
        onOpenChange(false);
        toast.success(message);
    };

    const openReview = (action: string) => {
        onOpenChange(false);
        toast(`"${action}" needs review before it runs`, {
            description:
                'Dangerous actions never execute from search — this opens the approval flow instead. AI proposes; the administrator approves.',
        });
    };

    return (
        <CommandDialog
            open={open}
            onOpenChange={onOpenChange}
            title="Command palette"
            description="Search or run a command"
        >
            <CommandInput placeholder="Search or run a command…" />
            <CommandList>
                <CommandEmpty>No results found.</CommandEmpty>

                <CommandGroup heading="Navigate">
                    {navigation.map((item) => (
                        <CommandItem
                            key={item.href}
                            value={`navigate ${item.label}`}
                            onSelect={() => navigate(item.href)}
                        >
                            {item.label}
                        </CommandItem>
                    ))}
                </CommandGroup>

                <CommandSeparator />

                <CommandGroup heading="Quick actions">
                    <CommandItem
                        value="find configuration files"
                        onSelect={() =>
                            runSafeAction('Searching configuration files…')
                        }
                    >
                        Find configuration files
                    </CommandItem>
                    <CommandItem
                        value="open plugin catalog discover"
                        onSelect={() =>
                            runSafeAction('Opening the plugin catalog…')
                        }
                    >
                        Open plugin catalog
                    </CommandItem>
                    <CommandItem
                        value="rescan mounted server safe undoable"
                        onSelect={() =>
                            runSafeAction('Rescan queued — safe and undoable.')
                        }
                    >
                        Rescan mounted server
                    </CommandItem>
                    <CommandItem
                        value="restart server dangerous"
                        onSelect={() => openReview('Restart server')}
                    >
                        Restart server…
                    </CommandItem>
                </CommandGroup>

                <CommandSeparator />

                <CommandGroup heading="Assistant">
                    <CommandItem
                        value="ask the assistant a question"
                        onSelect={() =>
                            runSafeAction('Starting a new assistant question…')
                        }
                    >
                        Ask a question
                    </CommandItem>
                </CommandGroup>

                <CommandSeparator />

                <CommandGroup heading="Recent activity">
                    <CommandItem
                        value="view recent activity"
                        onSelect={() =>
                            runSafeAction('Opening recent activity…')
                        }
                    >
                        View recent activity
                    </CommandItem>
                </CommandGroup>
            </CommandList>
        </CommandDialog>
    );
}
