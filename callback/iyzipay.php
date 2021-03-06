<?php
/**
 * WHMCS Iyzipay 3D Secure Callback File
 *
 * Author: Milos Markovic
 * Company: The Web Tailor, Inc.
 * Version: 1.0
 * Description: Iyzico Merchant Gateway Module For WHMCS
 * 
 * @copyright Copyright (c) 2017 The Web Tailor, Inc.
 */

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
include __DIR__ . '/../iyzipay/IyzipayBootstrap.php';
IyzipayBootstrap::init();

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}
// Set the base URL
$baseUrl = set_base_url($gatewayParams);
if(isset($_POST['token'])) {
    // Popup + Responsive versions
    $options = new \Iyzipay\Options();
    $options->setApiKey($gatewayParams['apiKey']);
    $options->setSecretKey($gatewayParams['secretKey']);
    $options->setBaseUrl($baseUrl);
    
    $request = new \Iyzipay\Request\RetrieveCheckoutFormRequest();
    $request->setLocale(\Iyzipay\Model\Locale::EN);
    $request->setConversationId($gatewayParams["conversationId"]);
    $request->setToken($_POST['token']);
    
    # make request
    $checkoutForm = \Iyzipay\Model\CheckoutForm::retrieve($request, $options);
    
    if (NULL == $checkoutForm) {
        $cbSuccess = false;
        callback3DSecureRedirect($invoiceId, $cbSuccess);
        die;
    }
    
    $cbSuccess = false;

    if ($checkoutForm->getConversationId() != $gatewayParams["conversationId"]) {
        $cbSuccess = false;
        $transactionStatus = "Request cannot be verified";
        die($transactionStatus);
    }

    if ("success" == $checkoutForm->getStatus() && 1 == $checkoutForm->getFraudStatus()) {
        $invoiceId = $checkoutForm->getBasketId();
        $transactionId = $checkoutForm->getpaymentId();
        $paymentAmount = $checkoutForm->getPaidPrice();
        $paymentFee = get_comission_rate($checkoutForm);

        // Validate invoice id
        $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

        // Validate transaction id
        checkCbTransID($transactionId);

        logTransaction($gatewayParams['name'], $_POST, $transactionStatus);

        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $paymentAmount,
            $paymentFee,
            $gatewayModuleName
        );

        $cbSuccess = true;
    } elseif ("failure" == $checkoutForm->getStatus()) {
        $transactionStatus = $checkoutForm->getErrorMessage();
        $cbSuccess = false;
    }
} else if($_POST["status"]) {
    // Regular 3d auth
    $initStatus = $_POST["status"];
    $initTransactionId = $_POST["paymentId"];
    $initConversationId = $_POST["conversationId"];
    $initConversationData = $_POST["conversationData"];
    
    $cbSuccess = false;
    if ($initConversationId != $gatewayParams["conversationId"]) {
        $cbSuccess = false;
        $transactionStatus = "Request cannot be verified";
        die($transactionStatus);
    }
    
    if ("success" != $initStatus) {
        $cbSuccess = false;
        $transactionStatus = "3D Secure payment failed";
    }
    
    if ("success" == $initStatus) {
        $options = new \Iyzipay\Options();
        $options->setApiKey($gatewayParams['apiKey']);
        $options->setSecretKey($gatewayParams['secretKey']);
        $options->setBaseUrl($baseUrl);
        $request = new \Iyzipay\Request\CreateThreedsPaymentRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId($gatewayParams["conversationId"]);
        $request->setPaymentId($initTransactionId);
        $request->setConversationData($initConversationData);
        # make request
        $auth = \Iyzipay\Model\ThreedsPayment::create($request, $options);
    }
    
    if (NULL == $auth) {
        $cbSuccess = false;
        callback3DSecureRedirect($invoiceId, $cbSuccess);
    }
    
    if ("success" == $auth->getStatus() && 1 == $auth->getFraudStatus()) {
        $invoiceId = $auth->getBasketId();
        $transactionId = $auth->getpaymentId();
        $paymentAmount = $auth->getPaidPrice();
        $paymentFee = get_comission_rate($auth);
        // Validate invoice id
        $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
        // Validate transaction id
        checkCbTransID($transactionId);
        logTransaction($gatewayParams['name'], $_POST, $transactionStatus);
        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $paymentAmount,
            $paymentFee,
            $gatewayModuleName
        );
        $cbSuccess = true;
    } elseif ("failure" == $auth->getStatus()) {
        $transactionStatus = $auth->getErrorMessage();
        $cbSuccess = false;
    }
} else {
    $cbSuccess = false;
    $transactionStatus = "Request cannot be verified";
    die($transactionStatus);
}

// Redirect to invoice
callback3DSecureRedirect($invoiceId, $cbSuccess);
