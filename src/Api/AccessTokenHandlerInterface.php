<?php

namespace OblioSoftware\Api;

interface AccessTokenHandlerInterface {
    /**
     *  @return ?stdClass $accessToken
     */
    public function get();
    
    /**
     *  @param stdClass $accessToken
     */
    public function set($accessToken);
}