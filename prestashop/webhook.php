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
    
    $params = $_POST;

    if (empty($params)) {
        PrestaShopLogger::addLog('Passimpay webhook: Empty POST data', 2);
        die('NOTOK');
    }

    $requiredFields = ['order_id', 'platform_id', 'hash'];
    foreach ($requiredFields as $field) {
        if (!isset($params[$field]) || $params[$field] === '') {
            PrestaShopLogger::addLog('Passimpay webhook: Missing field ' . $field, 2);
            die('NOTOK');
        }
    }

    $orderId = (int)$params['order_id'];
    $order = new Order($orderId);

    if (!Validate::isLoadedObject($order)) {
        PrestaShopLogger::addLog('Passimpay webhook: Order #' . $orderId . ' not found', 2);
        die('NOTOK');
    }

    $config = Configuration::getMultiple(['PP_PLATFORM_ID', 'PP_SECRET_KEY']);
    
    if (empty($config['PP_PLATFORM_ID']) || empty($config['PP_SECRET_KEY'])) {
        PrestaShopLogger::addLog('Passimpay webhook: Module not configured', 3);
        die('NOTOK');
    }

    $api = new PassimpayMerchantAPI($config['PP_PLATFORM_ID'], $config['PP_SECRET_KEY']);
    
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
        PrestaShopLogger::addLog('Passimpay webhook: Invalid hash for order #' . $orderId, 3);
        die('NOTOK');
    }

    if (!empty($params['txhash'])) {
        try {
            $message = new Message();
            $message->message = sprintf(
                'Passimpay transaction: %s, TxHash: %s',
                isset($params['amount']) ? $params['amount'] : 'N/A',
                $params['txhash']
            );
            $message->id_order = (int)$order->id;
            $message->private = 1;
            $message->add();
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Passimpay: Failed to add transaction message', 2);
        }
    }

    $paymentStatus = $api->getOrderStatus($orderId);

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
                $message->add();
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
                $message->add();
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
                    $message->add();
                } catch (Exception $e) {}
            }
            
            PrestaShopLogger::addLog('Passimpay: Order #' . $orderId . ' payment error', 2);
            die('ERROR');
            break;

        default:
            try {
                $message = new Message();
                $message->message = 'Passimpay: Unable to verify payment status';
                $message->id_order = (int)$order->id;
                $message->private = 1;
                $message->add();
            } catch (Exception $e) {}
            
            PrestaShopLogger::addLog('Passimpay: Order #' . $orderId . ' unknown status: ' . $paymentStatus, 2);
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