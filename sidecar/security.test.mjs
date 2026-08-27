import test from 'node:test';
import assert from 'node:assert/strict';
import { assertUrl, isPrivateLiteral } from './security.mjs';

const policy = {allowed_hosts:['example.com'], allowed_ports:[443], require_https:true};

test('refuses private literals including IPv4-mapped IPv6', () => {
  assert.equal(isPrivateLiteral('127.0.0.1'), true);
  assert.equal(isPrivateLiteral('::ffff:127.0.0.1'), true);
  assert.equal(isPrivateLiteral('169.254.169.254'), true);
});

test('refuses a public hostname resolving to a private address', async () => {
  await assert.rejects(() => assertUrl('https://example.com', policy, async () => [{address:'10.0.0.7', family:4}]), error => error.code === 'private_network_refused');
});

test('accepts a declared host only when resolution remains public', async () => {
  await assert.doesNotReject(() => assertUrl('https://example.com', policy, async () => [{address:'93.184.216.34', family:4}]));
  await assert.rejects(() => assertUrl('https://user:pass@example.com', policy, async () => []), error => error.code === 'url_credentials_refused');
});
