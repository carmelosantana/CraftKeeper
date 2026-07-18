import { Head, Link, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { RestartRequired } from '@/components/craftkeeper/RestartRequired';
import { StatusText } from '@/components/craftkeeper/StatusBadge';
import { Button } from '@/components/ui/button';
import { ckSubtleSurfaceStyle } from '@/lib/ck-tokens';
import { AppShell } from '@/layouts/AppShell';
import type { PluginUploadProps } from '@/types/plugins';

/**
 * Manual upload — Task 15's ambiguity resolution #2: inspection FINDINGS
 * are shown before any install proposal exists. Two distinct requests:
 * `POST /plugins/upload` quarantines + inspects the file and re-renders
 * this SAME page with `findings` (no Operation created yet); only a
 * separate, explicit "Propose install" click
 * (`POST /plugins/upload/{token}/propose`) creates one.
 */
export default function PluginUpload({ findings, error }: PluginUploadProps) {
    const fileInput = useRef<HTMLInputElement>(null);
    const [pending, setPending] = useState(false);

    function submitUpload(e: React.FormEvent) {
        e.preventDefault();
        const file = fileInput.current?.files?.[0];

        if (!file) {
            return;
        }

        router.post(
            '/plugins/upload',
            { file },
            {
                forceFormData: true,
                onStart: () => setPending(true),
                onFinish: () => setPending(false),
            },
        );
    }

    function propose(asUpdate: boolean) {
        if (!findings) {
            return;
        }

        router.post(
            `/plugins/upload/${findings.token}/propose`,
            asUpdate && findings.existingInstallationPath
                ? { existing_path: findings.existingInstallationPath }
                : {},
            { onStart: () => setPending(true), onFinish: () => setPending(false) },
        );
    }

    return (
        <AppShell>
            <Head title="Upload plugin" />

            <header className="mb-[18px]">
                <h1 className="text-[20px] font-bold" style={{ color: 'var(--ck-text)' }}>
                    Upload plugin
                </h1>
                <nav className="mt-[4px] flex gap-[16px] text-[13px]" style={{ color: 'var(--ck-text-2)' }}>
                    <Link href="/plugins">Installed</Link>
                    <Link href="/plugins/discover">Discover</Link>
                    <span className="font-bold" style={{ color: 'var(--ck-text)' }}>
                        Upload JAR
                    </span>
                </nav>
            </header>

            {error && (
                <div
                    role="alert"
                    className="mb-[16px] rounded-[8px] border px-[12px] py-[10px] text-[12.5px]"
                    style={ckSubtleSurfaceStyle('danger')}
                >
                    {error}
                </div>
            )}

            {!findings && (
                <form
                    onSubmit={submitUpload}
                    className="grid max-w-[420px] gap-[12px] rounded-[12px] border p-[18px]"
                    style={{ backgroundColor: 'var(--ck-surface)', borderColor: 'var(--ck-border)' }}
                >
                    <label
                        htmlFor="plugin-jar-file"
                        className="text-[12.5px] font-semibold"
                        style={{ color: 'var(--ck-text)' }}
                    >
                        Plugin JAR file
                    </label>
                    <input
                        ref={fileInput}
                        id="plugin-jar-file"
                        type="file"
                        accept=".jar"
                        data-test="upload-file-input"
                        className="text-[12.5px]"
                    />
                    <Button type="submit" disabled={pending} data-test="upload-submit">
                        {pending ? 'Uploading…' : 'Upload & inspect'}
                    </Button>
                </form>
            )}

            {findings && (
                <div
                    className="grid max-w-[560px] gap-[16px] rounded-[12px] border p-[18px]"
                    data-test="upload-findings"
                    style={{ backgroundColor: 'var(--ck-elevated)', borderColor: 'var(--ck-border)' }}
                >
                    <h2 className="text-[14px] font-bold" style={{ color: 'var(--ck-text)' }}>
                        Inspection findings
                    </h2>

                    <dl className="grid grid-cols-[120px_1fr] gap-y-[6px] text-[12.5px]">
                        <dt style={{ color: 'var(--ck-text-2)' }}>Name</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{findings.name ?? '(not found)'}</dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Version</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{findings.version ?? '(unknown)'}</dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>API version</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{findings.apiVersion ?? '(unknown)'}</dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Checksum</dt>
                        <dd className="break-all font-mono text-[11px]" style={{ color: 'var(--ck-text)' }}>
                            {findings.sha256}
                        </dd>
                        <dt style={{ color: 'var(--ck-text-2)' }}>Size</dt>
                        <dd style={{ color: 'var(--ck-text)' }}>{findings.sizeBytes} bytes</dd>
                    </dl>

                    {findings.diagnostics.length > 0 && (
                        <div className="grid gap-[6px]" data-test="upload-diagnostics">
                            {findings.diagnostics.map((d, i) => (
                                <div
                                    key={i}
                                    role="alert"
                                    className="rounded-[7px] border px-[11px] py-[8px] text-[12px]"
                                    style={ckSubtleSurfaceStyle('warning')}
                                >
                                    <span className="mr-[6px] font-mono text-[10.5px]" style={{ color: 'var(--ck-text-2)' }}>
                                        [{d.issue}]
                                    </span>
                                    {d.message}
                                </div>
                            ))}
                        </div>
                    )}

                    {findings.existingInstallationPath && (
                        <div
                            role="status"
                            className="rounded-[7px] border px-[11px] py-[8px] text-[12px]"
                            style={ckSubtleSurfaceStyle('info')}
                        >
                            A plugin named "{findings.name}" is already installed at{' '}
                            <span className="font-mono">{findings.existingInstallationPath}</span>. This
                            upload can replace it (an update) or be installed separately.
                        </div>
                    )}

                    <RestartRequired variant="chip" label="Will require a restart" />

                    {findings.name === null && (
                        <span role="status">
                            <StatusText status="degraded" label="No usable plugin metadata found" />
                        </span>
                    )}

                    {/* Consequences (findings) BEFORE the confirm control. */}
                    <div className="flex flex-wrap gap-[10px]">
                        {findings.existingInstallationPath ? (
                            <Button
                                type="button"
                                onClick={() => propose(true)}
                                disabled={pending}
                                data-test="upload-propose-update"
                            >
                                {pending ? 'Preparing…' : 'Propose update'}
                            </Button>
                        ) : (
                            <Button
                                type="button"
                                onClick={() => propose(false)}
                                disabled={pending}
                                data-test="upload-propose-install"
                            >
                                {pending ? 'Preparing…' : 'Propose install'}
                            </Button>
                        )}
                        <Link href="/plugins/upload">
                            <Button type="button" variant="outline" disabled={pending}>
                                Start over
                            </Button>
                        </Link>
                    </div>
                </div>
            )}
        </AppShell>
    );
}
