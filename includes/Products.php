<?php

class Oblio_Products {
    /**
     *  Finds product if it exists
     *  @param array $data
     *  @return object $post
     */
    public function find( $data ) {
        global $wpdb;
        
        $product_id = 0;
        
        if ( $data['code'] ) {
            $product_id = (int) wc_get_product_id_by_sku( $data['code'] );
        }
        
        if ( $product_id > 0 ) {
            return $this->get( $product_id );
        } else {
            $post = get_page_by_title( $data['name'], OBJECT, 'product' );
            if ( $post ) {
                return $post;
            }
        }
        return null;
    }
    
    /**
     *  Finds product by id
     *  @param int $product_id
     *  @return object $post
     */
    public function get( $product_id ) {
        return get_post( $product_id );
    }
    
    /**
     *  Insert product
     *  @param array $data
     *  @return void
     */
    public function insert( $data ) {
        if ( empty( $data['name'] ) || empty( $data['price'] ) ) {
            return;
        }
        $post = array(
            'post_author'   => get_current_user_id(),
            'post_content'  => $data['description'],
            'post_excerpt'  => $data['description'],
            'post_status'   => 'publish',
            'post_title'    => $data['name'],
            'post_parent'   => '',
            'post_type'     => 'product',
        );

        //Create post
        $post_id = wp_insert_post( $post, $wp_error );
        
        switch ( (int) $data['vatPercentage'] ) {
            case  9: $_tax_class = 'reduced-rate'; break;
            case  0: $_tax_class = 'zero-rate'; break;
            default: $_tax_class = '';
        }
        
        update_post_meta( $post_id, '_visibility', 'visible' );
        update_post_meta( $post_id, '_stock_status', 'instock' );
        update_post_meta( $post_id, 'total_sales', '0');
        update_post_meta( $post_id, '_downloadable', 'no');
        update_post_meta( $post_id, '_virtual', 'no');
        update_post_meta( $post_id, '_purchase_note', '' );
        update_post_meta( $post_id, '_featured', 'no' );
        update_post_meta( $post_id, '_weight', '' );
        update_post_meta( $post_id, '_length', '' );
        update_post_meta( $post_id, '_width', '' );
        update_post_meta( $post_id, '_height', '' );
        update_post_meta( $post_id, '_sku', isset( $data['code'] ) ? $data['code'] : '' );
        update_post_meta( $post_id, '_product_attributes', array() );
        update_post_meta( $post_id, '_tax_status', 'taxable' );
        update_post_meta( $post_id, '_tax_class', $_tax_class );
        update_post_meta( $post_id, '_regular_price', $data['price'] );
        update_post_meta( $post_id, '_sale_price', '' );
        update_post_meta( $post_id, '_sale_price_dates_from', '' );
        update_post_meta( $post_id, '_sale_price_dates_to', '' );
        update_post_meta( $post_id, '_price', $data['price'] );
        update_post_meta( $post_id, '_sold_individually', '' );
        update_post_meta( $post_id, '_manage_stock', isset( $data['quantity'] ) ? 'yes' : 'no' );
        update_post_meta( $post_id, '_backorders', 'no' );
        update_post_meta( $post_id, '_stock', isset( $data['quantity'] ) ? $data['quantity'] : '' );
    }
    
    /**
     *  Update product
     *  @param int $product_id
     *  @param array $data
     *  @return void
     */
    public function update( $product_id, $data ) {
        /*if ( number_format( $data['price'], 2 ) === '0.00' ) {
            return;
        }//*/
        
        $post = $this->get( $product_id );
        if ( $post ) {
            $post_id = $post->ID;
            
            switch ((int) $data['vatPercentage']) {
                case  9: $_tax_class = 'reduced-rate'; break;
                case  0: $_tax_class = 'zero-rate'; break;
                default: $_tax_class = '';
            }
            $meta = get_post_meta( $post_id );
            if ( $meta['_manage_stock'][0] !== 'yes' ) {
                return;
            }
            
            $package_number = 0;
            if ( $post->post_type === 'product_variation' ) {
                if ( ! empty( $meta['cfwc_package_number'][0] ) ) {
                    $package_number = (int) $meta['cfwc_package_number'][0];
                } else {
                    $parent_id = $post->post_parent;
                    $package_number = (int) get_post_meta( $parent_id, 'custom_package_number', true );
                }
            } else if ( ! empty( $meta['custom_package_number'][0] ) ) {
                $package_number = (int) $meta['custom_package_number'][0];
            }
            
            if ( $package_number === 0 ) { // not set
                $package_number = 1;
            }
            
            $price = floatval( $data['price'] );
            if ( ! $data['vatIncluded'] ) {
                $price *= 1 + ( floatval( $data['vatPercentage'] ) / 100 );
            }
            
            $stock_quantity = isset( $data['quantity'] ) ? floor( $data['quantity'] / $package_number ) : '';
            $stock_status   = isset( $data['quantity'] ) && $data['quantity'] > 0 ? 'instock' : 'outofstock';
            
            // update_post_meta( $post_id, '_sku', isset( $data['code'] ) ? $data['code'] : '' );
            // update_post_meta( $post_id, '_tax_class', $_tax_class );
            // update_post_meta( $post_id, '_regular_price', $price * $package_number );
            // update_post_meta( $post_id, '_price', $price * $package_number );
            // update_post_meta( $post_id, '_manage_stock', isset( $data['quantity'] ) ? 'yes' : 'no' );
            update_post_meta( $post_id, '_stock', $stock_quantity );
            update_post_meta( $post_id, '_stock_status', $stock_status );

            try {
                $terms = [];
                foreach ( wp_get_post_terms( $post_id, 'product_visibility' ) as $WP_Term ) {
                    if ( 'outofstock' === $WP_Term->name ) {
                        continue;
                    }
                    $terms[] = $WP_Term->name;
                }

                if ( $stock_status === 'outofstock' ) {
                    $terms[] = 'outofstock';
                }

                $product = new WC_Product( $post_id );
                $product->set_stock_quantity( $stock_quantity );
                $product->set_stock_status( $stock_status );

                if ( ! is_wp_error( wp_set_post_terms( $post_id, $terms, 'product_visibility', false ) ) ) {
                    do_action( 'woocommerce_product_set_visibility', $post_id, $product->get_catalog_visibility() );
                }
            } catch (\Exception $e) {
                // 
            }
        }
    }
    
    /**
     *  Delete product
     *  @param int $product_id
     *  @return void
     */
    public function delete( $product_id ) {
        wp_delete_post( $product_id, true );
    }
}