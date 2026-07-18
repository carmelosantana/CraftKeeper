import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ProposalDTO } from '@/types/config';
import { DiffReview } from './DiffReview';

function makeProposal(overrides: Partial<ProposalDTO> = {}): ProposalDTO {
    return {
        operationId: 'op-1',
        status: 'proposed',
        kind: 'apply',
        diff: '--- server.properties (current)\n+++ server.properties (proposed)\n allow-flight=false\n-motd=hi\n+motd=hello\n',
        valid: true,
        diagnostics: [],
        restartImpact: 'restart',
        risk: 'standard',
        documentation: [{ path: 'motd', url: 'https://minecraft.wiki/w/Server.properties#motd' }],
        fields: [{ path: 'motd', summary: 'Replace motd', before: 'hi', after: 'hello' }],
        normalizationWarning: false,
        expiresAt: '2026-07-18T00:00:00Z',
        outcome: null,
        errorCode: null,
        ...overrides,
    };
}

describe('DiffReview', () => {
    it('shows the redacted diff, risk, restart effect, and only real field-path rows', () => {
        render(
            <DiffReview
                proposal={makeProposal()}
                onApprove={() => {}}
                onReject={() => {}}
            />,
        );

        expect(screen.getByText('motd')).toBeVisible();
        expect(screen.getByText(/− hi/)).toBeVisible();
        expect(screen.getByText(/\+ hello/)).toBeVisible();
        expect(screen.getByText('Changed fields (1)')).toBeVisible();
        expect(screen.getByText(/Needs a server restart/)).toBeVisible();
        expect(screen.getByText('Validation passed')).toBeVisible();
    });

    it('never renders a secret value — the proposal it receives is already redacted', () => {
        render(
            <DiffReview
                proposal={makeProposal({
                    diff: '--- server.properties (current)\n+++ server.properties (proposed)\n-rcon.password=••••••\n+rcon.password=••••••‌\n',
                    fields: [
                        {
                            path: 'rcon.password',
                            summary: 'Replace rcon.password',
                            before: '••••••',
                            after: '••••••',
                        },
                    ],
                })}
                onApprove={() => {}}
                onReject={() => {}}
            />,
        );

        expect(document.body.textContent).not.toMatch(/actual-secret-value/);
        expect(screen.getAllByText(/••••••/).length).toBeGreaterThan(0);
    });

    it('shows a normalization warning when the proposal will reformat the file', () => {
        render(
            <DiffReview
                proposal={makeProposal({ normalizationWarning: true })}
                onApprove={() => {}}
                onReject={() => {}}
            />,
        );

        expect(screen.getByText(/Reformatting notice/)).toBeVisible();
    });

    it('shows validation errors and disables approval until they are fixed', () => {
        render(
            <DiffReview
                proposal={makeProposal({
                    valid: false,
                    diagnostics: [
                        {
                            severity: 'error',
                            message: 'Value out of range',
                            path: 'max-players',
                            line: 3,
                            column: 1,
                        },
                    ],
                })}
                onApprove={() => {}}
                onReject={() => {}}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent('Value out of range');
        expect(screen.getByTestId('diff-review-approve')).toBeDisabled();
    });

    it('calls onApprove and onReject when their controls are activated', () => {
        const onApprove = vi.fn();
        const onReject = vi.fn();

        render(
            <DiffReview
                proposal={makeProposal()}
                onApprove={onApprove}
                onReject={onReject}
            />,
        );

        fireEvent.click(screen.getByTestId('diff-review-approve'));
        fireEvent.click(screen.getByTestId('diff-review-reject'));

        expect(onApprove).toHaveBeenCalledTimes(1);
        expect(onReject).toHaveBeenCalledTimes(1);
    });

    it('keeps approve/reject controls reachable (not hidden) in the mobile bottom-sheet variant', () => {
        render(
            <DiffReview
                proposal={makeProposal()}
                onApprove={() => {}}
                onReject={() => {}}
                variant="sheet"
            />,
        );

        const approve = screen.getByTestId('diff-review-approve');
        const reject = screen.getByTestId('diff-review-reject');

        expect(approve).toBeVisible();
        expect(reject).toBeVisible();
        expect(approve).not.toHaveAttribute('hidden');
        expect(approve.closest('[hidden]')).toBeNull();
    });

    it('hides approve/reject once the operation reaches a terminal state and shows the outcome', () => {
        render(
            <DiffReview
                proposal={makeProposal({ status: 'succeeded', outcome: 'Applied 1 change(s) to server.properties.' })}
                onApprove={() => {}}
                onReject={() => {}}
            />,
        );

        expect(screen.queryByTestId('diff-review-approve')).toBeNull();
        expect(
            screen.getByText('Applied 1 change(s) to server.properties.'),
        ).toBeVisible();
    });
});
