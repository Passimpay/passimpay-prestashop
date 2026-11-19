<?php

require_once __DIR__ . '/../../PassimpayMerchantAPI.php';

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
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                http_response_code(405);
                die('METHOD_NOT_ALLOWED');
            }
            $rawBody = file_get_contents('php://input', false, null, 0, 65537);
            if ($rawBody === false || strlen($rawBody) > 65536) {
                http_response_code(413);
                die('INVALID_BODY');
            }
            $params = json_decode($rawBody, true);
            $api = $this->initializeAPI();
            if (!$api) {
                http_response_code(503);
                die('NOT_CONFIGURED');
            }
            if (!$this->validateRequest($params, $api)) {
                http_response_code(400);
                die('INVALID_PAYLOAD');
            }
            $orderId = (int)$params['orderId'];
            if (!$this->verifyHash($api, $params, $orderId)) {
                http_response_code(403);
                die('INVALID_SIGNATURE');
            }

            // Один заказ обрабатывается последовательно даже при повторной доставке.
            $lock = 'pp_ps_' . substr(hash('sha256', _DB_NAME_ . ';' . _DB_PREFIX_ . ';' . $orderId), 0, 50);
            if ((int)Db::getInstance()->getValue("SELECT GET_LOCK('" . $lock . "', 0)") !== 1) {
                http_response_code(503);
                die('BUSY');
            }
            register_shutdown_function(function () use ($lock) {
                Db::getInstance()->getValue("SELECT RELEASE_LOCK('" . $lock . "')");
            });
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order) || $order->module !== 'passimpay') {
                http_response_code(404);
                die('ORDER_NOT_FOUND');
            }
            if ((int)$order->current_state === (int)Configuration::get('PS_OS_PAYMENT')) {
                http_response_code(200);
                die('OK');
            }

            $paymentStatus = $api->getOrderStatus($orderId);
            if (!in_array($paymentStatus, ['paid', 'wait', 'error'], true)) {
                $this->handleUnknownStatus($order, $orderId, $paymentStatus);
            }
            $this->logTransactionIfPresent($params, $order);
            $this->handlePaymentStatus($paymentStatus, $order, $orderId);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Notification processing failed', 3);
            http_response_code(503);
            die('PROCESSING_ERROR');
        }
    }

    private function validateRequest($params, $api)
    {
        return $api->validateWebhook($params);
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
        $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
        if (!$api->checkHash($params, $signature)) {
            PrestaShopLogger::addLog(self::WEBHOOK_PREFIX . 'Invalid signature for order #' . $orderId, 3);
            return false;
        }

        return true;
    }

    private function logTransactionIfPresent($params, $order)
    {
        if (empty($params['txhash'])) {
            return;
        }

        $text = sprintf(
            'transaction: %s, TxHash: %s',
            isset($params['amount']) ? $params['amount'] : 'N/A',
            $params['txhash']
        );
        $this->addOrderMessage($order, $text, 'Passimpay ');
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
        http_response_code(503);
        PrestaShopLogger::addLog(self::LOG_PREFIX . $orderId . ' unknown status: ' . $paymentStatus, 2);
        die('API_ERROR');
    }

    private function addOrderMessage($order, $messageText, $prefix = 'Passimpay: ')
    {
        $text = $prefix . htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
        if (Db::getInstance()->getValue('SELECT id_message FROM `' . _DB_PREFIX_ . 'message` WHERE id_order = '
            . (int)$order->id . " AND message = '" . pSQL($text, true) . "'")) {
            return;
        }
        $message = new Message();
        $message->message = $text;
        $message->id_order = (int)$order->id;
        $message->private = 1;
        $message->add();
    }
}
