<?php
/**
 * WooCommerce Product Functions
 *
 * Functions for product specific things.
 *
 * @author 		WooThemes
 * @category 	Core
 * @package 	WooCommerce/Functions
 * @version     2.1.0
 */

/**
 * Main function for returning products, uses the WC_Product_Factory class.
 *
 * @param mixed $the_product Post object or post ID of the product.
 * @param array $args (default: array()) Contains all arguments to be used to get this product.
 * @return WC_Product
 */
function get_product( $the_product = false, $args = array() ) {
	return WC()->product_factory->get_product( $the_product, $args );
}

/**
 * Update a product's stock amount
 *
 * @param  int $product_id
 * @param  int $new_stock_level
 */
function wc_update_product_stock( $product_id, $new_stock_level ) {
	$product = get_product( $product_id );

	if ( $product->is_type( 'variation' ) )
		$product->set_stock( $new_stock_level, true );
	else
		$product->set_stock( $new_stock_level );
}

/**
 * Update a product's stock status
 *
 * @param  int $product_id
 * @param  int $status
 */
function wc_update_product_stock_status( $product_id, $status ) {
	$product = get_product( $product_id );
	$product-> set_stock_status( $status );
}

/**
 * Returns whether or not SKUS are enabled.
 * @return bool
 */
function wc_product_sku_enabled() {
	return apply_filters( 'wc_product_sku_enabled', true );
}

/**
 * Returns whether or not product weights are enabled.
 * @return bool
 */
function wc_product_weight_enabled() {
	return apply_filters( 'wc_product_weight_enabled', true );
}

/**
 * Returns whether or not product dimensions (HxWxD) are enabled.
 * @return bool
 */
function wc_product_dimensions_enabled() {
	return apply_filters( 'wc_product_dimensions_enabled', true );
}

/**
 * Clear all transients cache for product data.
 *
 * @param int $post_id (default: 0)
 */
function wc_delete_product_transients( $post_id = 0 ) {
	global $wpdb;

	$post_id = absint( $post_id );

	// Clear core transients
	$transients_to_clear = array(
		'wc_products_onsale',
		'wc_hidden_product_ids',
		'wc_hidden_product_ids_search',
		'wc_attribute_taxonomies',
		'wc_term_counts'
	);

	foreach( $transients_to_clear as $transient ) {
		delete_transient( $transient );
	}

	// Clear transients for which we don't have the name
	$wpdb->query( "DELETE FROM `$wpdb->options` WHERE `option_name` LIKE ('_transient_wc_uf_pid_%') OR `option_name` LIKE ('_transient_timeout_wc_uf_pid_%')" );
	$wpdb->query( "DELETE FROM `$wpdb->options` WHERE `option_name` LIKE ('_transient_wc_ln_count_%') OR `option_name` LIKE ('_transient_timeout_wc_ln_count_%')" );
	$wpdb->query( "DELETE FROM `$wpdb->options` WHERE `option_name` LIKE ('_transient_wc_ship_%') OR `option_name` LIKE ('_transient_timeout_wc_ship_%')" );

	// Clear product specific transients
	$post_transients_to_clear = array(
		'wc_product_children_ids_',
		'wc_product_total_stock_',
		'wc_average_rating_',
		'wc_rating_count_',
		'woocommerce_product_type_', // No longer used
		'wc_product_type_', // No longer used
	);

	if ( $post_id > 0 ) {

		foreach( $post_transients_to_clear as $transient ) {
			delete_transient( $transient . $post_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM `$wpdb->options` WHERE `option_name` = %s OR `option_name` = %s", '_transient_' . $transient . $post_id, '_transient_timeout_' . $transient . $post_id ) );
		}

		clean_post_cache( $post_id );

	} else {

		foreach( $post_transients_to_clear as $transient ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM `$wpdb->options` WHERE `option_name` LIKE %s OR `option_name` LIKE %s", '_transient_' . $transient . '%', '_transient_timeout_' . $transient . '%' ) );
		}

	}

	do_action( 'woocommerce_delete_product_transients', $post_id );
}

/**
 * Function that returns an array containing the IDs of the products that are on sale.
 *
 * @since 2.0
 * @access public
 * @return array
 */
function woocommerce_get_product_ids_on_sale() {
	global $wpdb;

	// Load from cache
	$product_ids_on_sale = get_transient( 'wc_products_onsale' );

	// Valid cache found
	if ( false !== $product_ids_on_sale )
		return $product_ids_on_sale;

	$on_sale_posts = $wpdb->get_results( "
		SELECT post.ID, post.post_parent FROM `$wpdb->posts` AS post
		LEFT JOIN `$wpdb->postmeta` AS meta ON post.ID = meta.post_id
		LEFT JOIN `$wpdb->postmeta` AS meta2 ON post.ID = meta2.post_id
		WHERE post.post_type IN ( 'product1', 'product_variation' )
			AND post.post_status = 'publish'
			AND meta.meta_key = '_sale_price'
			AND meta2.meta_key = '_price'
			AND CAST( meta.meta_value AS DECIMAL ) >= 0
			AND CAST( meta.meta_value AS CHAR ) != ''
			AND CAST( meta.meta_value AS DECIMAL ) = CAST( meta2.meta_value AS DECIMAL )
		GROUP BY post.ID;
	" );

	$product_ids_on_sale = array();

	foreach ( $on_sale_posts as $post ) {
		$product_ids_on_sale[] = $post->ID;
		$product_ids_on_sale[] = $post->post_parent;
	}

	$product_ids_on_sale = array_unique( $product_ids_on_sale );

	set_transient( 'wc_products_onsale', $product_ids_on_sale );

	return $product_ids_on_sale;
}

/**
 * Function that returns an array containing the IDs of the featured products.
 *
 * @since 2.1
 * @access public
 * @return array
 */
function woocommerce_get_featured_product_ids() {

	// Load from cache
	$featured_product_ids = get_transient( 'wc_featured_products' );

	// Valid cache found
	if ( false !== $featured_product_ids )
		return $featured_product_ids;

	$featured = get_posts( array(
		'post_type'      => array( 'product', 'product_variation' ),
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'meta_query'     => array(
			array(
				'key' 		=> '_visibility',
				'value' 	=> array('catalog', 'visible'),
				'compare' 	=> 'IN'
			),
			array(
				'key' 	=> '_featured',
				'value' => 'yes'
			)
		),
		'fields' => 'id=>parent'
	) );

	$product_ids          = array_keys( $featured );
	$parent_ids           = array_values( $featured );
	$featured_product_ids = array_unique( array_merge( $product_ids, $parent_ids ) );

	set_transient( 'wc_featured_products', $featured_product_ids );

	return $featured_product_ids;
}

/**
 * woocommerce_get_product_terms function.
 *
 * Gets product terms in the order they are defined in the backend.
 *
 * @access public
 * @param mixed $object_id
 * @param mixed $taxonomy
 * @param mixed $fields ids, names, slugs, all
 * @return array
 */
function woocommerce_get_product_terms( $object_id, $taxonomy, $fields = 'all' ) {

	if ( ! taxonomy_exists( $taxonomy ) )
		return array();

	$terms 			= array();
	$object_terms 	= get_the_terms( $object_id, $taxonomy );

	if ( ! is_array( $object_terms ) )
    	return array();

    $args = array( 'fields' => 'ids' );

	$orderby = wc_attribute_orderby( $taxonomy );

	switch ( $orderby ) {
		case 'name' :
			$args['orderby']    = 'name';
			$args['menu_order'] = false;
		break;
		case 'id' :
			$args['orderby']    = 'id';
			$args['order']      = 'ASC';
			$args['menu_order'] = false;
		break;
		case 'menu_order' :
			$args['menu_order'] = 'ASC';
		break;
	}

	$all_terms 		= array_flip( get_terms( $taxonomy, $args ) );

	switch ( $fields ) {
		case 'names' :
			foreach ( $object_terms as $term )
				$terms[ $all_terms[ $term->term_id ] ] = $term->name;
			break;
		case 'ids' :
			foreach ( $object_terms as $term )
				$terms[ $all_terms[ $term->term_id ] ] = $term->term_id;
			break;
		case 'slugs' :
			foreach ( $object_terms as $term )
				$terms[ $all_terms[ $term->term_id ] ] = $term->slug;
			break;
		case 'all' :
			foreach ( $object_terms as $term )
				$terms[ $all_terms[ $term->term_id ] ] = $term;
			break;
	}

	ksort( $terms );

	return $terms;
}

/**
 * Filter to allow product_cat in the permalinks for products.
 *
 * @access public
 * @param string $permalink The existing permalink URL.
 * @param object $post
 * @return string
 */
function woocommerce_product_post_type_link( $permalink, $post ) {
    // Abort if post is not a product
    if ( $post->post_type !== 'product' )
    	return $permalink;

    // Abort early if the placeholder rewrite tag isn't in the generated URL
    if ( false === strpos( $permalink, '%' ) )
    	return $permalink;

    // Get the custom taxonomy terms in use by this post
    $terms = get_the_terms( $post->ID, 'product_cat' );

    if ( empty( $terms ) ) {
    	// If no terms are assigned to this post, use a string instead (can't leave the placeholder there)
        $product_cat = _x( 'uncategorized', 'slug', 'woocommerce' );
    } else {
    	// Replace the placeholder rewrite tag with the first term's slug
        $first_term = array_shift( $terms );
        $product_cat = $first_term->slug;
    }

    $find = array(
    	'%year%',
    	'%monthnum%',
    	'%day%',
    	'%hour%',
    	'%minute%',
    	'%second%',
    	'%post_id%',
    	'%category%',
    	'%product_cat%'
    );

    $replace = array(
    	date_i18n( 'Y', strtotime( $post->post_date ) ),
    	date_i18n( 'm', strtotime( $post->post_date ) ),
    	date_i18n( 'd', strtotime( $post->post_date ) ),
    	date_i18n( 'H', strtotime( $post->post_date ) ),
    	date_i18n( 'i', strtotime( $post->post_date ) ),
    	date_i18n( 's', strtotime( $post->post_date ) ),
    	$post->ID,
    	$product_cat,
    	$product_cat
    );

    $replace = array_map( 'sanitize_title', $replace );

    $permalink = str_replace( $find, $replace, $permalink );

    return $permalink;
}
add_filter( 'post_type_link', 'woocommerce_product_post_type_link', 10, 2 );


/**
 * Get the placeholder image URL for products etc
 *
 * @access public
 * @return string
 */
function woocommerce_placeholder_img_src() {
	return apply_filters( 'woocommerce_placeholder_img_src', WC()->plugin_url() . '/assets/images/placeholder.png' );
}

/**
 * Get the placeholder image
 *
 * @access public
 * @return string
 */
function woocommerce_placeholder_img( $size = 'shop_thumbnail' ) {
	$dimensions = wc_get_image_size( $size );

	return apply_filters('woocommerce_placeholder_img', '<img src="' . woocommerce_placeholder_img_src() . '" alt="Placeholder" width="' . $dimensions['width'] . '" height="' . $dimensions['height'] . '" />' );
}

/**
 * Variation Formatting
 *
 * Gets a formatted version of variation data or item meta
 *
 * @access public
 * @param string $variation (default: '')
 * @param bool $flat (default: false)
 * @return string
 */
function woocommerce_get_formatted_variation( $variation = '', $flat = false ) {
	global $woocommerce;

	if ( is_array( $variation ) ) {

		if ( ! $flat )
			$return = '<dl class="variation">';
		else
			$return = '';

		$variation_list = array();

		foreach ( $variation as $name => $value ) {

			if ( ! $value )
				continue;

			// If this is a term slug, get the term's nice name
            if ( taxonomy_exists( esc_attr( str_replace( 'attribute_', '', $name ) ) ) ) {
            	$term = get_term_by( 'slug', $value, esc_attr( str_replace( 'attribute_', '', $name ) ) );
            	if ( ! is_wp_error( $term ) && $term->name )
            		$value = $term->name;
            }

			if ( $flat )
				$variation_list[] = wc_attribute_label(str_replace('attribute_', '', $name)).': '.$value;
			else
				$variation_list[] = '<dt>'.wc_attribute_label(str_replace('attribute_', '', $name)).':</dt><dd>'.$value.'</dd>';
		}

		if ( $flat )
			$return .= implode( ', ', $variation_list );
		else
			$return .= implode( '', $variation_list );

		if ( ! $flat )
			$return .= '</dl>';

		return $return;
	}
}

/**
 * Function which handles the start and end of scheduled sales via cron.
 *
 * @access public
 * @return void
 */
function woocommerce_scheduled_sales() {
	global $woocommerce, $wpdb;

	// Sales which are due to start
	$product_ids = $wpdb->get_col( $wpdb->prepare( "
		SELECT postmeta.post_id FROM {$wpdb->postmeta} as postmeta
		LEFT JOIN {$wpdb->postmeta} as postmeta_2 ON postmeta.post_id = postmeta_2.post_id
		LEFT JOIN {$wpdb->postmeta} as postmeta_3 ON postmeta.post_id = postmeta_3.post_id
		WHERE postmeta.meta_key = '_sale_price_dates_from'
		AND postmeta_2.meta_key = '_price'
		AND postmeta_3.meta_key = '_sale_price'
		AND postmeta.meta_value > 0
		AND postmeta.meta_value < %s
		AND postmeta_2.meta_value != postmeta_3.meta_value
	", current_time( 'timestamp' ) ) );

	if ( $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			$sale_price = get_post_meta( $product_id, '_sale_price', true );

			if ( $sale_price ) {
				update_post_meta( $product_id, '_price', $sale_price );
			} else {
				// No sale price!
				update_post_meta( $product_id, '_sale_price_dates_from', '' );
				update_post_meta( $product_id, '_sale_price_dates_to', '' );
			}

			wc_delete_product_transients( $product_id );

			$parent = wp_get_post_parent_id( $product_id );

			// Sync parent
			if ( $parent ) {
				// We can force varaible product price to sync up by removing their min price meta
				delete_post_meta( $parent, '_min_variation_price' );

				// Grouped products need syncing via a function
				$this_product = get_product( $product_id );
				if ( $this_product->is_type( 'simple' ) )
					$this_product->grouped_product_sync();

				wc_delete_product_transients( $parent );
			}
		}
	}

	// Sales which are due to end
	$product_ids = $wpdb->get_col( $wpdb->prepare( "
		SELECT postmeta.post_id FROM {$wpdb->postmeta} as postmeta
		LEFT JOIN {$wpdb->postmeta} as postmeta_2 ON postmeta.post_id = postmeta_2.post_id
		LEFT JOIN {$wpdb->postmeta} as postmeta_3 ON postmeta.post_id = postmeta_3.post_id
		WHERE postmeta.meta_key = '_sale_price_dates_to'
		AND postmeta_2.meta_key = '_price'
		AND postmeta_3.meta_key = '_regular_price'
		AND postmeta.meta_value > 0
		AND postmeta.meta_value < %s
		AND postmeta_2.meta_value != postmeta_3.meta_value
	", current_time( 'timestamp' ) ) );

	if ( $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			$regular_price = get_post_meta( $product_id, '_regular_price', true );

			update_post_meta( $product_id, '_price', $regular_price );
			update_post_meta( $product_id, '_sale_price', '' );
			update_post_meta( $product_id, '_sale_price_dates_from', '' );
			update_post_meta( $product_id, '_sale_price_dates_to', '' );

			wc_delete_product_transients( $product_id );

			$parent = wp_get_post_parent_id( $product_id );

			// Sync parent
			if ( $parent ) {
				// We can force variable product price to sync up by removing their min price meta
				delete_post_meta( $parent, '_min_variation_price' );

				// Grouped products need syncing via a function
				$this_product = get_product( $product_id );
				if ( $this_product->is_type( 'simple' ) )
					$this_product->grouped_product_sync();

				wc_delete_product_transients( $parent );
			}
		}
	}
}
add_action( 'woocommerce_scheduled_sales', 'woocommerce_scheduled_sales' );

/**
 * woocommerce_get_attachment_image_attributes function.
 *
 * @access public
 * @param mixed $attr
 * @return void
 */
function woocommerce_get_attachment_image_attributes( $attr ) {
	if ( strstr( $attr['src'], 'woocommerce_uploads/' ) )
		$attr['src'] = woocommerce_placeholder_img_src();

	return $attr;
}
add_filter( 'wp_get_attachment_image_attributes', 'woocommerce_get_attachment_image_attributes' );


/**
 * woocommerce_prepare_attachment_for_js function.
 *
 * @access public
 * @param mixed $response
 * @return void
 */
function woocommerce_prepare_attachment_for_js( $response ) {

	if ( isset( $response['url'] ) && strstr( $response['url'], 'woocommerce_uploads/' ) ) {
		$response['full']['url'] = woocommerce_placeholder_img_src();
		if ( isset( $response['sizes'] ) ) {
			foreach( $response['sizes'] as $size => $value ) {
				$response['sizes'][ $size ]['url'] = woocommerce_placeholder_img_src();
			}
		}
	}

	return $response;
}
add_filter( 'wp_prepare_attachment_for_js', 'woocommerce_prepare_attachment_for_js' );

/**
 * Track product views
 */
function woocommerce_track_product_view() {
	if ( ! is_singular( 'product' ) )
		return;

	global $post, $product;

	if ( empty( $_COOKIE['woocommerce_recently_viewed'] ) )
		$viewed_products = array();
	else
		$viewed_products = (array) explode( '|', $_COOKIE['woocommerce_recently_viewed'] );

	if ( ! in_array( $post->ID, $viewed_products ) )
		$viewed_products[] = $post->ID;

	if ( sizeof( $viewed_products ) > 15 )
		array_shift( $viewed_products );

	// Store for session only
	setcookie( "woocommerce_recently_viewed", implode( '|', $viewed_products ), 0, COOKIEPATH, COOKIE_DOMAIN, false, true );
}

add_action( 'template_redirect', 'woocommerce_track_product_view', 20 );
