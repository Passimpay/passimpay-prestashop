<?php

/**
 * Class PassimpayMerchantAPI
 *
 * @property integer orderId
 * @property integer Count
 * @property bool|string error
 * @property bool|string response
 * @property bool|string customerKey
 * @property bool|string status
 * @property bool|string paymentUrl
 * @property bool|string paymentId
 */
class PassimpayMerchantAPI
{

    /**
     * Статус платежа: Заверешено, оплачено
     */
    const PAYMENT_STATUS_COMPLETED = 'paid';

    /**
     * Статус платежа: Ошибка
     */
    const PAYMENT_STATUS_ERROR = 'error';

    /**
     * Статус платежа: Ошибка запроса
     */
    const PAYMENT_STATUS_REQUEST_ERROR = 'request_error';

    /**
     * Статус платежа: в обработке
     */
    const PAYMENT_STATUS_PROCESSING = 'wait';

    private $_api_url;
    private $_platformId;
    private $_secretKey;
    private $_paymentId;
    private $_status;
    private $_error;
    private $_response;
    private $_paymentUrl;

    /**
     * Constructor
     *
     * @param string $platformId Your platform ID
     * @param string $secretKey Secret key for platform_id
     * @param string $api_url Url for API
     */
    public function __construct($platformId, $secretKey)
    {
        $this->_api_url = 'https://passimpay.io/api';
        $this->_platformId = $platformId;
        $this->_secretKey = $secretKey;
    }

    /**
     * Get class property or json key value
     *
     * @param mixed $name Name for property or json key
     *
     * @return bool|string
     */
    public function __get($name)
    {
        switch ($name) {
            case 'paymentId':
                return $this->_paymentId;
            case 'status':
                return $this->_status;
            case 'error':
                return $this->_error;
            case 'paymentUrl':
                return $this->_paymentUrl;
            case 'response':
                return htmlentities($this->_response);
            default:
                if ($this->_response) {
                    if ($json = json_decode($this->_response, true)) {
                        foreach ($json as $key => $value) {
                            if (strtolower($name) == strtolower($key)) {
                                return $json[$key];
                            }
                        }
                    }
                }

                return false;
        }
    }

    /**
     * @return string
     */
    public function getApiUrl()
    {
        return $this->_api_url;
    }

    /**
     * @return string
     */
    public function getPlatformId()
    {
        return $this->_platformId;
    }

    /**
     * @return string
     */
    public function getSecretKey()
    {
        return $this->_secretKey;
    }

    /**
     * Initialize the payment
     *
     * @param mixed $args mixed You could use associative array or url params string
     *
     * @return bool
     */
    public function init($args)
    {
        return $this->buildQuery('createorder', $args);
    }

    /**
     * Get Order status
     *
     * @param $orderId
     * @return mixed
     */
    public function getOrderStatus($orderId)
    {
        $result = $this->buildQuery('orderstatus', ['platform_id' => $this->getPlatformId(), 'order_id' => $orderId ]);

        if (!$result->error && $result->status) {
            $status = $result->status; // paid, error, wait
        } else {
            $status = static::PAYMENT_STATUS_REQUEST_ERROR;
        }

        return $status;
    }

    /**
     * Builds a query string and call sendRequest method.
     * Could be used to custom API call method.
     *
     * @param string $path API method name
     * @param mixed  $args query params
     *
     * @return mixed
     * @throws HttpException
     */
    public function buildQuery($path, $args)
    {
        $url = $this->_api_url;
        if (is_array($args)) {
            if (!array_key_exists('platform_id', $args)) {
                $args['platform_id'] = $this->_platformId;
            }
            if (!array_key_exists('apikey', $args)) {
                $args['apikey'] = $this->_secretKey;
            }
            if (!array_key_exists('hash', $args)) {
                $apikey = $args['apikey'];
                unset($args['apikey']);
                krsort($args);
                $payload = http_build_query($args);
                $args['hash'] = hash_hmac('sha256', $payload, $apikey);
            }
        }
        $url = $this->_combineUrl($url, $path);
        return $this->_sendRequest($url, $args);
    }

    /**
     * Combines parts of URL. Simply gets all parameters and puts '/' between
     *
     * @return string
     */
    private function _combineUrl()
    {
        $args = func_get_args();
        $url = '';
        foreach ($args as $arg) {
            if (is_string($arg)) {
                if ($arg[strlen($arg) - 1] !== '/') {
                    $arg .= '/';
                }
                $url .= $arg;
            } else {
                continue;
            }
        }

        return rtrim($url, '/');
    }

    /**
     * Main method. Call API with params
     *
     * @param string $url     API Url
     * @param array  $args    API params
     *
     * @return mixed
     * @throws HttpException
     */
    private function _sendRequest($url, $args)
    {
        $this->_error = '';

        $postData = http_build_query($args);

        if ($curl = curl_init()) {
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $postData);
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
            curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
            $out = curl_exec($curl);
            $this->_response = $out;

            $json = json_decode($out);
            if ($json) {
                if (@$json->result !== 1) {
                    $this->_error = @$json->message;
                } else {
                    $this->_paymentUrl = @$json->url;
//                    $this->_paymentId = md5($this->_paymentUrl);
                    $this->_status = @$json->status;
                }
            }

            curl_close($curl);

            return $this;

        } else {
            throw new HttpException(
                'Can not create connection to ' . $url . ' with args '
                . json_encode($args), 404
            );
        }
    }

    /**
     * Validate params from request
     *
     * @param $params
     * @param $hash
     * @return bool
     */
    public function checkHash($params, $hash)
    {
        $apikey = $this->getSecretKey();

        $payload = http_build_query($params);

        if (!isset($hash) || hash_hmac('sha256', $payload, $apikey) != $hash)
        {
            return false;
        }

        return true;
    }

    /**
     * Проверяем, оплачен ли заказ
     *
     * @param $orderId
     * @return bool
     */
    public function orderStatusIsCompleted($orderId)
    {
        $orderStatus = $this->getOrderStatus($orderId);
        file_put_contents('tmp.log', $orderId . ' : ' . $orderStatus . PHP_EOL, FILE_APPEND);
        //die($this->helper->getOrderStatus($orderId));
        return $orderStatus === $this::PAYMENT_STATUS_COMPLETED;
    }
}
