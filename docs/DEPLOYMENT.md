# Deployment

Build with docker compose build and start with docker compose up -d. PostgreSQL and Redis use named persistent volumes and are not published to the host. Supply secrets through the environment or a secret manager; never commit .env.
