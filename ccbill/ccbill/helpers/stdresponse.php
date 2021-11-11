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
//defined('_JEXEC') or die('JEXEC not defined');
//die('poop');


$orderId = $_GET['zc_orderid'];

if($orderId == null || strlen($orderId) < 1)
  die('blah');

$newLoc = $_SERVER[HTTP_HOST] . $_SERVER[REQUEST_URI];

$qmIndex = strrpos($newLoc, '?');

if($qmIndex >= 0)
  $newLoc = substr($newLoc, 0, $qmIndex);

$newLoc = str_replace('plugins/vmpayment/ccbill/ccbill/helpers/stdresponse.php', 
                      'index.php/orders/number/' . $orderId, 
                      $newLoc)

  //header('Location: ' . $newLoc);
/*
$success = $viewData["success"];
$payment_name = $viewData["payment_name"];
$payment = $viewData["payment"];
$order = $viewData["order"];
$currency = $viewData["currency"];
die('success: ' . + $success);
*/
?>
<br />Taking you to your order.  Please <a href="//<?php echo $newLoc ?>">click here</a> if you are not redirected. 
<script type="text/javascript">
	document.location = '//<?php echo $newLoc ?>';
</script>