<?php
/**
 * Plugin Name: WooCommerce Cinema Film Product Types
 * Description: Adds DVD Film, Blu-ray Film, and PVOD Film product types with SEO automation, Yoast sync, forced categories and admin UI controls.
 * Version: 3.0
 * Author: Rehman
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------
 CONSTANTS
------------------------------------------------------- */

const CINEMA_DVD   = 'dvd_film';
const CINEMA_BLURAY = 'bluray_film';
const CINEMA_PVOD  = 'pvod_film';


/* -------------------------------------------------------
 REGISTER PRODUCT TYPES
------------------------------------------------------- */

add_filter('product_type_selector', function($types){

	$types[CINEMA_DVD]   = __('DVD Film','woocommerce');
	$types[CINEMA_BLURAY] = __('Blu-ray Film','woocommerce');
	$types[CINEMA_PVOD]  = __('PVOD Film','woocommerce');

	return $types;

}, PHP_INT_MAX);



/* -------------------------------------------------------
 MAP PRODUCT CLASSES
------------------------------------------------------- */

add_filter('woocommerce_product_class', function($classname,$product_type){

	if($product_type === CINEMA_DVD) return 'WC_Product_DVD_Film';
	if($product_type === CINEMA_BLURAY) return 'WC_Product_Bluray_Film';
	if($product_type === CINEMA_PVOD) return 'WC_Product_PVOD_Film';

	return $classname;

},10,2);



/* -------------------------------------------------------
 DECLARE PRODUCT CLASSES
------------------------------------------------------- */

add_action('woocommerce_loaded', function(){

	if(class_exists('WC_Product_Simple')){

		if(!class_exists('WC_Product_DVD_Film')){
			class WC_Product_DVD_Film extends WC_Product_Simple{
				public function get_type(){ return CINEMA_DVD; }
			}
		}

		if(!class_exists('WC_Product_Bluray_Film')){
			class WC_Product_Bluray_Film extends WC_Product_Simple{
				public function get_type(){ return CINEMA_BLURAY; }
			}
		}

		if(!class_exists('WC_Product_PVOD_Film')){
			class WC_Product_PVOD_Film extends WC_Product_Simple{
				public function get_type(){ return CINEMA_PVOD; }
			}
		}

	}

});



/* -------------------------------------------------------
 DATA STORE
------------------------------------------------------- */

add_filter('woocommerce_data_stores', function($stores){

	$stores['product-'.CINEMA_DVD]   = 'WC_Product_Data_Store_CPT';
	$stores['product-'.CINEMA_BLURAY] = 'WC_Product_Data_Store_CPT';
	$stores['product-'.CINEMA_PVOD]  = 'WC_Product_Data_Store_CPT';

	return $stores;

});



/* -------------------------------------------------------
 SHOW SIMPLE UI FOR CUSTOM TYPES
------------------------------------------------------- */

add_filter('woocommerce_product_data_tabs', function($tabs){

	foreach($tabs as $key=>$tab){

		$classes = isset($tab['class']) ? (array)$tab['class'] : [];

		if(in_array('show_if_simple',$classes,true)){

			$tabs[$key]['class'][]='show_if_'.CINEMA_DVD;
			$tabs[$key]['class'][]='show_if_'.CINEMA_BLURAY;
			$tabs[$key]['class'][]='show_if_'.CINEMA_PVOD;

		}
	}

	return $tabs;

},1000);



/* -------------------------------------------------------
 PRODUCT DEFAULTS
------------------------------------------------------- */

function cinema_apply_defaults(WC_Product $product){

	if(!$product instanceof WC_Product) return;

	$type = $product->get_type();

	if($type===CINEMA_DVD || $type===CINEMA_BLURAY){

		$product->set_virtual(true);
		$product->set_tax_status('taxable');
		$product->set_tax_class('');
		$product->set_manage_stock(false);
		$product->set_stock_status('instock');

	}

	if($type===CINEMA_PVOD){

		$product->set_virtual(true);
		$product->set_tax_status('none');
		$product->set_tax_class('zero-rate');
		$product->set_manage_stock(false);
		$product->set_stock_status('instock');
		$product->set_sold_individually(true);

	}

}

add_action('woocommerce_before_product_object_save','cinema_apply_defaults',PHP_INT_MAX);
add_action('woocommerce_admin_process_product_object','cinema_apply_defaults',PHP_INT_MAX);



/* -------------------------------------------------------
 ADD TO CART FOR CUSTOM TYPES
------------------------------------------------------- */

add_action('woocommerce_'.CINEMA_DVD.'_add_to_cart',function(){
	wc_get_template('single-product/add-to-cart/simple.php');
});

add_action('woocommerce_'.CINEMA_PVOD.'_add_to_cart',function(){
	wc_get_template('single-product/add-to-cart/simple.php');
});



/* -------------------------------------------------------
 SAFE SUBSTRING
------------------------------------------------------- */

function cinema_plugin_safe_substr($text,$length=200){

	$text = wp_strip_all_tags($text);
	$text = preg_replace('/\s+/',' ',trim($text));

	if(function_exists('mb_substr')){
		return mb_substr($text,0,$length);
	}

	return substr($text,0,$length);

}



/* -------------------------------------------------------
 YOAST SEO + CATEGORY AUTOMATION
------------------------------------------------------- */

add_action('save_post_product',function($post_id){

	$product = wc_get_product($post_id);
	if(!$product) return;

	$type = $product->get_type();
	$film = trim(preg_replace('/\s+(DVD|Blu-ray)$/i','',$product->get_name()));

	$seo_title = '';
	$meta_desc = '';

	if($type===CINEMA_DVD){

		$seo_title = $film.' DVD %%page%% %%sep%% %%sitename%%';
		$meta_desc = 'Buy %%title%% DVD. '.cinema_plugin_safe_substr($product->get_description());

		$required = ['Cinema','Cinema - DVD'];

	}

	if($type===CINEMA_BLURAY){

		$seo_title = $film.' Blu-ray %%page%% %%sep%% %%sitename%%';
		$meta_desc = 'Buy %%title%% Blu-ray. '.cinema_plugin_safe_substr($product->get_description());

		$required = ['Cinema','Cinema - Blu-ray'];

	}

	if($type===CINEMA_PVOD){

		$seo_title = 'Watch '.$film.' Movie Online %%page%% %%sep%% %%sitename%%';
		$meta_desc = 'Watch %%title%% movie online. '.cinema_plugin_safe_substr($product->get_description());

		$required = ['Cinema','Cinema - PVOD'];

	}

	if(isset($seo_title)){

		update_post_meta($post_id,'_yoast_wpseo_title',$seo_title);
		update_post_meta($post_id,'_yoast_wpseo_metadesc',$meta_desc);

	}

	if(!empty($required)){

		$ids=[];

		foreach($required as $name){

			$term=get_term_by('name',$name,'product_cat');

			if($term && !is_wp_error($term)){
				$ids[]=(int)$term->term_id;
			}

		}

		if($ids){

			$current=$product->get_category_ids();

			wp_set_object_terms(
				$post_id,
				array_unique(array_merge($current,$ids)),
				'product_cat'
			);

		}

	}

},PHP_INT_MAX);



/* -------------------------------------------------------
 WC VENDORS PRICE FIELD FIX
------------------------------------------------------- */

add_filter('wcv_product_meta_tabs',function($tabs){

	if(!isset($tabs['general'])) return $tabs;

	if(!isset($tabs['general']['fields'])){
		$tabs['general']['fields']=[];
	}

	$tabs['general']['fields']['_regular_price']=[
		'label'=>__('Regular Price','woocommerce'),
		'type'=>'number',
		'class'=>'wc_input_price short',
		'custom_attributes'=>[
			'step'=>'0.01',
			'min'=>'0'
		]
	];

	return $tabs;

},20);



/* -------------------------------------------------------
 ADMIN LAYOUT FIX
------------------------------------------------------- */

add_action('admin_head',function(){

	$screen = function_exists('get_current_screen') ? get_current_screen() : null;
	if(!$screen) return;

	if(!in_array($screen->post_type,['product','shop_coupon'],true)) return;

	echo '<style>
	#woocommerce-product-data .woocommerce_options_panel{
	width:80%;
	float:right;
	}
	</style>';

});