import { Form, Head, router } from '@inertiajs/react';
import BackupController from '@/actions/App/Http/Controllers/BackupController';
import { PageState } from '@/components/craftkeeper/PageState';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { BackupDTO, BackupsSettingsPageProps } from '@/types/settings';

/**
 * Task 19's Backups settings section. Every backup is a self-contained
 * archive App\Support\BackupService built from a SQLite ONLINE backup
 * (`VACUUM INTO`, never a raw file copy of a live database) plus
 * non-secret settings/catalog-cache/config exports — see that class's own
 * docblock for exactly what is (and, just as importantly, is not)
 * included. There is no restore button here: restoring means replacing
 * the running application's own database file, which this version treats
 * as a manual operational step (see App\Http\Controllers\
 * BackupController's own docblock), not a self-service action that would
 * need to interrupt the very request serving it.
 */
export default function BackupsSettings({ backups }: BackupsSettingsPageProps) {
    function destroy(backup: BackupDTO) {
        if (!window.confirm(`Delete backup "${backup.name}"? This cannot be undone.`)) {
            return;
        }

        router.delete(BackupController.destroy.url(backup.name), { preserveScroll: true });
    }

    return (
        <>
            <Head title="Backup settings" />

            <h1 className="sr-only">Backup settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Backups"
                    description="Application-state backups: the database, non-secret settings, catalog-cache metadata, and CraftKeeper configuration. Minecraft worlds are never included."
                />

                <Form {...BackupController.store.form()} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <Button disabled={processing} data-test="create-backup-button">
                            {processing ? 'Creating backup…' : 'Create backup'}
                        </Button>
                    )}
                </Form>

                {backups.length === 0 ? (
                    <PageState
                        state="empty"
                        title="No backups yet"
                        description='Create one above — it will appear here immediately.'
                    />
                ) : (
                    <ul className="grid gap-2" data-test="backup-list">
                        {backups.map((backup) => (
                            <li
                                key={backup.name}
                                data-test="backup-row"
                                className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                            >
                                <div className="min-w-0">
                                    <div className="font-mono text-xs font-semibold">{backup.name}</div>
                                    <div className="text-muted-foreground text-xs">
                                        {formatBytes(backup.sizeBytes)} · {backup.createdAt}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2">
                                    <a href={BackupController.download.url(backup.name)} data-test="download-backup-link">
                                        <Button type="button" variant="outline" size="sm">
                                            Download
                                        </Button>
                                    </a>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        size="sm"
                                        onClick={() => destroy(backup)}
                                        data-test="delete-backup-button"
                                    >
                                        Delete
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

BackupsSettings.layout = {
    breadcrumbs: [{ title: 'Backup settings', href: '/settings/backups' }],
};
