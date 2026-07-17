# CraftKeeper

The open-source Minecraft server control plane.

CraftKeeper is a Laravel 13 + Inertia 3 / React 19 application that turns a
mounted Minecraft server directory into a safe configuration, plugin, RCON,
AI, REST API, and MCP management experience.

## License

CraftKeeper is licensed under the [GNU Affero General Public License v3.0
or later](LICENSE) (`AGPL-3.0-or-later`).

## Development

Requires PHP 8.4, Node 22+, and Composer 2.

```bash
composer install
npm install
```

### Verification gates

```bash
composer test     # config cache clear, Pest test suite, PHPStan, Pint (check-only)
npm run test      # Vitest unit tests
npm run typecheck # TypeScript type checking
npm run build     # Vite production build
```
