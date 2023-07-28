<?php

namespace OblioSoftware\Api;

class AccessTokenHandler implements AccessTokenHandlerInterface {
    protected $_key = 'oblio_api_access_token';
    
    public function get() {
        $accessTokenJson = get_option($this->_key);
        if ($accessTokenJson) {
            $accessToken = json_decode($accessTokenJson);
            if ($accessToken && $accessToken->request_time + $accessToken->expires_in > time()) {
                return $accessToken;
            }
        }
        return null;
    }
    
    public function set($accessToken) {
        if (!is_string($accessToken)) {
            $accessToken = json_encode($accessToken);
        }
        update_option($this->_key, $accessToken);
    }
    
    public function clear() {
        update_option($this->_key, '');
    }
}