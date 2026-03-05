
/* -------------------------------------------------------
 CINEMA PRODUCT TYPES
 DVD
 PVOD
 BLURAY
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
 CATEGORY MAP
------------------------------------------------------- */

function cinema_get_required_cat_ids($type){

    $map = array(

        'dvd_film'    => array('cinema','cinema-dvd'),
        'pvod_film'   => array('cinema','cinema-pvod'),
        'bluray_film' => array('cinema','cinema-bluray'),

    );

    $slugs = $map[$type] ?? array();

    $ids = array();

    foreach($slugs as $slug){

        $term = get_term_by('slug',$slug,'product_cat');

        if($term && !is_wp_error($term)){
            $ids[] = (int)$term->term_id;
        }

    }

    return array_values(array_unique(array_filter($ids)));

}


/* -------------------------------------------------------
 ADD PRODUCT TYPES TO WC VENDORS
------------------------------------------------------- */

add_filter('wcv_product_type_selector','cinema_add_product_types',20);

function cinema_add_product_types($types){

    $types['dvd_film']    = __('DVD','cinema-domain');
    $types['pvod_film']   = __('PVOD Film','cinema-domain');
    $types['bluray_film'] = __('Blu-ray','cinema-domain');

    return $types;

}


/* -------------------------------------------------------
 SAVE PRODUCT LOGIC
------------------------------------------------------- */

add_action('wcv_save_product','cinema_save_logic',10);

function cinema_save_logic($post_id){

    $raw_type = $_POST['product-type'] ?? $_POST['product_type'] ?? '';

    if(!$raw_type) return;

    $type = sanitize_title($raw_type);

    if(!in_array($type,array('dvd_film','pvod_film','bluray_film'))) return;


    /* PVOD MIN PRICE CHECK */

    if($type==='pvod_film'){

        $min_price = 4.99;

        $raw_regular = $_POST['_regular_price'] ?? '';
        $regular = cinema_parse_price(wp_unslash($raw_regular));

        if($regular===null || $regular < $min_price){

            wp_die(
                esc_html('PVOD Film requires a Regular Price of $4.99 or higher.'),
                esc_html('Invalid price'),
                array('response'=>400)
            );

        }

    }


    wp_set_object_terms($post_id,$type,'product_type',false);


    update_post_meta($post_id,'_virtual','yes');
    update_post_meta($post_id,'_manage_stock','no');
    update_post_meta($post_id,'_stock_status','instock');


    if($type==='pvod_film'){

        update_post_meta($post_id,'_sold_individually','yes');
        update_post_meta($post_id,'_tax_status','none');
        update_post_meta($post_id,'_tax_class','zero-rate');

    }else{

        update_post_meta($post_id,'_tax_status','taxable');
        update_post_meta($post_id,'_tax_class','');

    }


    /* SEO */

    $title_override = wc_clean($_POST[$type.'_seo_title_override'] ?? '');
    $summary        = wc_clean($_POST[$type.'_seo_one_line_summary'] ?? '');

    $product   = wc_get_product($post_id);
    $film_name = $product ? $product->get_name() : get_the_title($post_id);

    $raw_desc = wp_strip_all_tags($product ? $product->get_description() : '');
    $raw_desc = preg_replace('/\s+/',' ',trim($raw_desc));

    if($summary==='' && $raw_desc!==''){
        $summary = cinema_safe_substr($raw_desc,0,200);
    }

    update_post_meta($post_id,'_'.$type.'_seo_title_override',$title_override);
    update_post_meta($post_id,'_'.$type.'_seo_one_line_summary',$summary);


    if($type==='dvd_film'){
        $seo_title = $title_override ?: $film_name.' DVD %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Buy %%title%% DVD';
    }

    if($type==='pvod_film'){
        $seo_title = $title_override ?: 'Watch '.$film_name.' Movie Online %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Watch %%title%% movie online';
    }

    if($type==='bluray_film'){
        $seo_title = $title_override ?: $film_name.' Blu-ray %%page%% %%sep%% %%sitename%%';
        $meta_desc = 'Buy %%title%% Blu-ray';
    }

    if($summary){
        $meta_desc .= '. '.$summary;
    }

    if(class_exists('WPSEO_Meta')){
        update_post_meta($post_id,'_yoast_wpseo_title',$seo_title);
        update_post_meta($post_id,'_yoast_wpseo_metadesc',$meta_desc);
    }


    /* FORCE CATEGORIES */

    $required = cinema_get_required_cat_ids($type);

    if($required){

        $product = wc_get_product($post_id);

        $current = $product ? $product->get_category_ids() : array();

        wp_set_object_terms(
            $post_id,
            array_unique(array_merge($current,$required)),
            'product_cat'
        );

    }

}



/* -------------------------------------------------------
 FRONTEND JS
------------------------------------------------------- */

add_action('wp_enqueue_scripts','cinema_frontend_js');

function cinema_frontend_js(){

if(is_admin()) return;

wp_register_script('cinema-inline','',array('jquery'),null,true);
wp_enqueue_script('cinema-inline');

wp_localize_script('cinema-inline','CINEMA_CFG',array(

'dvdCats'=>cinema_get_required_cat_ids('dvd_film'),
'pvodCats'=>cinema_get_required_cat_ids('pvod_film'),
'blurayCats'=>cinema_get_required_cat_ids('bluray_film'),

'minPVODPrice'=>4.99

));


wp_add_inline_script('cinema-inline',"

jQuery(function($){

function log(){ console.log('[CINEMA]',...arguments); }

function getType(){

const t = $('#product-type,#product_type,select[name=\"product-type\"],select[name=\"product_type\"]').first().val();

log('getType',t);

return t;

}

function isDVD(){ return getType()==='dvd_film'; }
function isPVOD(){ return getType()==='pvod_film'; }
function isBluray(){ return getType()==='bluray_film'; }


function setSubmitEnabled(state){

$('#product_save_button,#draft_button').prop('disabled',!state);

log('Submit enabled',state);

}


/* FORCE PRICING */

function forcePricing(){

if(!isDVD() && !isPVOD() && !isBluray()) return;

log('forcePricing running');

$('.wcv-product-accordion').removeClass('hide-all').css('display','block');

const general=$('#general');

general.removeClass('is_hidden').css('display','block');

const block=general.find('.show_if_simple.show_if_external').first();

if(block.length){

block.removeClass('is_hidden').css('display','block');

block.find('.is_hidden').removeClass('is_hidden').show();

}

$('#_regular_price,#_sale_price').removeClass('is_hidden').show();

}


function hideSchedule(){

$('#general .sale_price_dates_fields').hide();

}


/* LOCK CATEGORIES */

function lockCategories(){

let ids=[];

if(isDVD()) ids=CINEMA_CFG.dvdCats;
if(isPVOD()) ids=CINEMA_CFG.pvodCats;
if(isBluray()) ids=CINEMA_CFG.blurayCats;

(ids||[]).forEach(function(id){

const cb=$('input[name=\"product_cat[]\"][value=\"'+id+'\"]').first();

if(cb.length){

cb.prop('checked',true)
.prop('disabled',true)
.closest('li,label,.wcv-field')
.hide();

}

});

}


/* PRICE PARSER */

function parsePrice(val){

val=String(val||'').trim();

if(!val) return NaN;

val=val.replace(/[^0-9\\.]/g,'');

let num=parseFloat(val);

return isNaN(num)?NaN:num;

}


/* PVOD VALIDATION */

function validatePVODPrice(){

if(!isPVOD()){

setSubmitEnabled(true);

return true;

}

let raw=$('#_regular_price').val();

let price=parsePrice(raw);

let min=parseFloat(CINEMA_CFG.minPVODPrice);

let ok=!isNaN(price)&&price>=min;

log('validate price',{raw,price,min,ok});

if(ok){
setSubmitEnabled(true);
}else{
setSubmitEnabled(false);
}

return ok;

}


/* RUN */

function runAll(){

forcePricing();
hideSchedule();
lockCategories();
validatePVODPrice();

}

runAll();


$(document).on('input','#_regular_price',function(){

validatePVODPrice();

});


$(document).on('change','#product-type,#product_type',function(){

log('Product type changed');

setSubmitEnabled(true);

setTimeout(runAll,200);

});


$(document).on('submit','#wcv-product-edit',function(e){

if(!validatePVODPrice()){

alert('PVOD Film requires a minimum price of $4.99');

e.preventDefault();

return false;

}

});


/* SAFE MUTATION OBSERVER */

let running=false;

const target=document.querySelector('#general');

if(target && window.MutationObserver){

const observer=new MutationObserver(function(){

if(running) return;

running=true;

setTimeout(function(){

log('MutationObserver tick');

forcePricing();
hideSchedule();

running=false;

},120);

});

observer.observe(target,{
childList:true,
subtree:true
});

}

});
");

}