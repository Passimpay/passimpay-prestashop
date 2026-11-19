<?php
/**
 * Passimpay Webhook Handler (Standalone)
 * URL: https://your-domain.com/modules/passimpay/webhook.php
 */

@ini_set('max_execution_time', 60);
@ini_set('memory_limit', '256M');

try {
    $configPath = __DIR__ . '/../../config/config.inc.php';
    
    if (!file_exists($configPath)) {
        http_response_code(500);
        die('CONFIG_NOT_FOUND');
    }
    
    require_once($configPath);
    
    if (!Context::getContext()->shop->id) {
        $shop = new Shop(Configuration::get('PS_SHOP_DEFAULT'));
        Context::getContext()->shop = $shop;
    }
    
    if (!Context::getContext()->currency) {
        Context::getContext()->currency = new Currency(Configuration::get('PS_CURRENCY_DEFAULT'));
    }
    
    if (!Context::getContext()->language) {
        Context::getContext()->language = new Language(Configuration::get('PS_LANG_DEFAULT'));
    }
    
    if (!Context::getContext()->country) {
        Context::getContext()->country = new Country(Configuration::get('PS_COUNTRY_DEFAULT'));
    }
    
    require_once(__DIR__ . '/PassimpayMerchantAPI.php');
    
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
    $config = Configuration::getMultiple(['PP_PLATFORM_ID', 'PP_SECRET_KEY']);
    if (empty($config['PP_PLATFORM_ID']) || empty($config['PP_SECRET_KEY'])) {
        http_response_code(503);
        die('NOT_CONFIGURED');
    }
    $api = new PassimpayMerchantAPI($config['PP_PLATFORM_ID'], $config['PP_SECRET_KEY']);
    if (!$api->validateWebhook($params)) {
        http_response_code(400);
        die('INVALID_PAYLOAD');
    }
    if (!$api->checkHash($params, $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
        http_response_code(403);
        die('INVALID_SIGNATURE');
    }
    $orderId = (int)$params['orderId'];
    // Оба маршрута уведомлений используют одну блокировку заказа.
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
        http_response_code(503);
        die('API_ERROR');
    }

    // Для штатных хуков смены статуса нужен контейнер фронт-контроллера.
    Context::getContext()->container = \PrestaShop\PrestaShop\Adapter\ContainerBuilder::getContainer('front', _PS_MODE_DEV_);

    if (!empty($params['txhash'])) {
        try {
            $message = new Message();
            $message->message = htmlspecialchars(sprintf(
                'Passimpay transaction: %s, TxHash: %s',
                isset($params['amount']) ? $params['amount'] : 'N/A',
                $params['txhash']
            ), ENT_QUOTES, 'UTF-8');
            $message->id_order = (int)$order->id;
            $message->private = 1;
            if (!Db::getInstance()->getValue('SELECT id_message FROM `' . _DB_PREFIX_ . 'message` WHERE id_order = '
                . (int)$order->id . " AND message = '" . pSQL($message->message, true) . "'")) {
                $message->add();
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Passimpay: Failed to add transaction message', 2);
        }
    }

    switch ($paymentStatus) {
        case PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED:
            $paidStatusId = (int)Configuration::get('PS_OS_PAYMENT');
            
            if ($order->current_state == $paidStatusId) {
                PrestaShopLogger::addLog('Passimpay: Order #' . $orderId . ' already paid', 1);
                die('OK');
            }

            $order->setCurrentState($paidStatusId);
            
            try {
                $message = new Message();
                $message->message = 'Passimpay: Payment completed';
                $message->id_order = (int)$order->id;
                $message->private = 1;
                if (!Db::getInstance()->getValue('SELECT id_message FROM `' . _DB_PREFIX_ . 'message` WHERE id_order = '
                    . (int)$order->id . " AND message = '" . pSQL($message->message, true) . "'")) {
                    $message->add();
                }
            } catch (Exception $e) {
                PrestaShopLogger::addLog('Passimpay: Failed to add completion message', 2);
            }

            PrestaShopLogger::addLog('Passimpay: Order #' . $orderId . ' marked as paid', 1);
            die('OK');
            break;

        case PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING:
            try {
                $message = new Message();
                $message->message = 'Passimpay: Partial payment received';
                $message->id_order = (int)$order->id;
                $message->private = 1;
                if (!Db::getInstance()->getValue('SELECT id_message FROM `' . _DB_PREFIX_ . 'message` WHERE id_order = '
                    . (int)$order->id . " AND message = '" . pSQL($message->message, true) . "'")) {
                    $message->add();
                }
            } catch (Exception $e) {}
            
            PrestaShopLogger::addLog('Passimpay: Order #' . $orderId . ' payment processing', 1);
            die('PROCESSING');
            break;

        case PassimpayMerchantAPI::PAYMENT_STATUS_ERROR:
            $errorStatusId = (int)Configuration::get('PS_OS_ERROR');
            
            if ($order->current_state != $errorStatusId) {
                $order->setCurrentState($errorStatusId);
                
                try {
                    $message = new Message();
                    $message->message = 'Passimpay: Payment failed';
                    $message->id_order = (int)$order->id;
                    $message->private = 1;
                    if (!Db::getInstance()->getValue('SELECT id_message FROM `' . _DB_PREFIX_ . 'message` WHERE id_order = '
                        . (int)$order->id . " AND message = '" . pSQL($message->message, true) . "'")) {
                        $message->add();
                    }
                } catch (Exception $e) {}
            }
            
            PrestaShopLogger::addLog('Passimpay: Order #' . $orderId . ' payment error', 2);
            die('ERROR');
            break;

        default:
            http_response_code(503);
            die('API_ERROR');
    }
    
} catch (Exception $e) {
    PrestaShopLogger::addLog('Passimpay webhook exception: ' . $e->getMessage(), 3);
    http_response_code(500);
    die('EXCEPTION');
} catch (Error $e) {
    PrestaShopLogger::addLog('Passimpay webhook fatal error: ' . $e->getMessage(), 3);
    http_response_code(500);
    die('FATAL_ERROR');
}
