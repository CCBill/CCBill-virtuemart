<?php
/**
 *
 * CCBill payment plugin
 *
 * @author CCBill
 * @version $Id: ccbill.php 1 2018-02-22 00:00:00Z ccbill $
 * @package VirtueMart
 * @subpackage payment
 * Copyright (C) 2015-2021 CCBill. All rights reserved.
 * http://ccbill.com
 */

defined('_JEXEC') or die('Restricted access');
defined('DS') or define('DS', DIRECTORY_SEPARATOR);

if (!class_exists('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}


if (!class_exists('CCBillHelperCustomerData')) {
	require(VMPATH_ROOT .DS.'plugins'.DS.'vmpayment'.DS.'ccbill'.DS.'ccbill'.DS.'helpers'.DS.'customerdata.php');
}

class plgVmPaymentCCBill extends vmPSPlugin {

	// instance of class
  public static $_this = false;
	private $_errormessage = array();

	public $approved;
	public $declined;
	public $error;
	public $held;
	public $logEnabled = false;

	const APPROVED = 1;
	const DECLINED = 2;
	const ERROR = 3;
	const HELD = 4;

  /*
  * Constructor
  */

	function __construct(& $subject, $config) {

		parent::__construct($subject, $config);

		$this->_loggable = TRUE;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';

		$varsToPush = array(
		    'account_no'     => array('',  'char'),
  	    'subaccount_no'  => array('',  'char'),
  	    'form_name'      => array('',  'char'),
  	    'is_flexform'    => array('no','char'),
  	    'currency_code'  => array(940, 'int' ),
  	    'salt'           => array('',  'char'),

			  'payment_logos'  => array('',  'char'),

        // Restrictions
  			'countries'  => array('', 'char'),
  			'min_amount' => array('', 'int'),
  			'max_amount' => array('', 'int'),

  			// Discounts
  			'cost_per_transaction' => array('', 'float'),
  			'cost_percent_total'   => array('', 'float'),
  			'tax_id'               => array(0, 'int'),

		);

		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);

	}// end constructor

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}

	function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
	}

	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('CCBill Table');
	}// end getVmPluginCreateTableSQL

	function getTableSQLFields() {

		$SQLfields = array(
			'id'                           => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'          => 'int(1) UNSIGNED',
			'order_number'                 => 'char(64)',
			'virtuemart_paymentmethod_id'  => 'mediumint(1) UNSIGNED',
			'payment_name'                 => 'varchar(5000)',
			'payment_order_total'          => 'decimal(15,5) NOT NULL',
			'cost_per_transaction'         => 'decimal(10,2)',
			'cost_percent_total'           => 'decimal(10,2)',
			'tax_id'                       => 'smallint(1)',

			'ccbill_tx_id'                 => 'char(50)',
			'first_name'                   => 'varchar(255)',
			'last_name'                    => 'varchar(255)',
			'email'                        => 'varchar(255)',
			'currency_code'                => 'int(3)',
			'digest'                       => 'varchar(32)',
			'success'                      => 'char(1) DEFAULT \'0\'',
			'order_created'                => 'char(1) DEFAULT \'0\'',
			'order_date'                   => 'char(28)'
		);
		return $SQLfields;
	}// end getTableSQLFields


	/**
	 * * List payment methods selection
	 * @param VirtueMartCart $cart
	 * @param int $selected
	 * @param $htmlIn
	 * @return bool
	 */

	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {

		if ($this->getPluginMethods($cart->vendorId) === 0) {
			if (empty($this->_name)) {
				$app = JFactory::getApplication();
				$app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
				return false;
			} else {
				return false;
			}
		}
		$method_name = $this->_psType . '_name';

		$htmla = array();
		foreach ($this->methods as $this->_currentMethod) {
			if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

				$html = '';
				$cart_prices = array();
				$cart_prices['withTax'] = '';
				$cart_prices['salesPrice'] = '';
				$methodSalesPrice = $this->setCartPrices($cart, $cart_prices, $this->_currentMethod);

				$this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
				$html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);

				$htmla[] = $html;
			}
		}
		$htmlIn[] = $htmla;
		return true;

	}// plgVmDisplayListFEPayment


	/**
	 * Check if the payment conditions are fulfilled for this payment method
	 * @param VirtueMartCart $cart
	 * @param int $activeMethod
	 * @param array $cart_prices
	 * @return bool
	 */
	protected function checkConditions($cart, $activeMethod, $cart_prices) {


		$this->convert_condition_amount($activeMethod);

		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		$amount = $this->getCartAmount($cart_prices);
		$amount_cond = ($amount >= $activeMethod->min_amount AND $amount <= $activeMethod->max_amount
			OR
			($activeMethod->min_amount <= $amount AND ($activeMethod->max_amount == 0)));

		$countries = array();
		if (!empty($activeMethod->countries)) {
			if (!is_array($activeMethod->countries)) {
				$countries[0] = $activeMethod->countries;
			} else {
				$countries = $activeMethod->countries;
			}
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
			if ($amount_cond) {
				return TRUE;
			}
		}

		return FALSE;

	}// end checkConditions

	function plgVmOnUpdateOrderLinePayment(&$order) {
	  // do nothing
	}

	/*******************/
	/* Order cancelled */
	/*******************/
	public function plgVmOnCancelPayment(&$order, $old_order_status) {
		return NULL;
	}

	/**
	 * Validate payment on checkout
	 * @param VirtueMartCart $cart
	 * @return bool|null
	 */
	function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart) {

		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return NULL; // Another method was selected, do nothing
		}

		if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
			return FALSE;
		}

		return true;

	}// end plgVmOnCheckoutCheckDataPayment


	/**
	 * Create ccbill table if it doesn't exist
	 *
	 * @param $jplugin_id
	 * @return bool|mixed
	 */
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
	}// end plgVmOnStoreInstallPaymentPluginTable

	/**
	 *     * This event is fired after the payment method has been selected.
	 * It can be used to store additional payment info in the cart.
	 * @param VirtueMartCart $cart
	 * @param $msg
	 * @return bool|null
	 */
	public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg) {

		if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
			return null; // Another method was selected, do nothing
		}

		if (!($this->_currentMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
			return FALSE;
		}

		$this->setCart($cart);
		$this->setTotal($cart->cartPrices['billTotal']);
		$this->loadCustomerData();


		return TRUE;
	}// end plgVmOnSelectCheckPayment

	//Calculate the price (value, tax_id) of the selected method, It is called by the calculator
	//This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
	public function plgVmOnSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		if (!($selectedMethod = $this->getVmPluginMethod($cart->virtuemart_paymentmethod_id))) {
			return FALSE;
		}

		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
	}// end plgVmOnSelectedCalculatePricePayment

	// This method is fired when showing the order details in the frontend.
	// It displays the method-specific data.
	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}// end plgVmOnShowOrderFEPayment

	// Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	// The plugin must check first if it is the correct type
	function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
	}// end plgVmOnCheckAutomaticSelectedPayment

	public function setCart ($cart) {
		$this->cart = $cart;
		if (!isset($this->cart->cartPrices) or empty($this->cart->cartPrices)) {
			$this->cart->prepareCartData();
		}
	}// end setCart

	public function setTotal ($total) {
		if (!class_exists('CurrencyDisplay')) {
			require(VMPATH_ADMIN . DS  .'helpers'.DS.'currencydisplay.php');
		}
		$this->total = vmPSPlugin::getAmountValueInCurrency($total, $this->_currentMethod->payment_currency);

		$cd = CurrencyDisplay::getInstance($this->cart->pricesCurrency);
	}// end setTotal

	public function loadCustomerData () {
		$this->customerData = new CCBillHelperCustomerData();
		$this->customerData->load();
		$this->customerData->loadPost();
	}// end loadCustomerData

	// This method is fired when showing when priting an Order
	// Displays payment method-specific data.
	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
	}// end plgVmonShowOrderPrintPayment



	protected function renderPluginName($activeMethod) {

		$return = '';
		$plugin_name = $this->_psType . '_name';
		$plugin_desc = $this->_psType . '_desc';
		$description = '';

		$logosFieldName = $this->_psType . '_logos';
		$logos = $activeMethod->$logosFieldName;
		if (!empty($logos)) {
			$return = $this->displayLogos($logos) . ' ';
		}
		$pluginName = $return . '<span class="' . $this->_type . '_name">' . $activeMethod->$plugin_name . '</span>';

		if (!empty($activeMethod->$plugin_desc)) {
			$pluginName .= '<span class="' . $this->_type . '_description">' . $activeMethod->$plugin_desc . '</span>';
		}

		$pluginName .= $this->displayExtraPluginNameInfo($activeMethod);

		return $pluginName;

	}// end renderPluginName

	function displayExtraPluginNameInfo($activeMethod) {
		$this->_currentMethod = $activeMethod;

		$this->loadCustomerData();
		$extraInfo = '';

		return $extraInfo;

	}// end displayExtraPluginNameInfo

	public function getExtraPluginInfo () {
		$extraInfo = '';
		return $extraInfo;
	}// end getExtraPluginInfo

	function log($message, $method) {
	  if($this->logEnabled)
	   $this->debugLog($message, $method, 'debug');
	}// end log

  // On IPN Payment notification
	function plgVmOnPaymentNotification() {

	  $virtuemart_paymentmethod_id = -1;
	  $virtuemart_order_id = -1;

	  $this->log('1', 'plgVmOnPaymentNotification');

		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		$pdata = $_POST;

		$order_number = -1;

		$prefix = isset($pdata['X-zc_orderid']) ? 'X-' : '';

		//Invoice/order number returned as zc_orderid
		if(isset($pdata[$prefix . 'zc_orderid'])) {
		  $order_number = $pdata[$prefix . 'zc_orderid'];
		}
		else{

		  $this->log('1.1 zc_orderid not present', 'plgVmOnPaymentNotification');

	    foreach ($_POST as $key => $value) {

	     $this->log('1.1 POST ' . $key . ' = ' . $value, 'plgVmOnPaymentNotification');

      }
	    foreach ($_GET as $key => $value) {

	     $this->log("1.1 GET " . $key . " = " . $value, 'plgVmOnPaymentNotification');

      }

		  return FALSE;
		}// end if/else

	  $this->log("2: " . $order_number, 'plgVmOnPaymentNotification');

		$orderModel   = VmModel::getModel('orders');

		if (!($virtuemart_order_id = $orderModel->getOrderIdByOrderNumber($order_number))) {
	    $this->log("fail 2", 'plgVmOnPaymentNotification');
			return FALSE;
		}

		$order        = $orderModel->getOrder($virtuemart_order_id);

		$virtuemart_paymentmethod_id = -1;

		if(isset($pdata[$prefix . 'pmid'])){
		  $virtuemart_paymentmethod_id = $pdata[$prefix . 'pmid'];
		}// end if/else

	  $this->log("2.1. order id: " . $virtuemart_order_id, 'plgVmOnPaymentNotification');
	  $this->log("2.1.1. payment method id: " . $virtuemart_paymentmethod_id, 'plgVmOnPaymentNotification');

	  $currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id);


	  $this->log("2.2", 'plgVmOnPaymentNotification');

		if (!$this->selectedThisElement($currentMethod->payment_element)) {
	    $this->log("fail 2.5", 'plgVmOnPaymentNotification');
			return FALSE;
		}


	  $this->log("3", 'plgVmOnPaymentNotification');

		$payment_name = $this->renderPluginName($this->_currentMethod);

	  $this->log("4", 'plgVmOnPaymentNotification');

		$dbValues = array();

		$txId = '';
	  $responseDigest = '';
		$success = false;

		if(isset($pdata['responseDigest'])){
		  $responseDigest = $pdata['responseDigest'];
		}

		if(isset($pdata['subscription_id'])){
		  $this->log("4.1 subscription id: " . $pdata['subscription_id'], 'plgVmOnPaymentNotification');
		  $txId = $pdata['subscription_id'];
		  $success = 1;
		}

		if(isset($pdata['subscriptionId'])){
		  $this->log("4.1 subscription id: " . $$pdata['subscriptionId'], 'plgVmOnPaymentNotification');
		  $txId = $pdata['subscriptionId'];
		  $success = 1;
		}
		else{
		  $this->log("4.1 subscription id is blank", 'plgVmOnPaymentNotification');
		  $this->log("4.1 subscription id is blank" . $pdata['subscription_id'], 'plgVmOnPaymentNotification');
			/*
		  foreach ($_POST as $key => $value) {

	     $this->log("4.1 POST " . $key . " = " . $value, 'plgVmOnPaymentNotification');

      }

      foreach ($pdata as $key => $value) {

	     $this->log("4.1 pdata " . $key . " = " . $value, 'plgVmOnPaymentNotification');

      }
			*/
		}// end if


		// Prepare data that should be stored in the database
		$dbValues['virtuemart_order_id']  = $order['details']['BT']->order_id;
		$dbValues['order_number']         = $order['details']['BT']->order_number;

		$dbValues['virtuemart_paymentmethod_id']  = $virtuemart_paymentmethod_id;
		$dbValues['payment_name']                 = 'CCBill';
		$dbValues['payment_order_total']          = isset($pdata['formPrice']) ? $pdata['formPrice'] : $pdata['X-initialPrice'];
		$dbValues['cost_per_transaction']         = 0;
		$dbValues['cost_percent_total']           = 0;
		$dbValues['tax_id']                       = 0;

		$dbValues['ccbill_tx_id']         = $txId;
		$dbValues['first_name']           = $pdata[$prefix . 'customer_fname'];
		$dbValues['last_name']            = $pdata[$prefix . 'customer_lname'];
		$dbValues['email']                = $pdata[$prefix . 'email'];
		$dbValues['currency_code']        = $pdata[$prefix . 'currencyCode'];
		$dbValues['digest']               = $pdata[$prefix . 'formDigest'];
		$dbValues['success']              = $success;
		$dbValues['order_created']        = 1;
		$dbValues['order_date']           = date("Y-m-d H:i:s");

		$digestVerificationString = '';

		// Validate response digest
		if($success == 1){

      $subscriptionIdToHash = $txId;

      if($currentMethod->is_flexform == 'yes') {
        $subscriptionIdToHash = ltrim($txId, '0');
      }// end if

			$stringToHash = $subscriptionIdToHash . '1' . $currentMethod->salt;

			$myDigest = md5($stringToHash);

		  $digestVerificationString .= '; String to hash: ' . $stringToHash;
		  $digestVerificationString .= '; Digest comparison: ' . $myDigest . " : " . $responseDigest;

			$this->log("4.1 string to hash: " . $stringToHash, 'plgVmOnPaymentNotification');
			$this->log("4.1 digest comparison: " . $myDigest . " : " . $responseDigest, 'plgVmOnPaymentNotification');

			if($myDigest == $responseDigest) {
				$success = 1;
			}
			else {
			  $success = 0;
			}// end if/else digest matches

		}// end if

		// Store Data
		$this->storePSPluginInternalData($dbValues);

	  $this->log("Data stored in DB", 'plgVmOnPaymentNotification');


		// Process IPN
		$order_history = array();
		$order_history['customer_notified'] = 1;
		if($success == 1){
			$order_history['order_status'] = 'C';
			$order_history['comments'] = 'CCBill Payment Successful';
		}
		else{
			$order_history['order_status'] = 'U';
			$order_history['comments'] = 'CCBill Payment Failed ';// . $digestVerificationString;
		}// end if/else

	  $this->log("Updating order status: " . $order_history['order_status'], 'plgVmOnPaymentNotification');

		$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order_history, TRUE);

	  $this->log("Order status updated", 'plgVmOnPaymentNotification');


	  //// remove vmcart
		if ($success == 1 && isset($pdata[$prefix . 'context'])) {
			$this->emptyCart($pdata[$prefix . 'context'], $order_number);
	    $this->log("Cart emptied", 'plgVmOnPaymentNotification');
		}

		return true;
	}



	/**
	 * @param $html
	 * @return bool|null|string
	 */
	function plgVmOnPaymentResponseReceived(&$html) {

		if (!class_exists('VirtueMartCart')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists('shopFunctionsF')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		VmConfig::loadJLang('com_virtuemart_orders', TRUE);

		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
		$expresscheckout = vRequest::getVar('expresscheckout', '');
		if ($expresscheckout) {
			return;

		}
		$order_number = vRequest::getString('on', 0);
		$vendorId = 0;
		if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
			return NULL;
		}

		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return NULL;
		}
		if (!($payments = $this->getDatasByOrderNumber($order_number))) {
			return '';
		}
		$payment_name = $this->renderPluginName($this->_currentMethod);
		$payment = end($payments);

		VmConfig::loadJLang('com_virtuemart');
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);
		// to do: this
		vmdebug('plgVmOnPaymentResponseReceived', $payment);
		if (!class_exists('CurrencyDisplay')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'currencydisplay.php');
		}
		$currency = CurrencyDisplay::getInstance('', $order['details']['BT']->order_currency);

		// Delete the old stuff
		// get the correct cart / session
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return TRUE;
	}

	/**
	 *
	 * @param $cart
	 * @param $order
	 * @return bool|null|void
	 * called on return?
	 */
	function plgVmConfirmedOrder($cart, $order) {

		if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
			return FALSE;
		}

		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists('VirtueMartModelCurrency')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'currency.php');
		}

		$html='';
		$this->getPaymentCurrency($this->_currentMethod);
		$email_currency = $this->getEmailCurrency($this->_currentMethod);

		$payment_name = $this->renderPluginName($this->_currentMethod, $order);

		$html = $this->preparePost($order);

    $cart->_confirmDone = TRUE;
		$cart->_dataValidated = FALSE;
		$cart->emptyCart();
		$cart->setCartIntoSession();
		vRequest::setVar('html', $html);

	}

  function _getfield($string, $length) {
    return substr($string, 0, $length);
  }// end _getfield

	// Generate form fields for post
	function preparePost($order){

    $post_variables = Array();

	  $usrBT = $order['details']['BT'];
  	$usrST = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);
  	$session = JFactory::getSession();
  	$return_context = $session->getId();

  	$isFlexForm    = $this->_currentMethod->is_flexform == 'yes';
  	$formName      = $this->_currentMethod->form_name;
		$priceVarName  = 'formPrice';
		$periodVarName = 'formPeriod';

  	$first_name  = isset($usrBT->first_name) ? $this->_getField($usrBT->first_name, 50) : '';
  	$last_name   = isset($usrBT->last_name)  ? $this->_getField($usrBT->last_name,  50) : '';
  	$email       = isset($usrBT->email)      ? $this->_getField($usrBT->email,     100) : '';
  	$address     = isset($usrBT->address_1)  ? $this->_getField($usrBT->address_1,  60) : '';
  	$city        = isset($usrBT->city)       ? $this->_getField($usrBT->city,       40) : '';
  	$zip         = isset($usrBT->zip)        ? $this->_getField($usrBT->zip,        40) : '';

  	$state       = isset($usrBT->virtuemart_state_id)    ? $this->_getField(ShopFunctions::getStateByID($usrBT->virtuemart_state_id), 20) : '';
  	$country     = isset($usrBT->virtuemart_country_id)  ? $this->_getField(ShopFunctions::getCountryByID($usrBT->virtuemart_country_id), 60) : '';
  	$phone       = isset($usrBT->phone_1)                ? $this->_getField($usrBT->phone_1, 25) : '';

    $orderDetails = $order['details']['BT'];
  	$amount       = number_format($orderDetails->order_total, 2, '.', ''); //round($orderDetails->order_total, 2);
  	$orderNo       = $orderDetails->order_number;

    // Throw an error if the amount is zero
    if ( !($orderDetails->order_total > 0) )
        return "<script type=\"text/javascript\">alert('Invalid amount');</script>";

  	//$transactionAmount = str_replace('.','',number_format($order->info['total'], 2));
  	$billingPeriodInDays = 2;

  	$stringToHash = '' . $amount
	                     . $billingPeriodInDays
	                     . $this->_currentMethod->currency_code
	                     . $this->_currentMethod->salt;

	  $session = JFactory::getSession();
		$context = $session->getId();

  	$digest = md5($stringToHash);

  	$url = 'https://bill.ccbill.com/jpost/signup.cgi';

  	if($isFlexForm) {
    	$url            = 'https://api.ccbill.com/wap-frontflex/flexforms/' . $formName;
  		$priceVarName   = 'initialPrice';
  		$periodVarName  = 'initialPeriod';
  	}// end if

    $post_variables['clientAccnum']   = $this->_currentMethod->account_no;
    $post_variables['clientSubacc']   = $this->_currentMethod->subaccount_no;
    $post_variables['formName']       = $formName;
    $post_variables[$priceVarName]    = $amount;
    $post_variables[$periodVarName]   = $billingPeriodInDays;
    $post_variables['currencyCode']   = $this->_currentMethod->currency_code;
    $post_variables['customer_fname'] = $first_name;
    $post_variables['customer_lname'] = $last_name;
    $post_variables['email']          = $email;
    $post_variables['zipcode']        = $zip;
    //$post_variables['country']        = $country;
    $post_variables['order_pass']     = $order['details']['BT']->order_pass;
    $post_variables['state']          = $this->getStateCodeFromName($state);
    $post_variables['city']           = $city;
    $post_variables['address1']       = $address;
    $post_variables['zc_orderid']     = $orderNo;
    $post_variables['order_number']   = $orderNo;
    $post_variables['context']        = $context;
    $post_variables['pmid']           = $order['details']['BT']->virtuemart_paymentmethod_id;
    $post_variables['formDigest']     = $digest;

    // Compose form
	  $html = '<form action="' . $url . '" method="post" name="vm_ccbill_form" id="vmPaymentForm" accept-charset="UTF-8">';

	  $html .= '<input type="hidden" name="charset" value="utf-8">';

    foreach ($post_variables as $name => $value) {
    	$html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($value) . '" />';
    }

    $html .= '<input type="submit"  value="' . vmText::_('VMPAYMENT_CCBILL_REDIRECT_MESSAGE') . '" />';

    $html .= '</form>';

    return $html;

	}// end preparePost



	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
			return FALSE;
		}
		$this->getPaymentCurrency($this->_currentMethod);
		$paymentCurrencyId = $this->_currentMethod->payment_currency;
	}


	/**
	 * @param null $msg
	 */
	function redirectToCart ($msg = NULL) {
		if (!$msg) {
			$msg = vmText::_('VMPAYMENT_CCBILL_ERROR_TRY_AGAIN');
		}
		//$this->customerData->clear();
		$app = JFactory::getApplication();
		$app->redirect(JRoute::_('index.php?option=com_virtuemart&view=cart&Itemid=' . vRequest::getInt('Itemid'), false), $msg);
	}

	/*********************/
	/* Private functions */
	/*********************/


	/**
	 * Display stored payment data for an order
	 *
	 * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
	 */
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {
/*
		if (!$this->selectedThisByMethodId($payment_method_id)) {
			return NULL; // Another method was selected, do nothing
		}
		if (!($this->_currentMethod = $this->getVmPluginMethod($payment_method_id))) {
			return FALSE;
		}

		$html = '<table class="adminlist">' . "\n";
  	$html .= $this->getHtmlHeaderBE();
  	$code = "ccbill_response_";
  	foreach ($paymentTable as $key => $value) {
  	    if (substr($key, 0, strlen($code)) == $code) {
  		$html .= $this->getHtmlRowBE($key, $value);
  	    }
  	}
  	$html .= '</table>' . "\n";
  	*/
  	$html = '';
  	return $html;

	}// end plgVmOnShowOrderBEPayment

	function getStateCodeFromName($stateName){

    $rVal = $stateName;

    switch($rVal){
      case 'Alabama':         $rVal = 'AL';
        break;
      case 'Alaska':          $rVal = 'AK';
        break;
      case 'Arizona':         $rVal = 'AZ';
        break;
      case 'Arkansas':        $rVal = 'AR';
        break;
      case 'California':      $rVal = 'CA';
        break;
      case 'Colorado':        $rVal = 'CO';
        break;
      case 'Connecticut':     $rVal = 'CT';
        break;
      case 'Delaware':        $rVal = 'DE';
        break;
      case 'Florida':         $rVal = 'FL';
        break;
      case 'Georgia':         $rVal = 'GA';
        break;
      case 'Hawaii':          $rVal = 'HI';
        break;
      case 'Idaho':           $rVal = 'ID';
        break;
      case 'Illinois':        $rVal = 'IL';
        break;
      case 'Indiana':         $rVal = 'IN';
        break;
      case 'Iowa':            $rVal = 'IA';
        break;
      case 'Kansas':          $rVal = 'KS';
        break;
      case 'Kentucky':        $rVal = 'KY';
        break;
      case 'Louisiana':       $rVal = 'LA';
        break;
      case 'Maine':           $rVal = 'ME';
        break;
      case 'Maryland':        $rVal = 'MD';
        break;
      case 'Massachusetts':   $rVal = 'MA';
        break;
      case 'Michigan':        $rVal = 'MI';
        break;
      case 'Minnesota':       $rVal = 'MN';
        break;
      case 'Mississippi':     $rVal = 'MS';
        break;
      case 'Missouri':        $rVal = 'MO';
        break;
      case 'Montana':         $rVal = 'MT';
        break;
      case 'Nebraska':        $rVal = 'NE';
        break;
      case 'Nevada':          $rVal = 'NV';
        break;
      case 'New York':        $rVal = 'NY';
        break;
      case 'Ohio':            $rVal = 'OH';
        break;
      case 'Oklahoma':        $rVal = 'OK';
        break;
      case 'Oregon':          $rVal = 'OR';
        break;
      case 'Pennsylvania':    $rVal = 'PN';
        break;
      case 'Rhode Island':    $rVal = 'RI';
        break;
      case 'South Carolina':  $rVal = 'SC';
        break;
      case 'South Dakota':    $rVal = 'SD';
        break;
      case 'Tennessee':       $rVal = 'TN';
        break;
      case 'Texas':           $rVal = 'TX';
        break;
      case 'Utah':            $rVal = 'UT';
        break;
      case 'Virginia':        $rVal = 'VA';
        break;
      case 'Vermont':         $rVal = 'VT';
        break;
      case 'Washington':      $rVal = 'WA';
        break;
      case 'Wisconsin':       $rVal = 'WI';
        break;
      case 'West Virginia':   $rVal = 'WV';
        break;
      case 'Wyoming':         $rVal = 'WY';
        break;
    }// end switch

    return $rVal;

  }// end getStateCodeFromName


}

// No closing tag
