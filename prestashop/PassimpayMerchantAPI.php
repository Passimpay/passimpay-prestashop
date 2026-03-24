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
    
        if (isset($args['payment_id'])) {
            $body['paymentId'] = (string)$args['payment_id'];
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
        
        if ($json && isset($json['result']) && $json['result'] == 1) {
            return isset($json['status']) ? $json['status'] : 'error';
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
        
        $jsonBody = json_encode($body, JSON_UNESCAPED_SLASHES);
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
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            $this->_error = 'CURL Error: ' . curl_error($ch);
            curl_close($ch);
            return $this;
        }
        
        curl_close($ch);
        
        $this->_response = $response;
        $json = json_decode($response, true);
        
        if (!$json) {
            $this->_error = 'Invalid JSON response';
            return $this;
        }
        
        if (isset($json['result']) && $json['result'] == 1) {
            if (isset($json['url'])) {
                $this->_paymentUrl = $json['url'];
            }
        } else {
            $this->_error = isset($json['message']) ? $json['message'] : 'Unknown API error';
        }
        
        return $this;
    }

    public function checkHash($params, $hash)
    {
        if (empty($hash)) {
            return false;
        }
        
        $payload = http_build_query($params);
        $calculatedHash = hash_hmac('sha256', $payload, $this->_secretKey);
        
        return hash_equals($calculatedHash, $hash);
    }
}
