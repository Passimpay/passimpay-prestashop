<?php

set_error_handler('exceptions_error_handler', E_ALL);
function exceptions_error_handler($severity)
{
//    if (error_reporting() == 0) {
//        return;
//    }
//    if (error_reporting() & $severity) {
//        die('NOTOK');
//    }
}

class PassimpayNotificationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $params = $_POST;

        Tools::safePostVars();
        $order = Passimpay::getOrderById($params['order_id']);

        if (!$order) {
            die('NOTOK');
        }

        $config = Configuration::getMultiple(array('PP_PLATFORM_ID', 'PP_SECRET_KEY', 'PP_LANGUAGE'));
        $methodInstance = new PassimpayMerchantAPI($config['PP_PLATFORM_ID'], $config['PP_SECRET_KEY']);

        $data = [
            'platform_id' => (int) $_POST['platform_id'], // Platform ID
            'payment_id' => (int) $_POST['payment_id'], // currency ID
            'order_id' => (int) $_POST['order_id'], // Payment ID of your platform
            'amount' => $_POST['amount'], // transaction amount
            'txhash' => $_POST['txhash'], // Hash or transaction ID. You can find the transaction ID in the PassimPay transaction history in your account.
            'address_from' => $_POST['address_from'], // sender address
            'address_to' => $_POST['address_to'], // recipient address
            'fee' => $_POST['fee'], // network fee
        ];

        if (!$methodInstance->checkHash($data, $params['hash'])) {
//            file_put_contents('tmp.log', $params['order_id'] . ' : Hash is invalid' . PHP_EOL, FILE_APPEND);
            die('NOT OK');
        }

        if (!$methodInstance->orderStatusIsCompleted($params['order_id'])) {
//            file_put_contents('tmp.log', $params['order_id'] . ' : Payment process has wrong status' . PHP_EOL, FILE_APPEND);
            die('Payment process has wrong status');
        }

        $order->setCurrentState(_PS_OS_PAYMENT_);

        die('OK');
    }
}
