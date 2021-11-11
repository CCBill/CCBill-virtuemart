<?php
/**
 *
 * CCBill payment plugin
 *
 * @author CCBill
 * @version $Id: ccbill.php 1 2015-01-01 00:00:00Z ccbill $
 * @package VirtueMart
 * @subpackage payment
 * Copyright (C) 2015-2021 CCBill. All rights reserved.
 * http://ccbill.com
 */
defined('_JEXEC') or die('Restricted access');

class CCBillHelperCustomerData {

	private $_selected_method = '';
	private $_errormessage = array();


	public function load() {

		//$this->clear();
		if (!class_exists('vmCrypt')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'vmcrypt.php');
		}
		$session = JFactory::getSession();
		$sessionData = $session->get('ccbill', 0, 'vm');

		if (!empty($sessionData)) {
			$data =   (object)json_decode($sessionData, true);
			$this->_selected_method = $data->selected_method;

			$this->save();
			return $data;
		}
	}

	public function loadPost() {
		if (!class_exists('vmCrypt')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'vmcrypt.php');
		}
		// card information
		$virtuemart_paymentmethod_id = vRequest::getVar('virtuemart_paymentmethod_id', 0);		

		$this->_selected_method = $virtuemart_paymentmethod_id;
		
		$this->save();
	}

	public function save() {
		if (!class_exists('vmCrypt')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'vmcrypt.php');
		}
		$session = JFactory::getSession();
		$sessionData = new stdClass();
		$sessionData->selected_method = $this->_selected_method;

		$session->set('ccbill', json_encode($sessionData), 'vm');
	}

	public function reset() {
		$this->_selected_method = '';

		$this->save();
	}

	public function clear() {
		$session = JFactory::getSession();
		$session->clear('ccbill', 'vm');
	}

	public function getVar($var) {
		$this->load();
		return $this->{'_' . $var};
	}

	public function setVar($var, $val) {
		$this->{'_' . $var} = $val;
	}

}
