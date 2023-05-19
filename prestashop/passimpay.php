<?php

require_once 'PassimpayMerchantAPI.php';
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Passimpay extends PaymentModule
{
    protected $_html = '';
    private $_postErrors = array();
    public $pp_platform_id;
    public $pp_secret_key;
    public $pp_language;

    public function __construct()
    {
        $this->name = 'passimpay';
        $this->tab = 'payments_gateways';
        $this->version = '1.0';
        $this->author = 'Passimpay';

        $this->currencies = true;
        $this->currencies_mode = 'radio';

        $this->setPaymentAttributes();

        parent::__construct();

        /* The parent construct is required for translations */
        $this->page = basename(__FILE__, '.php');
        $this->displayName = 'Passimpay';
        $this->description = $this->l('Accept payments with Passimpay');
        $this->confirmUninstall = $this->l('Are you sure you want to delete your details ?');
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('paymentOptions'))
            return false;

        Configuration::updateValue('PP_PLATFORM_ID', '');
        Configuration::updateValue('PP_SECRET_KEY', '');
        Configuration::updateValue('PP_LANGUAGE', '');

        return true;
    }

    public function uninstall()
    {
        if (!Configuration::deleteByName('PP_PLATFORM_ID')
            || !Configuration::deleteByName('PP_SECRET_KEY')
            || !Configuration::deleteByName('PP_LANGUAGE')
            || !parent::uninstall()
        )
            return false;

        return true;
    }

    public function setPaymentAttributes()
    {
        $config = Configuration::getMultiple(array('PP_PLATFORM_ID', 'PP_SECRET_KEY', 'PP_LANGUAGE'));

        if (isset($config['PP_PLATFORM_ID']))
            $this->pp_platform_id = $config['PP_PLATFORM_ID'];
        if (isset($config['PP_SECRET_KEY']))
            $this->pp_secret_key = $config['PP_SECRET_KEY'];
        if (isset($config['PP_LANGUAGE']))
            $this->pp_language = $config['PP_LANGUAGE'];
    }

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        $newOption = new PaymentOption();
        $newOption->setCallToActionText($this->l('Pay with cryptocurrencies via Passimpay'))
            ->setAction($this->context->link->getModuleLink($this->name, 'validation', array(), true))
            ->setAdditionalInformation($this->context->smarty->fetch('module:passimpay/views/templates/front/payment_request.tpl'));

        return array($newOption);
    }

    private function _postValidation()
    {
        if (empty($_POST['pp_platform_id']))
            $this->_postErrors[] = $this->l('Plarform ID is required');
        elseif (empty($_POST['pp_secret_key']))
            $this->_postErrors[] = $this->l('Secret key is required');
    }

    private function _postProcess()
    {
        Configuration::updateValue('PP_PLATFORM_ID', $_POST['pp_platform_id']);
        Configuration::updateValue('PP_SECRET_KEY', $_POST['pp_secret_key']);
        Configuration::updateValue('PP_LANGUAGE', $_POST['pp_language']);

        $this->setPaymentAttributes();
    }

    public function getSetting($name, $value)
    {
        return htmlentities(Tools::getValue($name, $value), ENT_COMPAT, 'UTF-8');
    }

    public function getLanguageList()
    {
        return array(
            'ru' => $this->l('Russian'),
            'en' => $this->l('English'),
        );
    }

    public function getContent()
    {
        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();

            if (!sizeof($this->_postErrors)) {
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

        $this->smarty->assign(
            array(
                'action' => $_SERVER['REQUEST_URI'],
                'platform_id' => $this->getSetting('pp_platform_id', $this->pp_platform_id),
                'secret_key' => $this->getSetting('pp_secret_key', $this->pp_secret_key),
                'language' => $this->getSetting('pp_language', $this->pp_language),
                'languageList' => $this->getLanguageList(),
                'this' => $this,
            )
        );

        $this->_html .= $this->display(__FILE__, 'settings.tpl');

        return $this->_html;
    }

    public function getL($key)
    {
        $translations = array(
            'success' => 'Passimpay transaction is carried out successfully.',
            'fail' => 'Passimpay transaction is refused.'
        );
        return $translations[$key];
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

