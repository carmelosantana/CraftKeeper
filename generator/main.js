import { defaultState } from './lib/state.js';
import { generateSecrets } from './lib/secrets.js';
import { buildCompose } from './lib/compose.js';
import { buildEnv } from './lib/env.js';
import { buildReadme } from './lib/readme.js';
import { buildNextSteps } from './lib/steps.js';
import { buildZip } from './lib/bundle.js';

const state = defaultState();
state.secrets = generateSecrets();

const $ = (id) => document.getElementById(id);

// id -> how to read/write the control. Ids match generator/index.html.
const bindings = [
  ['domain', 'value'], ['timezone', 'value'], ['memory', 'number'],
  ['pluginUpdater', 'checked'], ['fileBrowser', 'checked'],
  ['publishCraftkeeperPort', 'checked'], ['craftkeeperPort', 'number'],
  ['trustedProxies', 'value'], ['trustedHosts', 'value'], ['realtime', 'checked'],
  ['javaPort', 'number'], ['bedrockPort', 'number'], ['minecraftVersion', 'value'],
  ['resourceLimits', 'checked'], ['cpus', 'number'], ['memoryLimit', 'value'],
  ['fileBrowserPort', 'number'], ['puid', 'number'], ['pgid', 'number'],
];

function readForm() {
  for (const [id, kind] of bindings) {
    const el = $(id);
    if (!el) continue;
    if (kind === 'checked') state[id] = el.checked;
    else if (kind === 'number') state[id] = Number(el.value);
    else state[id] = el.value;
  }
}

function writeForm() {
  for (const [id, kind] of bindings) {
    const el = $(id);
    if (!el) continue;
    if (kind === 'checked') el.checked = state[id];
    else el.value = state[id];
  }
}

const previews = {
  compose: () => buildCompose(state),
  env: () => buildEnv(state),
  readme: () => buildReadme(state),
  steps: () => buildNextSteps(state),
};

function render() {
  for (const [name, fn] of Object.entries(previews)) {
    const pre = $(`preview-${name}`);
    if (pre) pre.textContent = fn();
  }
}

function setupTabs() {
  document.querySelectorAll('[data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-panel]').forEach((p) => (p.hidden = true));
      document.querySelectorAll('[data-tab]').forEach((b) => b.classList.remove('active'));
      $(`panel-${btn.dataset.tab}`).hidden = false;
      btn.classList.add('active');
    });
  });
}

function setupCopy() {
  document.querySelectorAll('[data-copy]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      await navigator.clipboard.writeText(previews[btn.dataset.copy]());
      const original = btn.textContent;
      btn.textContent = 'Copied';
      setTimeout(() => (btn.textContent = original), 1200);
    });
  });
}

function setupDownload() {
  $('download').addEventListener('click', () => {
    const blob = new Blob([buildZip(state)], { type: 'application/zip' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'craftkeeper-stack.zip';
    a.click();
    URL.revokeObjectURL(url);
  });
}

function setupRegenerate() {
  $('regenerate').addEventListener('click', () => {
    state.secrets = generateSecrets();
    render();
  });
}

writeForm();
document.querySelectorAll('input, select').forEach((el) =>
  el.addEventListener('input', () => { readForm(); render(); }),
);
setupTabs();
setupCopy();
setupDownload();
setupRegenerate();
render();
