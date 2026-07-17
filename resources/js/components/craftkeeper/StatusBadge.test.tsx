import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { STATUS_BADGE_META, StatusBadge } from './StatusBadge';
import type { StatusBadgeStatus } from './StatusBadge';

describe('StatusBadge', () => {
    it('exposes status without relying on color', () => {
        render(<StatusBadge status="degraded" label="RCON unavailable" />);

        expect(screen.getByText('RCON unavailable')).toBeVisible();
        expect(screen.getByRole('status')).toHaveAccessibleName(/degraded/i);
    });

    it('falls back to the canonical status name as the visible label', () => {
        render(<StatusBadge status="online" />);

        expect(screen.getByText('Online')).toBeVisible();
        expect(screen.getByRole('status')).toHaveAccessibleName(/online/i);
    });

    it('pairs every status with a decorative, non-color shape glyph', () => {
        const { container } = render(<StatusBadge status="pending-restart" />);
        const glyph = container.querySelector('[data-ck-glyph]');

        expect(glyph).not.toBeNull();
        expect(glyph).toHaveAttribute('aria-hidden', 'true');
    });

    it('never encodes status with a color-only glyph — every state has a label', () => {
        (Object.keys(STATUS_BADGE_META) as StatusBadgeStatus[]).forEach(
            (status) => {
                const { unmount } = render(<StatusBadge status={status} />);

                expect(screen.getByRole('status')).toHaveAccessibleName(
                    new RegExp(STATUS_BADGE_META[status].label, 'i'),
                );
                unmount();
            },
        );
    });
});
