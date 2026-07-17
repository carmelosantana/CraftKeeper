# Architecture / Bootstrap Decisions

## Task 1 — Bootstrap Laravel, Quality Gates, and License

**Dependency resolution:** All pinned capability packages in the Task 1 brief
resolved cleanly on PHP 8.4.23 / Composer 2.10.1 at (or above) their pinned
constraints — no fallback to a looser version was required:

| Package | Pinned constraint | Resolved version |
|---|---|---|
| `laravel/mcp` | `^0.7` | `v0.7.2` |
| `carmelosantana/php-agents` | `^0.15` | `v0.15.0` |
| `laravel/fortify` | (unpinned) | `v1.37.2` |
| `laravel/sanctum` | (unpinned) | `v4.3.2` |
| `laravel/passport` | (unpinned) | `v13.7.5` |
| `laravel/reverb` | (unpinned) | `v1.10.2` |
| `symfony/yaml` | (unpinned) | `v8.1.x` |
| `yosymfony/toml` | (unpinned) | `v1.0.4` |
| `larastan/larastan` (dev) | (unpinned) | `^3.10` (starter kit shipped `^3.9`; re-running `composer require --dev larastan/larastan` bumped the constraint to the latest compatible release) |

No pinned constraint required weakening. No package in the resolved graph
requires Docker socket access; `laravel/sail` ships with the starter kit as
optional local-Docker tooling but is not invoked by any Composer/npm script
in the verification gates.

**Environment:** Built and verified on PHP 8.4.23, Composer 2.10.1, Node
26.4.0, npm 11.17.0. The plan/CI target Node 22 and PHP 8.4; Node 26 was
used locally per the task's ambiguity resolution and no incompatibility was
observed.

**`APP_NAME` default:** `config/app.php`'s `'name'` fallback was changed
from `'Laravel'` to `'CraftKeeper'` (in addition to setting `APP_NAME` in
`.env`/`.env.example`) so that the root route reliably renders "CraftKeeper"
regardless of whether a local `.env` is present — `.env` is git-ignored, so
relying on it alone would make `BootTest` environment-dependent.

**`composer.json` identity:** `name` was changed from the starter kit's
`laravel/react-starter-kit` to `craftkeeper/craftkeeper`, and `license` from
`MIT` to `AGPL-3.0-or-later`, matching the product identity and licensing
established in the V1 plan.

**Quality scripts:** `composer.json`'s `test` script was replaced with the
exact sequence from the Task 1 brief (`config:clear`, `artisan test`,
`phpstan analyse --memory-limit=1G`, `pint --test`). The starter kit's
pre-existing `lint`, `lint:check`, `types:check`, and `ci:check` scripts
were left in place (unused by the four required gates but kept so the
starter kit's `.github/workflows/tests.yml`, which calls `composer
ci:check`, keeps working). `package.json` gained the brief's required
`test`, `typecheck`, and `e2e` scripts; the pre-existing `types:check` was
kept alongside `typecheck` (identical command) for the same CI-workflow
compatibility reason.

**Boot test greenness:** `npm run test` requires at least one passing
Vitest test to exit 0 (Vitest exits non-zero on an empty test run). A small
infrastructure-only smoke test was added at `resources/js/lib/utils.test.ts`
covering the existing `cn()` class-name helper — it exercises the Vitest +
Testing Library + jsdom pipeline without building any new UI, which is out
of scope for Task 1 (Task 3).

**License text:** `LICENSE` is the unmodified GNU AGPL v3 license text
fetched verbatim from `https://www.gnu.org/licenses/agpl-3.0.txt`.
