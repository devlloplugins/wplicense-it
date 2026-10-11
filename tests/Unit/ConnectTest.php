<?php
/**
 * Tests for the Connect code and checklist.
 *
 * @package Devllo\WPLicenseIt
 */

declare( strict_types=1 );

namespace Devllo\WPLicenseIt\Tests\Unit;

require_once dirname( __DIR__ ) . '/Support/connect-stubs.php';

use Devllo\WPLicenseIt\Connect\Checklist;
use Devllo\WPLicenseIt\Connect\Snippet;
use PHPUnit\Framework\TestCase;
use WPLicenseIt\Client\Config;

/**
 * @covers \Devllo\WPLicenseIt\Connect\Snippet
 * @covers \Devllo\WPLicenseIt\Connect\Checklist
 */
final class ConnectTest extends TestCase {

	/**
	 * Evaluates the array the snippet passes to wplicense_it_client().
	 *
	 * @return array<string, mixed>
	 */
	private function args( string $code ): array {
		$this->assertTrue( 1 === preg_match( '/wplicense_it_client\(\s*(array\(.*\))\s*\);/s', $code, $m ), 'No wplicense_it_client call found' );

		return eval( 'return ' . $m[1] . ';' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Test of generated code.
	}

	private function assertParses( string $code ): void {
		token_get_all( '<?php ' . $code, TOKEN_PARSE );
		$this->assertTrue( true );
	}

	public function test_a_plugin_snippet_is_valid_php_with_the_values_filled_in(): void {
		$code = Snippet::generate( 'plugin', 'SEO Pro', 'seo-pro', 'https://shop.example.com', 7 );

		$this->assertParses( $code );
		$this->assertStringContainsString( "require_once __DIR__ . '/vendor/wplicense-it-client/wplicense-it-client.php';", $code );
		$this->assertStringContainsString( "'file'       => __FILE__,", $code );
		$this->assertStringNotContainsString( "'type'", $code );

		$args = $this->args( $code );
		$this->assertSame( 'https://shop.example.com', $args['server'] );
		$this->assertSame( 7, $args['product_id'] );
		$this->assertSame( 'SEO Pro', $args['name'] );
		$this->assertSame( '9.9.9', $args['version'] );
	}

	public function test_a_theme_snippet_is_valid_php_with_type_and_slug(): void {
		$code = Snippet::generate( 'theme', 'Neat', 'neat', 'https://shop.example.com', 9 );

		$this->assertParses( $code );
		$this->assertStringContainsString( 'get_template_directory()', $code );
		$this->assertStringNotContainsString( '__FILE__', $code );

		$args = $this->args( $code );
		$this->assertSame( 'theme', $args['type'] );
		$this->assertSame( 'neat', $args['slug'] );
		$this->assertSame( '9.9.9', $args['version'] );
	}

	public function test_the_snippet_is_accepted_by_the_real_client_configuration(): void {
		$plugin             = $this->args( Snippet::generate( 'plugin', 'SEO Pro', 'seo-pro', 'https://shop.example.com/', 7 ) );
		$plugin['basename'] = 'seo-pro/seo-pro.php';
		$config             = Config::from_array( $plugin );

		$this->assertSame( 'https://shop.example.com', $config->server );
		$this->assertSame( 7, $config->product_id );
		$this->assertSame( 'SEO Pro', $config->name );
		$this->assertSame( 'plugin', $config->type );
		$this->assertSame( 'seo-pro', $config->slug );

		$theme  = Config::from_array( $this->args( Snippet::generate( 'theme', 'Neat', 'neat', 'https://shop.example.com', 9 ) ) );
		$this->assertSame( 'theme', $theme->type );
		$this->assertSame( 'neat', $theme->slug );
		$this->assertSame( 9, $theme->product_id );
	}

	/**
	 * @dataProvider hostile_names
	 */
	public function test_awkward_names_still_give_valid_code_and_survive_unchanged( string $name, string $expected ): void {
		foreach ( array( 'plugin', 'theme' ) as $type ) {
			$code = Snippet::generate( $type, $name, 'x', 'https://shop.example.com', 1 );

			$this->assertParses( $code );
			$this->assertSame( $expected, $this->args( $code )['name'] );
		}
	}

	/**
	 * @return array<string, array{string,string}>
	 */
	public function hostile_names(): array {
		return array(
			'apostrophe'       => array( "Bob's Plugin", "Bob's Plugin" ),
			'double quotes'    => array( 'The "Pro" Plugin', 'The "Pro" Plugin' ),
			'backslash'        => array( 'A\\B', 'A\\B' ),
			'trailing slash'   => array( 'Ends with \\', 'Ends with \\' ),
			'dollar'           => array( 'Costs $5 {$x}', 'Costs $5 {$x}' ),
			'code injection'   => array( "x'); system('id'); //", "x'); system('id'); //" ),
			'php tag'          => array( 'a <?php echo 1; ?> b', 'a <?php echo 1; ?> b' ),
			'newline'          => array( "Line one\nLine two", 'Line one Line two' ),
			'unicode'          => array( 'Plügin 日本語', 'Plügin 日本語' ),
			'comment closer'   => array( '*/ evil /*', '*/ evil /*' ),
		);
	}

	public function test_a_hostile_server_value_stays_a_string(): void {
		$code = Snippet::generate( 'plugin', 'X', 'x', "https://a.com'); evil(); // x", 1 );

		$this->assertParses( $code );
		$this->assertSame( "https://a.com'); evil(); // x", $this->args( $code )['server'] );
	}

	/**
	 * @dataProvider slugs
	 */
	public function test_folder_names( string $slug, string $name, string $expected ): void {
		$this->assertSame( $expected, Snippet::clean_slug( $slug, $name ) );
	}

	/**
	 * @return array<string, array{string,string,string}>
	 */
	public function slugs(): array {
		return array(
			'normal'           => array( 'seo-pro', 'SEO Pro', 'seo-pro' ),
			'upper case'       => array( 'SEO_Pro', 'x', 'seo_pro' ),
			'unsafe characters' => array( '../etc/passwd', 'x', 'etc-passwd' ),
			'empty uses name'  => array( '', 'My Cool Plugin', 'my-cool-plugin' ),
			'nothing usable'   => array( '', '日本語', 'my-product' ),
			'long'             => array( str_repeat( 'a', 300 ), 'x', str_repeat( 'a', 100 ) ),
		);
	}

	/**
	 * @return array{has_package:bool,version:string,plan_count:int,created_count:int,tested_count:int,published_count:int,server_https:bool}
	 */
	private function facts( array $change = array() ): array {
		return array_merge(
			array(
				'has_package'     => false,
				'version'         => '',
				'plan_count'      => 0,
				'created_count'   => 0,
				'tested_count'    => 0,
				'published_count' => 0,
				'server_https'    => true,
			),
			$change
		);
	}

	/**
	 * @param array<int, array{done:bool,label:string,hint:string}> $items Checklist.
	 * @return bool[]
	 */
	private function done( array $items ): array {
		return array_column( $items, 'done' );
	}

	public function test_a_new_product_has_only_https_done(): void {
		$this->assertSame( array( false, false, false, true, false, false ), $this->done( Checklist::items( $this->facts() ) ) );
	}

	public function test_a_zip_without_a_version_does_not_count(): void {
		$this->assertFalse( Checklist::items( $this->facts( array( 'has_package' => true ) ) )[0]['done'] );
		$this->assertTrue( Checklist::items( $this->facts( array( 'has_package' => true, 'version' => '1.0' ) ) )[0]['done'] );
	}

	public function test_every_plan_has_to_be_created_tested_and_published(): void {
		$some = $this->facts( array( 'plan_count' => 2, 'created_count' => 1, 'tested_count' => 1, 'published_count' => 1 ) );
		$this->assertSame( array( false, true, false, true, false, false ), $this->done( Checklist::items( $some ) ) );

		$all = $this->facts( array( 'has_package' => true, 'version' => '1.0', 'plan_count' => 2, 'created_count' => 2, 'tested_count' => 2, 'published_count' => 2 ) );
		$this->assertSame( array( true, true, true, true, true, true ), $this->done( Checklist::items( $all ) ) );
	}

	public function test_a_plain_http_site_is_flagged(): void {
		$this->assertFalse( Checklist::items( $this->facts( array( 'server_https' => false ) ) )[3]['done'] );
	}

	public function test_every_item_says_what_to_do(): void {
		foreach ( Checklist::items( $this->facts() ) as $item ) {
			$this->assertTrue( '' !== $item['label'] && '' !== $item['hint'] );
		}
	}
}
