import { zipSync, strToU8 } from '../vendor/fflate.min.js';
import { buildCompose } from './compose.js';
import { buildEnv } from './env.js';
import { buildReadme } from './readme.js';

export function buildFileMap(s) {
  return {
    'compose.yml': buildCompose(s),
    '.env': buildEnv(s),
    'README.md': buildReadme(s),
  };
}

export function buildZip(s) {
  const entries = {};
  for (const [name, content] of Object.entries(buildFileMap(s))) {
    entries[name] = strToU8(content);
  }
  return zipSync(entries);
}
