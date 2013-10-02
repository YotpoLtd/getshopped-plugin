<?php
/*
	Plugin Name: Yotpo Social Reviews for GetShopped
	Description: Yotpo Social Reviews helps GetShopped store owners generate a ton of reviews for their products. Yotpo is the only solution which makes it easy to share your reviews automatically to your social networks to gain a boost in traffic and an increase in sales.
	Author: Yotpo
	Version: 1.0.0
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=getshopped_plugin_page_link	
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_woocommerce&utm_medium=plugin_page_link&utm_campaign=getshopped_plugin_page_link
 */
include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
register_activation_hook(   __FILE__, 'gs_yotpo_activation' );
register_uninstall_hook( __FILE__, 'gs_yotpo_uninstall' );
register_deactivation_hook( __FILE__, 'gs_yotpo_deactivate' );
add_action('plugins_loaded', 'gs_yotpo_init');
add_action('init', 'gs_yotpo_redirect');
		
function gs_yotpo_init() {
	$is_admin = is_admin();	
	if($is_admin) {
		include( plugin_dir_path( __FILE__ ) . 'templates/gs-yotpo-settings.php');
		include(plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/Yotpo.php');
		add_action( 'admin_menu', 'gs_yotpo_admin_settings' );
	}
	$yotpo_settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());
	if(!empty($yotpo_settings['app_key']) && gs_yotpo_compatible()) {			
		if(!$is_admin) {
			add_action( 'wp_enqueue_scripts', 'gs_yotpo_load_js' );  
			add_action( 'template_redirect', 'gs_yotpo_front_end_init', 1);	
		}				
		elseif(!empty($yotpo_settings['secret'])) {
			add_action('wpsc_purchase_log_save', 'gs_yotpo_map');
		}				
	}			
}

function gs_yotpo_redirect() {
	if ( get_option('gs_yotpo_just_installed', false)) {
		delete_option('gs_yotpo_just_installed');
		wp_redirect( ( ( is_ssl() || force_ssl_admin() || force_ssl_login() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=getshopped-yotpo-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=getshopped-yotpo-settings-page' ) ) ) );
		exit;
	}	
}

function gs_yotpo_admin_settings() {
	add_action( 'admin_enqueue_scripts', 'gs_yotpo_admin_styles' );	
	$page = add_menu_page( 'Yotpo', 'Yotpo', 'manage_options', 'getshopped-yotpo-settings-page', 'gs_display_yotpo_admin_page', 'none', null );			
}

function gs_yotpo_front_end_init() {	
	$settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());
	add_action('wpsc_transaction_results_shutdown', 'gs_yotpo_conversion_track');

	if($settings['bottom_line_enabled_product']) {	
		add_filter('wpsc_the_product_price_display_price_class', 'gs_yotpo_show_bottomline');
		wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
	}
	
	if (get_post_type() == 'wpsc-product'  && is_single()) {

		$widget_location = $settings['widget_location'];	
		if($settings['disable_native_review_system']) {
			add_filter('comments_open', 'gs_yotpo_remove_native_review_system', null, 2);
		}						
		if($widget_location == 'footer') {		
			// var_dump('hello');
			add_action('wpsc_theme_footer', 'gs_yotpo_show_widget', 10);
			// add_action('woocommerce_after_single_product', 'wc_yotpo_show_widget', 10);  TODO find the appropriate action to show the widget in
		}
		elseif($widget_location == 'tab') {
			// add_action('woocommerce_product_tabs', 'wc_yotpo_show_widget_in_tab');	TODO find the appropriate action to show widget in tab
		}
	}
	elseif ($settings['bottom_line_enabled_category']) {
		// add_action('woocommerce_after_shop_loop_item_title', 'wc_yotpo_show_buttomline',7);  TODO find the appropriate action to show the bottomline in category pages
		wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
	}							
}

function gs_yotpo_activation() {
	// add_action('wpsc_edit_order_status', 'test_map'); // TODO find appropriate action to send map
	if(current_user_can( 'activate_plugins' )) {
		update_option('gs_yotpo_just_installed', true);
		$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
		check_admin_referer( "activate-plugin_{$plugin}" );
		$default_settings = get_option('yotpo_settings', false);
		if(!is_array($default_settings)) {
			add_option('yotpo_settings', gs_yotpo_get_default_settings());
		}
		update_option('native_star_ratings_enabled', get_option('getshopped_enable_review_rating'));
		update_option('getshopped_enable_review_rating', 'no');			
	}        
}

function test_map($args) {
	var_dump($args);
}

function gs_yotpo_uninstall() {
	if(current_user_can( 'activate_plugins' ) && __FILE__ == WP_UNINSTALL_PLUGIN ) {
		check_admin_referer( 'bulk-plugins' );
		delete_option('yotpo_settings');	
	}	
}

function gs_yotpo_show_widget() {
	echo gs_yotpo_get_template('reviews');
}

function gs_yotpo_show_widget_in_tab($tabs) {
	$product = get_product();
	if($product->post->comment_status == 'open') {
		$settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());
		$tabs['yotpo_widget'] = array(
		'title' => $settings['widget_tab_name'],
		'priority' => 50,
		'callback' => 'gs_yotpo_show_widget'
		);
	}
	return $tabs;		
}

function gs_yotpo_load_js() {
	if (is_plugin_active('wp-e-commerce/wp-shopping-cart.php')) {
		wp_enqueue_script('yquery', 'https://www.yotpo.com/js/yQuery.js');
	}
}

function gs_yotpo_show_bottomline() {
	echo gs_yotpo_get_template('bottomLine');			
}

function gs_yotpo_get_template($type) {
	$productId = wpsc_the_product_id();
	$product = get_post($productId);
	if ( $product->comment_status == 'open' ) {
		$yotpo_settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());
		
		$productTitle = get_the_title($productId);
		$productDescription = htmlentities(wpsc_the_product_description());
		$productUrl = wpsc_this_page_url();
		$productSku = array_pop(get_product_meta($productId, 'sku'));
		$domain = gs_yotpo_get_shop_domain();
		
		$yotpo_div = "<div class='yotpo ".$type."' 
					data-appkey='".$yotpo_settings['app_key']."'
					data-domain='".$domain."'
					data-product-id='".$productId."'
					data-product-models='".$productSku."'
					data-name='".$productTitle."' 
					data-url='".$productUrl."' 
					data-image-url='".wpsc_the_product_image()."' 
					data-description='".$productDescription."' 
					data-bread-crumbs=''
					data-lang='".$yotpo_settings['language_code']."'></div>";
		return $yotpo_div;
	}
	return '';
}

function gs_yotpo_get_product_data($product) {	
	$product_data = array();
	$settings = get_option('yotpo_settings',gs_yotpo_get_default_settings());
	$product_data['app_key'] = $settings['app_key'];
	$product_data['shop_domain'] = gs_yotpo_get_shop_domain(); 
	$product_data['url'] = get_permalink($product->id);
	$product_data['lang'] = $settings['language_code']; 
	if($settings['yotpo_language_as_site'] == true) {
		$lang = explode('-', get_bloginfo('language'));
		// In some languages there is a 3 letters language code
		//TODO map these iso-639-2 to iso-639-1 (from 3 letters language code to 2 letters language code) 
		if(strlen($lang[0]) == 2) {
			$product_data['lang'] = $lang[0];	
		}		
	}
	$product_data['description'] = strip_tags($product->get_post_data()->post_excerpt);
	$product_data['id'] = $product->id;	
	$product_data['title'] = $product->get_title();
	$product_data['image-url'] = gs_yotpo_get_product_image_url($product->id);
	$product_data['product-models'] = $product->get_sku();	
	return $product_data;
}

function gs_yotpo_get_shop_domain() {
	return parse_url(get_bloginfo('url'),PHP_URL_HOST);
}

function gs_yotpo_remove_native_review_system($open, $post_id) {
	if ( get_post_type($post_id) == 'wpsc-product' ) {
		return false;
	}
	return $open;
}

function gs_yotpo_map($order_id) {

	if ($order_id->is_closed_order()) {
		// order status is "closed order" - TODO need to check with Omer if this is the only status we want to send purchases for
		throw new Exception('aaaaasd3424');	
	} else {

	}
	
	// try {
	// 		$purchase_data = gs_yotpo_get_single_map_data($order_id);
	// 		if(!is_null($purchase_data) && is_array($purchase_data)) {
	// 			$yotpo_settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());
	// 			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
	// 			$get_oauth_token_response = $yotpo_api->get_oauth_token();
	// 			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
	// 				$purchase_data['utoken'] = $get_oauth_token_response['access_token'];
	// 				$purchase_data['platform'] = 'getshopped';
	// 				$response = $yotpo_api->create_purchase($purchase_data);			
	// 		}
	// 	}		
	// }
	// catch (Exception $e) {
	// 	error_log($e->getMessage());
	// }
}

function gs_yotpo_get_single_map_data($order_id) {
	$order = new WC_Order($order_id);
	$data = null;
	if(!is_null($order->id)) {
		$data = array();
		$data['order_date'] = $order->order_date;
		$data['email'] = $order->billing_email;
		$data['customer_name'] = $order->billing_first_name.' '.$order->billing_last_name;
		$data['order_id'] = $order_id;
		$data['currency_iso'] = $order->order_custom_fields['_order_currency'];
		if(is_array($data['currency_iso'])) {
			$data['currency_iso'] = $data['currency_iso'][0];
		}
		$products_arr = array();
		foreach ($order->get_items() as $product) 
		{
			$product_instance = get_product($product['product_id']);
 
			$description = '';
			if (is_object($product_instance)) {
				$description = strip_tags($product_instance->get_post_data()->post_excerpt);	
			}
			$product_data = array();   
			$product_data['url'] = get_permalink($product['product_id']); 
			$product_data['name'] = $product['name'];
			$product_data['image'] = wc_yotpo_get_product_image_url($product['product_id']);
			$product_data['description'] = $description;
			$product_data['price'] = $product['line_total'];
			$products_arr[$product['product_id']] = $product_data;	
		}	
		$data['products'] = $products_arr;
	}
	return $data;
}

function gs_yotpo_get_product_image_url($product_id) {
	$url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
	return $url ? $url : null;
}

function gs_yotpo_get_past_orders() {
	$result = null;
	$args = array(
		'post_type'			=> 'shop_order',
		'posts_per_page' 	=> -1,
		'tax_query' => array(
			array(
				'taxonomy' => 'shop_order_status',
				'field' => 'slug',
				'terms' => array('completed'),
				'operator' => 'IN'
			)
		)	
	);	
	add_filter( 'posts_where', 'gs_yotpo_past_order_time_query' );
	$query = new WP_Query( $args );
	remove_filter( 'posts_where', 'gs_yotpo_past_order_time_query' );
	wp_reset_query();
	if ($query->have_posts()) {
		$orders = array();
		while ($query->have_posts()) { 
			$query->the_post();
			$order = $query->post;		
			$single_order_data = gs_yotpo_get_single_map_data($order->ID);
			if(!is_null($single_order_data)) {
				$orders[] = $single_order_data;
			}      	
		}
		if(count($orders) > 0) {
			$post_bulk_orders = array_chunk($orders, 200);
			$result = array();
			foreach ($post_bulk_orders as $index => $bulk)
			{
				$result[$index] = array();
				$result[$index]['orders'] = $bulk;
				$result[$index]['platform'] = 'woocommerce';			
			}
		}		
	}
	return $result;
}

function gs_yotpo_past_order_time_query( $where = '' ) {
	// posts in the last 30 days
	$where .= " AND post_date > '" . date('Y-m-d', strtotime('-90 days')) . "'";
	return $where;
}

function gs_yotpo_send_past_orders() {
	$yotpo_settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret']))
	{
		$past_orders = gs_yotpo_get_past_orders();		
		$is_success = true;
		if(!is_null($past_orders) && is_array($past_orders)) {
			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
				foreach ($past_orders as $post_bulk) 
					if (!is_null($post_bulk))
					{
						$post_bulk['utoken'] = $get_oauth_token_response['access_token'];
						$response = $yotpo_api->create_purchases($post_bulk);						
						if ($response['code'] != 200 && $is_success)
						{
							$is_success = false;
							$message = !empty($response['status']) && !empty($response['status']['message']) ? $response['status']['message'] : 'Error occurred';
							gs_yotpo_display_message($message, true);
						}
					}
				if ($is_success)
				{
					gs_yotpo_display_message('Past orders sent successfully' , false);
					$yotpo_settings['show_submit_past_orders'] = false;
					update_option('yotpo_settings', $yotpo_settings);
				}	
			}
		}
		else {
			gs_yotpo_display_message('Could not retrieve past orders', true);
		}	
	}
	else {
		gs_yotpo_display_message('You need to set your app key and secret token to post past orders', false);
	}		
}

function gs_yotpo_conversion_track($purchase_log_object) {
	if (!is_null($purchase_log_object) && ($purchase_log_object->is_accepted_payment() ||  $purchase_log_object->is_order_received())) {
		$yotpo_settings = get_option('yotpo_settings', gs_yotpo_get_default_settings());

		global $wpdb;
		$currency_code = $wpdb->get_var($wpdb->prepare("SELECT `code` FROM `".WPSC_TABLE_CURRENCY_LIST."` WHERE `id` = %d LIMIT 1", get_option('currency_type')));

		$conversion_params = http_build_query(
								array(
									'app_key' 		 => $yotpo_settings['app_key'],
									'order_id' 		 => $purchase_log_object->get('id'),
									'order_amount' 	 => $purchase_log_object->get('totalprice'),
									'order_currency' => $currency_code
								)
							);

		echo "<img 
		src='https://api.yotpo.com/conversion_tracking.gif?$conversion_params'
		width='1'
		height='1'></img>";
	}
}

function gs_yotpo_get_default_settings() {
	return array( 'app_key' => '',
				  'secret' => '',
				  'widget_location' => 'footer',
				  'language_code' => 'en',
				  'widget_tab_name' => 'Reviews',
				  'bottom_line_enabled_product' => true,
				  'bottom_line_enabled_category' => true,
				  'yotpo_language_as_site' => true,
				  'show_submit_past_orders' => true,
				  'disable_native_review_system' => true,
				  'native_star_ratings_enabled' => 'no');
}

function gs_yotpo_admin_styles($hook) {
	if($hook == 'toplevel_page_getshopped-yotpo-settings-page') {		
		wp_enqueue_script( 'yotpoSettingsJs', plugins_url('assets/js/settings.js', __FILE__), array('jquery-effects-core'));		
		wp_enqueue_style( 'yotpoSettingsStylesheet', plugins_url('assets/css/yotpo.css', __FILE__));
	}
	wp_enqueue_style('yotpoSideLogoStylesheet', plugins_url('assets/css/side-menu-logo.css', __FILE__));
}

function gs_yotpo_compatible() {
	return version_compare(phpversion(), '5.2.0') >= 0 && function_exists('curl_init');
}

function gs_yotpo_deactivate() {
	//update_option('woocommerce_enable_review_rating', get_option('native_star_ratings_enabled'));	TODO delete or modify to fit get shopped
}

// add_filter('woocommerce_tab_manager_integration_tab_allowed', 'wc_yotpo_disable_tab_manager_managment'); TODO ask Alon

function gs_yotpo_disable_tab_manager_managment($allowed, $tab) {
	if($tab == 'yotpo_widget') {
		$allowed = false;
		return false;
	}
}