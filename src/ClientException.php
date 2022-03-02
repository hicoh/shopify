<?php

namespace HiCo\ShopifySdk;

use Exception;
use Psr\Http\Message\ResponseInterface;

class ClientException extends Exception
{
    private ?Client $client;

    public function __construct($message = '', $code = 0, Exception $previous = null, Client $client = null)
    {
        $this->client = $client;
        parent::__construct($message, $code, $previous);
    }

    public function getErrors(): array
    {
        return $this->client->getErrors();
    }

    public function getLastResponse(): ResponseInterface
    {
        return $this->client->getLastResponse();
    }
}
