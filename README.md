HTTP Parser
===========
[![Code Coverage](https://scrutinizer-ci.com/g/hypeJunction/http-parser/badges/coverage.png?b=master)](https://scrutinizer-ci.com/g/hypeJunction/http-parser/?branch=master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/hypeJunction/http-parser/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/hypeJunction/http-parser/?branch=master)
[![Build Status](https://scrutinizer-ci.com/g/hypeJunction/http-parser/badges/build.png?b=master)](https://scrutinizer-ci.com/g/hypeJunction/http-parser/build-status/master)

Parses HTTP resources into serializable arrays of oEmbed-like metatags

## Features

 * Parses HTTP resources into oEmbed-like arrays of metatags
 * Handy for rendering URL cards


## Security

 * Make sure to implement a domain whitelist, as this method is not entirely secure and prone to XSS


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
		"keywords": "record, sounds, share, sound, audio, tracks, music, soundcloud",
		"referrer": "origin",
		"google-site-verification": "dY0CigqM8Inubs_hgrYMwk-zGchKwrvJLcvI_G8631Q",
		"viewport": "width=device-width,minimum-scale=1,maximum-scale=1,user-scalable=no",
		"fb:app_id": "19507961798",
		"og:site_name": "SoundCloud",
		"twitter:app:name:iphone": [
			"SoundCloud",
			"SoundCloud"
		],
		"description": "Stream PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix) by Phobos Records from desktop or your mobile device",
		"twitter:title": "PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix)",
		"twitter:description": "Release Date: 01.02.2016\n PHS014: Maksim Dark & Light Breath \"BELRUS EP\".\npro.beatport.com/release/belrus-ep/1683847\n\nfacebook.com/phobosrecords",
		"twitter:player": "https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F244902712&auto_play=false&show_artwork=true&visual=true&origin=twitter",
		"twitter:player:height": "400",
		"twitter:player:width": "435",
		"twitter:image:src": "https://i1.sndcdn.com/artworks-000145456354-01w756-t500x500.jpg",
		"twitter:card": "audio",
		"twitter:audio:artist_name": "Phobos Records",
		"twitter:audio:source": "https://api-partners.soundcloud.com/twitter/tracks/soundcloud:sounds:244902712/vmap",
		"twitter:app:url:googleplay": "soundcloud://sounds:244902712",
		"al:ios:app_store_id": "336353151",
		"al:android:package": "com.soundcloud.android",
		"og:type": "soundcloud:sound",
		"og:url": "https://soundcloud.com/phobosrecords/maksim-light-pulse",
		"og:image:width": "500",
		"al:web:should_fallback": "false",
		"soundcloud:user": "https://soundcloud.com/phobosrecords",
		"soundcloud:play_count": "611",
		"soundcloud:comments_count": "11",
		"soundcloud:like_count": "40",
		"msapplication-tooltip": "Launch SoundCloud",
		"msapplication-tileimage": "https://a-v2.sndcdn.com/assets/images/sc-icons/win8-2dc974a1.png",
		"msapplication-tilecolor": "#ff5500",
		"msapplication-starturl": "https://soundcloud.com"
	},
	"tags": [
		"record",
		"sounds",
		"share",
		"sound",
		"audio",
		"tracks",
		"music",
		"soundcloud"
	],
	"provider_name": "SoundCloud",
	"description": "Stream PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix) by Phobos Records from desktop or your mobile device",
	"title": "PHS014: Maksim Dark & Light Breath - Finger Pulse (Original Mix) by Phobos Records",
	"resource_type": "soundcloud:sound",
	"thumbnails": [
		"https://i1.sndcdn.com/artworks-000145456354-01w756-t500x500.jpg",
		"https://a-v2.sndcdn.com/assets/images/loader-dark-45940ae3.gif"
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


## Usage

```php
// sample index.php
require __DIR__ . '/vendor/autoload.php';

$client = new \GuzzleHttp\Client([
	'headers' => [
		'User-Agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9.2.12) Gecko/20101026 Firefox/3.6.12',
	],
	'allow_redirects' => [
		'max' => 3,
		'strict' => true,
		'referer' => true,
		'protocols' => ['http', 'https']
	],
	'timeout' => 5,
	'connect_timeout' => 5,
	'verify' => false,
]);
$parser = new \hypeJunction\Parser($client);

$url = $_GET['url'];

header('Content-Type: application/json');

echo json_encode($parser->parse($url));

```
