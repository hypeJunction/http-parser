<?php

declare(strict_types=1);

namespace hypeJunction;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class ParserTest extends TestCase {

	protected string $url = 'http://localhost/';

	protected string $test_file_path;

	protected function setUp(): void {
		$this->test_file_path = dirname(__DIR__) . '/test-files/';
	}

	protected function loadFile(string $name): string {
		return file_get_contents($this->test_file_path . $name);
	}

	protected function mock(string $type = '', array $options = []): Parser {

		switch ($type) {
			default :
				$responses = [
					new Response(404),
				];
				break;

			case 'html' :
				$html = $this->loadFile('ogp.html');
				$responses = [
					new Response(200, [
						'Content-Type' => 'text/html; charset=UTF-8',
						'Content-Length' => strlen($html),
					], $html),
					new Response(200, ['Content-Type' => 'image/x-icon']),
					new Response(200, ['Content-Type' => 'image/x-icon']),
					new Response(200, ['Content-Type' => 'image/png']),
					new Response(200, ['Content-Type' => 'image/png']),
					new Response(200, ['Content-Type' => 'application/xml+oembed']),
				];
				break;

			case 'jsonld' :
				$html = $this->loadFile('jsonld.html');
				$responses = [
					new Response(200, [
						'Content-Type' => 'text/html; charset=UTF-8',
						'Content-Length' => strlen($html),
					], $html),
				];
				break;

			case 'xml' :
				$xml = $this->loadFile('oembed.xml');
				$responses = [
					new Response(200, [
						'Content-Type' => 'application/xml+oembed; charset=UTF-8',
						'Content-Length' => strlen($xml),
					], $xml),
				];
				break;

			case 'json' :
				$json = $this->loadFile('oembed.json');
				$responses = [
					new Response(200, [
						'Content-Type' => 'application/json+oembed; charset=UTF-8',
						'Content-Length' => strlen($json),
					], $json),
				];
				break;

			case 'jpeg' :
				$jpg = $this->loadFile('w3c_home.jpg');
				$responses = [
					new Response(200, [
						'Content-Type' => 'image/jpeg',
						'Content-Length' => strlen($jpg),
					], $jpg),
				];
				break;
		}

		$handler = new MockHandler($responses);
		$client = new Client(['handler' => $handler]);

		return new Parser($client, array_merge(['allow_private_hosts' => true], $options));
	}

	protected function url(): string {
		return $this->url . mt_rand(10000, 5000000);
	}

	public function testParse(): void {
		$this->assertIsArray($this->mock()->parse($this->url()));
		$this->assertIsArray($this->mock('html')->parse($this->url()));
		$this->assertIsArray($this->mock('xml')->parse($this->url()));
		$this->assertIsArray($this->mock('json')->parse($this->url()));
		$this->assertIsArray($this->mock('jpeg')->parse($this->url()));
	}

	public function testGetImageData(): void {
		$data = $this->mock('jpeg')->getImageData($this->url());
		$this->assertEquals('photo', $data['type']);
		$this->assertNotEmpty($data['thumbnails']);

		$this->assertFalse($this->mock()->getImageData($this->url()));
	}

	public function testGetOEmbedDataXML(): void {
		$data = $this->mock('xml')->getOEmbedData($this->url());
		$this->assertEquals('link', $data['type']);

		$this->assertFalse($this->mock()->getOEmbedData($this->url()));
	}

	public function testGetOEmbedDataJSON(): void {
		$data = $this->mock('json')->getOEmbedData($this->url());
		$this->assertEquals('photo', $data['type']);
	}

	public function testGetDOMData(): void {
		$data = $this->mock('html')->getDOMData($this->url());
		$this->assertEquals('website', $data['type']);

		$this->assertFalse($this->mock()->getDOMData($this->url()));
	}

	public function testExists(): void {
		$this->assertFalse($this->mock()->exists($this->url()));
		$this->assertTrue($this->mock('html')->exists($this->url()));

		$this->assertNull($this->mock('html')->request('invalid.url'));
	}

	public function testRequest(): void {
		$this->assertInstanceOf(ResponseInterface::class, $this->mock()->request($this->url()));
		$this->assertInstanceOf(ResponseInterface::class, $this->mock('html')->request($this->url()));
	}

	public function testRead(): void {
		$this->assertEquals('', $this->mock()->read($this->url()));
		$this->assertEquals($this->loadFile('ogp.html'), $this->mock('html')->read($this->url()));
	}

	public function testIsHTML(): void {
		$this->assertFalse($this->mock()->isHTML($this->url()));
		$this->assertTrue($this->mock('html')->isHTML($this->url()));
		$this->assertFalse($this->mock('xml')->isHTML($this->url()));
		$this->assertFalse($this->mock('json')->isHTML($this->url()));
		$this->assertFalse($this->mock('jpeg')->isHTML($this->url()));
	}

	public function testIsJSON(): void {
		$this->assertFalse($this->mock()->isJSON($this->url()));
		$this->assertFalse($this->mock('html')->isJSON($this->url()));
		$this->assertFalse($this->mock('xml')->isJSON($this->url()));
		$this->assertTrue($this->mock('json')->isJSON($this->url()));
		$this->assertFalse($this->mock('jpeg')->isJSON($this->url()));
	}

	public function testIsXML(): void {
		$this->assertFalse($this->mock()->isXML($this->url()));
		$this->assertFalse($this->mock('html')->isXML($this->url()));
		$this->assertTrue($this->mock('xml')->isXML($this->url()));
		$this->assertFalse($this->mock('json')->isXML($this->url()));
		$this->assertFalse($this->mock('jpeg')->isXML($this->url()));
	}

	public function testIsImage(): void {
		$this->assertFalse($this->mock()->isImage($this->url()));
		$this->assertFalse($this->mock('html')->isImage($this->url()));
		$this->assertFalse($this->mock('xml')->isImage($this->url()));
		$this->assertFalse($this->mock('json')->isImage($this->url()));
		$this->assertTrue($this->mock('jpeg')->isImage($this->url()));
	}

	public function testGetContentType(): void {
		$this->assertEquals('', $this->mock()->getContentType($this->url()));
		$this->assertEquals('text/html', $this->mock('html')->getContentType($this->url()));
		$this->assertEquals('application/xml+oembed', $this->mock('xml')->getContentType($this->url()));
		$this->assertEquals('application/json+oembed', $this->mock('json')->getContentType($this->url()));
		$this->assertEquals('image/jpeg', $this->mock('jpeg')->getContentType($this->url()));
	}

	public function testGetHTML(): void {
		$this->assertEquals('', $this->mock()->getHTML($this->url()));
		$this->assertEquals($this->loadFile('ogp.html'), $this->mock('html')->getHTML($this->url()));
		$this->assertEquals('', $this->mock('xml')->getHTML($this->url()));
		$this->assertEquals('', $this->mock('json')->getHTML($this->url()));
	}

	public function testGetDOM(): void {
		$this->assertFalse($this->mock()->getDOM($this->url()));

		$url = $this->url();
		$dom = $this->mock('html')->getDOM($url);
		$this->assertInstanceOf(\DOMDocument::class, $dom);
		$this->assertEquals($url, $dom->documentURI);
	}

	public function testParseTitle(): void {
		$mock = $this->mock('html');
		$doc = $mock->getDOM($this->url());
		$this->assertEquals('Structured video array', $mock->parseTitle($doc));
	}

	public function testParseTitleWithoutTitleTag(): void {
		$mock = $this->mock('html');
		$doc = new \DOMDocument();
		$doc->loadHTML('<html><body><p>no title</p></body></html>');
		$this->assertEquals('', $mock->parseTitle($doc));
	}

	public function testParseLinkTags(): void {
		$mock = $this->mock('html');
		$doc = $mock->getDOM($this->url());
		$meta = $mock->parseLinkTags($doc);

		$this->assertContains("{$this->url}path/to/icon.ico", $meta['icons']);
		$this->assertContains("{$this->url}path/to/icon2.ico", $meta['icons']);
		$this->assertContains("http://examples.opengraphprotocol.us/video-array.xml", $meta['oembed_url']);
		$this->assertEquals("http://examples.opengraphprotocol.us/video-array.html", $meta['canonical']);
	}

	public function testParseMetaTags(): void {
		$mock = $this->mock('html');
		$doc = $mock->getDOM($this->url());
		$meta = $mock->parseMetaTags($doc);
		$this->assertEquals($meta['description'], $meta['metatags']['description']);
		$this->assertIsArray($meta['metatags']['og:video']);
		$this->assertEquals(['tag 1', 'tag 2'], $meta['tags']);
	}

	public function testParseImgTags(): void {
		$mock = $this->mock('html');
		$doc = $mock->getDOM($this->url());
		$meta = $mock->parseImgTags($doc);
		$this->assertContains("http://examples.opengraphprotocol.us/media/images/50.png", $meta['thumbnails']);
	}

	public function testParseJsonLd(): void {
		$mock = $this->mock('jsonld');
		$data = $mock->getDOMData($this->url());

		$this->assertEquals('JSON-LD headline', $data['title']);
		$this->assertEquals('JSON-LD description', $data['description']);
		$this->assertEquals('Article', $data['jsonld'][0]['@type']);
	}

	public function testCanonicalRemainsScalarWhenSetTwice(): void {
		$data = $this->mock('html')->getDOMData($this->url());
		$this->assertIsString($data['canonical']);
		$this->assertEquals('http://examples.opengraphprotocol.us/video-array.html', $data['canonical']);
	}

	public function testBlocksPrivateAndReservedHosts(): void {
		$parser = new Parser(new Client(['handler' => new MockHandler([new Response(200)])]));

		$this->assertNull($parser->request('http://localhost/secret'));
		$this->assertNull($parser->request('http://127.0.0.1/'));
		$this->assertNull($parser->request('http://10.0.0.1/'));
		$this->assertNull($parser->request('http://192.168.1.1/'));
		$this->assertNull($parser->request('http://169.254.169.254/latest/meta-data/'));
		$this->assertNull($parser->request('http://100.64.0.1/'));
		$this->assertNull($parser->request('http://[::1]/'));
	}

	public function testAllowsPublicIpLiterals(): void {
		$parser = new Parser(new Client(['handler' => new MockHandler([new Response(200)])]));

		$this->assertTrue($parser->isSafeUrl('http://93.184.216.34/'));
		$this->assertFalse($parser->isSafeUrl('ftp://93.184.216.34/'));
		$this->assertFalse($parser->isSafeUrl('file:///etc/passwd'));
	}

	public function testBlocksRedirectToPrivateHost(): void {
		$mock = new MockHandler([
			new Response(301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
			new Response(200, ['Content-Type' => 'text/html'], 'internal'),
		]);
		$client = new Client(['handler' => HandlerStack::create($mock)]);
		$parser = new Parser($client);

		$this->assertNull($parser->request('http://93.184.216.34/'));
	}

	public function testTruncatesOversizedBodies(): void {
		$body = str_repeat('a', 100);
		$mock = new MockHandler([
			new Response(200, ['Content-Type' => 'text/html'], $body),
		]);
		$client = new Client(['handler' => $mock]);
		$parser = new Parser($client, [
			'allow_private_hosts' => true,
			'max_body_length' => 10,
		]);

		$this->assertEquals(10, strlen($parser->read($this->url())));
	}

	public function testGetAbsoluteURL(): void {
		$mock = $this->mock();
		$doc = new \DOMDocument();
		$doc->documentURI = 'http://example.com/a/b/page.html?q=1';

		$this->assertEquals('http://example.com/c', $mock->getAbsoluteURL($doc, '/c'));
		$this->assertEquals('http://example.com/a/b/c.png', $mock->getAbsoluteURL($doc, 'c.png'));
		$this->assertEquals('http://example.com/a/c.png', $mock->getAbsoluteURL($doc, '../c.png'));
		$this->assertEquals('http://other.com/x', $mock->getAbsoluteURL($doc, '//other.com/x'));
		$this->assertEquals('https://cdn.com/i.png', $mock->getAbsoluteURL($doc, 'https://cdn.com/i.png'));

		$this->assertFalse($mock->getAbsoluteURL($doc, 'data:image/png;base64,xyz'));
		$this->assertFalse($mock->getAbsoluteURL($doc, 'javascript:alert(1)'));
		$this->assertFalse($mock->getAbsoluteURL($doc, ''));
	}

	public function testIsValidUrlHandlesMultibyteUrls(): void {
		$mock = $this->mock();
		$this->assertNotFalse($mock->isValidUrl('http://example.com/päge'));
		$this->assertNotFalse($mock->isValidUrl('http://example.com/page'));
		$this->assertFalse($mock->isValidUrl('not a url'));
	}

	public function testExitOnRecursiveAlternateLinking(): void {
		$mock = $this->getMockBuilder(Parser::class)
			->onlyMethods(['getDOMData', 'getImageData', 'getOEmbedData'])
			->disableOriginalConstructor()
			->getMock();

		$expected = [
			'oembed_url' => [
				$this->url,
			],
			'canonical' => [
				$this->url,
			],
		];

		$mock->method('getDOMData')
			->willReturn($expected);

		$mock->method('getImageData')
			->willReturn(false);

		$mock->method('getOEmbedData')
			->willReturn(false);

		$actual = $mock->parse('http://localhost/');

		$this->assertEquals($expected, $actual);
	}

}
