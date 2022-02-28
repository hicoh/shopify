<?php

namespace HiCo\ShopifySdk;

use Exception;

class ShopifyMissingScopesException extends ClientException
{
    protected array $missing_scopes = [];

    public function __construct($message = '', $code = 0, Exception $previous = null, Client $client = null, array $missing_scopes = [])
    {
        $this->missing_scopes = $missing_scopes;

        parent::__construct($message, $code, $previous, $client);
    }

    public function getMissingScopes(): array
    {
        return $this->missing_scopes;
    }

    public function setMissingScopes(array $missingScopes): self
    {
        $this->missing_scopes = $missingScopes;

        return $this;
    }
}
