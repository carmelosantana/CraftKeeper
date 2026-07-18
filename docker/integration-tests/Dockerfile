# Task 20's docker-compose.integration.yml "tests" service — a small,
# dependency-free PHP+cURL runner (see run.php's own docblock). Uses the
# same PHP major version as the main application image for consistency,
# with only the `curl` extension it actually needs (bundled by default in
# the official php-cli image already, this just makes sure ext-curl and
# php-fileinfo — needed by CURLFile's mime-type guessing — are present).
FROM php:8.4-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
    && docker-php-ext-install -j"$(nproc)" fileinfo \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY run.php .

# The plugin-upload scenario needs a real, valid JAR. This build context
# is `docker/integration-tests/` (see docker-compose.integration.yml), so
# it cannot reach `tests/fixtures/` at the repo root directly — this is a
# byte-for-byte copy of that same fixture jar (kept in sync manually;
# both ultimately trace to the same `plugin.yml`-only ZIP any change to
# the real fixture should be mirrored into).
COPY fixtures/Geyser-Spigot.jar /fixtures/Geyser-Spigot.jar

CMD ["php", "run.php"]
