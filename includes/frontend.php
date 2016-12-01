<?php

function aw_register_assets() {
	$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

	// CSS.
	// wp_register_style( 'algolia-autocomplete', ALGOLIA_WOOCOMMERCE_URL . 'assets/css/algolia-autocomplete.css', array(), ALGOLIA_VERSION, 'screen' );
	wp_register_style( 'algolia-woocommerce-instantsearch', ALGOLIA_WOOCOMMERCE_URL . 'assets/css/algolia-woocommerce-instantsearch.css', array(), ALGOLIA_WOOCOMMERCE_VERSION, 'screen' );
	wp_register_style( 'algolia-woocommerce-selector', ALGOLIA_WOOCOMMERCE_URL . 'assets/css/selector.css', array(), ALGOLIA_WOOCOMMERCE_VERSION, 'screen' );

	wp_register_script( 'algolia-woocommerce-selector', ALGOLIA_WOOCOMMERCE_URL . 'assets/js/selector.js', array('jquery'), ALGOLIA_WOOCOMMERCE_VERSION );
}

add_action( 'init', 'aw_register_assets' );


function aw_template_loader( $template ) {
	if ( ! aw_should_display_instantsearch() ) {
		return $template;
	}

	// Avoid injecting instantsearch 2 times.
	add_filter( 'algolia_should_override_search_with_instantsearch', '__return_false' );
}

// Make sure this is called before the Algolia plugin for WordPress one so that we can disable the template overriding.
add_filter( 'template_include', 'aw_template_loader', 9 );

function aw_enqueue_script() {
	if ( aw_should_display_instantsearch() ) {
		wp_enqueue_script( 'jquery' );
		wp_enqueue_script( 'algolia-instantsearch' );
		wp_enqueue_script( 'wp-util' );
		wp_enqueue_style( 'algolia-woocommerce-instantsearch' );
		wp_add_inline_style( 'algolia-woocommerce-instantsearch', aw_get_user_styles() );
	}
}

add_action( 'wp_enqueue_scripts', 'aw_enqueue_script', 15 );

/**
 * Returns the user styles configured in the admin panel.
 *
 * @return string
 */
function aw_get_user_styles() {
	$primary_color = aw_get_primary_color();
	if ( empty( $primary_color ) ) {
		return '';
	}

	$styles = '.ais-range-slider--connect { background-color : ' .  $primary_color . '}';

	// Todo: add all the styles here.

	return $styles;
}

/**
 * @return bool
 */
function aw_should_display_instantsearch() {
	$pages = aw_get_pages();
	$should_display = false;
	if ( is_product_category() && in_array( 'category', $pages ) ) {
		$should_display = true;
	}

	if ( is_product_tag() && in_array( 'tag', $pages ) ) {
		$should_display = true;
	}

	if ( is_search() && in_array( 'search', $pages ) && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'product' ) {
		$should_display = true;
	}

	return (bool) apply_filters( 'algolia_wc_should_display_instantsearch', $should_display );
}

function aw_footer() {
	if ( aw_should_display_instantsearch() ) {
		include_once aw_plugin_path() . '/templates/woocommerce-instantsearch.php';
	}
}
add_action( 'wp_footer', 'aw_footer' );

/**
 * @param string $template
 * @param string $file
 *
 * @return string
 */
function aw_default_template( $template, $file ) {
	/*// Replace instantsearch.php template if we search for products.
	if( 'instantsearch.php' === $file && get_query_var( 'post_type' ) === 'product' ) {
		return aw_plugin_path() . '/templates/woocommerce-instantsearch.php';
	}

	// Provide with a store oriented autocomplete by default.
	// Todo: we should probably de-register default autocomplete CSS.
	if( 'autocomplete.php' === $file ) {
		return aw_plugin_path() . '/templates/' . $file;
	}*/

	return $template;
}

add_filter( 'algolia_default_template', 'aw_default_template', 9, 2 );

/**
 * Add WooCommerce configuration to the Algolia config var
 * So that the templates can use it more easily.
 *
 * @param array $config
 *
 * @return array
 */
function aw_woocommerce_config( array $config ) {
	$config['woocommerce']['currency_symbol'] = get_woocommerce_currency_symbol();
	$config['woocommerce']['selector'] = aw_get_selector();

	if(is_product_category()) {
		$config['woocommerce']['page'] = 'category';

		$category = get_queried_object();
		$category_full_path = Algolia_Utils::get_taxonomy_tree( array( $category ), 'product_cat' );
		$deepest_level = array_pop( $category_full_path );
		$config['woocommerce']['category'] = $deepest_level[0];
	} elseif(is_product_tag()) {
		$config['woocommerce']['page'] = 'tag';
		$tag = get_queried_object();
		$config['woocommerce']['tag'] = $tag->name;
	} elseif(is_search()) {
		$config['woocommerce']['page'] = 'search';
	}

	$algolia = Algolia_Plugin::get_instance();
	$index = $algolia->get_index( 'posts_product' );

	$config['woocommerce']['sort_by'] = array();
	if ( null === $index ) {
		return $config;
	}

	$replicas = $index->get_replicas();

	$mapping = aw_get_sort_by_mapping();

	$default_option = aw_get_default_order_by_option();

	foreach ( $replicas as $replica ) {
		/** @var Algolia_Index_Replica $replica */
		$replica_index_name = $replica->get_replica_index_name( $index );
		$replica_attribute_name = $replica->get_attribute_name();
		$replica_order = $replica->get_order();

		// Only add the replica as a sorting option if a mapping is found.
		foreach ( $mapping as $wc_key => $entry ) {
			if ( ! isset( $entry['attribute'] ) ) {
				continue;
			}

			if ( $entry['attribute'] !== $replica_attribute_name ) {
				continue;
			}

			if ( $replica_order === $entry['order'] ) {
				$config['woocommerce']['sort_by'][] = array(
					'index_name'   => $replica_index_name,
					'display_name' => $entry['display_name'],
					'order'        => $replica_order,
					'attribute'    => $replica_attribute_name,
				);

				if ( $default_option === $wc_key ) {
					$config['woocommerce']['default_index_name'] = $replica_index_name;
				}

				// Once we found the display name, we can break.
				break;
			}
		}
	}

	// Todo: get the default index from the WC config.
	// loop over all and add 'default' as boolean

	return $config;
}

add_filter( 'algolia_config', 'aw_woocommerce_config', 5 );

/**
 * @param array         $replicas
 * @param Algolia_Index $index
 *
 * @return array
 */
function aw_products_index_replicas( array $replicas, Algolia_Index $index ) {
	if ( 'posts_product' !== $index->get_id() ) {
		return $replicas;
	}

	$mapping = aw_get_sort_by_mapping();
	foreach ( $mapping as $sort ) {
		if ( ! isset( $sort['attribute'] ) ) {
			// No attribute means we are dealing with the master index.
			continue;
		}

		$order = isset( $sort['order'] ) ? $sort['order'] : 'desc';
		$replicas[] = new Algolia_Index_Replica( $sort['attribute'], $order );
	}

	return $replicas;
}

add_filter( 'algolia_index_replicas', 'aw_products_index_replicas', 10, 2 );

/**
 * @param array $settings
 *
 * @return array
 */
function aw_product_index_settings( array $settings ) {

	$settings['attributesForFaceting'][] = 'price';

	return $settings;
}

add_filter( 'algolia_posts_product_index_settings', 'aw_product_index_settings' );

/**
 * @return array
 */
function aw_get_catalog_order_by_options() {
	$options = (array) apply_filters( 'woocommerce_catalog_orderby', array(
		'menu_order' => __( 'Default sorting', 'woocommerce' ),
		'popularity' => __( 'Sort by popularity', 'woocommerce' ),
		'rating'     => __( 'Sort by average rating', 'woocommerce' ),
		'date'       => __( 'Sort by newness', 'woocommerce' ),
		'price'      => __( 'Sort by price: low to high', 'woocommerce' ),
		'price-desc' => __( 'Sort by price: high to low', 'woocommerce' )
	) );

	if ( 'menu_order' !== aw_get_default_order_by_option() ) {
		unset( $options['menu_order'] );
	}

	if ( 'no' === get_option( 'woocommerce_enable_review_rating' ) ) {
		unset( $options['rating'] );
	}

	return $options;
}

/**
 * @return array
 */
function aw_get_sort_by_mapping() {
	$mapping = array(
		'menu_order' => array(
			'attribute' 	=> 'menu_order',
			'order'	    	=> 'asc',
		),
		'popularity' => array(
			'attribute'    	=> 'total_sales',
			'order'		    => 'desc',
		),
		'rating'     => array(
			'attribute'    	=> 'average_rating',
			'order'		    => 'desc',
		),
		'date'       => array(
			'attribute' 	=> 'post_date',
			'order'		    => 'desc',
		),
		'price'      => array(
			'attribute' 	=> 'price',
			'order' 		=> 'asc',
		),
		'price-desc' => array(
			'attribute' 	=> 'price',
			'order'		    => 'desc',
		),
	);

	$mapping = (array) apply_filters( 'algolia_wc_sort_by_mapping', $mapping );

	$wc_options = aw_get_catalog_order_by_options();
	foreach ( $mapping as $key => &$entry ) {
		if ( ! isset( $wc_options[ $key ] ) ) {
			// If the option does not exist in WooCommerce, remove it from the mapping.
			unset( $mapping[$key] );

			continue;
		}

		// Get the display name from WooCommerce.
		$entry['display_name'] = $wc_options[ $key ];
	}

	return $mapping;
}

/**
 * @return string
 */
function aw_get_default_order_by_option() {
	return (string) apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby' ) );
}

add_action( 'init', function() {
	if (
		current_user_can( 'manage_options' ) &&
		isset( $_GET['algolia_selector'] ) &&
		$_GET['algolia_selector'] === 'true'
	) {
		show_admin_bar(false);

		// Make sure we don't inject instantsearch while choosing the selector.
		add_filter( 'algolia_wc_should_display_instantsearch', '__return_false', 30 );

		add_action( 'wp_enqueue_scripts', function() {
			wp_enqueue_script( 'algolia-woocommerce-selector' );
			wp_enqueue_style( 'algolia-woocommerce-selector' );
		} );
	}
} );
