<?php

class PassimpayValidationModuleFrontController extends ModuleFrontController
{
    const ORDER_STEP_URL = 'index.php?controller=order&step=1';
    const LOG_PREFIX = 'Passimpay: ';
    const LOG_ORDER_PREFIX = 'Passimpay: Order #';

    public function init()
    {
        parent::init();
        
        if (!class_exists('PassimpayMerchantAPI')) {
            require_once dirname(__FILE__) . '/../../PassimpayMerchantAPI.php';
        }
    }

    public function postProcess()
    {
        $cart = $this->context->cart;
        
        if (!Validate::isLoadedObject($cart)) {
            Tools::redirect(self::ORDER_STEP_URL);
        }
        
        $customer = new Customer($cart->id_customer);
        
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect(self::ORDER_STEP_URL);
        }
        
        $passimpay = Module::getInstanceByName('passimpay');
        
        if (!$passimpay) {
            Tools::redirect(self::ORDER_STEP_URL);
        }
        
        try {
            $result = $passimpay->validateOrder(
                (int)$cart->id,
                (int)Configuration::get('PS_OS_BANKWIRE'),
                (float)$cart->getOrderTotal(true, Cart::BOTH),
                $passimpay->displayName,
                null,
                [],
                (int)$cart->id_currency,
                false,
                $customer->secure_key
            );
            
            if (!$result) {
                throw new Exception('validateOrder failed');
            }
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . 'validateOrder error - ' . $e->getMessage(), 3);
            Tools::redirect(self::ORDER_STEP_URL);
        }
        
        $orderId = isset($passimpay->currentOrder) && $passimpay->currentOrder 
            ? (int)$passimpay->currentOrder 
            : Order::getByCartId((int)$cart->id);
        
        if (!$orderId) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . 'Order creation failed for cart ' . $cart->id, 3);
            Tools::redirect(self::ORDER_STEP_URL);
        }
        
        PrestaShopLogger::addLog(self::LOG_ORDER_PREFIX . $orderId . ' created', 1);
        
        $paymentUrl = $this->getPaymentUrl($orderId);
        
        if ($paymentUrl) {
            Tools::redirect($paymentUrl);
        } else {
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $passimpay->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
        }
    }

    private function getPaymentUrl($orderId)
    {
        $result = false;
        
        try {
            $config = $this->getModuleConfig();
            $order = $this->loadOrder($orderId);
            
            if ($config && $order) {
                $result = $this->requestPaymentUrl($order, $config);
            }
            
        } catch (Exception $e) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . 'exception: ' . $e->getMessage(), 3);
        }
        
        return $result;
    }

    private function getModuleConfig()
    {
        $config = Configuration::getMultiple(['PP_PLATFORM_ID', 'PP_SECRET_KEY']);
        
        if (empty($config['PP_PLATFORM_ID']) || empty($config['PP_SECRET_KEY'])) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . 'Module not configured', 3);
            return null;
        }
        
        return $config;
    }

    private function loadOrder($orderId)
    {
        $order = new Order($orderId);
        
        if (!Validate::isLoadedObject($order)) {
            PrestaShopLogger::addLog(self::LOG_ORDER_PREFIX . $orderId . ' not found', 3);
            return null;
        }
        
        return $order;
    }

    private function requestPaymentUrl($order, $config)
    {
        $totalAmount = (float)$order->total_paid;
        $currency = new Currency($order->id_currency);
        
        $requestData = [
            'order_id' => (string)$order->id,
            'amount' => number_format($totalAmount, 2, '.', ''),
            'symbol' => $currency->iso_code
        ];
        
        $api = new PassimpayMerchantAPI($config['PP_PLATFORM_ID'], $config['PP_SECRET_KEY']);
        $apiResult = $api->init($requestData);
        
        if ($apiResult->error) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . 'API error: ' . $apiResult->error, 3);
            return false;
        }
        
        $paymentUrl = $apiResult->paymentUrl;
        
        if (empty($paymentUrl)) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . 'Empty payment URL', 3);
            return false;
        }
        
        return $paymentUrl;
    }
}