/* -------------------------------------------------------
 CINEMA PRODUCT SYSTEM
 Supports:
 - DVD
 - PVOD
 - BLURAY
------------------------------------------------------- */


/* -------------------------------------------------------
 SAFE SUBSTRING
------------------------------------------------------- */

function cinema_safe_substr($text,$start,$length){

    return function_exists('mb_substr')
        ? mb_substr($text,$start,$length)
        : substr($text,$start,$length);

}


/* -------------------------------------------------------
 REQUIRED CATEGORY MAP
------------------------------------------------------- */

function cinema_get_required_cat_ids($type){

    $map = array(

        'dvd_film'    => array('cinema','cinema-dvd'),
        'pvod_film'   => array('cinema','cinema-pvod'),
        'bluray_film' => array('cinema','cinema-bluray'),

    );

    $slugs = $map[$type] ?? array();
    $ids   = array();

    foreach($slugs as $slug){

        $term = get_term_by('slug',''.$slug.'','product_cat');

        if($term && !is_wp_error($term)){
            $ids[] = (int)$term->term_id;
        }

    }

    return array_values(array_unique(array_filter($ids)));

}


/* -------------------------------------------------------
 ADD PRODUCT TYPES
------------------------------------------------------- */

add_filter('wcv_product_type_selector','cinema_add_product_types',20);

function cinema_add_product_types($types){

    $types['dvd_film']    = __('DVD','cinema-domain');
    $types['pvod_film']   = __('PVOD Film','cinema-domain');
    $types['bluray_film'] = __('Blu-ray','cinema-domain');

    return $types;

}


/* -------------------------------------------------------
 NORMALIZE FILM TITLE FOR DISPLAY TITLE SAVE
------------------------------------------------------- */

function cinema_get_clean_base_title($title){

    $title = (string)$title;
    $title = trim($title);

    if($title === ''){
        return $title;
    }

    $title = preg_replace('/\s+(DVD|Blu-ray)$/i','',$title);

    return trim($title);

}


/* -------------------------------------------------------
 GET FINAL DISPLAY TITLE BY TYPE
------------------------------------------------------- */

function cinema_get_final_title_by_type($base_title,$type){

    $base_title = cinema_get_clean_base_title($base_title);

    if($type === 'dvd_film'){
        return trim($base_title.' DVD');
    }

    if($type === 'bluray_film'){
        return trim($base_title.' Blu-ray');
    }

    return $base_title;

}


/* -------------------------------------------------------
 GET SEO CLEAN TITLE
 Removes format suffix from title for SEO
------------------------------------------------------- */

function cinema_get_seo_clean_title($title){

    $title = (string)$title;
    $title = trim($title);

    if($title === ''){
        return $title;
    }

    $title = preg_replace('/\s+(DVD|Blu-ray)$/i','',$title);

    return trim($title);

}


/* -------------------------------------------------------
 SAVE PRODUCT LOGIC
------------------------------------------------------- */

add_action('wcv_save_product','cinema_save_logic',10);

function cinema_save_logic($post_id){

    $raw_type = $_POST['product-type'] ?? $_POST['product_type'] ?? '';

    if(!$raw_type){
        return;
    }

    $type = sanitize_title($raw_type);

    if(!in_array($type,array('dvd_film','pvod_film','bluray_film'),true)){
        return;
    }


    /* -------------------------------------------------------
     SET PRODUCT TYPE TERM
    ------------------------------------------------------- */

    wp_set_object_terms($post_id,$type,'product_type',false);


    /* -------------------------------------------------------
     AUTO APPEND FORMAT TO ACTUAL TITLE
    ------------------------------------------------------- */

    $current_title = get_the_title($post_id);
    $new_title     = cinema_get_final_title_by_type($current_title,$type);

    if($new_title && $new_title !== $current_title){

        remove_action('wcv_save_product','cinema_save_logic',10);

        wp_update_post(array(
            'ID'         => $post_id,
            'post_title' => $new_title,
            'post_name'  => sanitize_title($new_title),
        ));

        add_action('wcv_save_product','cinema_save_logic',10);
    }


    /* -------------------------------------------------------
     CORE SETTINGS
    ------------------------------------------------------- */

    update_post_meta($post_id,'_virtual','yes');
    update_post_meta($post_id,'_manage_stock','no');
    update_post_meta($post_id,'_stock_status','instock');

    if($type === 'pvod_film'){

        update_post_meta($post_id,'_sold_individually','yes');
        update_post_meta($post_id,'_tax_status','none');
        update_post_meta($post_id,'_tax_class','zero-rate');

    }else{

        update_post_meta($post_id,'_tax_status','taxable');
        update_post_meta($post_id,'_tax_class','');

    }


    /* -------------------------------------------------------
     REFRESH PRODUCT OBJECT AFTER POSSIBLE TITLE UPDATE
    ------------------------------------------------------- */

    clean_post_cache($post_id);
    $product = wc_get_product($post_id);

    $film_name = $product ? $product->get_name() : get_the_title($post_id);
    $seo_film_name = cinema_get_seo_clean_title($film_name);


    /* -------------------------------------------------------
     SEO FIELDS
    ------------------------------------------------------- */

    $title_override = wc_clean($_POST[$type.'_seo_title_override'] ?? '');
    $summary        = wc_clean($_POST[$type.'_seo_one_line_summary'] ?? '');

    $raw_desc = wp_strip_all_tags($product ? $product->get_description() : '');
    $raw_desc = preg_replace('/\s+/',' ',trim($raw_desc));

    if($summary === '' && $raw_desc !== ''){
        $summary = cinema_safe_substr($raw_desc,0,200);
    }

    update_post_meta($post_id,'_'.$type.'_seo_title_override',$title_override);
    update_post_meta($post_id,'_'.$type.'_seo_one_line_summary',$summary);


    if($type === 'dvd_film'){
        $seo_title = $title_override ?: $seo_film_name.' %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Buy '.$seo_film_name.' DVD';
    }

    if($type === 'pvod_film'){
        $seo_title = $title_override ?: 'Watch '.$seo_film_name.' Movie Online %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Watch '.$seo_film_name.' movie online';
    }

    if($type === 'bluray_film'){
        $seo_title = $title_override ?: $seo_film_name.' %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Buy '.$seo_film_name.' Blu-ray';
    }

    if($summary){
        $meta_desc .= '. '.$summary;
    }

    if(class_exists('WPSEO_Meta')){
        update_post_meta($post_id,'_yoast_wpseo_title',$seo_title);
        update_post_meta($post_id,'_yoast_wpseo_metadesc',$meta_desc);
    }


    /* -------------------------------------------------------
     FORCE CATEGORIES
    ------------------------------------------------------- */

    $required = cinema_get_required_cat_ids($type);

    if($required){

        $current = $product ? $product->get_category_ids() : array();

        wp_set_object_terms(
            $post_id,
            array_unique(array_merge($current,$required)),
            'product_cat'
        );

    }

}


/* -------------------------------------------------------
 FRONTEND JS FIX FOR WC VENDORS UI
------------------------------------------------------- */

add_action('wp_enqueue_scripts','cinema_frontend_js');

function cinema_frontend_js(){

    if(is_admin()){
        return;
    }

    wp_register_script('cinema-inline','',array('jquery'),null,true);
    wp_enqueue_script('cinema-inline');

    wp_localize_script('cinema-inline','CINEMA_CFG',array(

        'dvdCats'    => cinema_get_required_cat_ids('dvd_film'),
        'pvodCats'   => cinema_get_required_cat_ids('pvod_film'),
        'blurayCats' => cinema_get_required_cat_ids('bluray_film')

    ));

    wp_add_inline_script('cinema-inline',"

jQuery(function($){

function getType(){

    return $('#product-type,#product_type,select[name=\"product-type\"],select[name=\"product_type\"]')
        .first()
        .val();

}

function isDVD(){ return getType()==='dvd_film'; }
function isPVOD(){ return getType()==='pvod_film'; }
function isBluray(){ return getType()==='bluray_film'; }

function forcePricing(){

    if(!isDVD() && !isPVOD() && !isBluray()) return;

    $('.wcv-product-accordion').removeClass('hide-all').css('display','block');

    const general = $('#general');

    general.removeClass('is_hidden').css('display','block');

    const block = general.find('.show_if_simple.show_if_external').first();

    if(block.length){

        block.removeClass('is_hidden').css('display','block');
        block.find('.is_hidden').removeClass('is_hidden').show();

    }

    $('#_regular_price').removeClass('is_hidden').show();
    $('#_sale_price').removeClass('is_hidden').show();

}

function hideSchedule(){

    $('#general .sale_price_dates_fields').hide();

}

function lockCategories(){

    let ids = [];

    if(isDVD()) ids = CINEMA_CFG.dvdCats;
    if(isPVOD()) ids = CINEMA_CFG.pvodCats;
    if(isBluray()) ids = CINEMA_CFG.blurayCats;

    (ids || []).forEach(function(id){

        const cb = $('input[name=\"product_cat[]\"][value=\"'+id+'\"]').first();

        if(cb.length){

            cb.prop('checked',true)
              .prop('disabled',true)
              .closest('li,label,.wcv-field')
              .hide();

        }

    });

}

function runAll(){

    forcePricing();
    hideSchedule();
    lockCategories();

}

runAll();

$(document).on(
    'change input',
    '#product-type,#product_type,select[name=\"product-type\"],select[name=\"product_type\"]',
    function(){
        setTimeout(runAll,200);
    }
);

/* Mutation observer fixing WC Vendors hiding fields */

const target = document.querySelector('#product-meta-accordion');

if(target && window.MutationObserver){

    const observer = new MutationObserver(function(){

        forcePricing();
        hideSchedule();

    });

    observer.observe(target,{
        attributes:true,
        childList:true,
        subtree:true
    });

}

});
");
}