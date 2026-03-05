<?php
/**
 * Plugin Name: WooCommerce - Blu-ray Film Product Type
 * Description: Blu-ray Film product type behaving like TVOD (virtual, no shipping UI) with different SEO and categories.
 * Version: 1.0.2
 * Author: Rehman
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const BLURAY_SLUG = 'bluray_film';

/* ----------------------------------------------------
 * 1. Add Product Type
 * ---------------------------------------------------- */
add_filter( 'product_type_selector', function( $types ) {
	$types[ BLURAY_SLUG ] = __( 'Blu-ray Film', 'woocommerce' );
	return $types;
}, PHP_INT_MAX );

/* ----------------------------------------------------
 * 2. Map to Simple
 * ---------------------------------------------------- */
add_filter( 'woocommerce_product_class', function( $classname, $product_type ) {
	if ( $product_type === BLURAY_SLUG ) {
		return 'WC_Product_Bluray_Film';
	}
	return $classname;
}, 10, 2 );

add_action( 'woocommerce_loaded', function() {
	if ( class_exists( 'WC_Product_Simple' ) && ! class_exists( 'WC_Product_Bluray_Film' ) ) {
		class WC_Product_Bluray_Film extends WC_Product_Simple {
			public function get_type() { return BLURAY_SLUG; }
		}
	}
});

/* ----------------------------------------------------
 * 3. Data Store
 * ---------------------------------------------------- */
add_filter( 'woocommerce_data_stores', function( $stores ) {
	$stores[ 'product-' . BLURAY_SLUG ] = 'WC_Product_Data_Store_CPT';
	return $stores;
});

/* ----------------------------------------------------
 * 4. Inherit Simple UI Tabs
 * ---------------------------------------------------- */
add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {
	foreach ( $tabs as $key => $tab ) {
		$classes = isset( $tab['class'] ) ? (array) $tab['class'] : array();
		if ( in_array( 'show_if_simple', $classes, true ) ) {
			$tabs[$key]['class'][] = 'show_if_bluray_film';
		}
	}
	return $tabs;
}, 1000 );

/* ----------------------------------------------------
 * 5. Sync Admin UI Classes
 * ---------------------------------------------------- */
add_action( 'admin_footer-post.php', 'bluray_sync_show_classes', 1000 );
add_action( 'admin_footer-post-new.php', 'bluray_sync_show_classes', 1000 );
function bluray_sync_show_classes() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){
		$('.show_if_simple, .options_group.show_if_simple').addClass('show_if_bluray_film');
		$('#general_product_data, #inventory_product_data').addClass('show_if_bluray_film');

		var $type = $('#product-type');
		if ($type.length) {
			$type.trigger('change');
			$(document.body).trigger('woocommerce-product-type-change', [ $type.val() ]);
		}
	});
	</script>
	<?php
}

/* ----------------------------------------------------
 * 6. Enforce Defaults (TVOD-style)
 * ---------------------------------------------------- */
function bluray_apply_defaults( WC_Product $product ) {

	if ( ! $product instanceof WC_Product ) return;
	if ( $product->get_type() !== BLURAY_SLUG ) return;

	$product->set_virtual( true );
	$product->set_tax_status( 'taxable' );
	$product->set_tax_class( '' ); // Standard
	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
}
add_action( 'woocommerce_before_product_object_save', 'bluray_apply_defaults', PHP_INT_MAX );
add_action( 'woocommerce_admin_process_product_object', 'bluray_apply_defaults', PHP_INT_MAX );

/* ----------------------------------------------------
 * 7. Safety Meta Backup
 * ---------------------------------------------------- */
add_action( 'save_post_product', function( $post_id ) {

	$product = wc_get_product( $post_id );
	if ( ! $product || $product->get_type() !== BLURAY_SLUG ) return;

	update_post_meta( $post_id, '_virtual', 'yes' );
	update_post_meta( $post_id, '_tax_status', 'taxable' );
	update_post_meta( $post_id, '_tax_class', '' );
	update_post_meta( $post_id, '_manage_stock', 'no' );
	update_post_meta( $post_id, '_stock_status', 'instock' );

}, PHP_INT_MAX );

/* ----------------------------------------------------
 * 8. Lock Fields + Hide Shipping
 * ---------------------------------------------------- */
add_action( 'admin_footer-post.php', 'bluray_admin_js', 1001 );
add_action( 'admin_footer-post-new.php', 'bluray_admin_js', 1001 );
function bluray_admin_js() {
	$screen = get_current_screen();
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){

		function isBluRay(){
			return $('#product-type').val() === '<?php echo BLURAY_SLUG; ?>';
		}

		function apply(){
			if (!isBluRay()) return;

			$('#_virtual').prop('checked', true).trigger('change').prop('disabled', true);
			$('select[name="_tax_status"]').val('taxable').trigger('change').prop('disabled', true);
			$('select[name="_tax_class"]').val('').trigger('change').prop('disabled', true);

			$('.woocommerce_product_data_tabs li#general_product_data_shipping').hide();
			$('#shipping_product_data').hide();
		}

		setTimeout(apply, 150);
		$('#product-type').on('change', function(){ setTimeout(apply,150); });
		$(document.body).on('woocommerce-product-type-change', function(){ setTimeout(apply,150); });

	});
	</script>
	<?php
}

add_action( 'admin_footer-post.php', 'bluray_seo_autofill_js', 1002 );
add_action( 'admin_footer-post-new.php', 'bluray_seo_autofill_js', 1002 );

function bluray_seo_autofill_js() {

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){

		function isBluRay(){
			return $('#product-type').val() === '<?php echo BLURAY_SLUG; ?>';
		}

		function clean(s){
			return (s || '').replace(/<[^>]*>/g,'').replace(/\s+/g,' ').trim();
		}

		function autoFillTitle(){
			if (!isBluRay()) return;

			var $title = $('#title');
			var $seo   = $('#_bluray_seo_title_override');

			if (!$title.length || !$seo.length) return;
			if (($seo.val() || '').trim() !== '') return;

			var filmName = clean($title.val());
			if (!filmName) return;

			$seo.val(filmName + ' Blu-ray %%page%% %%sep%% %%sitename%%');
		}

		function autoFillSummary(){
			if (!isBluRay()) return;

			var $sum = $('#_bluray_seo_one_line_summary');
			if (!$sum.length) return;
			if (($sum.val() || '').trim() !== '') return;

			var desc = '';

			// Classic editor
			if ($('#content').length) {
				desc = $('#content').val();
			}

			// Gutenberg fallback
			if (!desc && typeof wp !== 'undefined' && wp.data) {
				try {
					desc = wp.data.select('core/editor').getEditedPostContent();
				} catch(e){}
			}

			desc = clean(desc);

			if (!desc) return;

			$sum.val(desc.substring(0,200));
		}

		function runAll(){
			autoFillTitle();
			autoFillSummary();
		}

		// Initial load
		setTimeout(runAll, 150);

		// Title typing
		$('#title').on('input', autoFillTitle);

		// Description typing
		$('#content').on('input', autoFillSummary);

		// Product type change
		$('#product-type').on('change', function(){
			setTimeout(runAll,150);
		});

	});
	</script>
	<?php
}


/* ----------------------------------------------------
 * 9. Blu-ray SEO Fields (VISIBLE)
 * ---------------------------------------------------- */
add_action( 'woocommerce_product_options_general_product_data', function() {

	echo '<div class="options_group show_if_bluray_film">';

	woocommerce_wp_text_input( array(
		'id'          => '_bluray_seo_title_override',
		'label'       => __( 'Blu-ray SEO Title (optional)', 'woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Leave blank to auto-generate: [Film Name] Blu-ray %%page%% %%sep%% %%sitename%%', 'woocommerce' ),
	) );

	woocommerce_wp_textarea_input( array(
		'id'          => '_bluray_seo_one_line_summary',
		'label'       => __( 'Blu-ray SEO One-line Summary (optional)', 'woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Leave blank to auto-fill from description (first 200 characters).', 'woocommerce' ),
	) );

	echo '</div>';

}, 100 );

/* ----------------------------------------------------
 * 10. Save SEO Fields
 * ---------------------------------------------------- */
add_action( 'woocommerce_admin_process_product_object', function( $product ) {

	if ( ! $product || $product->get_type() !== BLURAY_SLUG ) return;

	$title_override = isset($_POST['_bluray_seo_title_override']) ? wc_clean( wp_unslash($_POST['_bluray_seo_title_override']) ) : '';
	$summary        = isset($_POST['_bluray_seo_one_line_summary']) ? wc_clean( wp_unslash($_POST['_bluray_seo_one_line_summary']) ) : '';

	$product->update_meta_data( '_bluray_seo_title_override', $title_override );
	$product->update_meta_data( '_bluray_seo_one_line_summary', $summary );

}, PHP_INT_MAX );

/* ----------------------------------------------------
 * 11. Yoast Sync + Force Categories
 * ---------------------------------------------------- */
function bluray_safe_substr( $text, $length = 200 ) {
	$text = wp_strip_all_tags( $text );
	$text = preg_replace( '/\s+/', ' ', trim( $text ) );
	return function_exists( 'mb_substr' )
		? mb_substr( $text, 0, $length )
		: substr( $text, 0, $length );
}

add_action( 'save_post_product', function( $post_id ) {

	$product = wc_get_product( $post_id );
	if ( ! $product || $product->get_type() !== BLURAY_SLUG ) return;

	$film_name      = $product->get_name();
	$title_override = get_post_meta( $post_id, '_bluray_seo_title_override', true );
	$summary        = get_post_meta( $post_id, '_bluray_seo_one_line_summary', true );

	if ( $summary === '' ) {
		$summary = bluray_safe_substr( $product->get_description() );
	}

	$seo_title = $title_override !== ''
		? $title_override
		: $film_name . ' Blu-ray %%page%% %%sep%% %%sitename%%';

	$meta_desc = 'Buy %%title%% Blu-ray.';
	if ( $summary ) $meta_desc .= ' ' . $summary;

	if ( class_exists( 'WPSEO_Meta' ) ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
	}

	$required_slugs = array( 'cinema', 'cinema-bluray' );
	$required_ids = array();

	foreach ( $required_slugs as $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$required_ids[] = (int) $term->term_id;
		}
	}

	if ( ! empty( $required_ids ) ) {
		$current = $product->get_category_ids();
		wp_set_object_terms(
			$post_id,
			array_unique( array_merge( $current, $required_ids ) ),
			'product_cat'
		);
	}

}, PHP_INT_MAX );