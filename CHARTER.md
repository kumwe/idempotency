# Idempotency charter

Portable idempotency keys, immutable replay state and ledger ports.

The package owns reusable values, contracts, deterministic behavior and its implementation tests. App owns persistence, transactions, final authorization, active policy selection, delivery and operational scheduling. No App or SDK imports, aliases, shadow implementations or runtime fallbacks are permitted.
