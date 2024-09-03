<?php
    require_once (__DIR__.'/lib.php');
    use AmoCRM\OAuth2\Client\Provider\AmoCRM;
    include_once '../vendor/autoload.php';
    include_once '../src/AmoCRM.php';
    session_start();
    if( !isset($_REQUEST['secret']) || !isset($_REQUEST['externalId']) ){
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM bills WHERE `external_id` = ? and `secret` = ? and `status` = ?");
    $stmt->execute([$_REQUEST['externalId'],$_REQUEST['secret'], 'paid']);
    $bill = $stmt->fetch(PDO::FETCH_LAZY);
    if( $bill['id'] > 0 )
    {
        $stmt = $db->prepare("SELECT * FROM users WHERE `member_id` = ?");
        $stmt->execute([$bill['member_id']]);
        $user = $stmt->fetch(PDO::FETCH_LAZY);
        if( $user['id'] > 0 && $bill['PAYMENT_ID'] ){
            //-----------------------------------------Проверка токена--------------------------------------------------
            $provider = new AmoCRM([
                'clientId' => C_REST_CLIENT_ID,
                'clientSecret' => C_REST_CLIENT_SECRET,
                'redirectUri' => C_REST_REDIRECT_URI,
            ]);
            if (isset($_GET['referer'])) {
                $provider->setBaseDomain($_GET['referer']);
            }
            $accessToken = amoGetToken( $user['member_id'] );
            $provider->setBaseDomain($accessToken->getValues()['baseDomain']);
            if ($accessToken->hasExpired()) {
                try {
                    $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\RefreshToken(), [
                        'refresh_token' => $accessToken->getRefreshToken(),
                    ]);
                    amoSaveToken( $user['member_id'], $user['account_id'] , [
                        'accessToken' => $accessToken->getToken(),
                        'refreshToken' => $accessToken->getRefreshToken(),
                        'expires' => $accessToken->getExpires(),
                        'baseDomain' => $provider->getBaseDomain(),
                    ]);
                } catch (Exception $e) {
                    //die((string)$e);
                }
            }
            $token = $accessToken->getToken();
            //-----------------------------------Поиск каталогов--------------------------------------------------------
            $idCatalogInv = -1;
            try {
                $data = $provider->getHttpClient()
                    ->request('GET', $provider->urlAccount() . 'api/v4/catalogs', [
                        'headers' => $provider->getHeaders($accessToken)
                    ]);
                $parsedBody = json_decode($data->getBody()->getContents(), true);
                foreach ($parsedBody['_embedded']['catalogs'] as $value) {
                    if( $value['type'] == 'invoices' ){
                        $idCatalogInv = $value['id'];
                    }
                }
            } catch (GuzzleHttp\Exception\GuzzleException $e) {
                //var_dump((string)$e);
            }
            //-----------------------------------Отметить счёт оплаченным-----------------------------------------------
            if( $idCatalogInv != -1 ){
                if( $user['defStatusAfter'] != 'none' ){
                    $dataw = array(
                        "name" => "",
                        "custom_fields_values" => [
                            [
                                "field_code"=> "BILL_STATUS",
                                "values"=> [
                                    [
                                        "enum_code" => $user['defStatusAfter']
                                    ]
                                ]
                            ]
                        ]
                    );
                    try {
                        $data = $provider->getHttpClient()
                            ->request('PATCH', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogInv.'/elements/'.$bill['PAYMENT_ID'], [
                                'headers' => $provider->getHeaders($accessToken),
                                'form_params' => $dataw
                            ]);
                        SendAmoLog( $data->getBody()->getContents(), 'api/v4/catalogs/userID-'.$user['id'] );
                    } catch (GuzzleHttp\Exception\GuzzleException $e) {
                        //var_dump((string)$e);
                        SendAmoLog( (string)$e, 'api/v4/catalogs/userID-'.$user['id'] );
                    }
                }
                //------------------------------------------------------------------------------------------------------
                if( $bill['permalink'] != null ) {
                    $dataw = array(
                        "name" => "",
                        "custom_fields_values" => [
                            [
                                "field_code" => "URL_ECOM_KASSA",
                                "values" => [
                                    [
                                        "value" => $bill['permalink']
                                    ]
                                ]
                            ]
                        ]
                    );
                    try {
                        $data = $provider->getHttpClient()
                            ->request('PATCH', $provider->urlAccount() . 'api/v4/catalogs/' . $idCatalogInv . '/elements/' . $bill['PAYMENT_ID'], [
                                'headers' => $provider->getHeaders($accessToken),
                                'form_params' => $dataw
                            ]);
                        SendAmoLog($data->getBody()->getContents(), 'api/v4/catalogs/userID-' . $user['id']);
                    } catch (GuzzleHttp\Exception\GuzzleException $e) {
                        //var_dump((string)$e);
                        SendAmoLog((string)$e, 'api/v4/catalogs/userID-' . $user['id']);
                    }
                }
            }
            //---------------------------------Передвинуть сделку на этап-----------------------------------------------
            if( $user['defPipeline'] > 0 ){
                $stmt = $db->prepare("SELECT * FROM linkDealPayment WHERE `member_id` = ? AND `PAYMENT_ID` = ?");
                $stmt->execute([$user['member_id'],$bill['PAYMENT_ID']]);
                $linkDealPayment = $stmt->fetch(PDO::FETCH_LAZY);
                if( $linkDealPayment['id'] ){
                    $dataw = array( "pipeline_id" => (int)$user['defPipeline'] );
                    if( $user['defStatus'] > 0 ){
                        $dataw["status_id"] = (int)$user['defStatus'];
                    }
                    if ( $curl = curl_init() ) {
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
                        curl_setopt($curl, CURLOPT_URL, $provider->urlAccount().'/api/v4/leads/'.$linkDealPayment['dealid']);
                        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
                        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($dataw));
                        curl_setopt($curl, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Authorization: Bearer ' . $accessToken,
                        ]);
                        curl_setopt($curl, CURLOPT_HEADER, false);
                        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                        $out = curl_exec($curl);
                        SendAmoLog( $out, '/api/v4/leads/-userID-'.$user['id'] );
                        curl_close($curl);
                    }
                }
            }
        }
        if( $user['id'] > 0 && $user['webHookUrl'] )
        {
            //----------------------------------------------------------------------------------------------------------
            $paramurl = str_replace('{{ID}}', $bill['PAYMENT_ID'], $user['webHookUrl']);
            $paramurl = str_replace('[ID]', $bill['PAYMENT_ID'], $user['webHookUrl']);
            $paramurl = str_replace('{ID}', $bill['PAYMENT_ID'], $user['webHookUrl']);
            if ($curl = curl_init()) {
                curl_setopt($curl, CURLOPT_URL,$paramurl);
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "GET");
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($curl, CURLOPT_TIMEOUT, 60);
                curl_setopt($curl, CURLOPT_TIMECONDITION, 60);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60);
                curl_exec($curl);
                curl_close($curl);
            }
        }
        $query = "UPDATE `bills` SET `status` = :status WHERE `id` = :id";
        $params = [
            ':id' => $bill['id'],
            ':status' => 'paid'
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    }
    exit;
?>