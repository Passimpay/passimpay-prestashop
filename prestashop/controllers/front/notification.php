<?php

use Passimpay\PassimpayMerchantAPI;

class PassimpayNotificationModuleFrontController extends ModuleFrontController
{
    const LOG_PREFIX = 'Passimpay: Order #';
    const WEBHOOK_PREFIX = 'Passimpay webhook: ';
    
    public function init()
    {
        parent::init();
    }

    public function postProcess()
    {
        $params = $_POST;

        if (!$this->validateRequest($params)) {
            die('NOTOK');
        }

        $orderId = (int)$params['order_id'];
        $order = new Order($orderId);

        if (!Validate::isLoadedObject($order)) {
            PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Order #' . $orderId . ' not found', 2);
            die('NOTOK');
        }

        $api = $this->initializeAPI();
        if (!$api) {
            die('NOTOK');
        }

        if (!$this->verifyHash($api, $params, $orderId)) {
            die('NOTOK');
        }

        $this->logTransactionIfPresent($params, $order);

        $paymentStatus = $api->getOrderStatus($orderId);
        $this->handlePaymentStatus($paymentStatus, $order, $orderId);
    }

    private function validateRequest($params)
    {
        if (empty($params)) {
            PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Empty POST data', 2);
            return false;
        }

        $requiredFields = ['order_id', 'platform_id', 'hash'];
        foreach ($requiredFields as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Missing field ' . $field, 2);
                return false;
            }
        }

        return true;
    }

    private function initializeAPI()
    {
        $config = Configuration::getMultiple(['PP_PLATFORM_ID', 'PP_SECRET_KEY']);
        
        if (empty($config['PP_PLATFORM_ID']) || empty($config['PP_SECRET_KEY'])) {
            PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Module not configured', 3);
            return null;
        }

        return new PassimpayMerchantAPI($config['PP_PLATFORM_ID'], $config['PP_SECRET_KEY']);
    }

    private function verifyHash($api, $params, $orderId)
    {
        $data = [
            'platform_id'   => (int) $params['platform_id'],
            'payment_id'    => isset($params['payment_id']) ? (int) $params['payment_id'] : 0,
            'order_id'      => (int) $params['order_id'],
            'amount'        => isset($params['amount']) ? $params['amount'] : '0',
            'txhash'        => isset($params['txhash']) ? $params['txhash'] : '',
            'address_from'  => isset($params['address_from']) ? $params['address_from'] : '',
            'address_to'    => isset($params['address_to']) ? $params['address_to'] : '',
            'fee'           => isset($params['fee']) ? $params['fee'] : '0',
        ];
        
        if (isset($params['confirmations'])) {
            $data['confirmations'] = (int) $params['confirmations'];
        }

        if (!$api->checkHash($data, $params['hash'])) {
            PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Invalid hash for order #' . $orderId, 3);
            return false;
        }

        return true;
    }

    private function logTransactionIfPresent($params, $order)
    {
        if (empty($params['txhash'])) {
            return;
        }

        $message = new Message();
        $message->message = sprintf(
            'Passimpay transaction: %s, TxHash: %s',
            isset($params['amount']) ? $params['amount'] : 'N/A',
            $params['txhash']
        );
        $message->id_order = (int)$order->id;
        $message->private = 1;
        $message->add();
    }

    private function handlePaymentStatus($paymentStatus, $order, $orderId)
    {
        switch ($paymentStatus) {
            case PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED:
                $this->handleCompletedPayment($order, $orderId);
                break;

            case PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING:
                $this->handleProcessingPayment($order, $orderId);
                break;

            case PassimpayMerchantAPI::PAYMENT_STATUS_ERROR:
                $this->handleErrorPayment($order, $orderId);
                break;

            default:
                $this->handleUnknownStatus($order, $orderId, $paymentStatus);
        }
    }

    private function handleCompletedPayment($order, $orderId)
    {
        $paidStatusId = (int)Configuration::get('PS_OS_PAYMENT');
        
        if ($order->current_state == $paidStatusId) {
            PrestaShopLogger::addLog(self::LOG_PREFIX . $orderId . ' already paid', 1);
            die('OK');
        }

        $order->setCurrentState($paidStatusId);
        $this->addOrderMessage($order, 'Payment completed');
        
        PrestaShopLogger::addLog(self::LOG_PREFIX . $orderId . ' marked as paid', 1);
        die('OK');
    }

    private function handleProcessingPayment($order, $orderId)
    {
        $this->addOrderMessage($order, 'Partial payment received');
        PrestaShopLogger::addLog(self::LOG_PREFIX . $orderId . ' payment processing', 1);
        die('PROCESSING');
    }

    private function handleErrorPayment($order, $orderId)
    {
        $errorStatusId = (int)Configuration::get('PS_OS_ERROR');
        
        if ($order->current_state != $errorStatusId) {
            $order->setCurrentState($errorStatusId);
            $this->addOrderMessage($order, 'Payment failed');
        }
        
        PrestaShopLogger::addLog(self::LOG_PREFIX . $orderId . ' payment error', 2);
        die('ERROR');
    }

    private function handleUnknownStatus($order, $orderId, $paymentStatus)
    {
        $this->addOrderMessage($order, 'Unable to verify payment status');
        PrestaShopLogger::addLog(self::LOG_PREFIX . $orderId . ' unknown status: ' . $paymentStatus, 2);
        die('API_ERROR');
    }

    private function addOrderMessage($order, $messageText)
    {
        $message = new Message();
        $message->message = 'Passimpay: ' . $messageText;
        $message->id_order = (int)$order->id;
        $message->private = 1;
        $message->add();
    }
}
