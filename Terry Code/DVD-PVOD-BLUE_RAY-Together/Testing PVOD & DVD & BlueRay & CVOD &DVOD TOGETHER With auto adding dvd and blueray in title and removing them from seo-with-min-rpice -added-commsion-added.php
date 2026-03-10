/* -------------------------------------------------------
 CINEMA PRODUCT SYSTEM
 Supports:
 - DVD
 - PVOD
 - BLURAY
 - CVOD
 - DVOD
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
 PRICE PARSER
------------------------------------------------------- */

function cinema_parse_price($value){

    $value = (string)$value;
    $value = trim($value);

    if($value==='') return null;

    $value = preg_replace('/[^0-9,\.]/','',$value);

    if($value==='') return null;

    if(strpos($value,',')!==false && strpos($value,'.')===false){
        $value = str_replace(',','.', $value);
    }else{
        $value = str_replace(',','',$value);
    }

    if(!is_numeric($value)) return null;

    return (float)$value;

}


/* -------------------------------------------------------
 REQUIRED CATEGORY MAP
------------------------------------------------------- */

function cinema_get_required_cat_ids($type){

    $map = array(

        'dvd_film'    => array('cinema','cinema-dvd'),
        'pvod_film'   => array('cinema','cinema-pvod'),
        'bluray_film' => array('cinema','cinema-bluray'),
        'cvod_film'   => array('cinema','cinema-cvod'),
        'dvod_film'   => array('cinema','cinema-dvod'),

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
    $types['cvod_film']   = __('CVOD Film','cinema-domain');
    $types['dvod_film']   = __('DVOD Film','cinema-domain');

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

    if(!in_array($type,array('dvd_film','pvod_film','bluray_film','cvod_film','dvod_film'),true)){
        return;
    }


    /* -------------------------------------------------------
     PRICE RULES
    ------------------------------------------------------- */

    $raw_regular = $_POST['_regular_price'] ?? '';
    $regular     = cinema_parse_price(wp_unslash($raw_regular));

    if($type === 'pvod_film'){

        $min_price = 4.99;

        if($regular === null || $regular < $min_price){

            wp_die(
                esc_html('PVOD Film requires a Regular Price of $4.99 or higher.'),
                esc_html('Invalid price'),
                array('response'=>400)
            );

        }

    }

    if($type === 'cvod_film'){

        $fixed_price = 2.99;

        if($regular === null || (float)$regular !== (float)$fixed_price){

            wp_die(
                esc_html('CVOD Film must have a fixed Regular Price of $2.99.'),
                esc_html('Invalid price'),
                array('response'=>400)
            );

        }

    }

    if($type === 'dvod_film'){

        $fixed_price = 1.99;

        if($regular === null || (float)$regular !== (float)$fixed_price){

            wp_die(
                esc_html('DVOD Film must have a fixed Regular Price of $1.99.'),
                esc_html('Invalid price'),
                array('response'=>400)
            );

        }

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

    if($type === 'pvod_film' || $type === 'cvod_film' || $type === 'dvod_film'){

        update_post_meta($post_id,'_sold_individually','yes');
        update_post_meta($post_id,'_tax_status','none');
        update_post_meta($post_id,'_tax_class','zero-rate');

  /*      if($type === 'pvod_film'){

           
            update_post_meta($post_id,'_wcv_product_commission_type','percent');
            update_post_meta($post_id,'_wcv_product_commission',90);

            update_post_meta($post_id,'wcv_commission_type','percent');
            update_post_meta($post_id,'wcv_commission_percent',90);

        }   */

/* -------------------------------------------------------
 COMMISSION RULES
------------------------------------------------------- */

$commission = null;

if($type === 'pvod_film'){
    $commission = 80;
}

if($type === 'cvod_film'){
    $commission = 80;
}

if($type === 'dvod_film'){
    $commission = 80;
}

if($type === 'dvd_film'){
    $commission = 90;
}

if($commission !== null){

    update_post_meta($post_id,'_wcv_product_commission_type','percent');
    update_post_meta($post_id,'_wcv_product_commission',$commission);

    /* WC Vendors Admin UI */
    update_post_meta($post_id,'wcv_commission_type','percent');
    update_post_meta($post_id,'wcv_commission_percent',$commission);

}

    }else{

        update_post_meta($post_id,'_tax_status','taxable');
        update_post_meta($post_id,'_tax_class','');

    }


    /* -------------------------------------------------------
     REFRESH PRODUCT OBJECT AFTER POSSIBLE TITLE UPDATE
    ------------------------------------------------------- */

    clean_post_cache($post_id);
    $product = wc_get_product($post_id);

    $film_name     = $product ? $product->get_name() : get_the_title($post_id);
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

    if($type === 'cvod_film'){
        $seo_title = $title_override ?: 'Watch '.$seo_film_name.' Movie Online %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Watch '.$seo_film_name.' movie online';
    }

    if($type === 'dvod_film'){
        $seo_title = $title_override ?: 'Watch '.$seo_film_name.' Movie Online %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Watch '.$seo_film_name.' movie online';
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

        'dvdCats'        => cinema_get_required_cat_ids('dvd_film'),
        'pvodCats'       => cinema_get_required_cat_ids('pvod_film'),
        'blurayCats'     => cinema_get_required_cat_ids('bluray_film'),
        'cvodCats'       => cinema_get_required_cat_ids('cvod_film'),
        'dvodCats'       => cinema_get_required_cat_ids('dvod_film'),
        'minPVODPrice'   => 4.99,
        'fixedCVODPrice' => 2.99,
        'fixedDVODPrice' => 1.99

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
function isCVOD(){ return getType()==='cvod_film'; }
function isDVOD(){ return getType()==='dvod_film'; }

function setSubmitEnabled(state){

    $('#product_save_button,#draft_button').prop('disabled',!state);

}

function forcePricing(){

    if(!isDVD() && !isPVOD() && !isBluray() && !isCVOD() && !isDVOD()) return;

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
    if(isCVOD()) ids = CINEMA_CFG.cvodCats;
    if(isDVOD()) ids = CINEMA_CFG.dvodCats;

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

function parsePrice(val){

    val = String(val || '').trim();

    if(!val) return NaN;

    val = val.replace(/[^0-9\\.]/g,'');

    let num = parseFloat(val);

    return isNaN(num) ? NaN : num;

}

function ensurePriceNotice(){

    let box = $('#cinema-price-error');

    if(box.length) return box;

    box = $('<div/>',{
        id:'cinema-price-error',
        css:{
            marginTop:'8px',
            padding:'8px 10px',
            border:'1px solid #d63638',
            background:'#fff5f5',
            color:'#b32d2e',
            fontSize:'13px',
            display:'none'
        },
        text:''
    });

    $('#_regular_price').closest('.control-group,.form-group,.wcv-field').append(box);

    return box;

}

function validatePrice(){
    if(!$('#_regular_price').length){
    return true;
}
    const notice = ensurePriceNotice();

    if(!isPVOD() && !isCVOD() && !isDVOD()){

        notice.hide();
        setSubmitEnabled(true);
        return true;

    }

    let raw   = $('#_regular_price').val();
    let price = parsePrice(raw);
    let ok    = true;
    let text  = '';

    if(isPVOD()){

        let min = parseFloat(CINEMA_CFG.minPVODPrice);

        ok   = !isNaN(price) && price >= min;
        text = 'PVOD Film requires a minimum price of $' + min.toFixed(2);

    }

    if(isCVOD()){

        let fixed = parseFloat(CINEMA_CFG.fixedCVODPrice);

        ok   = !isNaN(price) && price === fixed;
        text = 'CVOD Film must have a fixed price of $' + fixed.toFixed(2);

    }

    if(isDVOD()){

        let fixed = parseFloat(CINEMA_CFG.fixedDVODPrice);

        ok   = !isNaN(price) && price === fixed;
        text = 'DVOD Film must have a fixed price of $' + fixed.toFixed(2);

    }

    if(ok){

        notice.hide();
        setSubmitEnabled(true);

    }else{

        notice.text(text).show();
        setSubmitEnabled(false);

    }

    return ok;

}

function runAll(){

    forcePricing();
    hideSchedule();
    lockCategories();
    validatePrice();

}

runAll();

$(document).on(
    'change input',
    '#product-type,#product_type,select[name=\"product-type\"],select[name=\"product_type\"]',
    function(){
        setSubmitEnabled(true);
        setTimeout(runAll,200);
    }
);

$(document).on('input','#_regular_price',function(){
    validatePrice();
});

$(document).on('submit','#wcv-product-edit',function(e){

    if(!validatePrice()){

        e.preventDefault();
        return false;

    }

});

/* Mutation observer fixing WC Vendors hiding fields */

const target = document.querySelector('#product-meta-accordion');

if(target && window.MutationObserver){

    let running = false;

    const observer = new MutationObserver(function(){

        if(running) return;

        running = true;

        setTimeout(function(){

            forcePricing();
            hideSchedule();
            validatePrice();

            running = false;

        },150);

    });

    observer.observe(target,{
        childList:true,
        subtree:true
    });

}

});
");
}


/* -------------------------------------------------------
 OPTIONAL ADMIN NOTICE FOR PVOD COMMISSION DEBUG
------------------------------------------------------- */

add_action('admin_notices', function(){

    if(!isset($_GET['post'])) return;

    $post_id = intval($_GET['post']);

    $type  = get_post_meta($post_id,'_wcv_product_commission_type',true);
    $value = get_post_meta($post_id,'_wcv_product_commission',true);

    echo '<div class=\"notice notice-success\"><p>';
    echo 'Commission type: <strong>'.esc_html($type).'</strong><br>';
    echo 'Commission value: <strong>'.esc_html($value).'</strong>';
    echo '</p></div>';

});