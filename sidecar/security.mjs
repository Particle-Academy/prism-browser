import net from 'node:net';
import dns from 'node:dns/promises';

export function isPrivateLiteral(hostname) {
  let ip = hostname.replace(/^\[|\]$/g, '').toLowerCase();
  if (ip.startsWith('::ffff:')) ip = ip.slice(7);
  if (!net.isIP(ip)) return hostname === 'localhost' || hostname.endsWith('.localhost') || hostname.endsWith('.local');
  if (ip === '::1' || ip === '::' || ip.startsWith('fc') || ip.startsWith('fd') || /^fe[89ab]/.test(ip) || ip.startsWith('2001:db8:')) return true;
  const p = ip.split('.').map(Number);
  return p.length === 4 && (p[0] === 10 || p[0] === 127 || p[0] === 0 || (p[0] === 169 && p[1] === 254) || (p[0] === 172 && p[1] >= 16 && p[1] <= 31) || (p[0] === 192 && p[1] === 168) || p[0] >= 224);
}

export async function assertUrl(raw, policy, lookup = dns.lookup) {
  const url = new URL(raw);
  const hostname = url.hostname.toLowerCase().replace(/\.$/, '');
  if (url.username || url.password) throw Object.assign(new Error(), {code: 'url_credentials_refused'});
  if (policy.require_https !== false && url.protocol !== 'https:') throw Object.assign(new Error(), {code: 'https_required'});
  if (isPrivateLiteral(hostname)) throw Object.assign(new Error(), {code: 'private_address_refused'});
  const allowed = (policy.allowed_hosts ?? []).some(entry => {
    entry = entry.toLowerCase().replace(/\.$/, '');
    return hostname === entry || (entry.startsWith('*.') && hostname.endsWith(entry.slice(1)));
  });
  if (!allowed) throw Object.assign(new Error(), {code: 'host_not_allowed'});
  const port = Number(url.port || (url.protocol === 'https:' ? 443 : 80));
  if (!(policy.allowed_ports ?? [443]).includes(port)) throw Object.assign(new Error(), {code: 'port_not_allowed'});
  if (!net.isIP(hostname)) {
    const addresses = await lookup(hostname, {all:true, verbatim:true});
    if (addresses.length === 0 || addresses.some(({address}) => isPrivateLiteral(address))) throw Object.assign(new Error(), {code:'private_address_refused'});
  }
}
