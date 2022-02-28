<?php

namespace HiCo\ShopifySdk;

class WebhookException extends \Exception
{
    private string $data;

    private string $hmac_header;

    public function __construct($message = '', $code = 0, \Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getData()
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getHmacHeader()
    {
        return $this->hmac_header;
    }

    public function setHmacHeader(string $hmac_header): self
    {
        $this->hmac_header = $hmac_header;

        return $this;
    }
}
