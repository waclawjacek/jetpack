<?php
/**
 * Plugin Name: Asset CDN
 * Description: Speed up Javascript and CSS
 * Plugin URI: https://github.com/automattic/jetpack
 * Author: Automattic
 * Author URI: https://automattic.com
 * Version: 0.1.0
 * Text Domain: asset-cdn
 * Domain Path: /languages/
 * License: GPLv2 or later
 */

/**
 * TODO
 * - versioning (combine ver hashes) and cachebusting
 * - concat/minify/serve JS too
 * - asset inlining for smaller styles?
 * - critical CSS support?
 * - non-enqueued assets?
 */

class Asset_CDN {
	private static $__instance = null;

	private $cdn_server;
	private $concat_style_groups = array();
	private $concat_script_groups = array();
	private $inject_critical_css = false;

	/**
	 * Singleton implementation
	 *
	 * @return object
	 */
	public static function instance() {
		if ( ! is_a( self::$__instance, 'Asset_CDN' ) ) {
			self::$__instance = new Asset_CDN();
		}

		return self::$__instance;
	}

	public static function reset() {
		self::$__instance = null;
	}

	private function __construct() {
		$this->cdn_server = apply_filters( 'jetpack_asset_cdn_url', 'https://cdn.wpvm.io' );
		// $this->cdn_server = 'http://localhost:8090';

		// allow smaller CSS by only minifying assets on the page
		add_filter( 'jetpack_implode_frontend_css', '__return_false' );

		// rewrite CSS tags
		add_filter( 'script_loader_tag', array( $this, 'register_concat_scripts' ), -100, 3 );
		add_filter( 'style_loader_tag', array( $this, 'register_concat_styles' ), -100, 4 );

		add_action( 'wp_head', array( $this, 'render_concatenated_styles_head' ), PHP_INT_MAX );
		add_action( 'wp_head', array( $this, 'render_concatenated_scripts_head' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( $this, 'render_concatenated_styles_footer' ), PHP_INT_MAX );
		add_action( 'wp_footer', array( $this, 'render_concatenated_scripts_footer' ), PHP_INT_MAX );
	}

	/**
	 * Render functions
	 */

	function render_concatenated_styles_head() {
		$this->flush_concatenated_styles(0);
	}

	function render_concatenated_styles_footer() {
		$this->flush_concatenated_styles(1);
	}

	private function flush_concatenated_styles( $group ) {
		if ( ! isset( $this->concat_style_groups[ $group ] ) ) {
			return;
		}

		$style_groups = $this->concat_style_groups[ $group ];

		if ( empty( $style_groups ) ) {
			return;
		}

		// special URL to concatenation service
		global $wp_styles;
		$site_url = site_url();
		foreach( $style_groups as $media => $styles ) {
			$urls = array();
			$vers = array();

			foreach( $styles as $style ) {
				$urls[] = str_replace( untrailingslashit( $site_url ), '', $style->src );
				$vers[] = $style->ver ? $style->ver : $wp_styles->default_version;
			}

			$cdn_url = $this->cdn_server . '/css?b=' .
				urlencode( $site_url ) . '&' .
				http_build_query( array( 'f' => $urls ) ) . '&' .
				http_build_query( array( 'v' => $vers ) );
			// if we are injecting critical CSS, load the full CSS async

			if ( $this->inject_critical_css ) {
				echo '<link rel="preload" onload="this.rel=\'stylesheet\'" as="style" type="text/css" media="' . $media . '" href="' . esc_attr( $cdn_url ) . '"/>';
			} else {
				echo '<link rel="stylesheet" type="text/css" media="' . $media . '" href="' . esc_attr( $cdn_url ) . '"/>';
			}

			foreach( $styles as $style ) {
				if ( isset( $style->extra['concat-after'] ) && $style->extra['concat-after'] ) {
					printf( "<style id='%s-inline-css' type='text/css'>\n%s\n</style>\n", esc_attr( $style->handle ), implode( "\n", $style->extra['concat-after'] ) );
				}
			}
		}

		$this->concat_style_groups[ $group ] = array();
	}

	function render_concatenated_scripts_head() {
		$this->flush_concatenated_scripts( 0 );
	}

	function render_concatenated_scripts_footer() {
		$this->flush_concatenated_scripts( 1 );
	}

	private function flush_concatenated_scripts( $group ) {
		if ( ! isset( $this->concat_script_groups[ $group ] ) ) {
			return;
		}

		$scripts = $this->concat_script_groups[ $group ];

		if ( empty( $scripts ) ) {
			return;
		}

		// special URL to concatenation service
		global $wp_scripts;
		$site_url = site_url();
		$urls = array();
		$vers = array();

		foreach( $scripts as $script ) {
			$urls[] = str_replace( untrailingslashit( $site_url ), '', $script->src );
			$vers[] = $script->ver ? $script->ver : $wp_scripts->default_version;
			if ( isset( $script->extra['before'] ) && $script->extra['before'] ) {
				echo sprintf( "<script type='text/javascript'>\n%s\n</script>\n", $script->extra['before'] );
			}
		}

		$cdn_url = $this->cdn_server . '/js?b=' .
			urlencode( $site_url ) . '&' .
			http_build_query( array( 'f' => $urls ) ) . '&' .
			http_build_query( array( 'v' => $vers ) );

		// TODO: if there is NO inline or external script tags in the body, render async (maybe?)
		echo '<script type="text/javascript" src="' . esc_attr( $cdn_url ) . '"></script>';

		foreach( $scripts as $script ) {
			if ( isset( $script->extra['after'] ) && $script->extra['after'] ) {
				echo sprintf( "<script type='text/javascript'>\n%s\n</script>\n", $script->extra['after'] );
			}
		}

		$this->concat_script_groups[ $group ] = array();
	}

	/**
	 * Asset modification functions
	 */

	/**
	 * Scripts
	 */

	public function register_concat_scripts( $tag, $handle, $src ) {
		global $wp_scripts;

		// don't do admin for now
		if ( is_admin() || ! isset( $wp_scripts->registered[$handle] ) ) {
			return $tag;
		}

		$script = $wp_scripts->registered[$handle];

		if ( $this->should_concat_script( $script ) ) {
			$this->buffer_script( $script );
			return '';
		}

		// if this is a non-CDN script, and there are existing CDN scripts in this group, print them and reset the
		// array
		$group = isset( $script->extra['group'] ) ? $script->extra['group'] : 0;
		$this->flush_concatenated_scripts( $group );

		return $tag;
	}

	private function should_concat_script( $script ) {
		// only concat local scripts
		$is_local       = $this->is_local_url( $script->src );
		// don't concat conditional scripts
		$is_conditional = isset( $script->extra['conditional'] );
		return apply_filters( 'jetpack_perf_concat_script', $is_local && ! $is_conditional, $script->handle, $script->src );
	}

	private function buffer_script( $script ) {
		$group = isset( $script->extra['group'] ) ? $script->extra['group'] : 0;
		if ( ! isset( $this->concat_script_groups[$group] ) ) {
			$this->concat_script_groups[$group] = array();
		}
		$this->concat_script_groups[$group][] = $script;
	}

	/**
	 * Styles
	 */

	public function register_concat_styles( $tag, $handle, $href, $media ) {
		global $wp_styles;

		// don't do admin for now
		if ( is_admin() || ! isset( $wp_styles->registered[$handle] ) ) {
			return $tag;
		}

		$style = $wp_styles->registered[$handle];

		if ( $this->should_concat_style( $style ) ) {
			$this->buffer_style( $style );
			return '';
		}

		return $tag;
	}

	private function buffer_style( $style ) {
		$group = isset( $style->extra['group'] ) ? $style->extra['group'] : 0;
		$media = $style->args;

		// rename the 'after' code so that we can output it separately
		if ( isset( $style->extra['after'] ) ) {
			$style->extra['concat-after'] = $style->extra['after'];
			unset( $style->extra['after'] );
		}

		if ( ! $media ) {
			$media = 'all';
		}

		if ( ! isset( $this->concat_style_groups[$group] ) ) {
			$this->concat_style_groups[$group] = array();
		}

		if ( ! isset( $this->concat_style_groups[$group][$media] ) ) {
			$this->concat_style_groups[$group][$media] = array();
		}

		$this->concat_style_groups[$group][$media][] = $style;
	}

	private function should_concat_style( $style ) {
		// only concat local styles
		$is_local       = $this->is_local_url( $style->src );
		// don't concat conditional styles
		$is_conditional = isset( $style->extra['conditional'] );
		return apply_filters( 'jetpack_perf_concat_style', $is_local && ! $is_conditional, $style->handle, $style->src );
	}

	private function is_local_url( $url ) {
		$site_url = site_url();
		return ( strncmp( $url, '/', 1 ) === 0 && strncmp( $url, '//', 2 ) !== 0 )
			|| strpos( $url, $site_url ) === 0;
	}
}