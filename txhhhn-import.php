<?php
/**
 * Plugin Name: TXHHHN Import
 * Plugin URI: http://dev.sps.vn
 * Description: Lay du lieu website tuixachhanghieuhanoi.com
 * Version: 1.0
 * Author: spsdev
 * Author URI: https://sps.vn
 */
require_once( dirname( __FILE__ ) . '/simplehtmldom/simple_html_dom.php' );

if ( is_admin() ) {
	require_once( dirname( __FILE__ ) . '/admin.php' );
	new TX_Admin();
}
//Mặt nạ dưỡng ẩm trắng da MOVR Hongkong
//