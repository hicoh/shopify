<?php

namespace HiCo\ShopifySdk;

use GuzzleHttp;
use Psr\Http\Message\ResponseInterface;

abstract class Client
{
    /**
     * Shopify API URL format.
     */
    public const URL_FORMAT = 'https://{api_key}:{password}@{shop_domain}/admin/';

    /**
     * Shopify call limit header.
     */
    public const CALL_LIMIT_HEADER = 'http_x_shopify_shop_api_call_limit';

    /**
     * The query params that are allowed when page_info is present in the query.
     *
     * @var array
     */
    public $allowed_page_info_params = [
    'page_info',
    'limit',
    'fields',
    '_apiFeatures',
  ];

    /**
     * Fetches data from the API as JSON.
     *
     * @var bool
     */
    public $fetch_as_json = true;

    /**
     * Rate limits API calls so we don't hit Shopify's rate limiter.
     *
     * @var bool
     */
    public $rate_limit = true;

    /**
     * Set default options.
     *
     * @var array
     */
    public $default_opts = [
    'connect_timeout' => 3.0,
    'timeout' => 3.0,
  ];

    /**
     * Default limit number of resources to fetch from Shopify.
     *
     * @var int
     */
    public $default_limit = 250;

    /**
     * Default headers to pass into every request.
     *
     * @var array
     */
    public $default_headers = [];

    /**
     * Delays the next API call. Set by the rate limiter.
     *
     * @var bool
     */
    protected $delay_next_call = false;

    /**
     * The last response from the API.
     *
     * @var ResponseInterface
     */
    protected $last_response;

    /**
     * Whether the response we got back had errors.
     *
     * @var bool
     */
    protected $has_errors = false;

    /**
     * Errors from the last response.
     *
     * @var array
     */
    protected $errors = [];

    /**
     * The client type, either "public" or "private".
     *
     * @var string
     */
    protected $client_type;

    /**
     * The ".myshopify.com" shop domain.
     *
     * @var string
     */
    protected $shop_domain;

    /**
     * The app password.
     *
     * @var string
     */
    protected $password;

    /**
     * The app shared secret.
     *
     * @var string
     */
    protected $shared_secret;

    /**
     * The app API key.
     *
     * @var string
     */
    protected $api_key;

    /**
     * The Shopify API version.
     *
     * The version can be overwritten by passing a "version" option
     * to PublicApp class $opts.
     *
     * @var string
     */
    protected $version = '2020-01';

    /**
     * The GuzzleHttp Client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The current call limit usage.
     *
     * @var int
     */
    protected $call_limit;

    /**
     * The call bucket size.
     *
     * @var int
     */
    protected $call_bucket;

    protected ?PaginatedResponse $paginated_response;

    /**
     * Get a new Guzzle Client.
     *
     * @return \GuzzleHttp\Client
     */
    protected function getNewHttpClient(array $opts = [])
    {
        $this->setDefaultOpts($opts);

        return new GuzzleHttp\Client($opts);
    }

    /**
     * Makes a request to the Shopify API.
     *
     * @param string $method
     *                         HTTP Method, either GET, POST, PUT, DELETE
     * @param string $resource
     *                         Shopify resource. Such as shop, products, customers, orders, etc.
     * @param array  $opts
     *                         Options to pass to the request
     *
     * @return responseInterface
     *                           Returns a Response object
     *
     * @throws ClientException
     */
    public function request($method, $resource, array $opts = [])
    {
        if ($this->fetch_as_json && !isset($opts['headers']['Accept'])) {
            $opts['headers']['Accept'] = 'application/json';
        }

        $opts['headers'] = array_merge($opts['headers'], $this->default_headers);

        if ($this->rate_limit && $this->delay_next_call) {
            // Sleep a random amount of time to help prevent bucket overflow.
            usleep(rand(3, 10) * 1000000);
        }

        if (isset($opts['query']['page_info'])) {
            // Only some params allowed when page_info isset, so we should remove the other.
            $this->cleanPageInfoQuery($opts['query']);
        }

        if (isset($opts['query']['page'])) {
            // Log a warning if the page parameter is used.
            trigger_error('The "page" query parameter is no longer supported in the Shopify API.', E_USER_WARNING);
        }

        try {
            $this->last_response = $this->client->request($method, $resource.'.json', $opts);

            // Add the paginated response.
            if ('GET' === strtoupper($method)) {
                $this->paginated_response = new PaginatedResponse($this, $resource, $opts);
            }
        } catch (GuzzleHttp\Exception\RequestException $e) {
            $this->last_response = $e->getResponse();
            if ($this->last_response instanceof ResponseInterface) {
                $this->handleScopeException($e);

                $this->has_errors = true;
                $this->errors = $this->getResponseJsonObjectKey($this->last_response, 'errors');
                throw new ClientException(print_r($this->errors, true), $this->last_response->getStatusCode(), $e, $this);
            } else {
                throw new ClientException('Request failed ('.$this->shop_domain.':'.$method.':'.$resource.'):'.print_r($opts, true), 0, $e, $this);
            }
        }

        $this->has_errors = false;
        $this->errors = [];

        $this->setCallLimitParams();

        if ($this->callLimitReached()) {
            $this->delay_next_call = true;
        } else {
            $this->delay_next_call = false;
        }

        return $this->last_response;
    }

    /**
     * Handle a missing scope exception.
     *
     * @param \GuzzleHttp\Exception\RequestException $e
     *
     * @throws ShopifyMissingScopesException
     */
    private function handleScopeException(GuzzleHttp\Exception\RequestException $e)
    {
        if (false !== stripos($e->getMessage(), 'requires merchant approval')) {
            $message = strstr(strstr($e->getMessage(), '[API]'), ' scope.', true);
            $missing_scope = str_replace('[API] This action requires merchant approval for ', '', $message);
            if (!(empty($missing_scope))) {
                throw new ShopifyMissingScopesException('Missing required scope', $e->getCode(), $e, $this, [$missing_scope]);
            }
        }
    }

    /**
     * Removes query params that are not allowed when page_info is present.
     */
    protected function cleanPageInfoQuery(array &$query)
    {
        foreach ($query as $key => $value) {
            if (!in_array($key, $this->allowed_page_info_params)) {
                unset($query[$key]);
            }
        }
    }

    /**
     * Resets the pager for future requests.
     */
    public function resetPager()
    {
        $this->paginated_response = null;
    }

    /**
     * Get the PaginatedResponse object from the last request.
     *
     * @return PaginatedResponse
     */
    public function getPaginatedResponse()
    {
        return $this->paginated_response;
    }

    /**
     * Check if there is a next page.
     *
     * @return bool
     */
    public function hasNextPage()
    {
        return $this->paginated_response->hasNextPage();
    }

    /**
     * Check if there is a previous page.
     *
     * @return bool
     */
    public function hasPrevPage()
    {
        return $this->paginated_response->hasPrevPage();
    }

    /**
     * Get the next page of results.
     *
     * @return array|object
     */
    public function getNextPage()
    {
        return $this->paginated_response->getNextPage();
    }

    /**
     * Get the previous page of results.
     *
     * @return array|object
     */
    public function getPrevPage()
    {
        return $this->paginated_response->getPrevPage();
    }

    /**
     * Get the next page params.
     *
     * @return array
     */
    public function getNextPageParams()
    {
        return $this->paginated_response->getNextPageParams();
    }

    /**
     * Get the previous page params.
     *
     * @return array
     */
    public function getPrevPageParams()
    {
        return $this->paginated_response->getPrevPageParams();
    }

    /**
     * Get the JSON response object.
     *
     * @return array|object|null
     */
    protected function getResponseJsonObject(ResponseInterface $response)
    {
        $contents = $response->getBody()->getContents();

        return json_decode($contents);
    }

    /**
     * Get a specific key from the JSON response.
     *
     * @param string $key
     *
     * @return mixed|null
     */
    protected function getResponseJsonObjectKey(ResponseInterface $response, $key)
    {
        if ($object = $this->getResponseJsonObject($response)) {
            if (isset($object->{$key})) {
                return $object->{$key};
            }
        }

        return null;
    }

    /**
     * Sets the default opts.
     */
    protected function setDefaultOpts(array &$opts)
    {
        foreach ($this->default_opts as $key => $value) {
            if (!isset($opts[$key])) {
                $opts[$key] = $value;
            }
        }
    }

    /**
     * Sets call limit params from the Shopify header.
     *
     * If there is an error and we don't get back a valid response we use defaults.
     */
    protected function setCallLimitParams()
    {
        if (empty(json_decode($this->last_response->getBody(), true)) ||
        empty($this->last_response->getHeader(self::CALL_LIMIT_HEADER)[0])) {
            return;
        }

        $limit_parts = explode('/', $this->last_response->getHeader(self::CALL_LIMIT_HEADER)[0]);
        if (isset($limit_parts[0]) && isset($limit_parts[1])) {
            $this->call_limit = (int) $limit_parts[0];
            $this->call_bucket = (int) $limit_parts[1];
        } else {
            $this->call_limit = 0;
            $this->call_bucket = 40;
        }
    }

    /**
     * Perform a GET request to the Shopify API.
     *
     * @param string $resource
     *                         Shopify resource
     * @param array  $opts
     *                         Additional options to pass to the request
     *
     * @return object|array
     *                      Returns the Shopify API response JSON decoded
     *
     * @see Client::request()
     */
    public function get($resource, array $opts = [])
    {
        $response = $this->request('GET', $resource, $opts);

        return $this->getResponseJsonObject($response);
    }

    /**
     * Perform a POST request to the Shopify API.
     *
     * @param string       $resource
     *                               Shopify resource
     * @param object|array $data
     *                               Data to JSON encode and send to the API
     * @param array        $opts
     *                               Additional options to pass to the request
     *
     * @return object|array
     *                      Returns the Shopify API response JSON decoded
     *
     * @see Client::request()
     */
    public function post($resource, $data, array $opts = [])
    {
        $opts['json'] = $data;
        $response = $this->request('POST', $resource, $opts);

        return $this->getResponseJsonObject($response);
    }

    /**
     * Perform a PUT request to the Shopify API.
     *
     * @param string       $resource
     *                               Shopify resource
     * @param object|array $data
     *                               Data to JSON encode and send to the API
     * @param array        $opts
     *                               Additional options to pass to the request
     *
     * @return object|array
     *                      Returns the Shopify API response JSON decoded
     *
     * @see Client::request()
     */
    public function put($resource, $data, array $opts = [])
    {
        $opts['json'] = $data;
        $response = $this->request('PUT', $resource, $opts);

        return $this->getResponseJsonObject($response);
    }

    /**
     * Perform a DELETE request to the Shopify API.
     *
     * @param string $resource
     *                         Shopify resource
     * @param array  $opts
     *                         Additional options to pass to the request
     *
     * @return object|array
     *                      Returns the Shopify API response JSON decoded
     *
     * @see Client::request()
     */
    public function delete($resource, array $opts = [])
    {
        $response = $this->request('DELETE', $resource, $opts);

        return $this->getResponseJsonObject($response);
    }

    /**
     * Checks if the last request produced errors.
     *
     * @return bool
     *              Returns TRUE if the last request had errors
     */
    public function hasErrors()
    {
        return $this->has_errors;
    }

    /**
     * Gets errors from the last response.
     *
     * @return array
     *               Array of errors
     */
    public function getErrors()
    {
        return $this->errors;
    }

    /**
     * Gets the last response object.
     *
     * @return ResponseInterface
     */
    public function getLastResponse()
    {
        return $this->last_response;
    }

    /**
     * Determines if the call limit has been reached.
     *
     * @return bool
     *              Returns TRUE if the call limit has been reached
     */
    public function callLimitReached()
    {
        return $this->getCallLimit() >= 0.8;
    }

    /**
     * Determines the call limit.
     *
     * If result is < 1, limit has not been reached.
     * If result == 1, limit has been reached.
     *
     * @return float
     *               Call limit as ratio decimal
     */
    public function getCallLimit()
    {
        if ((int) $this->call_bucket > 0) {
            return $this->call_limit / $this->call_bucket;
        } else {
            return 0;
        }
    }

    /**
     * Get the current API version being used by the Client.
     *
     * @return string
     */
    public function getAPIVersion()
    {
        return $this->version;
    }

    /**
     * Builds the API URL from the client settings.
     *
     * @return string
     *                API URL
     */
    protected function getApiUrl()
    {
        $url = strtr(self::URL_FORMAT, [
      '{api_key}' => $this->api_key,
      '{password}' => $this->password,
      '{shop_domain}' => $this->shop_domain,
    ]);

        if (!empty($this->version)) {
            $url .= 'api/'.$this->version.'/';
        }

        return $url;
    }

    /**
     * Parses an incoming webhook and validates it.
     *
     * @param bool   $validate
     *                            Whether the webhook data should be validated or not
     * @param string $data
     *                            JSON data to parse. Data we be pulled from php://input if not provided.
     * @param string $hmac_header
     *                            Shopify HMAC header. Default will be pulled from $_SERVER.
     *
     * @return object
     *                Shopify webhook data
     *
     * @throws ClientException
     */
    public function getIncomingWebhook($validate = true, $data = '', $hmac_header = '')
    {
        $webhook = new IncomingWebhook($this->shared_secret);
        if ($validate) {
            try {
                $webhook->validate($data, $hmac_header);
            } catch (WebhookException $e) {
                throw new ClientException('Invalid webhook: '.$e->getMessage(), 0, $e, $this);
            }
        }

        return $webhook->getData();
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
        return hash_hmac('sha256', $data, $secret, false);
    }

    /**
     * Allows you to iterate over paginated resources.
     * This will return a single resource at a time to the iterator.
     *
     * @code
     * foreach ($client->getResourcePager('products', 5) as $product) {
     *   // API will fetch 5 products at a time.
     *   // This will update ALL products in the store.
     *   $client->updateProduct(['title' => $product->title . ' updated']);
     * }
     * @endcode
     *
     * @param string $resource
     *                         Shopify resource
     * @param int    $limit
     *                         Limit resources returned per request. If not set, the default_limit will be used.
     * @param array  $opts
     *                         Additional options to pass to the request. Note: You don't need to set the page/limit.
     *
     * @return \Generator
     */
    public function getResourcePager($resource, $limit = null, array $opts = [])
    {
        if (isset($opts['query']['limit'])) {
            $fetch_total = $opts['query']['limit'];
        }

        if (!isset($opts['query']['limit'])) {
            $opts['query']['limit'] = ($limit ?: $this->default_limit);
        }

        if ($opts['query']['limit'] > 250) {
            // If we are trying to fetch more than 250 items we need to make multiple requests
            // and not return more than what the original limit was.
            $opts['query']['limit'] = 250;
        }

        // Get the first page of results.
        $result = $this->get($resource, $opts);

        $returned_count = 0;

        while (!empty($result)) {
            foreach (get_object_vars($result) as $resource_name => $results) {
                if (empty($results) || (isset($fetch_total) && $returned_count >= $fetch_total)) {
                    return;
                }
                foreach ($results as $object) {
                    ++$returned_count;
                    yield $object;
                }
            }
            // Get subsequent pages of results.
            $result = $this->getNextPage();
        }
    }

    public function getShopInfo(array $fields = [])
    {
        $opts['query']['fields'] = implode(',', $fields);
        $result = $this->get('shop', $opts);
        if (is_object($result)) {
            return $result->shop;
        }

        return $result['shop'];
    }

    /**
     * Gets all resources matching the query from the server.
     *
     * This will fetch ALL resources from the server. May use multiple requests
     * depending on the number of items being returned.
     *
     * @param string $resource
     *                         Shopify resource
     * @param array  $opts
     *                         Additional options
     *
     * @return array
     *               Returns all resource items as an array
     */
    public function getResources($resource, array $opts = [])
    {
        $items = [];
        foreach ($this->getResourcePager($resource, null, $opts) as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * Loads a Shopify resource by it's ID.
     *
     * @param string $resource
     *                         Shopify resource
     * @param int    $id
     *                         Shopify resource ID
     * @param array  $fields
     *                         Array if field query params
     *
     * @return mixed
     */
    public function getResourceById($resource, $id, array $fields = [])
    {
        $opts['query']['fields'] = implode(',', $fields);
        $result = $this->get($resource.'/'.$id, $opts);

        return reset($result);
    }

    /**
     * Updates a given resource by it's ID and returns it.
     *
     * @param string $resource
     *                         Shopify resource
     * @param int    $id
     *                         Shopify resource ID
     * @param array  $data
     *                         Update data array
     *
     * @return mixed
     */
    public function updateResource($resource, $id, array $data = [])
    {
        $result = $this->put($resource.'/'.$id, $data);

        return reset($result);
    }

    /**
     * Deletes a Shopify resource.
     *
     * @param string $resource
     *                         Shopify resource.
     *                         Shopify resource ID.
     * @param array  $opts
     *                         Options to pass to the query
     *
     * @return array|object|null
     */
    public function deleteResource($resource, $id, array $opts = [])
    {
        return $this->delete($resource.'/'.$id, $opts);
    }

    /**
     * Gets the count of the resource based on the passed filters.
     *
     * @param string $resource
     *                         Shopify resource
     * @param array  $filters
     *                         Array of filters
     *
     * @return int
     *             Returns the count
     */
    public function getResourceCount($resource, array $filters = [])
    {
        $opts['query'] = $filters;
        $result = $this->get($resource.'/count', $opts);
        if (is_object($result)) {
            return (int) $result->count;
        }

        throw new ClientException('Count is not found');
    }

    /**
     * Creates a resource and returns it's full data.
     *
     * @param string $resource
     *                         Shopify resource
     * @param array  $data
     *                         Shopify resource data
     *
     * @return mixed
     */
    public function createResource($resource, array $data = [])
    {
        $result = $this->post($resource, $data);

        return reset($result);
    }

    /**
     * Gets all products matching the filter query.
     *
     * @param array $opts
     *                    Options to pass to the request
     *
     * @return array
     *               Array of products
     */
    public function getProducts(array $opts = [])
    {
        return $this->getResources('products', $opts);
    }

    /**
     * Get a specific product.
     *
     * @param int   $id
     *                      Product ID
     * @param array $fields
     *                      Specific product fields to return
     *
     * @return object
     */
    public function getProduct($id, array $fields = [])
    {
        return $this->getResourceById('products', $id, $fields);
    }

    /**
     * Count products based on passed filters.
     *
     * @param array $filters
     *                       Filters
     *
     * @return int
     */
    public function getProductsCount(array $filters = [])
    {
        return $this->getResourceCount('products', $filters);
    }

    /**
     * Creates a product.
     *
     * @param array $product
     *                       A valid Shopify product
     *
     * @return object
     */
    public function createProduct(array $product = [])
    {
        $data = ['product' => $product];

        return $this->createResource('products', $data);
    }

    /**
     * Updates a specific product with new params.
     *
     * @param int   $id
     *                              Product ID
     * @param array $update_product
     *                              New product values
     *
     * @return object
     */
    public function updateProduct($id, array $update_product = [])
    {
        $data['product'] = $update_product;

        return $this->updateResource('products', $id, $data);
    }

    /**
     * Deletes a specific product.
     *
     * @param int $id
     *                Product ID to delete
     *
     * @return object
     */
    public function deleteProduct($id)
    {
        return $this->deleteResource('products', $id);
    }
}
