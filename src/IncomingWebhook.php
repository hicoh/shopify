<?php

namespace HiCo\ShopifySdk;

/**
 * Class IncomingWebhook.
 *
 * Helper class to validate and process incoming Shopify webhooks.
 */
class IncomingWebhook
{
    private $data;

    private $hmac_header;

    private $shared_secret;

    /**
     * @param string $shared_secret
     *                              Shopify shared secret key
     */
    public function __construct($shared_secret)
    {
        $this->shared_secret = $shared_secret;
    }

    /**
     * Determines if the webhook data is valid (unchanged/secure).
     *
     * @param string $data
     *                            Raw JSON incoming data. If not provided $data will be populated from
     *                            php://input stream.
     * @param string $hmac_header
     *                            Shopify HMAC header that is sent in the request. If not provided the HMAC header
     *                            will be populated from the $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] variable.
     *
     * @return bool
     *              Returns FALSE if there is an error in the data
     *
     * @throws WebhookException
     *
     * @see https://docs.shopify.com/api/webhooks/using-webhooks @endlink
     */
    public function validate($data = '', $hmac_header = '')
    {
        if (empty($data)) {
            $data = file_get_contents('php://input');
        }

        if (empty($hmac_header) && isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'])) {
            $hmac_header = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'];
        }

        if (empty($hmac_header)) {
            throw new WebhookException('HMAC Header is empty.', 0, null);
        }

        if (empty($data)) {
            throw new WebhookException('Data is empty.', 0, null);
        }

        $this->data = $data;
        $this->hmac_header = $hmac_header;

        if ($hmac_header !== $this->calculateHmac($this->data, $this->shared_secret)) {
            throw new WebhookException('Invalid webhook.', 0, null);
        }

        return true;
    }

    /**
     * Calculates the HMAC based on Shopify's specification.
     *
     * @param string $data
     *                       JSON data
     * @param string $secret
     *                       Shopify shared secret
     *
     * @return string
     */
    public function calculateHmac($data, $secret)
    {
        return base64_encode(hash_hmac('sha256', $data, $secret, true));
    }

    /**
     * Gets the data in a usable object.
     *
     * @return object
     */
    public function getData()
    {
        return json_decode($this->data);
    }

    /**
     * Gets the raw JSON input from the request.
     *
     * @return mixed
     */
    public function getRawData()
    {
        return $this->data;
    }

    public function setHmacHeader(string $hmacHeader): self
    {
        $this->hmac_header = $hmacHeader;

        return $this;
    }

    public function getHmacHeader(): string
    {
        return $this->hmac_header;
    }
}
