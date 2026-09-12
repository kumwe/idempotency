'use strict';
const assert = require('node:assert/strict');
const {readDocuments, validateSchemas, verifyRelations} = require('./verify.cjs');
const original = readDocuments();
const api = 'resources/public-api/v1.json';
const caps = 'resources/capabilities/v1.json';
const services = 'resources/service-map/v1.json';
const releaseRecord = 'docs/release-record.md';
validateSchemas(original);
verifyRelations(original);
const fixtures = [
  ['capability release is required', values => {delete values[caps].release;}, validateSchemas, /capabilities.*required property 'release'/s],
  ['legacy string capabilities are rejected', values => {values[caps].capabilities = ['idempotency'];}, validateSchemas, /capabilities.*must be object/s],
  ['empty native requirements are not null', values => {values[caps].native_requirements = [];}, validateSchemas, /capabilities.*native_requirements/s],
  ['legacy service map keys are rejected', values => {values[services].providers = [];}, validateSchemas, /service-map.*additional properties/s],
  ['provider absence requires a reason', values => {values[services].provider_absence_reason = null;}, verifyRelations, /semantic ownership drift/],
  ['namespace roots require their trailing separator', values => {values[releaseRecord].source.app.old_namespace_roots[0] = 'Kumwe\\App';}, validateSchemas, /release-record.*old_namespace_roots/s],
  ['replacement maps must be explicit strings', values => {values[releaseRecord].consumer_contract.namespace_or_api_replacements[0] = {from: 'Old', to: 'New'};}, validateSchemas, /release-record.*namespace_or_api_replacements/s],
  ['obsolete concurrency instructions are rejected', values => {values[releaseRecord].concurrency = {};}, validateSchemas, /release-record.*additional properties/s],
  ['obsolete roadmap instructions are rejected', values => {values[releaseRecord].governance.non_roadmap_refs = [];}, validateSchemas, /release-record.*additional properties/s],
  ['framework release record requires its matching discriminated section', values => {values[releaseRecord].framework_php = null;}, validateSchemas, /release-record.*framework_php/s],
  ['public method parameters reject undocumented schema fields', values => {values[api].symbols['Kumwe\\Idempotency\\IdempotencyKey'].methods.fromString.parameters[0].unknown = true;}, validateSchemas, /public-api.*additional properties/s],
  ['canonical manifest version must be semantic', values => {values[api].release = 'latest';}, validateSchemas, /public-api.*release/s],
  ['unowned exported symbols are rejected', values => {values[caps].capabilities[0].symbols.pop();}, verifyRelations, /semantic ownership drift/],
  ['duplicate capability ownership is rejected', values => {values[caps].capabilities[1].symbols.push(values[caps].capabilities[0].symbols[0]);}, verifyRelations, /semantic ownership drift/],
  ['incomplete release record callable inventory is rejected', values => {values[releaseRecord].framework_php.extracted_symbols[0].public_methods.pop();}, verifyRelations, /release record member drift/],
  ['stale release record manifest bytes are rejected', values => {values[releaseRecord].ownership.public_manifests[0].sha256 = '0'.repeat(64);}, verifyRelations, /Release record digest differs/],
  ['missing release record manifest identity is rejected', values => {values[releaseRecord].ownership.public_manifests.pop();}, verifyRelations, /Missing or duplicate release record digest/],
];
for (const [description, mutate, validate, expected] of fixtures) {
  const values = structuredClone(original);
  mutate(values);
  assert.throws(() => validate(values), expected, description);
}
console.log('Governance refusal regressions passed: ' + fixtures.length + ' independent malformed or inconsistent documents.');
