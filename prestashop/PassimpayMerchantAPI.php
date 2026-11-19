<?php

class PassimpayMerchantAPI
{
    const PAYMENT_STATUS_COMPLETED = 'paid';
    const PAYMENT_STATUS_ERROR = 'error';
    const PAYMENT_STATUS_PROCESSING = 'wait';

    private $_api_url;
    private $_platformId;
    private $_secretKey;
    private $_error;
    private $_response;
    private $_paymentUrl;

    public function __construct($platformId, $secretKey)
    {
        $this->_api_url = 'https://api.passimpay.io';
        $this->_platformId = $platformId;
        $this->_secretKey = $secretKey;
    }

    public function __get($name)
    {
        switch ($name) {
            case 'error':
                return $this->_error;
            case 'paymentUrl':
                return $this->_paymentUrl;
            case 'response':
                return $this->_response;
            default:
                if ($this->_response) {
                    $json = json_decode($this->_response, true);
                    if ($json && isset($json[$name])) {
                        return $json[$name];
                    }
                }
                return false;
        }
    }

    public function getPlatformId()
    {
        return $this->_platformId;
    }

    public function getSecretKey()
    {
        return $this->_secretKey;
    }

    public function init($args)
    {
        $url = $this->_api_url . '/v2/createorder';
    
        $body = array(
            'platformId' => (int)$this->_platformId,
            'orderId' => (string)$args['order_id'],
            'amount' => (string)$args['amount']
        );
    
        if (isset($args['symbol']) && !empty($args['symbol'])) {
            $body['symbol'] = strtoupper($args['symbol']);
        }
    
        if (isset($args['currencies']) && !empty($args['currencies'])) {
            $body['currencies'] = $args['currencies'];
        }
    
        if (isset($args['type'])) {
            $body['type'] = (int)$args['type'];
        }
    
        return $this->_sendV2Request($url, $body);
    }

    public function getOrderStatus($orderId)
    {
        $url = $this->_api_url . '/v2/orderstatus';
        
        $body = array(
            'platformId' => (int)$this->_platformId,
            'orderId' => (string)$orderId
        );
        
        $result = $this->_sendV2Request($url, $body);
        
        $json = json_decode($this->_response, true);
        
        if (!$this->_error && is_array($json) && isset($json['result'], $json['status']) && $json['result'] === 1
            && in_array($json['status'], ['paid', 'wait', 'error'], true)) {
            return $json['status'];
        }
        
        return 'request_error';
    }

    public function orderStatusIsCompleted($orderId)
    {
        $status = $this->getOrderStatus($orderId);
        return $status === self::PAYMENT_STATUS_COMPLETED;
    }

    private function _sendV2Request($url, $body)
    {
        $this->_error = '';
        $this->_paymentUrl = '';
        $this->_response = '';

        if ($body['platformId'] <= 0 || !is_string($this->_secretKey) || $this->_secretKey === ''
            || !preg_match('~^[A-Za-z0-9+/=,:._-]{1,64}$~D', $body['orderId'])
            || (isset($body['amount']) && !preg_match('/^[0-9]+\.[0-9]{2}$/D', $body['amount']))
            || (isset($body['symbol']) && !preg_match('/^[A-Z]{3}$/D', $body['symbol']))
            || (isset($body['type']) && !in_array($body['type'], [0, 1, 2], true))
            || (isset($body['currencies']) && (!is_string($body['currencies']) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $body['currencies'])))) {
            $this->_error = 'Invalid payment parameters';
            return $this;
        }
        
        $jsonBody = json_encode($body);
        if ($jsonBody === false) {
            $this->_error = 'Unable to encode request';
            return $this;
        }
        $signatureString = (int)$this->_platformId . ';' . $jsonBody . ';' . $this->_secretKey;
        $signature = hash_hmac('sha256', $signatureString, $this->_secretKey);
        
        $headers = array(
            'Content-Type: application/json',
            'x-signature: ' . $signature
        );
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $this->_error = 'CURL Error: ' . curl_error($ch);
            curl_close($ch);
            return $this;
        }
        
        curl_close($ch);
        
        $this->_response = $response;
        $json = json_decode($response, true);
        
        if (!is_array($json)) {
            $this->_error = 'Invalid JSON response';
            return $this;
        }
        
        if ($httpCode === 200 && isset($json['result']) && $json['result'] === 1) {
            if (isset($json['url'])) {
                $parts = is_string($json['url']) ? parse_url($json['url']) : false;
                if (!$parts || !filter_var($json['url'], FILTER_VALIDATE_URL) || $parts['scheme'] !== 'https'
                    || isset($parts['user']) || isset($parts['pass'])) {
                    $this->_error = 'Invalid payment URL';
                    return $this;
                }
                $this->_paymentUrl = $json['url'];
            }
        } else {
            $this->_error = isset($json['message']) && is_string($json['message']) ? $json['message'] : 'API request failed';
        }
        
        return $this;
    }

    public function checkHash($params, $hash)
    {
        if (!$this->validateWebhook($params) || !is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)
            || !is_string($this->_secretKey) || $this->_secretKey === '') {
            return false;
        }
        
        // Проверяем полный JSON до преобразования полей для CMS.
        $payload = json_encode($params);
        if ($payload === false) {
            return false;
        }
        $contract = (int)$this->_platformId . ';' . $payload . ';' . $this->_secretKey;
        $calculatedHash = hash_hmac('sha256', $contract, $this->_secretKey);
        
        return hash_equals($calculatedHash, $hash);
    }

    public function validateWebhook($params)
    {
        if (!is_array($params) || !isset($params['type'], $params['platformId'], $params['orderId'])
            || $params['type'] !== 'deposit' || !is_int($params['platformId']) || $params['platformId'] <= 0
            || $params['platformId'] !== (int)$this->_platformId || !is_string($params['orderId'])
            || strlen($params['orderId']) > 64 || !preg_match('/^[1-9][0-9]*$/D', $params['orderId'])
            || filter_var($params['orderId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return false;
        }
        foreach (['paymentId', 'confirmations', 'destinationTag'] as $field) {
            if (array_key_exists($field, $params) && (!is_int($params[$field]) || $params[$field] < ($field === 'paymentId' ? 1 : 0))) {
                return false;
            }
        }
        foreach (['amount', 'amountReceive', 'feeService', 'feeNetwork'] as $field) {
            if (array_key_exists($field, $params) && (!is_string($params[$field]) || strlen($params[$field]) > 128
                || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $params[$field]))) {
                return false;
            }
        }
        foreach (['txhash', 'addressFrom', 'addressTo'] as $field) {
            if (array_key_exists($field, $params) && (!is_string($params[$field]) || strlen($params[$field]) > 512)) {
                return false;
            }
        }
        return true;
    }
}
