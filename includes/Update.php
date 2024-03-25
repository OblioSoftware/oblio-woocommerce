<?php

add_filter('plugins_api', '_oblio_plugin_info', 20, 3);

function _oblio_plugin_info($res, $action, $args) {
    if ('plugin_information' !== $action) {
        return false;
    }
    if ($args->slug !== 'woocommerce-oblio') {
        return $res;
    }

    $remote = get_transient('oblio_update');

    if (false == $remote) {
        $remote = wp_remote_get('https://obliosoftware.github.io/builds/woocommerce/info.json', array(
            'timeout' => 1,
            'headers' => array(
                'Accept' => 'application/json'
            )
        ));

        set_transient('oblio_update', $remote, 43200); // 12 hours cache
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

add_filter('site_transient_update_plugins', '_oblio_push_update', 1000);

function _oblio_push_update($transient) {
    $plugin = 'woocommerce-oblio/woocommerce-oblio.php';
    $remote = get_transient('oblio_update');
    $response = $remote ? json_decode($remote['body']) : null;

    if (
        !is_object($transient) ||
        (!empty($transient->checked[$plugin]) && $transient->checked[$plugin] === $response->version) ||
        OBLIO_VERSION === '[PLUGIN_VERSION]'
        ) {
        return $transient;
    }

    if (false == $remote) {
        // info.json is the file with the actual plugin information on your server
        $remote = wp_remote_get('https://obliosoftware.github.io/builds/woocommerce/info.json', array(
            'timeout' => 1,
            'headers' => array(
                'Accept' => 'application/json'
            ))
        );

        set_transient('oblio_update', $remote, 43200); // 12 hours cache
    }
    
    if ($remote) {
        // your installed plugin version should be on the line below! You can obtain it dynamically of course
        if ($response && version_compare(OBLIO_VERSION, $response->version, '<') && version_compare($response->requires, get_bloginfo('version'), '<')) {
            $res = new stdClass();
            $res->slug = 'woocommerce-oblio';
            $res->url = $response->url;
            $res->plugin = $plugin;
            $res->new_version = $response->version;
            $res->tested = $response->tested;
            $res->package = $response->download_url;
            $res->icons = (array) $response->icons;
            $res->banners = (array) $response->banners;
            $transient->response[$res->plugin] = $res;
            $transient->checked[$res->plugin] = $response->version;
        }
    }
    return $transient;
}