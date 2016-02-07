<?php

namespace hypeJunction;

use DOMDocument;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Parses HTTP resource into a serialable array of metatags
 */
class Parser {

	/**
	 * @var ClientInterface
	 */
	private $client;

	/**
	 * @var array
	 */
	static $cache;

	/**
	 * Constructor
	 * @param ClientInterface $client HTTP Client
	 */
	public function __construct(ClientInterface $client) {
		$this->client = $client;
	}

	/**
	 * Parses a URL into a an array of metatags
	 *
	 * @param string $url URL to parse
	 * @return array
	 */
	public function parse($url = '') {

		$data = $this->getImageData($url);
		if (!$data) {
			$data = $this->getOEmbedData($url);
		}
		if (!$data) {
			$data = $this->getDOMData($url);
			if (is_array($data) && !empty($data['oembed_url'])) {
				foreach ($data['oembed_url'] as $oembed_url) {
					$oembed_data = $this->parse($oembed_url);
					if (!empty($oembed_data) && is_array($oembed_data)) {
						$oembed_data['oembed_url'] = $oembed_data['url'];
						unset($oembed_data['url']);
						$data = array_merge($data, $oembed_data);
					}
				}
			}
		}
	
		if (!is_array($data)) {
			$data = array();
		}

		if (empty($data['thumbnail_url']) && !empty($data['thumbnails'])) {
			$data['thumbnail_url'] = $data['thumbnails'][0];
		}

		return $data;
	}

	/**
	 * Parses image metatags
	 *
	 * @param string $url URL of the image
	 * @return array|false
	 */
	public function getImageData($url = '') {
		if (!$this->isImage($url)) {
			return false;
		}

		return array(
			'type' => 'photo',
			'url' => $url,
			'thumbnails' => array($url),
		);
	}

	/**
	 * Parses OEmbed data
	 *
	 * @param  string $url URL of the image
	 * @return array|false
	 */
	public function getOEmbedData($url = '') {

		if (!$this->isJSON($url) && !$this->isXML($url)) {
			return false;
		}

		$meta = array(
			'url' => $url,
		);

		$content = $this->read($url);
		if (!$content) {
			return $meta;
		}

		$data = new \stdClass();
		if ($this->isJSON($url)) {
			$data = @json_decode($content);
		} else if ($this->isXML($url)) {
			$data = @simplexml_load_string($content);
		}

		$props = array(
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
		);
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
	 * @param  string $url URL
	 * @return array|false
	 */
	public function getDOMData($url = '') {

		if (!$this->isHTML($url)) {
			return false;
		}

		$doc = $this->getDOM($url);
		$defaults = array(
			'url' => $url,
		);

		$link_tags = $this->parseLinkTags($doc);
		$meta_tags = $this->parseMetaTags($doc);
		$img_tags = $this->parseImgTags($doc);

		$meta = array_merge_recursive($defaults, $link_tags, $meta_tags, $img_tags);
		
		if (empty($meta['title'])) {
			$meta['title'] = $this->parseTitle($doc);
		}


		return $meta;
	}

	/**
	 * Check if URL exists and is reachable by making an HTTP request to retrieve header information
	 *
	 * @param string $url URL of the resource
	 * @return boolean
	 */
	public function exists($url = '') {
		$response = $this->request($url);
		if ($response instanceof Response) {
			return $response->getStatusCode() == 200;
		}
		return false;
	}

	/**
	 * Returns head of the resource
	 *
	 * @param string $url URL of the resource
	 * @return Response|false
	 */
	public function request($url = '') {
		if (!filter_var($url, FILTER_VALIDATE_URL)) {
			return false;
		}
		if (!isset(self::$cache[$url])) {
			try {
				$response = $this->client->request('GET', $url);
			} catch (Exception $e) {
				$response = false;
				error_log("Parser Error for HEAD request ($url): {$e->getMessage()}");
			}
			self::$cache[$url] = $response;
		}

		return self::$cache[$url];
	}

	/**
	 * Get contents of the page
	 *
	 * @param string $url URL of the resource
	 * @return string
	 */
	public function read($url = '') {
		$body = '';
		if (!$this->exists($url)) {
			return $body;
		}

		$response = $this->request($url);
		$body = (string) $response->getBody();
		return $body;
	}

	/**
	 * Checks if resource is an html page
	 *
	 * @param string $url URL of the resource
	 * @return boolean
	 */
	public function isHTML($url = '') {
		$mime = $this->getContentType($url);
		return strpos($mime, 'text/html') !== false;
	}

	/**
	 * Checks if resource is JSON
	 *
	 * @param string $url URL of the resource
	 * @return boolean
	 */
	public function isJSON($url = '') {
		$mime = $this->getContentType($url);
		return strpos($mime, 'json') !== false;
	}

	/**
	 * Checks if resource is XML
	 *
	 * @param string $url URL of the resource
	 * @return boolean
	 */
	public function isXML($url = '') {
		$mime = $this->getContentType($url);
		return strpos($mime, 'xml') !== false;
	}

	/**
	 * Checks if resource is an image
	 *
	 * @param string $url URL of the resource
	 * @return boolean
	 */
	public function isImage($url = '') {
		$mime = $this->getContentType($url);
		if ($mime) {
			list($simple, ) = explode('/', $mime);
			return ($simple == 'image');
		}

		return false;
	}

	/**
	 * Get mime type of the URL content
	 *
	 * @param string $url URL of the resource
	 * @return string
	 */
	public function getContentType($url = '') {
		$response = $this->request($url);
		if ($response instanceof Response) {
			$header = $response->getHeader('Content-Type');
			if (is_array($header) && !empty($header)) {
				$parts = explode(';', $header[0]);
				return trim($parts[0]);
			}
		}
		return '';
	}

	/**
	 * Returns HTML contents of the page
	 *
	 * @param string $url URL of the resource
	 * @return string
	 */
	public function getHTML($url = '') {
		if (!$this->isHTML($url)) {
			return '';
		}
		return $this->read($url);
	}

	/**
	 * Returns HTML contents of the page as a DOMDocument
	 *
	 * @param string $url URL of the resource
	 * @return DOMDocument
	 */
	public function getDOM($url = '') {
		$html = $this->getHTML($url);
		$doc = new DOMDocument();
		@$doc->loadHTML($html);
		if (!$doc->documentURI) {
			$doc->documentURI = $url;
		}
		return $doc;
	}

	/**
	 * Parses document title
	 *
	 * @param DOMDocument $doc Document
	 * @return string
	 */
	public function parseTitle(DOMDocument $doc) {
		$node = $doc->getElementsByTagName('title');
		$title = $node->item(0)->nodeValue;
		return ($title) ? : '';
	}

	/**
	 * Parses <link> tags
	 *
	 * @param DOMDocument $doc Document
	 * @return array
	 */
	public function parseLinkTags(DOMDocument $doc) {

		$meta = array();

		$nodes = $doc->getElementsByTagName('link');
		foreach ($nodes as $node) {
			$rel = $node->getAttribute('rel');
			$href = $node->getAttribute('href');

			switch ($rel) {

				case 'icon' :
					$meta['icons'][] = $this->getAbsoluteURL($doc, $href);
					break;

				case 'canonical' :
					$meta['canonical'] = $this->getAbsoluteURL($doc, $href);
					break;

				case 'alternate' :
					$type = $node->getAttribute('type');
					if (in_array($type, array(
								'application/json+oembed',
								'text/json+oembed',
								'application/xml+oembed',
								'text/xml+oembed'
							))) {
						$meta['oembed_url'][] = $this->getAbsoluteURL($doc, $href);
					}
					break;
			}
		}

		return $meta;
	}

	/**
	 * Parses <meta> tags
	 *
	 * @param DOMDocument $doc Document
	 * @return array
	 */
	public function parseMetaTags(DOMDocument $doc) {

		$meta = array();

		$nodes = $doc->getElementsByTagName('meta');
		if (!empty($nodes)) {
			foreach ($nodes as $node) {
				$name = $node->getAttribute('name');
				if (!$name) {
					$name = $node->getAttribute('property');
				}
				if (!$name) {
					continue;
				}

				$name = strtolower($name);

				$content = $node->getAttribute('content');
				if (isset($meta['metatags'][$name])) {
					if (!is_array($meta['metatags'][$name])) {
						$meta['metatags'][$name] = array($meta['metatags'][$name]);
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
						if (is_string($content)) {
							$content = explode(',', $content);
							$content = array_map('trim', $content);
						}
						$meta['tags'] = $content;
						break;

					case 'og:site_name' :
					case 'twitter:site' :
						if (empty($meta['provider_name'])) {
							$meta['provider_name'] = $content;
						}
						break;

					case 'og:image' :
					case 'twitter:image' :
						$meta['thumbnails'][] = $this->getAbsoluteURL($doc, $content);
						break;
				}
			}
		}

		return $meta;
	}

	/**
	 * Parses <img> tags
	 *
	 * @param DOMDocument $doc Document
	 * @return array
	 */
	public function parseImgTags(DOMDocument $doc) {

		$meta = array();

		$nodes = $doc->getElementsByTagName('img');
		foreach ($nodes as $node) {
			$src = $node->getAttribute('src');
			$meta['thumbnails'][] = $this->getAbsoluteURL($doc, $src);
		}

		return $meta;
	}

	/**
	 * Normalizes relative URLs
	 *
	 * @param DOMDocument $doc  Document
	 * @param string      $href URL to normalize
	 * @return string
	 */
	public function getAbsoluteURL(DOMDocument $doc, $href = '') {

		// Check if $url is absolute
		if (parse_url($href, PHP_URL_HOST)) {
			return $href;
		}

		$uri = trim($doc->documentURI ? : '', '/');

		// Check if $url is relative to root
		if (substr($href, 0, 1) === "/") {
			$scheme = parse_url($uri, PHP_URL_SCHEME);
			$host = parse_url($uri, PHP_URL_HOST);
			return "$scheme://$host$href";
		}

		// $url is relative to page
		return "$uri/$href";
	}

}
