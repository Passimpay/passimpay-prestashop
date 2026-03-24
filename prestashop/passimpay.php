<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/PassimpayMerchantAPI.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Passimpay extends PaymentModule
{
    const CONFIG_PLATFORM_ID = 'PP_PLATFORM_ID';
    const CONFIG_SECRET_KEY = 'PP_SECRET_KEY';
    const CONFIG_PAYMENT_TYPE = 'PP_PAYMENT_TYPE';
    const LOG_PREFIX = 'Passimpay: ';

    /** API type: 0 = card + crypto, 1 = crypto only, 2 = card only */
    const PAYMENT_TYPE_BOTH = 0;
    const PAYMENT_TYPE_CRYPTO = 1;
    const PAYMENT_TYPE_CARD = 2;
    
    protected $_html = '';
    private $_postErrors = array();
    
    public $pp_platform_id;
    public $pp_secret_key;
    public $pp_payment_type;
    public $pp_language;
    public $page;

    public function __construct()
    {
        $this->name = 'passimpay';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->author = 'Passimpay';

        $this->currencies = true;
        $this->currencies_mode = 'radio';

        $this->setPaymentAttributes();

        parent::__construct();

        $this->page = basename(__FILE__, '.php');
        $this->displayName = 'Passimpay';
        $this->description = $this->l('Accept cryptocurrency payments via Passimpay');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');
    }

    public function install()
    {
        if (!parent::install() 
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('displayAdminOrderSide')
            || !$this->registerHook('displayHeader')) {
            return false;
        }

        Configuration::updateValue(self::CONFIG_PLATFORM_ID, '');
        Configuration::updateValue(self::CONFIG_SECRET_KEY, '');
        Configuration::updateValue(self::CONFIG_PAYMENT_TYPE, self::PAYMENT_TYPE_BOTH);

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName(self::CONFIG_PLATFORM_ID)
            || !Configuration::deleteByName(self::CONFIG_SECRET_KEY)
            || !Configuration::deleteByName(self::CONFIG_PAYMENT_TYPE)
            || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function setPaymentAttributes()
    {
        $config = Configuration::getMultiple(array(
            self::CONFIG_PLATFORM_ID,
            self::CONFIG_SECRET_KEY,
            self::CONFIG_PAYMENT_TYPE
        ));

        if (isset($config[self::CONFIG_PLATFORM_ID])) {
            $this->pp_platform_id = $config[self::CONFIG_PLATFORM_ID];
        }
        if (isset($config[self::CONFIG_SECRET_KEY])) {
            $this->pp_secret_key = $config[self::CONFIG_SECRET_KEY];
        }
        if (isset($config[self::CONFIG_PAYMENT_TYPE])) {
            $this->pp_payment_type = (int)$config[self::CONFIG_PAYMENT_TYPE];
        } else {
            $this->pp_payment_type = self::PAYMENT_TYPE_BOTH;
        }
    }

    /**
     * Call-to-action text and logo for checkout depending on payment type.
     * @return array ['label' => string, 'logo' => string]
     */
    public function getPaymentTypeDisplay()
    {
        $type = (int)$this->pp_payment_type;
        $baseUrl = $this->context->link->getBaseLink(true) . 'modules/' . $this->name . '/';
        $imgDir = dirname(__FILE__) . '/views/img/';
        $defaultLogo = $baseUrl . 'logo.svg';

        $logos = array(
            self::PAYMENT_TYPE_BOTH  => $defaultLogo,
            self::PAYMENT_TYPE_CRYPTO => $baseUrl . 'views/img/logo_crypto.svg',
            self::PAYMENT_TYPE_CARD  => $baseUrl . 'views/img/logo_card.svg',
        );
        $labels = array(
            self::PAYMENT_TYPE_BOTH  => $this->l('Pay with card or crypto via Passimpay'),
            self::PAYMENT_TYPE_CRYPTO => $this->l('Pay with cryptocurrency via Passimpay'),
            self::PAYMENT_TYPE_CARD  => $this->l('Pay with bank card via Passimpay'),
        );
        if (!isset($logos[$type])) {
            $type = self::PAYMENT_TYPE_BOTH;
        }
        $logo = $logos[$type];
        if ($type === self::PAYMENT_TYPE_CRYPTO) {
            if (!file_exists($imgDir . 'logo_crypto.svg') && !file_exists($imgDir . 'logo_crypto.png')) {
                $logo = $defaultLogo;
            } elseif (file_exists($imgDir . 'logo_crypto.png') && !file_exists($imgDir . 'logo_crypto.svg')) {
                $logo = $baseUrl . 'views/img/logo_crypto.png';
            }
        } elseif ($type === self::PAYMENT_TYPE_CARD) {
            if (!file_exists($imgDir . 'logo_card.svg') && !file_exists($imgDir . 'logo_card.png')) {
                $logo = $defaultLogo;
            } elseif (file_exists($imgDir . 'logo_card.png') && !file_exists($imgDir . 'logo_card.svg')) {
                $logo = $baseUrl . 'views/img/logo_card.png';
            }
        }
        return array(
            'label' => $labels[$type],
            'logo'  => $logo,
            'type'  => $type,
        );
    }

    public function hookDisplayHeader($params)
    {
        if (!$this->active) {
            return '';
        }
        $controller = $this->context->controller;
        $isOrder = ($controller && (isset($controller->controller_name) && $controller->controller_name === 'order'));
        if (!$isOrder) {
            return '';
        }
        return '<script>
(function() {
    document.addEventListener("DOMContentLoaded", function() {
        var imgs = document.querySelectorAll("img[src*=\"passimpay\"]");
        for (var i = 1; i < imgs.length; i++) {
            imgs[i].style.display = "none";
        }
    });
})();
</script>';
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        $display = $this->getPaymentTypeDisplay();
        $this->context->smarty->assign('passimpay_checkout', $display);

        $newOption = new PaymentOption();
        $newOption->setCallToActionText($display['label'])
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAdditionalInformation($this->context->smarty->fetch('module:passimpay/views/templates/front/payment_request.tpl'))
            ->setLogo($display['logo']);

        return array($newOption);
    }

    public function hookDisplayAdminOrderSide($params)
    {
        $order = new Order((int)$params['id_order']);
        
        if (!Validate::isLoadedObject($order)) {
            return '';
        }
        
        if (strpos($order->payment, 'Passimpay') === false) {
            return '';
        }
        
        if (Tools::isSubmit('passimpay_check_payment') && Tools::getValue('id_order') == $order->id) {
            $result = $this->manualPaymentCheck($order->id);
            $this->context->smarty->assign('check_result', $result);
        }
        
        $this->context->smarty->assign([
            'order_id' => $order->id,
            'order_state' => $order->current_state,
            'check_url' => $this->context->link->getAdminLink('AdminOrders', true, [], [
                'id_order' => $order->id,
                'vieworder' => 1,
                'passimpay_check_payment' => 1
            ])
        ]);
        
        return $this->display(__FILE__, 'views/templates/admin/order_payment_check.tpl');
    }
    
    private function manualPaymentCheck($orderId)
    {
        $result = [
            'success' => false,
            'message' => 'Unknown error'
        ];
        
        $config = Configuration::getMultiple([self::CONFIG_PLATFORM_ID, self::CONFIG_SECRET_KEY]);
        
        if (empty($config[self::CONFIG_PLATFORM_ID]) || empty($config[self::CONFIG_SECRET_KEY])) {
            $result['message'] = 'Module not configured';
        } else {
            $order = new Order($orderId);
            
            if (!Validate::isLoadedObject($order)) {
                $result['message'] = 'Order not found';
            } else {
                $api = new PassimpayMerchantAPI($config[self::CONFIG_PLATFORM_ID], $config[self::CONFIG_SECRET_KEY]);
                $paymentStatus = $api->getOrderStatus($orderId);
                
                PrestaShopLogger::addLog(
                    self::LOG_PREFIX . 'Manual check for order #' . $orderId . ' - Status: ' . $paymentStatus,
                    1
                );
                
                switch ($paymentStatus) {
                    case PassimpayMerchantAPI::PAYMENT_STATUS_COMPLETED:
                        $result = $this->handleCompletedPaymentCheck($order);
                        break;
                        
                    case PassimpayMerchantAPI::PAYMENT_STATUS_PROCESSING:
                        $result['message'] = 'Payment is still processing.';
                        break;
                        
                    case PassimpayMerchantAPI::PAYMENT_STATUS_ERROR:
                        $result['message'] = 'Payment failed.';
                        break;
                        
                    default:
                        $result['message'] = 'Unable to verify payment. API status: ' . $paymentStatus;
                        break;
                }
            }
        }
        
        return $result;
    }
    
    private function handleCompletedPaymentCheck($order)
    {
        $paidStatusId = (int)Configuration::get('PS_OS_PAYMENT');
        
        if ($order->current_state == $paidStatusId) {
            return [
                'success' => true,
                'already_paid' => true,
                'message' => 'Order is already marked as paid. Passimpay confirms payment status.'
            ];
        }
        
        $order->setCurrentState($paidStatusId);
        
        $message = new Message();
        $message->message = self::LOG_PREFIX . 'Payment confirmed via manual check';
        $message->id_order = (int)$order->id;
        $message->private = 1;
        $message->add();
        
        return [
            'success' => true,
            'message' => 'Payment confirmed by Passimpay. Order status updated to PAID.'
        ];
    }

    private function _postValidation()
    {
        if (empty($_POST['pp_platform_id'])) {
            $this->_postErrors[] = $this->l('Platform ID is required');
        } elseif (empty($_POST['pp_secret_key'])) {
            $this->_postErrors[] = $this->l('Secret key is required');
        }
    }

    private function _postProcess()
    {
        Configuration::updateValue(self::CONFIG_PLATFORM_ID, $_POST['pp_platform_id']);
        Configuration::updateValue(self::CONFIG_SECRET_KEY, $_POST['pp_secret_key']);
        $type = isset($_POST['pp_payment_type']) ? (int)$_POST['pp_payment_type'] : self::PAYMENT_TYPE_BOTH;
        if (in_array($type, array(self::PAYMENT_TYPE_BOTH, self::PAYMENT_TYPE_CRYPTO, self::PAYMENT_TYPE_CARD), true)) {
            Configuration::updateValue(self::CONFIG_PAYMENT_TYPE, $type);
        }
        $this->setPaymentAttributes();
    }

    public function getSetting($name, $value)
    {
        return htmlentities(Tools::getValue($name, $value), ENT_COMPAT, 'UTF-8');
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();

            if (!count($this->_postErrors)) {
                $this->_postProcess();
                $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }

        // Generate webhook URL for this merchant
        $webhookUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'modules/passimpay/webhook.php';
	$settingsLogoUrl = Tools::getShopDomainSsl(true) . __PS_BASE_URI__ . 'modules/passimpay/views/img/logo.svg';

        $paymentTypeOptions = array(
            self::PAYMENT_TYPE_BOTH  => $this->l('Card and cryptocurrency'),
            self::PAYMENT_TYPE_CRYPTO => $this->l('Cryptocurrency only'),
            self::PAYMENT_TYPE_CARD  => $this->l('Bank card only'),
        );
        $cardNotice = $this->l('Before enabling card payments, ensure that «Cards/Bank transfer» is turned on in your Passimpay platform settings.');
        $this->smarty->assign(
            array(
                'action' => $_SERVER['REQUEST_URI'],
                'platform_id' => $this->getSetting('pp_platform_id', $this->pp_platform_id),
                'secret_key' => $this->getSetting('pp_secret_key', $this->pp_secret_key),
                'payment_type' => $this->getSetting('pp_payment_type', $this->pp_payment_type),
                'payment_type_options' => $paymentTypeOptions,
                'passimpay_card_notice' => $cardNotice,
                'webhook_url' => $webhookUrl,
		'settings_logo_url' => $settingsLogoUrl,
                'this' => $this,
            )
        );

        $this->_html .= $this->display(__FILE__, 'settings.tpl');

        return $this->_html;
    }

    public static function getOrderById($id_order)
    {
        $order = Db::getInstance()->getRow(
            'SELECT * FROM `'._DB_PREFIX_.'orders`
			WHERE `id_order` = '.(int)$id_order
        );

        return ($order['id_order'] > 0) ? new Order($order['id_order']) : null;
    }
}
