<?php

declare(strict_types=1);

namespace hypeJunction;

use DOMDocument;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Parses an HTTP resource into a serializable array of metatags
 * (oEmbed, OpenGraph, Twitter cards, JSON-LD, link/meta/img tags).
 */
class Parser {

	/**
	 * Default parser options
	 */
	public const DEFAULT_OPTIONS = [
		// Maximum number of bytes read from a response body (bodies are truncated beyond this)
		'max_body_length' => 2097152, // 2 MB
		// URL schemes the parser is allowed to fetch
		'allowed_schemes' => ['http', 'https'],
		// Allow requests to private/reserved IP ranges and single-label hosts (enable for testing only)
		'allow_private_hosts' => false,
		// Maximum number of <img> tags validated with an HTTP request per document
		'max_image_checks' => 10,
		// Maximum number of discovered oEmbed endpoints to fetch per document
		'max_oembed_fetches' => 3,
		// Maximum number of redirects to follow (each redirect target is re-validated)
		'max_redirects' => 5,
		// Extra Guzzle request options merged into every request
		'request_options' => [],
	];

	private ClientInterface $client;

	private LoggerInterface $logger;

	/**
	 * @var array<string, mixed>
	 */
	private array $options = self::DEFAULT_OPTIONS;

	/**
	 * Per-instance response cache
	 *
	 * @var array<string, ResponseInterface|null>
	 */
	private array $cache = [];

	/**
	 * @param ClientInterface      $client  HTTP client
	 * @param array<string, mixed> $options Parser options (see self::DEFAULT_OPTIONS)
	 * @param LoggerInterface|null $logger  PSR-3 logger
	 */
	public function __construct(ClientInterface $client, array $options = [], ?LoggerInterface $logger = null) {
		$this->client = $client;
		$this->options = array_merge(self::DEFAULT_OPTIONS, $options);
		$this->logger = $logger ?? new NullLogger();
	}

	/**
	 * Parses a URL into an array of metatags
	 *
	 * @return array<string, mixed>
	 */
	public function parse(string $url = ''): array {
		$data = $this->getImageData($url);
		if (!$data) {
			$data = $this->getOEmbedData($url);
		}
		if (!$data) {
			$data = $this->getDOMData($url);
			if (is_array($data) && !empty($data['oembed_url'])) {
				$oembed_urls = array_slice((array) $data['oembed_url'], 0, (int) $this->options['max_oembed_fetches']);
				foreach ($oembed_urls as $oembed_url) {
					$oembed_data = $this->getOEmbedData((string) $oembed_url);
					if (!empty($oembed_data)) {
						$oembed_data['oembed_url'] = $oembed_data['url'];
						unset($oembed_data['url']);
						$data = array_merge($data, $oembed_data);
					}
				}
			}
		}

		if (!is_array($data)) {
			$data = [];
		}

		if (empty($data['thumbnail_url']) && !empty($data['thumbnails'])) {
			$data['thumbnail_url'] = $data['thumbnails'][0];
		}

		return $data;
	}

	/**
	 * Parses image metatags
	 *
	 * @return array<string, mixed>|false
	 */
	public function getImageData(string $url = '') {
		if (!$this->isImage($url)) {
			return false;
		}

		return [
			'type' => 'photo',
			'url' => $url,
			'thumbnails' => [$url],
		];
	}

	/**
	 * Parses oEmbed data
	 *
	 * @return array<string, mixed>|false
	 */
	public function getOEmbedData(string $url = '') {
		if (!$this->isJSON($url) && !$this->isXML($url)) {
			return false;
		}

		$meta = [
			'url' => $url,
		];

		$content = $this->read($url);
		if (!$content) {
			return $meta;
		}

		$data = null;
		if ($this->isJSON($url)) {
			$data = json_decode($content);
		} else if ($this->isXML($url)) {
			$prev = libxml_use_internal_errors(true);
			$data = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
			libxml_clear_errors();
			libxml_use_internal_errors($prev);
		}

		if (!is_object($data)) {
			return $meta;
		}

		$props = [
			'type',
			'version',
			'title',
			'author_name',
			'author_url',
			'provider_name',
			'provider_url',
			'cache_age',
			'thumbnail_url',
			'thumbnail_width',
			'thumbnail_height',
			'width',
			'height',
			'html',
		];
		foreach ($props as $key) {
			if (!empty($data->$key)) {
				$meta[$key] = (string) $data->$key;
			}
		}

		return $meta;
	}

	/**
	 * Parses metatags from DOM
	 *
	 * @return array<string, mixed>|false
	 */
	public function getDOMData(string $url = '') {
		if (!$this->isHTML($url)) {
			return false;
		}

		$doc = $this->getDOM($url);
		if (!$doc) {
			return false;
		}

		$meta = [
			'url' => $url,
		];

		$parts = [
			$this->parseLinkTags($doc),
			$this->parseMetaTags($doc),
			$this->parseJsonLd($doc),
			$this->parseImgTags($doc),
		];

		// list keys are concatenated, scalar keys are first-wins
		$list_keys = ['icons', 'thumbnails', 'oembed_url', 'jsonld'];
		foreach ($parts as $part) {
			foreach ($part as $key => $value) {
				if (in_array($key, $list_keys, true)) {
					$merged = array_merge($meta[$key] ?? [], (array) $value);
					// jsonld holds nested arrays, which array_unique() cannot compare
					$meta[$key] = ($key === 'jsonld') ? $merged : array_values(array_unique($merged));
				} else if (!isset($meta[$key]) || $meta[$key] === '' || $meta[$key] === []) {
					$meta[$key] = $value;
				}
			}
		}

		if (empty($meta['title'])) {
			$meta['title'] = $this->parseTitle($doc);
		}

		return $meta;
	}

	/**
	 * Check if URL exists and is reachable by making an HTTP request
	 */
	public function exists(string $url = ''): bool {
		$response = $this->request($url);

		return $response instanceof ResponseInterface && $response->getStatusCode() === 200;
	}

	/**
	 * Validate URL
	 *
	 * @return string|false Normalized URL or false
	 */
	public function isValidUrl(string $url = '') {
		// based on http://php.net/manual/en/function.filter-var.php#104160
		$res = filter_var($url, FILTER_VALIDATE_URL);
		if ($res) {
			return $res;
		}

		// Check if it has unicode chars
		$l = mb_strlen($url);
		if (strlen($url) === $l) {
			return false;
		}

		// Replace wide chars by "X"
		$s = '';
		for ($i = 0; $i < $l; ++$i) {
			$ch = mb_substr($url, $i, 1);
			$s .= (strlen($ch) > 1) ? 'X' : $ch;
		}

		// Re-check now
		return filter_var($s, FILTER_VALIDATE_URL) ? $url : false;
	}

	/**
	 * Checks that the URL uses an allowed scheme and does not point at a
	 * private/reserved network (SSRF guard)
	 */
	public function isSafeUrl(string $url): bool {
		$parts = parse_url($url);
		if (!is_array($parts)) {
			return false;
		}

		$scheme = strtolower($parts['scheme'] ?? '');
		if (!in_array($scheme, (array) $this->options['allowed_schemes'], true)) {
			return false;
		}

		$host = $parts['host'] ?? '';
		if ($host === '') {
			return false;
		}

		if (!empty($this->options['allow_private_hosts'])) {
			return true;
		}

		return $this->isPublicHost($host);
	}

	/**
	 * Checks that a host name or IP literal resolves exclusively to public addresses
	 */
	protected function isPublicHost(string $host): bool {
		$host = strtolower(trim($host, '[]'));

		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return $this->isPublicIp($host);
		}

		if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || !str_contains($host, '.')) {
			return false;
		}

		$ips = [];
		$a = @gethostbynamel($host);
		if (is_array($a)) {
			$ips = $a;
		}
		$aaaa = @dns_get_record($host, DNS_AAAA);
		if (is_array($aaaa)) {
			foreach ($aaaa as $record) {
				if (!empty($record['ipv6'])) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		if (empty($ips)) {
			return false;
		}

		foreach ($ips as $ip) {
			if (!$this->isPublicIp($ip)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks that an IP address is not private, reserved, loopback, link-local or CGNAT
	 */
	protected function isPublicIp(string $ip): bool {
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
			return false;
		}

		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$long = ip2long($ip);
			$blocked = [
				['0.0.0.0', 8],       // "this" network
				['100.64.0.0', 10],   // CGNAT
				['169.254.0.0', 16],  // link-local
				['192.0.0.0', 24],    // IETF protocol assignments
				['198.18.0.0', 15],   // benchmarking
			];
			foreach ($blocked as [$network, $bits]) {
				$mask = -1 << (32 - $bits);
				if (($long & $mask) === (ip2long($network) & $mask)) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Fetches the resource (with response caching)
	 */
	public function request(string $url = ''): ?ResponseInterface {
		$url = str_replace(' ', '%20', $url);
		if (!$this->isValidUrl($url) || !$this->isSafeUrl($url)) {
			return null;
		}

		if (!array_key_exists($url, $this->cache)) {
			try {
				$response = $this->client->request('GET', $url, $this->buildRequestOptions());
				$response = $this->truncateBody($response);
			} catch (Throwable $e) {
				$response = null;
				$this->logger->notice('Parser error for GET request ({url}): {message}', [
					'url' => $url,
					'message' => $e->getMessage(),
				]);
			}
			$this->cache[$url] = $response;
		}

		return $this->cache[$url];
	}

	/**
	 * Builds per-request Guzzle options, re-validating every redirect target
	 *
	 * @return array<string, mixed>
	 */
	protected function buildRequestOptions(): array {
		$options = [
			'stream' => true,
			'allow_redirects' => [
				'max' => (int) $this->options['max_redirects'],
				'strict' => true,
				'referer' => true,
				'protocols' => (array) $this->options['allowed_schemes'],
				'on_redirect' => function ($request, $response, UriInterface $uri): void {
					if (!$this->isSafeUrl((string) $uri)) {
						throw new \RuntimeException("Redirect to unsafe URL blocked: $uri");
					}
				},
			],
		];

		return array_replace_recursive($options, (array) $this->options['request_options']);
	}

	/**
	 * Reads at most max_body_length bytes of the response body
	 */
	protected function truncateBody(ResponseInterface $response): ResponseInterface {
		$max = (int) $this->options['max_body_length'];
		$body = $response->getBody();

		$data = '';
		while (!$body->eof() && strlen($data) <= $max) {
			$chunk = $body->read(65536);
			if ($chunk === '') {
				break;
			}
			$data .= $chunk;
		}
		$body->close();

		if (strlen($data) > $max) {
			$data = substr($data, 0, $max);
		}

		return $response->withBody(Utils::streamFor($data));
	}

	/**
	 * Get contents of the page
	 */
	public function read(string $url = ''): string {
		if (!$this->exists($url)) {
			return '';
		}

		$response = $this->request($url);

		return (string) $response->getBody();
	}

	/**
	 * Checks if resource is an html page
	 */
	public function isHTML(string $url = ''): bool {
		$mime = $this->getContentType($url);

		return strpos($mime, 'text/html') !== false;
	}

	/**
	 * Checks if resource is JSON
	 */
	public function isJSON(string $url = ''): bool {
		$mime = $this->getContentType($url);

		return strpos($mime, 'json') !== false;
	}

	/**
	 * Checks if resource is XML
	 */
	public function isXML(string $url = ''): bool {
		$mime = $this->getContentType($url);

		return strpos($mime, 'xml') !== false;
	}

	/**
	 * Checks if resource is an image
	 */
	public function isImage(string $url = ''): bool {
		$mime = $this->getContentType($url);
		if ($mime) {
			list($simple, ) = explode('/', $mime);

			return $simple === 'image';
		}

		return false;
	}

	/**
	 * Get mime type of the URL content
	 */
	public function getContentType(string $url = ''): string {
		$response = $this->request($url);
		if ($response instanceof ResponseInterface) {
			$header = $response->getHeader('Content-Type');
			if (!empty($header)) {
				$parts = explode(';', $header[0]);

				return trim($parts[0]);
			}
		}

		return '';
	}

	/**
	 * Returns HTML contents of the page
	 */
	public function getHTML(string $url = ''): string {
		if (!$this->isHTML($url)) {
			return '';
		}

		return $this->read($url);
	}

	/**
	 * Returns HTML contents of the page as a DOMDocument
	 *
	 * @return DOMDocument|false
	 */
	public function getDOM(string $url = '') {
		$html = $this->getHTML($url);
		if (empty($html)) {
			return false;
		}

		$doc = new DOMDocument();

		$prev = libxml_use_internal_errors(true);

		if (function_exists('mb_encode_numericentity')) {
			$html = mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
		}
		$doc->loadHTML($html, LIBXML_NONET);

		if (!$doc->documentURI) {
			$doc->documentURI = $url;
		}

		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		return $doc;
	}

	/**
	 * Parses document title
	 */
	public function parseTitle(DOMDocument $doc): string {
		$node = $doc->getElementsByTagName('title')->item(0);

		return $node ? trim($node->textContent) : '';
	}

	/**
	 * Parses <link> tags
	 *
	 * @return array<string, mixed>
	 */
	public function parseLinkTags(DOMDocument $doc): array {
		$meta = [
			'icons' => [],
			'thumbnails' => [],
		];

		$nodes = $doc->getElementsByTagName('link');
		foreach ($nodes as $node) {
			$rel = strtolower($node->getAttribute('rel'));
			$href = $node->getAttribute('href');

			switch ($rel) {

				case 'icon' :
				case 'shortcut icon' :
				case 'apple-touch-icon' :
				case 'apple-touch-icon-precomposed' :
					$image_url = $this->getAbsoluteURL($doc, $href);
					if ($image_url && $this->isImage($image_url)) {
						$meta['icons'][] = $image_url;
					}
					break;

				case 'canonical' :
					$canonical = $this->getAbsoluteURL($doc, $href);
					if ($canonical) {
						$meta['canonical'] = $canonical;
					}
					break;

				case 'alternate' :
					$type = $node->getAttribute('type');
					if (in_array($type, [
						'application/json+oembed',
						'text/json+oembed',
						'application/xml+oembed',
						'text/xml+oembed',
					], true)) {
						$oembed_url = $this->getAbsoluteURL($doc, $href);
						if ($oembed_url) {
							$meta['oembed_url'][] = $oembed_url;
						}
					}
					break;
			}
		}

		return $meta;
	}

	/**
	 * Parses <meta> tags
	 *
	 * @return array<string, mixed>
	 */
	public function parseMetaTags(DOMDocument $doc): array {
		$meta = [];

		$nodes = $doc->getElementsByTagName('meta');
		foreach ($nodes as $node) {
			$name = $node->getAttribute('name');
			if (!$name) {
				$name = $node->getAttribute('property');
			}
			if (!$name) {
				continue;
			}

			$name = strtolower($name);

			if ($name === 'og:image:url' || $name === 'og:image:secure_url') {
				$name = 'og:image';
			}

			$content = $node->getAttribute('content');
			if (isset($meta['metatags'][$name])) {
				if (!is_array($meta['metatags'][$name])) {
					$meta['metatags'][$name] = [$meta['metatags'][$name]];
				}
				$meta['metatags'][$name][] = $content;
			} else {
				$meta['metatags'][$name] = $content;
			}

			switch ($name) {

				case 'title' :
				case 'og:title' :
				case 'twitter:title' :
					if (empty($meta['title'])) {
						$meta['title'] = $content;
					}
					break;

				case 'og:type' :
					if (empty($meta['type'])) {
						$meta['type'] = $content;
					}
					break;

				case 'description' :
				case 'og:description' :
				case 'twitter:description' :
					if (empty($meta['description'])) {
						$meta['description'] = $content;
					}
					break;

				case 'keywords' :
					$meta['tags'] = array_map('trim', explode(',', $content));
					break;

				case 'og:site_name' :
				case 'twitter:site' :
					if (empty($meta['provider_name'])) {
						$meta['provider_name'] = $content;
					}
					break;

				case 'og:url' :
					if (empty($meta['canonical'])) {
						$canonical = $this->getAbsoluteURL($doc, $content);
						if ($canonical) {
							$meta['canonical'] = $canonical;
						}
					}
					break;

				case 'og:image' :
				case 'twitter:image' :
				case 'twitter:image:src' :
					$image_url = $this->getAbsoluteURL($doc, $content);
					if ($image_url && $this->isImage($image_url)) {
						$meta['thumbnails'][] = $image_url;
					}
					break;
			}
		}

		return $meta;
	}

	/**
	 * Parses JSON-LD (schema.org) structured data
	 *
	 * @return array<string, mixed>
	 */
	public function parseJsonLd(DOMDocument $doc): array {
		$meta = [];

		$nodes = $doc->getElementsByTagName('script');
		foreach ($nodes as $node) {
			if (strtolower($node->getAttribute('type')) !== 'application/ld+json') {
				continue;
			}

			$data = json_decode($node->textContent, true);
			if (!is_array($data)) {
				continue;
			}

			// unwrap @graph and single-item arrays
			if (isset($data['@graph']) && is_array($data['@graph'])) {
				$data = $data['@graph'];
			}
			$items = array_is_list($data) ? $data : [$data];

			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}

				$meta['jsonld'][] = $item;

				if (empty($meta['title']) && !empty($item['headline']) && is_string($item['headline'])) {
					$meta['title'] = $item['headline'];
				} else if (empty($meta['title']) && !empty($item['name']) && is_string($item['name'])) {
					$meta['title'] = $item['name'];
				}

				if (empty($meta['description']) && !empty($item['description']) && is_string($item['description'])) {
					$meta['description'] = $item['description'];
				}

				$image = $item['image'] ?? null;
				if (is_array($image)) {
					$image = $image['url'] ?? ($image[0] ?? null);
					if (is_array($image)) {
						$image = $image['url'] ?? null;
					}
				}
				if (is_string($image) && $image !== '') {
					$image_url = $this->getAbsoluteURL($doc, $image);
					if ($image_url && $this->isImage($image_url)) {
						$meta['thumbnails'][] = $image_url;
					}
				}
			}
		}

		return $meta;
	}

	/**
	 * Parses <img> tags
	 *
	 * @return array<string, mixed>
	 */
	public function parseImgTags(DOMDocument $doc): array {
		$meta = [
			'thumbnails' => [],
		];

		$checks = 0;
		$max_checks = (int) $this->options['max_image_checks'];

		$nodes = $doc->getElementsByTagName('img');
		foreach ($nodes as $node) {
			if ($checks >= $max_checks) {
				break;
			}
			$src = $node->getAttribute('src');
			$image_url = $this->getAbsoluteURL($doc, $src);
			if (!$image_url) {
				continue;
			}
			$checks++;
			if ($this->isImage($image_url)) {
				$meta['thumbnails'][] = $image_url;
			}
		}

		return $meta;
	}

	/**
	 * Resolves a (possibly relative) URL against the document URI (RFC 3986)
	 *
	 * @return string|false
	 */
	public function getAbsoluteURL(DOMDocument $doc, string $href = '') {
		$href = trim($href);
		if ($href === '') {
			return false;
		}

		$scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
		if ($scheme !== '' && !in_array($scheme, (array) $this->options['allowed_schemes'], true)) {
			// data:, javascript:, file: and other non-fetchable schemes
			return false;
		}

		try {
			$base = new Uri($doc->documentURI ?: '');
			$resolved = UriResolver::resolve($base, new Uri($href));
		} catch (Throwable $e) {
			return false;
		}

		if (!in_array(strtolower($resolved->getScheme()), (array) $this->options['allowed_schemes'], true) || $resolved->getHost() === '') {
			return false;
		}

		return (string) $resolved;
	}

}
