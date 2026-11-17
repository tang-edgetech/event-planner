<?php
/**
 * User-agent parsing (device, browser + major version, OS + version) and bot detection.
 * Small regex tables, no external library.
 *
 * Used by: TMG_Reports_Collect, TMG_Reports_Recorder (server events), tests.
 */

defined( 'ABSPATH' ) || exit;

class TMG_Reports_UA {

	const BOTS = '/bot\b|bot\/|bot;|crawl|spider|slurp|facebookexternalhit|facebookcatalog|whatsapp|telegrambot|preview|headless|lighthouse|pagespeed|gtmetrix|ptst|pingdom|uptime|monitor|statuscake|site24x7|newrelic|datadog|curl|wget|python|java\/|okhttp|go-http|node-fetch|axios|undici|libwww|httpclient|httpie|guzzle|scrapy|phantomjs|selenium|puppeteer|playwright|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|claudebot|ccbot|anthropic|perplexity|amazonbot|applebot|yandex(?!browser)|baiduspider|sogou|exabot|archive\.org|wordpress\/|feedfetcher|mediapartners|adsbot|inspectiontool|embedly|qwantify|validator|^mozilla\/5\.0$/i';

	public static function is_bot( string $ua ): bool {
		$ua = trim( $ua );
		if ( '' === $ua || strlen( $ua ) < 12 ) {
			return true;
		}
		return (bool) preg_match( self::BOTS, $ua );
	}

	/** @return array{device:string,browser:string,browser_version:string,os:string,os_version:string} */
	public static function parse( string $ua ): array {
		$r = array(
			'device'          => 'desktop',
			'browser'         => 'Other',
			'browser_version' => '',
			'os'              => 'Other',
			'os_version'      => '',
		);

		if ( preg_match( '/iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))/i', $ua ) ) {
			$r['device'] = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|BB10|Opera Mini|IEMobile/i', $ua ) ) {
			$r['device'] = 'mobile';
		}

		$os = array(
			'/Windows Phone(?: OS)? ([\d.]+)/' => array( 'Windows Phone', '' ),
			'/Windows NT 10\.0/'               => array( 'Windows', '10/11' ),
			'/Windows NT 6\.3/'                => array( 'Windows', '8.1' ),
			'/Windows NT 6\.2/'                => array( 'Windows', '8' ),
			'/Windows NT 6\.1/'                => array( 'Windows', '7' ),
			'/Windows NT ([\d.]+)/'            => array( 'Windows', '' ),
			'/iPhone OS ([\d_]+)/'             => array( 'iOS', '' ),
			'/iPad.*CPU OS ([\d_]+)/'          => array( 'iPadOS', '' ),
			'/CPU OS ([\d_]+)/'                => array( 'iPadOS', '' ),
			'/HarmonyOS/'                      => array( 'HarmonyOS', '' ),
			'/Android ([\d.]+)/'               => array( 'Android', '' ),
			'/Android/'                        => array( 'Android', '' ),
			'/Mac OS X ([\d_.]+)/'             => array( 'macOS', '' ),
			'/CrOS/'                           => array( 'ChromeOS', '' ),
			'/Ubuntu/'                         => array( 'Ubuntu', '' ),
			'/Linux/'                          => array( 'Linux', '' ),
		);
		foreach ( $os as $re => $info ) {
			if ( preg_match( $re, $ua, $m ) ) {
				$r['os']         = $info[0];
				$version         = '' !== $info[1] ? $info[1] : str_replace( '_', '.', $m[1] ?? '' );
				$parts           = explode( '.', $version );
				$r['os_version'] = substr( implode( '.', array_slice( $parts, 0, 'macOS' === $info[0] ? 2 : 1 ) ), 0, 20 );
				break;
			}
		}

		$browsers = array(
			'/Edg(?:e|A|iOS)?\/([\d.]+)/'   => 'Edge',
			'/(?:OPR|Opera)\/([\d.]+)/'     => 'Opera',
			'/SamsungBrowser\/([\d.]+)/'    => 'Samsung Internet',
			'/UCBrowser\/([\d.]+)/'         => 'UC Browser',
			'/YaBrowser\/([\d.]+)/'         => 'Yandex Browser',
			'/HuaweiBrowser\/([\d.]+)/'     => 'Huawei Browser',
			'/(?:FBAN|FBAV)\/([\d.]+)/'     => 'Facebook app',
			'/Instagram ([\d.]+)/'          => 'Instagram app',
			'/\bLine\/([\d.]+)/'            => 'LINE app',
			'/MicroMessenger\/([\d.]+)/'    => 'WeChat',
			'/LinkedInApp/'                 => 'LinkedIn app',
			'/TikTok|musical_ly/'           => 'TikTok app',
			'/(?:Chrome|CriOS)\/([\d.]+)/'  => 'Chrome',
			'/(?:Firefox|FxiOS)\/([\d.]+)/' => 'Firefox',
			'/Version\/([\d.]+).*Safari/'   => 'Safari',
			'/(?:MSIE |Trident\/.*rv:)([\d.]+)/' => 'Internet Explorer',
		);
		foreach ( $browsers as $re => $name ) {
			if ( preg_match( $re, $ua, $m ) ) {
				$r['browser']         = $name;
				$r['browser_version'] = isset( $m[1] ) ? substr( explode( '.', $m[1] )[0], 0, 20 ) : '';
				break;
			}
		}
		return $r;
	}
}
