<?php

add_filter('plugins_api', '_oblio_plugin_info', 20, 3);

function _oblio_plugin_info( $res, $action, $args ) {
    if ('plugin_information' !== $action) {
        return false;
    }
    if ($args->slug !== 'woocommerce-oblio') {
        return $res;
    }
    
    // trying to get from cache first
    if (false == $remote = get_transient('oblio_update')) {
        $remote = wp_remote_get('https://obliosoftware.github.io/builds/woocommerce/info.json', array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));
    
        if (! is_wp_error($remote) && isset($remote['response']['code']) && $remote['response']['code'] == 200 && !empty($remote['body'])) {
            set_transient('oblio_update', $remote, 43200); // 12 hours cache
        }
    }
    
    if (!is_wp_error($remote) && isset($remote['response']['code']) && $remote['response']['code'] == 200 && !empty($remote['body'])) {
        $remote = json_decode( $remote['body'] );
        $res = new stdClass();
    
        $res->name = $remote->name;
        $res->slug = 'woocommerce-oblio';
        $res->version = $remote->version;
        $res->tested = $remote->tested;
        $res->requires = $remote->requires;
        $res->author = '<a href="https://www.oblio.eu">Oblio Software</a>';
        $res->author_profile = 'https://www.oblio.eu';
        $res->download_link = $remote->download_url;
        $res->trunk = $remote->download_url;
        $res->requires_php = $remote->requires_php;
        $res->last_updated = $remote->last_updated;
        $res->sections = array(
            'description' => $remote->sections->description,
            'installation' => $remote->sections->installation,
            'changelog' => $remote->sections->changelog
            // you can add your custom sections (tabs) here
        );
        
        if (!empty($remote->sections->screenshots)) {
            $res->sections['screenshots'] = $remote->sections->screenshots;
        }
    
        return $res;
    }
    
    return false;
}

add_filter('site_transient_update_plugins', '_oblio_push_update');

function _oblio_push_update($transient) {
    if (empty($transient->checked) || OBLIO_VERSION === '[PLUGIN_VERSION]') {
        return $transient;
    }
    
    if (false == $remote = get_transient('oblio_update')) {
        // info.json is the file with the actual plugin information on your server
        $remote = wp_remote_get('https://obliosoftware.github.io/builds/woocommerce/info.json', array(
            'timeout' => 10,
            'headers' => array(
                'Accept' => 'application/json'
            ))
        );
        
        if (is_wp_error($remote)) {
            return $transient;
        }
        
        if (!is_wp_error($remote) && isset($remote['response']['code']) && $remote['response']['code'] == 200 && !empty($remote['body'])) {
            set_transient( 'oblio_update', $remote, 43200 ); // 12 hours cache
        }
    }
    
    if ($remote) {
        $remote = json_decode($remote['body']);
        // your installed plugin version should be on the line below! You can obtain it dynamically of course
        if ($remote && version_compare( OBLIO_VERSION, $remote->version, '<') && version_compare($remote->requires, get_bloginfo('version'), '<')) {
            $res = new stdClass();
            $res->slug = 'woocommerce-oblio';
            $res->url = $remote->url;
            $res->plugin = 'woocommerce-oblio/woocommerce-oblio.php';
            $res->new_version = $remote->version;
            $res->tested = $remote->tested;
            $res->package = $remote->download_url;
            $res->icons = (array) $remote->icons;
            $res->banners = (array) $remote->banners;
            $transient->response[$res->plugin] = $res;
            $transient->checked[$res->plugin] = $remote->version;
        }
    }
    return $transient;
}