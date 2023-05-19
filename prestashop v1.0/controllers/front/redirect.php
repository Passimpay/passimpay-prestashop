<?php

class PassimpayRedirectModuleFrontController extends ModuleFrontController
{
    public function init()
    {
        $order = Passimpay::getOrderById(Tools::getValue('OrderId'));
        Tools::redirect('guest-tracking?id_order=' . $order->reference);
    }
}