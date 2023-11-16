<?php

namespace OblioSoftware\Api\Request;

use OblioSoftware\Api\RequestInterface;

class WebhookDelete implements RequestInterface {
    private int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }

    public function getMethod(): string
    {
        return 'DELETE';
    }

    public function getUri(): string
    {
        return 'api/webhooks/' . $this->id;
    }

    public function getOptions(): array
    {
        return [];
    }
}