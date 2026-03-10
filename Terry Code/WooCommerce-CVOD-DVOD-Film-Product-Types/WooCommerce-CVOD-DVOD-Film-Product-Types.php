<?php
/**
 * Plugin Name: WooCommerce - CVOD & DVOD Film Product Types
 * Description: Adds "CVOD Film" and "DVOD Film" product types. Applies Virtual, Tax None/Zero rate, In stock, Sold individually. Adds SEO helpers + Yoast sync + forced categories.
 * Version:     1.0
 * Author:      Rehman
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const CVOD_SLUG = 'cvod_film';
const DVOD_SLUG = 'dvod_film';


/*-------------------------------------------------------
ADD PRODUCT TYPES
-------------------------------------------------------*/
add_filter( 'product_type_selector', function( $types ) {

	$types[ CVOD_SLUG ] = __( 'CVOD Film', 'woocommerce' );
	$types[ DVOD_SLUG ] = __( 'DVOD Film', 'woocommerce' );

	return $types;

}, PHP_INT_MAX );


/*-------------------------------------------------------
MAP PRODUCT TYPE -> CLASS
-------------------------------------------------------*/
add_filter( 'woocommerce_product_class', function( $classname, $product_type ) {

	if ( $product_type === CVOD_SLUG ) {
		return 'WC_Product_CVOD_Film';
	}

	if ( $product_type === DVOD_SLUG ) {
		return 'WC_Product_DVOD_Film';
	}

	return $classname;

}, 10, 2 );


/*-------------------------------------------------------
DECLARE PRODUCT CLASSES
-------------------------------------------------------*/
add_action( 'woocommerce_loaded', function() {

	if ( class_exists( 'WC_Product_Simple' ) ) {

		if ( ! class_exists( 'WC_Product_CVOD_Film' ) ) {
			class WC_Product_CVOD_Film extends WC_Product_Simple {
				public function get_type() { return CVOD_SLUG; }
			}
		}

		if ( ! class_exists( 'WC_Product_DVOD_Film' ) ) {
			class WC_Product_DVOD_Film extends WC_Product_Simple {
				public function get_type() { return DVOD_SLUG; }
			}
		}

	}

});


/*-------------------------------------------------------
ADD TO CART SUPPORT
-------------------------------------------------------*/
add_action( 'woocommerce_' . CVOD_SLUG . '_add_to_cart', function() {
	wc_get_template( 'single-product/add-to-cart/simple.php' );
});

add_action( 'woocommerce_' . DVOD_SLUG . '_add_to_cart', function() {
	wc_get_template( 'single-product/add-to-cart/simple.php' );
});


/*-------------------------------------------------------
ADD TO CART URL FIX
-------------------------------------------------------*/
add_filter( 'woocommerce_product_add_to_cart_url', function( $url, $product ) {

	if ( $product && in_array( $product->get_type(), array(CVOD_SLUG, DVOD_SLUG), true ) ) {

		return $product->is_purchasable() && $product->is_in_stock()
			? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $product->get_id() ) )
			: get_permalink( $product->get_id() );
	}

	return $url;

}, 10, 2 );


/*-------------------------------------------------------
DATA STORE
-------------------------------------------------------*/
add_filter( 'woocommerce_data_stores', function( $stores ) {

	$stores[ 'product-' . CVOD_SLUG ] = 'WC_Product_Data_Store_CPT';
	$stores[ 'product-' . DVOD_SLUG ] = 'WC_Product_Data_Store_CPT';

	return $stores;

});


/*-------------------------------------------------------
SHOW SIMPLE FIELDS
-------------------------------------------------------*/
add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {

	foreach ( $tabs as $key => $tab ) {

		$classes = isset( $tab['class'] ) ? (array) $tab['class'] : array();

		if ( in_array( 'show_if_simple', $classes, true ) ) {

			$tabs[$key]['class'][] = 'show_if_cvod_film';
			$tabs[$key]['class'][] = 'show_if_dvod_film';

		}

	}

	return $tabs;

}, 1000 );


/*-------------------------------------------------------
SYNC UI
-------------------------------------------------------*/
add_action( 'admin_footer-post.php',     'vod_sync_show_classes', 1000 );
add_action( 'admin_footer-post-new.php', 'vod_sync_show_classes', 1000 );

function vod_sync_show_classes() {

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>

<script>

jQuery(function($){

$('.show_if_simple, .options_group.show_if_simple')
.addClass('show_if_cvod_film show_if_dvod_film');

$('#general_product_data, #inventory_product_data')
.addClass('show_if_cvod_film show_if_dvod_film');

});

</script>

<?php
}


/*-------------------------------------------------------
DEFAULT SETTINGS
-------------------------------------------------------*/
function vod_apply_defaults_on_object( WC_Product $product ) {

	if ( ! $product instanceof WC_Product ) return;

	if ( ! in_array( $product->get_type(), array(CVOD_SLUG, DVOD_SLUG), true ) ) return;

	$product->set_virtual( true );

	$product->set_tax_status( 'none' );
	$product->set_tax_class( 'zero-rate' );

	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );

	$product->set_sold_individually( true );

}

add_action( 'woocommerce_before_product_object_save',   'vod_apply_defaults_on_object', PHP_INT_MAX );
add_action( 'woocommerce_admin_process_product_object', 'vod_apply_defaults_on_object', PHP_INT_MAX );


/*-------------------------------------------------------
SAFETY META
-------------------------------------------------------*/
add_action( 'save_post_product', function( $post_id ) {

	$product = wc_get_product( $post_id );

	if ( ! $product ) return;

	if ( ! in_array( $product->get_type(), array(CVOD_SLUG, DVOD_SLUG), true ) ) return;

	update_post_meta( $post_id, '_virtual', 'yes' );
	update_post_meta( $post_id, '_tax_status', 'none' );
	update_post_meta( $post_id, '_tax_class', 'zero-rate' );
	update_post_meta( $post_id, '_sold_individually', 'yes' );
	update_post_meta( $post_id, '_stock_status', 'instock' );
	update_post_meta( $post_id, '_manage_stock', 'no' );

}, PHP_INT_MAX );


/*-------------------------------------------------------
SEO FIELD UI
-------------------------------------------------------*/
add_action( 'woocommerce_product_options_general_product_data', function() {

	echo '<div class="options_group show_if_cvod_film show_if_dvod_film">';

	woocommerce_wp_textarea_input( array(
		'id' => '_vod_seo_summary',
		'label' => __('SEO One-line Summary','woocommerce')
	));

	echo '</div>';

});


/*-------------------------------------------------------
YOAST SYNC
-------------------------------------------------------*/
add_action( 'save_post_product', function( $post_id ) {

	$product = wc_get_product( $post_id );

	if ( ! $product ) return;

	if ( ! in_array( $product->get_type(), array(CVOD_SLUG, DVOD_SLUG), true ) ) return;

	$title = $product->get_name();

	$summary = get_post_meta( $post_id, '_vod_seo_summary', true );

	$seo_title = 'Watch ' . $title . ' Movie Online %%page%% %%sep%% %%sitename%%';

	$seo_desc = 'Watch %%title%% movie online. ' . $summary;

	if ( defined('WPSEO_VERSION') ) {

		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $seo_desc );

	}

}, PHP_INT_MAX );


/*-------------------------------------------------------
FORCE CATEGORIES
-------------------------------------------------------*/
add_action( 'save_post_product', function( $post_id ) {

	$product = wc_get_product( $post_id );

	if ( ! $product ) return;

	$type = $product->get_type();

	if ( $type === CVOD_SLUG ) {

		$required = array('Cinema','Cinema - CVOD');

	}

	elseif ( $type === DVOD_SLUG ) {

		$required = array('Cinema','Cinema - DVOD');

	}

	else return;

	$ids = array();

	foreach ( $required as $name ) {

		$term = get_term_by( 'name', $name, 'product_cat' );

		if ( $term && ! is_wp_error($term) ) {

			$ids[] = (int)$term->term_id;

		}

	}

	if ( ! empty($ids) ) {

		$current = $product->get_category_ids();

		$merged = array_unique(array_merge($current,$ids));

		wp_set_object_terms( $post_id, $merged, 'product_cat' );

	}

}, PHP_INT_MAX );