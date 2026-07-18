# Security Policy

CraftKeeper mediates between an administrator and a Minecraft server it
deliberately does not fully control (no Docker socket access, a
contained filesystem boundary, a bounded RCON client). See
`docs/security/threat-model.md` for the full trust-boundary writeup, and
`docs/architecture/decisions.md` for how each boundary was verified.

## Reporting a vulnerability

**Do not open a public GitHub issue for a suspected security
vulnerability.** Instead, report it privately using one of:

- GitHub's [private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing/privately-reporting-a-security-vulnerability)
  feature on this repository ("Security" tab → "Report a vulnerability").
  This is the preferred channel — it creates a private advisory thread
  with the maintainer and lets us collaborate on a fix before disclosure.
- If that is not available to you, email the maintainer listed in
  `.github/CODEOWNERS` with a subject line starting `SECURITY:` and a
  description of the issue. Please include, where possible:
  - The affected version/tag or image digest.
  - Steps to reproduce, or a minimal proof of concept.
  - The impact you believe the issue has (what boundary it crosses —
    filesystem containment, RCON scope, secret redaction, API/MCP scope
    enforcement, etc.).

We will acknowledge a report within **5 business days** and aim to
provide an initial assessment (confirmed / not applicable / needs more
information) within **10 business days**. Fix timelines depend on
severity and are communicated in the private advisory thread.

## Supported versions

CraftKeeper is pre-1.0 software under active development on the
`craftkeeper-v1` branch. Once a `v1.0.0` tag is published (see
`CHANGELOG.md` and `.github/workflows/image.yml`), security fixes are
released as new patch tags (`v1.0.x`) against the `latest` major line;
only the most recently published minor/patch version receives fixes.
Older tags remain pullable from GHCR (image tags are immutable once
published — see `docs/operations/upgrades.md`) but do not receive
backported patches.

## Scope

In scope:

- The CraftKeeper application itself (`app/`, `resources/js/`,
  `routes/`), its Dockerfile/entrypoint/Nginx/Supervisor configuration,
  and the GitHub Actions workflows that build, sign, and publish its
  release image.
- Authentication, RCON command policy, filesystem containment, plugin
  installation/verification, AI redaction, REST API scoping, and MCP
  OAuth/grant enforcement — see `docs/security/threat-model.md`'s table
  of structural guarantees for what each of these is expected to hold.

Out of scope (please still report if you find something surprising, but
these are documented, accepted boundaries, not bugs — see
`docs/security/threat-model.md`'s "Known, accepted residual risk"
section and `docs/architecture/decisions.md` for the full rationale of
each):

- Minecraft server console output is not secret-redacted (it is the
  Minecraft process's own free-form stdout, outside CraftKeeper's
  control).
- A narrow log-rotation race window in the log tailer.
- The AI provider adapter does not yet distinguish a 401 from a 429 from
  a timeout — all collapse to the same "unavailable" state.
- Vulnerabilities in a third-party Minecraft/Paper/Geyser/Floodgate
  server, plugin, or the Hangar/Modrinth services themselves — report
  those upstream.
- Vulnerabilities that require the reporter to already hold a valid
  administrator session or an API token/MCP grant with the exact scope
  needed to perform the action being reported (i.e. "a token scoped for
  X can do X" is expected behavior, not a finding).

## Coordinated disclosure

We ask for a reasonable window (typically 90 days, sooner if a fix ships
faster) before any public disclosure, so operators running the published
image have time to upgrade. We will credit reporters in the relevant
`CHANGELOG.md` entry and GitHub Release notes unless you ask to remain
anonymous.
