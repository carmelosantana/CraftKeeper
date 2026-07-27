import { DEFAULTS } from './services.js';

// A fresh, mutable config object. `secrets` is filled in by the UI (or a test)
// with the output of generateSecrets().
export function defaultState() {
  return { ...DEFAULTS, secrets: null };
}
