import { configure } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

// The app's test-hook convention is `data-test="..."` throughout
// resources/js/pages and resources/js/features (see e.g. login.tsx's
// `data-test="login-button"` and playwright.config.ts's matching
// `testIdAttribute`), not Testing Library's own `data-testid` default —
// align `getByTestId()` here so Vitest/RTL and Playwright agree on one
// attribute instead of silently matching nothing.
configure({ testIdAttribute: 'data-test' });
