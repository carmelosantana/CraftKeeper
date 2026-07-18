# Task 20: a tiny, dependency-free (no npm install — plain Node "net"
# module only) fake bounded RCON service for docker-compose.integration.yml.
# See server.js's own docblock for exactly what protocol surface it
# implements and why.
FROM node:22-alpine

WORKDIR /app
COPY server.js .

EXPOSE 25575

ENV RCON_PORT=25575
ENV RCON_PASSWORD=craftkeeper-integration-rcon

CMD ["node", "server.js"]
