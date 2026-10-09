# Architecture

Phase 0 uses a Laravel modular monolith. Domain module boundaries are reserved under app; only Platform, Audit, and infrastructure contracts are implemented. PostgreSQL is the durable source of truth. Redis is used for cache and queues. Outbox records are written in the caller's database transaction and are published at least once by a future worker abstraction.
