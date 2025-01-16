<?php
/*
 * Plugin name: Simple WP Crossposting – Metabox.io
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Provides better compatibility with metabox.io
 * Version: 1.1
 * Plugin URI: https://rudrastyh.com/support/metabox-io
 */

class Rudr_SWC_Metaboxio {

	function __construct() {
		// regular posts and post types (+blocks)
		add_filter( 'rudr_swc_pre_crosspost_post_data', array( $this, 'process_fields' ), 25, 3 );
		add_filter( 'rudr_swc_pre_crosspost_post_data', array( $this, 'process_blocks' ), 30, 2 );

		add_filter( 'rudr_swc_pre_crosspost_term_data', array( $this, 'process_fields' ), 10, 3 );


		// https://docs.metabox.io/extensions/mb-frontend-submission/
		//add_action( 'rwmb_frontend_after_process', array( $this, 'frontend_submit' ), 10, 2 );
	}

	public function process_fields( $data, $blog, $object_type = 'post' ) {

		// if no meta fields do nothing
		if( ! isset( $data[ 'meta' ] ) || ! $data[ 'meta' ] || ! is_array( $data[ 'meta' ] ) ) {
			return $data;
		}
		// if no metabox.io
		if( ! function_exists( 'rwmb_get_field_settings' ) ) {
			return $data;
		}
		// just in case
		if( empty( $data[ 'id' ] ) ) {
			return $data;
		}

		if( 'rudr_swc_pre_crosspost_term_data' == current_filter() ) {
			$object_type = 'term';
			$object_id = get_term_by( 'id', $data[ 'id' ], $data[ 'taxonomy' ] );
		} else {
			$object_type = 'post';
			$object_id = (int) $data[ 'id' ];
		}

		foreach( $data[ 'meta' ] as $meta_key => $meta_value ) {
			$field = rwmb_get_field_settings( $meta_key, array( 'object_type' => $object_type ), $object_id );
			// if it is not really an ACF field (returns false)
			if( ! $field ) {
				continue;
			}

			$meta_value = $this->process_field_by_type( $meta_value, $field[ 'type' ], $blog );

			// re-organize the fields
			$data[ 'meta_box' ][ $meta_key ] = $meta_value;
			unset( $data[ 'meta' ][ $meta_key ] );
			// not necessary to unset repeater subfields like repeater_0_text

		}
//file_put_contents( __DIR__ . '/log.txt' , print_r( $data, true ) );
//echo '<pre>';var_dump( $data);exit;

		return $data;

	}


	public function process_field_by_type( $meta_value, $field_type, $blog, $is_subfield = false ) {

		switch( $field_type ) {
			case 'file_advanced':
			case 'file_upload':
			case 'image_advanced':
			case 'image_upload':
			case 'single_image':
			case 'video': {
				$meta_value = $this->process_attachment_field( $meta_value, $blog );
				break;
			}
			case 'post': {
				$meta_value = $this->process_relationships_field( $meta_value, $blog );
				break;
			}
		}

		return $meta_value;

	}


	private function process_attachment_field( $meta_value, $blog ) {

		// sometimes we need if
		$meta_value = maybe_unserialize( $meta_value );
		if( is_array( $meta_value ) ) {
			// gallery field
			$meta_value = array_filter( array_map( function( $attachment_id ) use ( $blog ) {
				$crossposted = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $attachment_id, $blog );
				if( isset( $crossposted[ 'id' ] ) && $crossposted[ 'id' ] ) {
					return $crossposted[ 'id' ];
				}
				return false; // will be removed with array_filter()
			}, $meta_value ) );
		} else {
			// image or file field
			$crossposted = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $meta_value, $blog );
			if( isset( $crossposted[ 'id' ] ) && $crossposted[ 'id' ] ) {
				$meta_value = $crossposted[ 'id' ];
			}
		}
		//return null;
		return $meta_value ? $meta_value : null;

 	}


	private function process_relationships_field( $meta_value, $blog ) {

		$blog_id = Rudr_Simple_WP_Crosspost::get_blog_id( $blog );

		$meta_value = maybe_unserialize( $meta_value );
		$ids = is_array( $meta_value ) ? $meta_value : array( $meta_value );

		$crossposted_ids = array();
		foreach( $ids as $id ) {
			$post_type = get_post_type( $id );
			if( 'product' === $post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				// no need to check connection type, this method does that
				if( $product && ( $new_id = Rudr_Simple_Woo_Crosspost::is_crossposted_product( $product, $blog ) ) ) {
					$crossposted_ids[] = $new_id;
				}
			} else {
				if( $new_id = Rudr_Simple_WP_Crosspost::is_crossposted( $id, $blog_id ) ) {
					$crossposted_ids[] = $new_id;
				}
			}
		}
		return $crossposted_ids ? $crossposted_ids : 0; // zero allows to bypass "required" flag on sub-sites

	}


	public function process_blocks( $data, $blog ) {

		// no blocks, especially no acf ones
		if( ! has_blocks( $data[ 'content' ] ) ) {
			return $data;
		}

		$blocks = parse_blocks( $data[ 'content' ] );

		// let's do the shit
		foreach( $blocks as &$block ) {
			$block = $this->process_block( $block, $blog );
		}

		$processed_content = '';
		foreach( $blocks as $processed_block ) {
			if( $processed_rendered_block = $this->render_block( $processed_block ) ) {
				$processed_content .= "{$processed_rendered_block}\n\n";
			}
		}

		$data[ 'content' ] = $processed_content;
		return $data;
	}

	public function process_block( $block, $blog ) {

		// first – process inner blocks
		if( $block[ 'innerBlocks' ] ) {
			foreach( $block[ 'innerBlocks' ] as &$innerBlock ) {
				$innerBlock = $this->process_block( $innerBlock, $blog );
			}
		}

		// second – once the block itself non metabox.io, we do nothing
		if( empty( $block[ 'blockName' ] ) || 0 !== strpos( $block[ 'blockName' ], 'meta-box/' ) ) {
			return $block;
		}

		$metabox_block = mb_get_block( $block[ 'blockName' ] );
		$field_types = wp_list_pluck( $metabox_block->render_callback[0]->meta_box[ 'fields' ], 'type', 'id' );

		// skip the block if it has empty data
		if( empty( $block[ 'attrs' ][ 'data' ] ) || ! $block[ 'attrs' ][ 'data' ] ) {
			return $block;
		}

		// now we are going to work with fields!
		$fields = array();
		foreach( $block[ 'attrs' ][ 'data' ] as $key => &$value ) {
			switch_to_blog( $new_blog_id );
			$value = $this->process_field_by_type( $value, $field_types[ $key ], $blog );
			restore_current_blog();
		}

		return $block;

	}


	public function render_block( $processed_block ) {

		if( empty( $processed_block[ 'blockName' ] ) ){
			return false;
		}

		$processed_rendered_block = '';
		// block name
		$processed_rendered_block .= "<!-- wp:{$processed_block[ 'blockName' ]}";
		// data
		if( $processed_block[ 'attrs' ] ) {
			$processed_rendered_block .= ' ' . wp_unslash( wp_json_encode( $processed_block[ 'attrs' ] ) );
		}

		if( ! $processed_block[ 'innerHTML' ] && ! $processed_block[ 'innerBlocks' ] ) {
			$processed_rendered_block .= " /-->";
		} else {
			// ok now we have either html or innerblocks or both
			// but we are going to use innerContent to populate that
			$innerBlockIndex = 0;
			$processed_rendered_block .= " -->";
			foreach( $processed_block[ 'innerContent' ] as $piece ) {
				if( isset( $piece ) && $piece ) {
					$processed_rendered_block .= $piece; // innerHTML
				} else {
					if( $processed_inner_block = $this->render_block( $processed_block[ 'innerBlocks' ][$innerBlockIndex] ) ) {
						$processed_rendered_block .= $processed_inner_block;
					}
					$innerBlockIndex++;
				}
			}
			$processed_rendered_block .= "<!-- /wp:{$processed_block[ 'blockName' ]} -->";
		}

		return $processed_rendered_block;

	}


	// public function frontend_submit( $config, $post_id ) {
	//
	// 	if( $post = get_post( $post_id ) ) {
	//
	// 		$crosspost_instance = new Rudr_Simple_Multisite_Crosspost();
	// 		$blogs = $crosspost_instance->get_blogs();
  //     $blog_ids = array_keys( $blogs );
	// 		$crosspost_instance->crosspost( $post, $blog_ids );
	//
	// 	}
	//
	// }

}


new Rudr_SWC_Metaboxio;
