<?php

namespace OblioSoftware\Api;

interface RequestInterface {
    /**
     * @return string GET/POST/PUT/PATCH/DELETE
     */
    public function getMethod(): string;

    public function getUri(): string;

    public function getOptions(): array;
}