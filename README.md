HTTP Parser
===========
[![CI](https://github.com/hypeJunction/http-parser/actions/workflows/ci.yml/badge.svg)](https://github.com/hypeJunction/http-parser/actions/workflows/ci.yml)

Parses HTTP resources into serializable arrays of oEmbed-like metatags.

## Features

 * Parses HTTP resources into oEmbed-like arrays of metatags
 * Extracts OpenGraph, Twitter card, JSON-LD (schema.org), `<link>`, `<meta>` and `<img>` data
 * Discovers and resolves oEmbed endpoints (JSON and XML)
 * Handy for rendering URL cards / link previews

## Requirements

 * PHP 8.1+
 * Guzzle 7

## Installation

```sh
composer require hypejunction/http-parser
```

## Usage

```php
require __DIR__ . '/vendor/autoload.php';

$client = new \GuzzleHttp\Client([
	'headers' => [
		'User-Agent' => 'MyApp LinkPreview/1.0 (+https://myapp.example)',
	],
	'timeout' => 5,
	'connect_timeout' => 5,
]);

$parser = new \hypeJunction\Parser($client);

$data = $parser->parse('https://example.com/some/page');
```

### Options

```php
$parser = new \hypeJunction\Parser($client, [
	// Maximum number of bytes read from a response body (default: 2 MB)
	'max_body_length' => 2097152,

	// URL schemes the parser is allowed to fetch (default: http, https)
	'allowed_schemes' => ['http', 'https'],

	// Allow requests to private/reserved IP ranges (default: false — see SECURITY.md)
	'allow_private_hosts' => false,

	// Maximum number of <img> tags verified with an HTTP request per document (default: 10)
	'max_image_checks' => 10,

	// Maximum number of discovered oEmbed endpoints fetched per document (default: 3)
	'max_oembed_fetches' => 3,

	// Maximum number of redirects to follow; each target is re-validated (default: 5)
	'max_redirects' => 5,

	// Extra Guzzle request options merged into every request
	'request_options' => [],
], $logger); // optional PSR-3 logger
```

## Security

Fetching user-supplied URLs is dangerous by nature. The parser blocks
private/reserved networks, re-validates redirects, and caps response sizes by
default, but you must still:

 * **Escape everything you render.** All returned values are
   attacker-controlled strings. Only render the oEmbed `html` field for an
   explicit allowlist of trusted providers, ideally in a sandboxed iframe.
 * **Keep TLS verification on.** Never pass `'verify' => false` to Guzzle.
 * **Consider an egress proxy** or IP pinning at the HTTP-client layer if DNS
   rebinding is in your threat model.

See [SECURITY.md](SECURITY.md) for the full threat model.

## Sample Output

```json
{
	"url": "https://soundcloud.com/phobosrecords/maksim-light-pulse",
	"canonical": "https://soundcloud.com/phobosrecords/maksim-light-pulse",
	"oembed_url": "https://soundcloud.com/oembed?url=https%3A%2F%2Fsoundcloud.com%2Fphobosrecords%2Fmaksim-light-pulse&format=json",
	"icons": [
		"https://a-v2.sndcdn.com/assets/images/sc-icons/favicon-2cadd14b.ico"
	],
	"metatags": {
		"og:site_name": "SoundCloud",
		"og:type": "soundcloud:sound",
		"og:url": "https://soundcloud.com/phobosrecords/maksim-light-pulse",
		"twitter:title": "PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix)",
		"twitter:card": "audio"
	},
	"tags": ["record", "sounds", "share", "sound", "audio", "tracks", "music", "soundcloud"],
	"provider_name": "SoundCloud",
	"description": "Stream PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix) by Phobos Records",
	"title": "PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix) by Phobos Records",
	"thumbnails": [
		"https://i1.sndcdn.com/artworks-000145456354-01w756-t500x500.jpg"
	],
	"type": "rich",
	"version": "1",
	"width": "100%",
	"height": "400",
	"html": "<iframe width=\"100%\" height=\"400\" scrolling=\"no\" frameborder=\"no\" src=\"https://w.soundcloud.com/player/?visual=true&url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F244902712&show_artwork=true\"></iframe>",
	"author_name": "Phobos Records",
	"author_url": "https://soundcloud.com/phobosrecords",
	"provider_url": "http://soundcloud.com",
	"thumbnail_url": "http://i1.sndcdn.com/artworks-000145456354-01w756-t500x500.jpg"
}
```

## Development

```sh
composer install
composer test      # PHPUnit
composer analyse   # PHPStan
```
