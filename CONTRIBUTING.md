# Contributing to CraftKeeper

Thanks for considering a contribution. CraftKeeper is AGPL-3.0-or-later
and built with a specific, deliberately narrow architecture — please
read this document before opening a pull request, since several
constraints below are non-negotiable project boundaries, not style
preferences.

## Before you start

- **Open an issue first** for anything beyond a small fix (typo, a
  clearly broken test, a docs correction). CraftKeeper's V1 scope is
  fixed by `docs/superpowers/plans/2026-07-17-craftkeeper-v1.md`'s
  "Global Constraints" and "Explicitly Deferred Beyond V1" sections —
  proposals that cross those boundaries (Docker socket access, multiple
  Minecraft servers, autonomous AI actions, NBT/world editing, etc.) will
  be declined regardless of implementation quality, so it's worth
  confirming scope before writing code.
- **One reviewable change per pull request.** Mirrors this project's own
  development convention (one task, one commit, one passing gate run —
  see `docs/architecture/decisions.md` for the full history). A PR that
  bundles an unrelated refactor with a bug fix is harder to review and
  harder to revert if something regresses.

## Non-negotiable boundaries

These hold regardless of how a change is implemented — a PR that
weakens any of them will be declined even if it "still passes tests"
(the tests themselves would need to change, which is itself the
red flag):

- **No Docker socket access, ever.** CraftKeeper must never need
  `/var/run/docker.sock` or an equivalent to manage its own or any other
  container.
- **Filesystem containment.** Every path CraftKeeper reads or writes
  under the mounted Minecraft directory must go through
  `App\Filesystem\MinecraftPath` — no new code path may bypass it, follow
  a symlink outside the configured root, or touch NBT/world region/player
  data files.
- **Approval before mutation.** Every mutating action (config change,
  RCON command, plugin install/update/disable/remove, server stop)
  becomes an `App\Models\Operation`, and only an explicitly *approved*
  operation may execute. AI, the REST API, and MCP may propose; only a
  human (or a token/grant with the specific separately-consented scope
  for it) may approve.
- **No secrets to external AI.** Anything classified as a secret must be
  redacted from an outgoing hosted-AI request before it is sent — see
  `App\Ai\SecretRedactor`.
- **Scoped tokens and grants.** A REST API token or MCP OAuth grant must
  only be able to do what its scope explicitly names. Read access must
  never imply write or RCON access.

See `docs/security/threat-model.md` for the full list of structural
guarantees and how each is verified.

## Development setup

Requires PHP 8.4, Node 22+, and Composer 2.

```bash
composer install
npm install
composer setup   # copies .env, generates APP_KEY + Passport keys, migrates, builds assets
```

### Verification gates

Run these before opening a pull request — they are the same commands
`.github/workflows/ci.yml` runs, split by domain:

```bash
composer test     # Pest test suite, PHPStan (level 7), Pint (check-only)
npm run test      # Vitest unit tests
npm run typecheck # TypeScript type checking
npm run lint:check
npm run format:check
npm run build     # Vite production build
npm run e2e       # Playwright end-to-end suite
```

A pull request that touches `docker-compose.integration.yml`, the
`Dockerfile`, or anything under `app/Filesystem`, `app/Console`
(RCON), or `app/Operations` should also be run against the integration
stack locally:

```bash
docker compose -f docker-compose.integration.yml up --build \
  --abort-on-container-exit --exit-code-from tests
docker compose -f docker-compose.integration.yml down -v
```

## Test-driven development

This project was built task-by-task with a red/green discipline (write
a failing test that encodes the requirement, then implement until it
passes) — new work should follow the same pattern rather than adding
implementation first and tests afterward. See
`docs/architecture/decisions.md` for many worked examples of this across
Tasks 1–20.

## Design system

If a change touches `resources/js/`, it must use the existing `--ck-*`
CSS custom properties (`Design/handoff/design-tokens.json`) — do not
introduce a second token vocabulary, a new color literal, or a
hardcoded pixel breakpoint outside the documented 480/768/1024px set.
Status and risk indicators must combine icon/shape, label, and color —
never color alone (WCAG 2.2 AA is the accessibility target for
everything user-visible).

## Workflow and CI/CD changes

Any change under `.github/` requires review from a CODEOWNERS-listed
reviewer (see `.github/CODEOWNERS`) — this is enforced by branch
protection, not just convention. Third-party GitHub Actions must be
pinned to a full commit SHA (not a mutable tag), `id-token: write` must
never appear outside the image-signing job, and `pull_request_target`
must never check out and run code from a fork. Run
`pipx run zizmor==1.27.0 .github/workflows/` locally if you have `pipx`
available (it also runs as a required CI job) before submitting a
workflow change.

## Commit messages

Short, imperative, prefixed by kind where it helps scanning history
(`feat:`, `fix:`, `docs:`, `chore:`, `test:`) — see `git log` for the
convention already in use throughout this repository.

## License

By contributing, you agree that your contribution is licensed under
AGPL-3.0-or-later, matching the rest of this repository (see `LICENSE`).
