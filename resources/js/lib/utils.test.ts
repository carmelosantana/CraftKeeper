import { describe, expect, it } from 'vitest';
import { cn } from './utils';

describe('cn', () => {
    it('joins class names together', () => {
        expect(cn('a', 'b')).toBe('a b');
    });

    it('resolves conflicting Tailwind classes to the last one', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });

    it('drops falsy values', () => {
        expect(cn('a', false && 'b', undefined, null, 'c')).toBe('a c');
    });
});
