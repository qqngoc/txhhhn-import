<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
class TX_Admin {

	public function __construct() {
		add_action('admin_menu', array($this, 'admin_menu'), 50);
		add_action('wp_ajax_import_product_cat', array($this, 'import_product_cat'));
		add_action('wp_ajax_import_products_cat', array($this, 'import_products_cat'));
		add_action('wp_ajax_delete_attachment', array($this, 'delete_attachment'));
		add_action('wp_ajax_delete_product', array($this, 'delete_product'));
		
	}

	public function admin_menu() {
		add_submenu_page('tools.php', 'Product imports', 'TX Import', 'manage_options', 'txhhhn-import', array($this, 'import_page'));
	}

	public function import_product_cat() {
		$cat = isset($_POST['cat']) ? $_POST['cat'] : '';
		$parent = isset($_POST['parent']) ? absint($_POST['parent']) : 0;
		$return = array(
			'term_id' => 0,
			'term_taxonomy_id' => 0,
			'children' => array(),
		);
		if(!empty($cat)) {
			$child_cats = json_decode(wp_remote_retrieve_body(wp_remote_get('http://tuixachhanghieuhanoi.com/api/term.php?parent='.absint($cat['contentId']).'&json')),true);
			$slug = str_replace('.html', '', $cat['contentUrl']);
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if($term) {
				$return['term_id'] = $term->term_id;
				if($parent>0) {
					//error_log('parent:'.$parent.' | child:'.$term->term_id);
					wp_update_term($term->term_id,'product_cat', array('parent'=>$parent));
				}
			} else {
				$return = wp_insert_term( $cat['contentName'], 'product_cat', array(
					'description' => $cat['contentDescription'],
					'slug' => $slug,
					'parent' => $parent
				) );
			}

			$return['children'] = $child_cats;
		}
		wp_send_json($return);
		die;
	}

	public function import_products_cat() {
		if(!class_exists('WooCommerce')) return;
		$cat = isset($_POST['cat']) ? $_POST['cat'] : '';
		$cat_slug = str_replace('.html', '', $cat['contentUrl']);
		$wc_product_cat = get_term_by('slug', $cat_slug, 'product_cat');
		if($wc_product_cat) {
			$products = get_transient('products_'.$cat['contentId']);
			if($products==false) {
				$products = wp_remote_retrieve_body(wp_remote_get('http://tuixachhanghieuhanoi.com/api/post.php?cat='.$cat['contentId'].'&json'));
				set_transient('products_'.$cat['contentId'], $products);
			}
			$products = json_decode($products,true,512);
			if(!empty($products)) {
				//print_r($products);
				?>
				<ul>
					<?php
					foreach ($products as $product) {
						
						//print_r($product);
						$product_slug = str_replace('.html', '', $product['contentUrl']);
						$product_id = $this->post_exists($product_slug,'product');
						
						$data = array(
							'post_title' => $product['contentName'],
							'post_type' => 'product',
							'post_name' => $product_slug,
							'post_status' => 'publish'
						);
						if($product['bodyContent']!='') {
							$data['post_content'] = $product['bodyContent'];
						}
						$post_excerpt = '';

						if($product['productOrigin']!='') {
							$post_excerpt .= '<p>Xuất xứ: <strong>'.$product['productOrigin'].'</strong></p>';
						}
						if($product['productMaterial']!='') {
							$post_excerpt .= '<p>Chất liệu: <strong>'.$product['productMaterial'].'</strong></p>';
						}
						if($product['productStyle']!='') {
							$post_excerpt .= '<p>Kiểu dáng: <strong>'.$product['productStyle'].'</strong></p>';
						}

						if($post_excerpt!='') {
							$data['post_excerpt'] = $post_excerpt;
						}
						
						if($product_id) {
							$data['ID'] = $product_id;
						}
						
						$product_id = wp_insert_post($data);
						
						if($product_id) {
							if($product['thumbnail']!='') {
								$thumbnail_id = $this->upload_attachment($product['thumbnail'], 0);
								set_post_thumbnail( $product_id, $thumbnail_id );
							}
							
							wp_set_object_terms( $product_id, $wc_product_cat->term_id, 'product_cat' );
							$wc_product = wc_get_product($product_id);
							
							$price = absint($product['productPrice']);

							$props = array(
								'sku' => ( $product['productCode']!='' ) ? wc_clean( $product['productCode']) : null,
								'purchase_note' 	 => '',
								'downloadable'       => 'no',
								'virtual'            => 'no',
								'featured'           => '',
								'catalog_visibility' => null,
								'tax_status'         => null,
								'tax_class'          => null,
								'weight'             => null,
								'length'             => null,
								'width'              => null,
								'height'             => null,
								'shipping_class_id'  => null,
								'sold_individually'  => 'no',
								'upsell_ids'         => array(),
								'cross_sell_ids'     => array(),
								'regular_price'      => ( $price>0 ) ? $price : null,
								'sale_price'         => null,
								'date_on_sale_from'  => '',
								'date_on_sale_to'    => '',
								'manage_stock'       => 'no',
								'backorders'         => 'no',
								'stock_status'       => 'instock',
								'stock_quantity'     => 0,
								'low_stock_amount'   => '',
								'download_limit'     => '-1',
								'download_expiry'    => '-1',
								// Those are sanitized inside prepare_downloads.
								//'downloads'          => null,
								'product_url'        => '',
								'button_text'        => '',
								'children'           => null,
								'reviews_allowed'    => true,
								'attributes'         => array(),
								'default_attributes' => array()
							);

							if(!empty($product['images'])) {
								//_product_image_gallery
								$gallery = array();
								foreach ($product['images'] as $url) {
									$gallery[] = $this->upload_attachment($url, 0);
								}
								$props['gallery_image_ids'] = $gallery;
							}

							$wc_product->set_props($props);
							$wc_product->save();
							
							echo '<li>'.esc_html($product['contentName']);
							if(preg_match('/(<img[^>]+>)/i', $product['bodyContent'])) {
								echo ' - has body image';
							}
							echo ' [OK]</li>';
						} else {
							echo '<li>'.esc_html($product['contentName']).' [Fail]</li>';
						}
						
						//echo '<li>'.esc_html($product['contentName']).' - OK</li>';
					}
					
					?>

				</ul>
				
				<?php
			}
		}
		die;
	}

	public function import_page() {
		if(!current_user_can('manage_options')) return;
		?>
		<style type="text/css">
			#imported > li div {
				font-weight: bold;
			}
			#imported > li ul {
				padding-left: 20px;
			}
		</style>
		<div class="wrap">
			<h2>Lấy dữ liệu</h2>
			<div class="postbox">
				<div class="inside">
				<button class="button primary" id="start-import">Start</button>
				<button class="button primary" id="stop-import">Stop</button>
				<button class="button primary" id="delete-products">Delete products</button>
				<button class="button primary" id="delete-attachments">Delete attachments</button>
				<ul id="imported"></ul>
				<?php
		
				// lay danh sach danh muc
				$product_cats = get_transient('txhhhn_product_cats');
				if($product_cats==false) {
					$product_cats = wp_remote_retrieve_body(wp_remote_get('http://tuixachhanghieuhanoi.com/api/term.php?json'));
					set_transient('txhhhn_product_cats', $product_cats, DAY_IN_SECONDS);
				}

				if(!empty($product_cats)) {
					?>
					<script type="text/javascript">
						jQuery(function($){
							var admin_ajax = '<?=admin_url('admin-ajax.php')?>';
							var pcats = JSON.parse('<?=$product_cats?>');
							var result = $('#imported');
							result.html('');
							var ajax_product = null;
							var ajax_cat = null;
							
							function import_product(cats, index=0) {
								ajax_product = $.ajax({
									url:admin_ajax+'?action=import_products_cat',
									type:'POST',
									//dataType:'json',
									data: {cat:cats[index]},
									success: function(response) {
										if(cats.length>index+1) {
											result.prepend('<li><div>'+cats[index]['contentName']+'</div>'+response+'</li>');
											//result.prepend();
											import_product(cats,index+1);
										} else {
											result.prepend('<li>The end!</li>');
										}
									}
								});
							}

							function import_cat(cats, index=0, parent=0) {
								var prefix = (parent>0) ? '--cat- ': 'cat- ';
								ajax_cat = $.ajax({
									url:admin_ajax+'?action=import_product_cat',
									type:'POST',
									dataType:'json',
									data: {cat:cats[index], parent:parent},
									success: function(response) {
										if(cats.length>index+1) {
											result.prepend('<li>'+prefix+cats[index]['contentName']+'</li>');
											if(response.children.length>0) {
												import_cat(response.children, 0, response.term_id);
											} 
											import_cat(cats,index+1,parent);
										} else {
											if(parent==0) {
												//result.prepend('<li>End cat</li>');
												result.html('<li>End cat</li>');
												import_product(pcats);
											}
										}
									}
								});
							}

							$('#start-import').on('click', function(e){
								result.html('');
								import_cat(pcats);
							});

							$('#stop-import').on('click', function(e){
								if(ajax_product!=null) {
									result.prepend('<li>Quit importing the product</li>');
									ajax_product.abort();
								}
								if(ajax_cat!=null) {
									result.prepend('<li>Quit importing the product cat</li>');
									ajax_cat.abort();
								}
							});

							function delete_attachment() {
								$.ajax({
									url:admin_ajax+'?action=delete_attachment',
									type:'POST',
									dataType:'json',
									success: function(res) {
										if(res['deleted'].length>0) {
											$.each(res['deleted'],function(i,val){
												result.prepend('<li>'+val+'</li>');
											});
										}
										
										if(res.con) {
											delete_attachment();
										} else {
											result.prepend('<li>Completed!</li>');
										}
									}
								});
							}

							$('#delete-attachments').on('click', function(e){
								result.html('');
								delete_attachment();
							});

							function delete_product() {
								$.ajax({
									url:admin_ajax+'?action=delete_product',
									type:'POST',
									dataType:'json',
									success: function(res) {
										if(res['deleted'].length>0) {
											$.each(res['deleted'],function(i,val){
												result.prepend('<li>'+val+'</li>');
											});
										}
										
										if(res.con) {
											delete_product();
										} else {
											result.prepend('<li>Completed!</li>');
										}
									}
								});
							}

							$('#delete-products').on('click', function(e){
								result.html('');
								delete_product();
							});
						});
					</script>
					<?php
				}
				
				?>
				</div>
			</div>
		</div>
		<?php
	}

	public function delete_product() {
		$products = get_posts(array(
			'post_type' => 'product',
			'numberposts' => 20
		));
		$return = array(
			'deleted' => array(),
			'con' => false
		);
		if(!empty($products)) {
			foreach ($products as $key => $value) {
				wp_delete_post($value->ID,true);
				$return['deleted'][] = $value->post_title;
			}
			$return['con'] = true;
		}
		wp_send_json($return);
	}

	public function delete_attachment() {
		$attachments = get_posts(array(
			'post_type' => 'attachment',
			'numberposts' => 20,
			'date_query' => array(
		        array(
		            'after' => 'January 6st, 2020',
		        ),
		    ),
		));
		$return = array(
			'deleted' => array(),
			'con' => false
		);
		if(!empty($attachments)) {
			foreach ($attachments as $key => $value) {
				wp_delete_post($value->ID,true);
				$return['deleted'][] = $value->post_title;
			}
			$return['con'] = true;
		}
		wp_send_json($return);
	}

	private function post_exists( $post_name='', $type = '' ) {
		global $wpdb;

		$query = "SELECT ID FROM $wpdb->posts WHERE 1=1";
		$args = array();

		if ( !empty ( $post_type ) ) {
			$query .= ' AND post_type=%s';
			$args[] = $post_type;
		}

		if ( !empty ( $post_name ) ) {
			$query .= ' AND post_name=%s';
			$args[] = $post_name;
		}

		if ( !empty ( $args ) ) {
			$sql = $wpdb->prepare($query, $args);
			return (int) $wpdb->get_var($sql);
		}

		return 0;
	}

	private function upload_attachment($url='', $post_id=0) {
		$attachmentId = false;
		if( !empty( $url )  ) {

			if ( !function_exists('media_handle_upload') ) {
			    require_once(ABSPATH . "wp-admin" . '/includes/image.php');
			    require_once(ABSPATH . "wp-admin" . '/includes/file.php');
			    require_once(ABSPATH . "wp-admin" . '/includes/media.php');
			}

			$file = array();
			$file['name'] = wp_basename(urldecode($url));
			$file['tmp_name'] = download_url($url);
			
			$attach_name = sanitize_title(preg_replace('/\.[^.]+$/', '', sanitize_file_name($file['name'])));
			
			$attachmentId = $this->post_exists( $attach_name, 'attachment' );
			
			if(!$attachmentId) {
				if (is_wp_error($file['tmp_name'])) {
				    @unlink($file['tmp_name']);
				    //var_dump( $file['tmp_name']->get_error_messages( ) );
				} else {
				    $attachmentId = media_handle_sideload($file, $post_id);

				    if ( is_wp_error($attachmentId) ) {
				        @unlink($file['tmp_name']);
				        return 0;
				    }
				}
			}
			
			
		}
		return absint($attachmentId);
	}
}
