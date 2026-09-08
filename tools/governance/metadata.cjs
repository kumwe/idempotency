'use strict';
const namespace = 'Kumwe\\Idempotency\\';
function documents(api) {
  const symbols = Object.keys(api.symbols);
  return {
    'resources/capabilities/v1.json': {
      schema: 'kumwe-package-capabilities/v1', package: api.package, release: api.release, namespace,
      responsibility: 'Immutable replay identities, GenericV1 request fingerprints, captured results and ledger contracts.',
      non_responsibilities: ['Host authorization and credential admission', 'Persistence, transaction coupling and concurrent storage', 'Retention scheduling and external effects'],
      capabilities: [
        {id: 'idempotency.replay-values', title: 'Replay identity and immutable state',
          description: 'Bounded keys, immutable state transitions and captured responses use an explicitly supplied GenericV1 canonical encoder.',
          symbols: symbols.filter(name => !name.endsWith('Ledger') && !name.endsWith('Purger')),
          documentation: ['docs/public-api.md', 'docs/architecture.md']},
        {id: 'idempotency.ledger-contracts', title: 'Ledger and retention contracts',
          description: 'Durable owner-fenced replay, secret-once replay and bounded purge interfaces; the consumer supplies storage and transactions.',
          symbols: symbols.filter(name => name.endsWith('Ledger') || name.endsWith('Purger')),
          documentation: ['docs/public-api.md', 'docs/test-ownership.md', 'docs/integration.md']},
      ], native_requirements: null, deprecations: [],
    },
    'resources/service-map/v1.json': {
      schema: 'kumwe-package-service-map/v1', package: api.package, release: api.release,
      config_provider: null,
      provider_absence_reason: 'Immutable values and stateless helpers use direct construction; host adapters implement the ledger ports and supply CanonicalEncoder explicitly.',
      factories: [], aliases: {}, delegators: [], configuration_keys: [],
    },
  };
}
module.exports = {documents};
