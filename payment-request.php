<?php

// If this is a payment request

if (isset($_POST["data"])) {

// Functions to decrypt the payment request from Ecwid

  function getEcwidPayload($app_secret_key, $data) {
    // Get the encryption key (16 first bytes of the app's client_secret key)
    $encryption_key = substr($app_secret_key, 0, 16);

    // Decrypt payload
    $json_data = aes_128_decrypt($encryption_key, $data);

    // Decode json
    $json_decoded = json_decode($json_data, true);
    return $json_decoded;
  }

  function aes_128_decrypt($key, $data) {
    // Ecwid sends data in url-safe base64. Convert the raw data to the original base64 first
    $base64_original = str_replace(array('-', '_'), array('+', '/'), $data);

    // Get binary data
    $decoded = base64_decode($base64_original);

    // Initialization vector is the first 16 bytes of the received data
    $iv = substr($decoded, 0, 16);

    // The payload itself is is the rest of the received data
    $payload = substr($decoded, 16);

    // Decrypt raw binary payload
    $json = openssl_decrypt($payload, "aes-128-cbc", $key, OPENSSL_RAW_DATA, $iv);
    //$json = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key, $payload, MCRYPT_MODE_CBC, $iv); // You can use this instead of openssl_decrupt, if mcrypt is enabled in your system

    return $json;
  }

  // Get payload from the POST and decrypt it
  $ecwid_payload = $_POST['data'];
  $client_secret = "LogxI79IsFenVSBydPN3bZz9ItpdcYXK"; // This is a dummy value. Place your client_secret key here. You received it from Ecwid team in email when registering the app

  // The resulting JSON from payment request will be in $order variable
  $order = getEcwidPayload($client_secret, $ecwid_payload);

  // Debug preview of the request decoded earlier
  echo "<h3>REQUEST DETAILS</h3>";

      // Account info from merchant app settings in app interface in Ecwid CP
      $merchantId = $order['merchantAppSettings']['merchantId'];
      $merchantKey = $order['merchantAppSettings']['merchantKey'];

      // OPTIONAL: Split name field into two fields: first name and last name
      $fullName = explode(" ", $order["cart"]["order"]["billingPerson"]["name"]);
      $firstName = $fullName[0]; $lastName = $fullName[1];

      // Encode access token and prepare calltack URL template
      $callbackPayload = base64_encode($order['token']);
      $callbackUrl = "https://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"."?storeId=".$order['storeId']."&orderNumber=".$order['cart']['order']['orderNumber']."&callbackPayload=".$callbackPayload;

        $strDescription = '';
        foreach($order['items'] as $item){
            $strDescription .= strip_tags($item['name']);
            if($item['quantity'] > 1)
                $strDescription .= "*".$item['quantity'];
            $strDescription .= "; ";
        }

        if($order["cart"]["currency"] == "RUR")
            $order["cart"]["currency"] = "RUB";

        // Request parameters to pass into payment gateway
        $request = array(
            'pg_amount'         => (int)$order['total'],
            'pg_description'    => $strDescription,
            'pg_encoding'       => 'UTF-8',
            'pg_currency'       => $order["cart"]["currency"],
            'pg_user_ip'        => $_SERVER['REMOTE_ADDR'],
            'pg_lifetime'       => 86400,
            'pg_merchant_id'    => $merchantId,
            'pg_order_id'       => $order['cart']['order']['orderNumber'],
            'pg_result_url'     => $order["returnUrl"],
            'pg_request_method' => 'GET',
            'pg_salt'           => rand(21, 43433),
            'pg_success_url'    => $callbackUrl."&status=PAID",
            'pg_failure_url'	=> $callbackUrl."&status=CANCELLED",
            'pg_user_phone'     => $order["cart"]["order"]["billingPerson"]["phone"],
            'pg_user_contact_email' => $order["cart"]["order"]["email"]
        );
        $request['pg_testing_mode'] = ('yes' === $order['merchantAppSettings']['testmode']) ? 1 : 0;

        $url = 'payment.php';
        ksort($request);
        array_unshift($request, $url);
        array_push($request, $merchantKey);
        $str = implode(';', $request);
        $request['pg_sig'] = md5($str);
        unset($request[0], $request[1]);
        $query = http_build_query($request);
        $action = 'https://api.paybox.money/' . $url . '?' . $query;

        // Print form on a page to submit it from a button press

        echo '<form id="paybox_payment_form" action="https://api.paybox.money/payment.php" method="post">';
            foreach ($request as $name => $value) {
                echo "<input type='hidden' name='$name' value='$value'></input>";
            }
        echo "<input type='submit' value='Submit'>";
        echo "</form>";
        echo "<script>document.querySelector('#payment_form).submit();</script>";

}

// If we are returning back to storefront. Callback from payment

if (isset($_GET["callbackPayload"]) && isset($_GET["status"])) {

    // Set variables
    $client_id = "paybox-money-dev";
    $token = base64_decode(($_GET['callbackPayload']));
    $storeId = $_GET['storeId'];
    $orderNumber = $_GET['orderNumber'];
    $status = $_GET['status'];
    $returnUrl = "https://app.ecwid.com/custompaymentapps/$storeId?orderId=$orderNumber&clientId=$client_id";

    // Prepare request body for updating the order
    $json = json_encode(array(
        "paymentStatus" => $status,
        "externalTransactionId" => "transaction_".$orderNumber
    ));

    // URL used to update the order via Ecwid REST API
    $url = "https://app.ecwid.com/api/v3/$storeId/orders/transaction_$orderNumber?token=$token";

    // Send request to update order
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json','Content-Length: ' . strlen($json)));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS,$json);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    // return customer back to storefront
    echo "<script>window.location = '$returnUrl'</script>";

}

else {

  header('HTTP/1.0 403 Forbidden');
  echo 'Access forbidden!';

}


?>
