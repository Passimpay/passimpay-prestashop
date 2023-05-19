<?php

/**
 * @since 1.5.0
 */
class PassimpayValidationModuleFrontController extends ModuleFrontController
{
    static public $vats = [
        'none' => 'none',
        '0' => 'vat0',
        '10' => 'vat10',
        '18' => 'vat18',
        '20' => 'vat20',
    ];

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer))
            Tools::redirect('index.php?controller=order&step=1');

        $mailVars = array(
            '{bankwire_owner}' => Configuration::get('BANK_WIRE_OWNER'),
            '{bankwire_details}' => nl2br(Configuration::get('BANK_WIRE_DETAILS')),
            '{bankwire_address}' => nl2br(Configuration::get('BANK_WIRE_ADDRESS'))
        );

        $passimpay = new Passimpay();
        $passimpay->validateOrder($cart->id, Configuration::get('PS_OS_BANKWIRE'),
            (float)$cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName, NULL,
            $mailVars, (int)$this->context->currency->id, false, $customer->secure_key);

        $paymentUrl = $this->getPaymentUrl();
        $order = Order::getIdByCartId($cart->id);

        if ($paymentUrl) {
            Tools::redirect($paymentUrl);
        } else {
            Tools::redirect('index.php?controller=order-detail&id_order=' . $order);
        }
    }

    public function getPaymentUrl()
    {
        $cookie = $this->context->cookie;
        $cart = $this->context->cart;
        $config = Configuration::getMultiple(array('PP_PLATFORM_ID', 'PP_SECRET_KEY', 'PP_LANGUAGE'));

        $price = $cart->getOrderTotal(true, 3);
        $currentCurrencyId = $this->context->currency->id;
        $defaultCurrencyId = Currency::getDefaultCurrency()->id;
        $usdCurrencyId = Currency::getIdByIsoCode('USD');
        if ($currentCurrencyId !== $usdCurrencyId) {
            if ($currentCurrencyId !== $defaultCurrencyId) {
                $price = Tools::convertPrice($price, $currentCurrencyId, false);
            }
            $price = Tools::convertPrice($price, $usdCurrencyId, true);
        }
        $requestData = array(
            'order_id'  => (int) Order::getIdByCartId($cart->id),
            'amount'    => number_format($price, 2, '.', ''),
        );
//        var_dump($requestData, $usdCurrencyId); die();

        if ($config['PP_LANGUAGE'] == 'en') {
            $requestData['Language'] = 'en';
        }

        global $smarty;
        $smarty->caching = false;
        $smarty->force_compile = true;
        $smarty->compile_check = false;

        $Passimpay = new PassimpayMerchantAPI($config['PP_PLATFORM_ID'], $config['PP_SECRET_KEY']);
        $request = $Passimpay->init($requestData);

//        var_dump($request, $requestData); die();
//        $request = json_decode($request);

        return $request->paymentUrl;
    }

    function logs($requestData, $request, $file)
    {
        // log send
        $log = '[' . date('D M d H:i:s Y', time()) . '] ';
        $log .= json_encode($requestData, JSON_UNESCAPED_UNICODE);
        $log .= "\n";
        file_put_contents(dirname(__FILE__) . $file, $log, FILE_APPEND);

        $log = '[' . date('D M d H:i:s Y', time()) . '] ';
        $log .= $request;
        $log .= "\n";
        file_put_contents(dirname(__FILE__) . $file, $log, FILE_APPEND);
    }

    /**
     * @param $taxRule
     * @param $taxRate
     * @return mixed
     */
    static public function getVat($taxRule, $taxRate)
    {
        if ($taxRule) {
            return self::$vats[$taxRate];
        } else {
            return self::$vats['none'];
        }
    }

    function getRoundedCartAmount($products, $shippingPrice)
    {
        $roundedAmount = round($shippingPrice, 2);

        foreach ($products as $product) {
            $roundedAmount += round($product['price_wt'], 2) * $product['quantity'];
        }

        return $roundedAmount;
    }
}
