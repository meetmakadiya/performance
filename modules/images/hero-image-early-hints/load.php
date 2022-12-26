<?php
/**
 * Module Name: Hero Image Early Hints
 * Description: Adds a 103 Early Hints response header to preload the hero image of any page.
 * Experimental: Yes
 *
 * @since n.e.x.t
 * @package performance-lab
 */

/**
 * Intercepts image rendered in content to detect what is most likely the hero image.
 *
 * @since n.e.x.t
 *
 * @param string $filtered_image The image tag.
 * @param string $context        The context of the image.
 * @return string The unmodified image tag.
 */
function perflab_hieh_img_tag_check( $filtered_image, $context ) {
	global $perflab_hieh_request_uri;

	if ( ! isset( $perflab_hieh_request_uri ) ) {
		return $filtered_image;
	}

	if ( 'the_content' !== $context && 'the_post_thumbnail' !== $context ) {
		return $filtered_image;
	}

	// Determining hero image relies on lazy loading logic.
	if ( ! wp_lazy_loading_enabled( 'img', $context ) ) {
		return $filtered_image;
	}

	if ( ! empty( $filtered_image ) && strpos( $filtered_image, 'loading="lazy"' ) === false ) {
		// In reality, this approach will not work well, because the image loaded may be one in `srcset` rather than
		// in `src`. However, Early Hints do not support `imagesrcset` and `imagesizes`, this is only supported in
		// a `preload` link tag (see https://html.spec.whatwg.org/multipage/semantics.html#early-hints:attr-link-imagesrcset).
		// This probably means at this point Early Hints can only be reasonably used for CSS or JS.
		if ( preg_match( '/ src="([^"]+)/', $filtered_image, $matches ) ) {
			update_option( 'perflab_hieh_' . md5( $perflab_hieh_request_uri ), $matches[1] );
		}
		remove_filter( 'wp_content_img_tag', 'perflab_hieh_img_tag_check' );
		remove_filter( 'post_thumbnail_html', 'perflab_hieh_post_thumbnail_html_check' );
	}

	return $filtered_image;
}

/**
 * Intercepts the post thumbnail HTML to detect what is most likely the hero image.
 *
 * @since n.e.x.t
 *
 * @param string $html The post thumbnail HTML.
 * @return string The unmodified thumbnail HTML.
 */
function perflab_hieh_post_thumbnail_html_check( $html ) {
	return perflab_hieh_img_tag_check( $html, 'the_post_thumbnail' );
}

/**
 * Adds hooks to detect hero image, based on current user permissions.
 *
 * To avoid this from running on any (unauthenticated) page load and thus avoid race conditions due to high traffic,
 * this logic should only be run when a user with capabilities to edit posts is logged-in.
 *
 * The current user is set before the 'init' action.
 *
 * @since n.e.x.t
 */
function perflab_hieh_add_hooks() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	add_filter( 'wp_content_img_tag', 'perflab_hieh_img_tag_check', 10, 2 );
	add_filter( 'post_thumbnail_html', 'perflab_hieh_post_thumbnail_html_check' );
}
add_action( 'init', 'perflab_hieh_add_hooks' );

/**
 * Checks the request URI and based on it attempts to send a 103 Early Hints header for the hero image.
 *
 * @since n.e.x.t
 */
function perflab_hieh_send_early_hints_header() {
	global $perflab_hieh_request_uri;

	// Bail if not a frontend request.
	if ( is_admin() || defined( 'XMLRPC_REQUEST' ) || defined( 'REST_REQUEST' ) || defined( 'MS_FILES_REQUEST' ) ) {
		return;
	}

	$perflab_hieh_request_uri = $_SERVER['REQUEST_URI'];

	$home_path = parse_url( home_url(), PHP_URL_PATH );
	if ( is_string( $home_path ) && '' !== $home_path ) {
		$home_path       = trim( $home_path, '/' );
		$home_path_regex = sprintf( '|^%s|i', preg_quote( $home_path, '|' ) );

		$perflab_hieh_request_uri = preg_replace( $home_path_regex, '', $perflab_hieh_request_uri );
		$perflab_hieh_request_uri = trim( $perflab_hieh_request_uri, '/' );
	}

	if ( empty( $perflab_hieh_request_uri ) ) {
		$perflab_hieh_request_uri = '/';
	}

	$hero_img_url = get_option( 'perflab_hieh_' . md5( $perflab_hieh_request_uri ) );
	if ( ! $hero_img_url ) {
		return;
	}

	status_header( 103 );
	header( "Link: <{$hero_img_url}>; rel=preload; as=image", false );

	// Fix WP core headers no longer being output because of its problematic `headers_sent()` checks.
	add_filter(
		'wp_headers',
		function( $headers ) {
			// Send headers on 'send_headers' early, since status header will still be sent by WP.
			add_action(
				'send_headers',
				function() use ( $headers ) {
					if ( isset( $headers['Last-Modified'] ) && false === $headers['Last-Modified'] ) {
						unset( $headers['Last-Modified'] );

						header_remove( 'Last-Modified' );
					}

					foreach ( (array) $headers as $name => $field_value ) {
						header( "{$name}: {$field_value}" );
					}
				},
				// phpcs:ignore PHPCompatibility.Constants.NewConstants.php_int_minFound
				PHP_INT_MIN
			);
			return $headers;
		}
	);
}
add_action( 'plugins_loaded', 'perflab_hieh_send_early_hints_header' );