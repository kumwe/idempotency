'use strict';
const assert = require('node:assert/strict');
const {readDocuments, validateSchemas, verifyRelations} = require('./verify.cjs');
const original = readDocuments();
const api = 'resources/public-api/v1.json';
const caps = 'resources/capabilities/v1.json';
const services = 'resources/service-map/v1.json';
const handoff = 'MIGRATION-HANDOFF.md';
validateSchemas(original);
verifyRelations(original);
const fixtures = [
  ['capability release is required', values => {delete values[caps].release;}, validateSchemas, /capabilities.*required property 'release'/s],
  ['legacy string capabilities are rejected', values => {values[caps].capabilities = ['idempotency'];}, validateSchemas, /capabilities.*must be object/s],
  ['empty native requirements are not null', values => {values[caps].native_requirements = [];}, validateSchemas, /capabilities.*native_requirements/s],
  ['legacy service map keys are rejected', values => {values[services].providers = [];}, validateSchemas, /service-map.*additional properties/s],
  ['provider absence requires a reason', values => {values[services].provider_absence_reason = null;}, verifyRelations, /semantic ownership drift/],
  ['namespace roots require their trailing separator', values => {values[handoff].source.app.old_namespace_roots[0] = 'Kumwe\\App';}, validateSchemas, /MIGRATION-HANDOFF.*old_namespace_roots/s],
  ['replacement maps must be explicit strings', values => {values[handoff].next_task.namespace_or_api_replacements[0] = {from: 'Old', to: 'New'};}, validateSchemas, /MIGRATION-HANDOFF.*namespace_or_api_replacements/s],
  ['migration references cannot be prose', values => {values[handoff].concurrency.related_migrations = ['Published canonical dependency'];}, validateSchemas, /MIGRATION-HANDOFF.*related_migrations/s],
  ['governance references require allocated identifiers', values => {values[handoff].governance.non_roadmap_refs = ['Portable package extraction'];}, validateSchemas, /MIGRATION-HANDOFF.*non_roadmap_refs/s],
  ['framework handoff requires its matching discriminated section', values => {values[handoff].framework_php = null;}, validateSchemas, /MIGRATION-HANDOFF.*framework_php/s],
  ['public method parameters reject undocumented schema fields', values => {values[api].symbols['Kumwe\\Idempotency\\IdempotencyKey'].methods.fromString.parameters[0].unknown = true;}, validateSchemas, /public-api.*additional properties/s],
  ['canonical manifest version must be semantic', values => {values[api].release = 'latest';}, validateSchemas, /public-api.*release/s],
  ['unowned exported symbols are rejected', values => {values[caps].capabilities[0].symbols.pop();}, verifyRelations, /semantic ownership drift/],
  ['duplicate capability ownership is rejected', values => {values[caps].capabilities[1].symbols.push(values[caps].capabilities[0].symbols[0]);}, verifyRelations, /semantic ownership drift/],
  ['incomplete handoff callable inventory is rejected', values => {values[handoff].framework_php.extracted_symbols[0].public_methods.pop();}, verifyRelations, /handoff member drift/],
  ['stale handoff manifest bytes are rejected', values => {values[handoff].ownership.public_manifests[0].sha256 = '0'.repeat(64);}, verifyRelations, /Handoff digest differs/],
  ['missing handoff manifest identity is rejected', values => {values[handoff].ownership.public_manifests.pop();}, verifyRelations, /Missing or duplicate handoff digest/],
];
for (const [description, mutate, validate, expected] of fixtures) {
  const values = structuredClone(original);
  mutate(values);
  assert.throws(() => validate(values), expected, description);
}
console.log('Governance refusal regressions passed: ' + fixtures.length + ' independent malformed or inconsistent documents.');
