# Architecture

Kumwe\Idempotency owns the portable contracts and state rules catalogued in [the API](public-api.md). Dependencies are limited to the Composer require section and verified by the token boundary gate. No App/SDK namespaces, Doctrine, Symfony or Illuminate implementation may enter source.

Seven extracted types preserve key/state/ledger boundaries. Result snapshots and explicit profile methods tighten data lifetime guarantees.

[Integration](integration.md) defines scalar serialization, explicit collaborators and host concurrency requirements.
