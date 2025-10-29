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
    const LOG_PREFIX = 'Passimpay: ';
    
    protected $_html = '';
    private $_postErrors = array();
    
    public $pp_platform_id;
    public $pp_secret_key;
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
            || !$this->registerHook('displayAdminOrderSide')) {
            return false;
        }

        Configuration::updateValue(self::CONFIG_PLATFORM_ID, '');
        Configuration::updateValue(self::CONFIG_SECRET_KEY, '');

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName(self::CONFIG_PLATFORM_ID)
            || !Configuration::deleteByName(self::CONFIG_SECRET_KEY)
            || !parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function setPaymentAttributes()
    {
        $config = Configuration::getMultiple(array(self::CONFIG_PLATFORM_ID, self::CONFIG_SECRET_KEY));

        if (isset($config[self::CONFIG_PLATFORM_ID])) {
            $this->pp_platform_id = $config[self::CONFIG_PLATFORM_ID];
        }
        
        if (isset($config[self::CONFIG_SECRET_KEY])) {
            $this->pp_secret_key = $config[self::CONFIG_SECRET_KEY];
        }
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return array();
        }

        $newOption = new PaymentOption();
        $newOption->setCallToActionText($this->l('Pay with cryptocurrencies via Passimpay'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAdditionalInformation($this->context->smarty->fetch('module:passimpay/views/templates/front/payment_request.tpl'));

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

        $this->smarty->assign(
            array(
                'action' => $_SERVER['REQUEST_URI'],
                'platform_id' => $this->getSetting('pp_platform_id', $this->pp_platform_id),
                'secret_key' => $this->getSetting('pp_secret_key', $this->pp_secret_key),
                'webhook_url' => $webhookUrl,
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