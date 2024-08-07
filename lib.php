<?php

require_once (__DIR__.'/settings.php');


function SendLog( $message ){
    global $db;
    if( strlen($message) > 0 ){
        $query = "INSERT INTO `logs` ( `logtxt`, `unix` ) VALUES (:logtxt,:unix)";
        $params = [
            ':logtxt' => $message,
            ':unix' => time()
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    }
}
function SendAmoLog( $message, $logtype ){
    global $db;
    if( strlen($message) > 0 ){
        $query = "INSERT INTO `bxLogs` ( `logtxt`, `unix`, `logtype` ) VALUES (:logtxt,:unix,:logtype)";
        $params = [
            ':logtxt' => $message,
            ':unix' => time(),
            ':logtype' => $logtype
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    }
}
/*
 *  Получаем ид физического лица в системе
 */
//----------------------------------------------------------------------------------------------------------------------
function format_uuidv4($data)
{
    assert(strlen($data) == 16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}
//----------------------------------------------------------------------------------------------------------------------
function ShowGetDataToPay( $accid, $did, $email, $paySystem ){
    return '<meta http-equiv="refresh" content="1; url=/webpay.php?accid='.$accid.'&did='.$did.'&email='.$email.'&paysystem='.urlencode(json_encode( $paySystem )).'">';
}
//----------------------------------------------------------------------------------------------------------------------
function ShowErrorToPay( $errorText ){
    return '<meta http-equiv="refresh" content="1; url=/webpay.php?error='.urlencode($errorText).'">';
}
//----------------------------------------------------------------------------------------------------------------------
function GetPayUrl( $token, $kassaid, $paymentsType, $email, $totalSumm, $arrayItems, $companyArray, $externalId, $secret ){
    $arrayTypePay = -1;
    $paramurl = "https://app.ecomkassa.ru/fiscalorder/v2/$kassaid/sell?token=$token";
    if ($curl = curl_init()) {
        curl_setopt($curl, CURLOPT_URL,$paramurl);
        curl_setopt($curl, CURLOPT_POST, true);
        $jayParsedAry = [
            "external_id" => $externalId,
            "receipt" => [
                "client" => [
                    "email" => $email
                ],
                "prePaid" => true,
                "company" => $companyArray,
                "items" => $arrayItems,
                "payments" => [
                    [
                        "type" => (int)$paymentsType,
                        "sum" => (float)$totalSumm
                    ]
                ],
                "total" => (float)$totalSumm
            ],
            "service" => [
                "callback_url" => "https://".C_REST_MAIN_DOMAIN."/callback.php?secret=$secret&externalId=$externalId"
            ],
            "timestamp" => date('d.m.y H:i:s')
        ];
        SendLog(json_encode($jayParsedAry));
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode( $jayParsedAry ));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMECONDITION, 60);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        $out = curl_exec($curl);
        SendLog('ANS ' . $out);
        $outJson = json_decode( $out );

        curl_close($curl);
        $arrayTypePay = $outJson;
    }
    return $arrayTypePay;
}
//----------------------------------------------------------------------------------------------------------------------
function GetPaymentTypes( $token, $kassaid ){
    $arrayTypePay = -1;
    $paramurl = "https://app.ecomkassa.ru/fiscalorder/v2/$kassaid/paymentTypes?token=$token";
    if ($curl = curl_init()) {
        curl_setopt($curl, CURLOPT_URL,$paramurl);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMECONDITION, 60);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        $out = curl_exec($curl);
        $outJson = json_decode( $out );
        SendLog($out);
        SendLog($paramurl);
        curl_close($curl);
        $arrayTypePay = $outJson;
    }
    return $arrayTypePay;
}
//----------------------------------------------------------------------------------------------------------------------
function GetToken( $loginUser, $passUser ){
    $tokenResult = -1;
    $paramurl = "https://app.ecomkassa.ru/fiscalorder/v2/getToken?login=$loginUser&pass=$passUser";
    if ($curl = curl_init()) {
        curl_setopt($curl, CURLOPT_URL,$paramurl);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_TIMECONDITION, 60);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
        $out = curl_exec($curl);
        $outJson = json_decode( $out );
        curl_close($curl);
        if( isset( $outJson->token ) ){
            $tokenResult = $outJson->token;
        }
    }
    return $tokenResult;
}
//----------------------------------------------------------------------------------------------------------------------
function amoSaveToken( $clientId, $accessToken)
{
    global $db;
    if (
        isset($accessToken)
        && isset($accessToken['accessToken'])
        && isset($accessToken['refreshToken'])
        && isset($accessToken['expires'])
        && isset($accessToken['baseDomain'])
    ) {
        $data = [
            'accessToken' => $accessToken['accessToken'],
            'expires' => $accessToken['expires'],
            'refreshToken' => $accessToken['refreshToken'],
            'baseDomain' => $accessToken['baseDomain'],
        ];

        $stmt = $db->prepare("SELECT `id` FROM users WHERE `member_id` = ?");
        $stmt->execute([$clientId]);
        $userData = $stmt->fetch(PDO::FETCH_LAZY);
        if( $userData['id'] > 0 && $clientId != '-' )        {
            $query = "UPDATE `users` SET `settings` = :sett WHERE `id` = :id";
            $params = [
                ':id' => $userData['id'],
                ':sett' => json_encode($data)
            ];
            $stmt = $db->prepare($query);
            //обновление
        }else{
            $account_id = 0;
            $secret = md5(rand(1, 10000000).time());
            $query = "INSERT INTO `users` (`settings`, `member_id`, `unix_install`, `secret_code`, `account_id`) VALUES (:sett,:memb,:unix,:secret,:account_id)";
            $params = [
                ':sett' => json_encode($data),
                ':memb' => $clientId,
                ':unix' => time(),
                ':secret' => $secret,
                ':account_id' => $account_id
            ];
            $stmt = $db->prepare($query);
        }
        $stmt->execute($params);

        //file_put_contents(TOKEN_FILE, json_encode($data));
    } else {
        exit('Invalid access token ' . var_export($accessToken, true));
    }
}

/**
 * @return \League\OAuth2\Client\Token\AccessToken
 */
function amoGetToken( $clientId )
{
    global $db;
    $accessToken = [];
    $stmt = $db->prepare("SELECT `id`, `settings` FROM users WHERE `member_id` = ?");
    $stmt->execute([$clientId]);
    $userData = $stmt->fetch(PDO::FETCH_LAZY);
    if( $userData['id'] > 0 && $clientId != '-' )
    {
        $accessToken = json_decode($userData['settings'], true);
    }
    if (
        isset($accessToken)
        && isset($accessToken['accessToken'])
        && isset($accessToken['refreshToken'])
        && isset($accessToken['expires'])
        && isset($accessToken['baseDomain'])
    ) {
        return new \League\OAuth2\Client\Token\AccessToken([
            'access_token' => $accessToken['accessToken'],
            'refresh_token' => $accessToken['refreshToken'],
            'expires' => $accessToken['expires'],
            'baseDomain' => $accessToken['baseDomain'],
        ]);
    } else {
        exit('Invalid access token ' . var_export($accessToken, true));
    }
}
?>