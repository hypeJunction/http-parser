# Security Policy

## Threat model

This library fetches and parses remote, untrusted URLs. That makes it a
server-side request forgery (SSRF) and untrusted-content pipeline by design.
The parser ships with the following mitigations enabled by default:

- **SSRF guard** — only `http`/`https` URLs are fetched; hostnames and IP
  literals pointing at loopback, private (RFC 1918), link-local, CGNAT, or
  otherwise reserved ranges are rejected, and every redirect target is
  re-validated before it is followed.
- **Response size cap** — bodies are truncated at `max_body_length`
  (2 MB by default) to prevent memory exhaustion.
- **Request amplification caps** — at most `max_image_checks` `<img>` URLs
  and `max_oembed_fetches` discovered oEmbed endpoints are fetched per
  document.
- **XML hardening** — oEmbed XML is parsed with `LIBXML_NONET`; external
  entity loading is disabled by default on all supported PHP versions.

## Residual risks you must handle in your application

- **DNS rebinding** — the guard validates DNS at check time; a malicious
  resolver can still return a public IP first and a private IP on the actual
  connection. If you need to defend against this, resolve and pin IPs at the
  HTTP-client layer, or route requests through an egress proxy with its own
  network policy.
- **XSS** — returned values (`title`, `description`, `html`, `metatags`, …)
  are attacker-controlled strings. Escape them for the output context, and
  only render the oEmbed `html` field from an explicit provider allowlist
  (ideally inside a sandboxed iframe).
- **TLS** — never disable certificate verification (`'verify' => false`) on
  the Guzzle client.

## Reporting a vulnerability

Please report vulnerabilities privately via GitHub security advisories
(https://github.com/hypeJunction/http-parser/security/advisories) rather than
opening a public issue.
