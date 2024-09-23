<?php

use AmoCRM\OAuth2\Client\Provider\AmoCRM;

include_once '../vendor/autoload.php';
include_once '../src/AmoCRM.php';
require_once (__DIR__.'/lib.php');

session_start();
/**
 * Создаем провайдера
 */
$provider = new AmoCRM([
    'clientId' => C_REST_CLIENT_ID,
    'clientSecret' => C_REST_CLIENT_SECRET,
    'redirectUri' => C_REST_REDIRECT_URI,
]);
if (!isset($_REQUEST['client_id'])) {
    exit;
}
if (isset($_GET['referer'])) {
    $provider->setBaseDomain($_GET['referer']);
}
if (!isset($_GET['request']) && isset($_GET['code'])) {
    /**
     * Ловим обратный код
     */
    try {
        /** @var \League\OAuth2\Client\Token\AccessToken $access_token */
        $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\AuthorizationCode(), [
            'code' => $_GET['code'],
        ]);

    } catch (Exception $e) {
        //die((string)$e);
    }
    /** @var \AmoCRM\OAuth2\Client\Provider\AmoCRMResourceOwner $ownerDetails */
    try {
        $data = $provider->getHttpClient()
            ->request('GET', $provider->urlAccount() . 'api/v2/account', [
                'headers' => $provider->getHeaders($accessToken)
            ]);
        $result = $data->getBody()->getContents();
        $parsedBody = json_decode($result, true);
        if (!$accessToken->hasExpired()) {
            amoSaveToken( $parsedBody['id'] , $parsedBody['current_user'],[
                'accessToken' => $accessToken->getToken(),
                'refreshToken' => $accessToken->getRefreshToken(),
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
        }
        $query = "UPDATE `users` SET `install` = :install WHERE `member_id` = :member_id";
        $params = [
            ':member_id' => $parsedBody['id'],
            ':install' => 0
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
    }
    //------------------------------------Поиск идов каталогов----------------------------------------------------------
    $idCatalogInv = -1;
    $idCatalogProd = -1;
    try {
        $data = $provider->getHttpClient()
            ->request('GET', $provider->urlAccount() . 'api/v4/catalogs', [
                'headers' => $provider->getHeaders($accessToken)
            ]);
        $result = $data->getBody()->getContents();
        SendAmoLog( $result, 'api/v4/catalogs-ins-'.$_REQUEST['client_id'] );
        $parsedBody2 = json_decode($result, true);
        foreach ($parsedBody2['_embedded']['catalogs'] as $value) {
            if( $value['type'] == 'invoices' ){
                $idCatalogInv = $value['id'];
            }
            else if( $value['type'] == 'products' ){
                $idCatalogProd = $value['id'];
            }
        }
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
    }
    try {
        $dataw = array(
            "name"=> "Ссылка на оплату Ecom",
            "type"=> "url",
            "code"=> "URL_LEAD_ECOM_KASSA"
        );
        $data = $provider->getHttpClient()
            ->request('POST', $provider->urlAccount() . 'api/v4/leads/custom_fields', [
                'headers' => $provider->getHeaders($accessToken),
                'form_params' => $dataw
            ]);
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
    }
    if( $idCatalogProd != -1 ){
        $findPriznakSposob = false;
        $findPriznakTowara = false;
        try {
            $data = $provider->getHttpClient()
                ->request('GET', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogProd.'/custom_fields', [
                    'headers' => $provider->getHeaders($accessToken)
                ]);
            $parsedBody = json_decode($data->getBody()->getContents(), true);
            foreach ($parsedBody['_embedded']['custom_fields'] as $value) {
                if( $value['name'] == 'Признак способа расчета' ){
                    $findPriznakSposob = true;
                }
                else if( $value['name'] == 'Признак товара' ){
                    $findPriznakTowara = true;
                }
            }
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            //var_dump((string)$e);
        }
        if( $findPriznakSposob == false ){
            try {
                $dataw = array(
                    "name" => "Признак способа расчета",
                    "type" => "select",
                    "code" => "PRZ_TYPE_ECOM_KASSA",
                    "sort" => "500",
                    "enums"=> [
                        ["value"=> "полный расчет"],
                        ["value"=> "предоплата 100% - полная предварительная оплата, которая осуществляется клиентом до получения товара/оказания услуги"],
                        ["value"=> "предоплата - частичная предварительная оплата, которая осуществляется клиентом до получения товара/оказания услуги"],
                        ["value"=> "аванс - предоплата в случаях, когда заранее нельзя определить перечень товаров/работ/услуг"],
                        ["value"=> "частичный расчет и кредит"],
                        ["value"=> "передача в кредит"],
                        ["value"=> "оплата кредита"]
                    ]
                );
                $data = $provider->getHttpClient()
                    ->request('POST', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogProd.'/custom_fields', [
                        'headers' => $provider->getHeaders($accessToken),
                        'form_params' => $dataw
                    ]);
            } catch (GuzzleHttp\Exception\GuzzleException $e) {
                //var_dump((string)$e);
            }
        }
        if( $findPriznakTowara == false ){
            try {
                $dataw = array(
                    "name" => "Признак товара",
                    "type" => "select",
                    "code" => "PRZ_TOWARA_ECOM_KASSA",
                    "sort" => "500",
                    "enums"=> [
                        ["value"=> "товар"],
                        ["value"=> "подакцизный товар"],
                        ["value"=> "работа"],
                        ["value"=> "услуга"],
                        ["value"=> "ставка азартной игры"],
                        ["value"=> "выигрыш азартной игры"],
                        ["value"=> "лотерейный билет"],
                        ["value"=> "выигрыш лотереи"],
                        ["value"=> "предоставление результатов интеллектуальной деятельности"],
                        ["value"=> "платеж"],
                        ["value"=> "агентское вознаграждение"],
                        ["value"=> "составной предмет расчета"],
                        ["value"=> "иной предмет расчета"]
                    ]
                );
                $data = $provider->getHttpClient()
                    ->request('POST', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogProd.'/custom_fields', [
                        'headers' => $provider->getHeaders($accessToken),
                        'form_params' => $dataw
                    ]);
            } catch (GuzzleHttp\Exception\GuzzleException $e) {
                //var_dump((string)$e);
            }
        }
    }
    if( $idCatalogInv != -1 ){
        //Устанавливаем хуки
        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE `member_id` = ?");
            $stmt->execute([$parsedBody['id']]);
            $userData = $stmt->fetch(PDO::FETCH_LAZY);
            if($userData['id']){
                $dataw = array(
                    "destination" => "https://".C_REST_MAIN_DOMAIN."/webhook.php?code=".substr($userData['secret_code'],0,8),
                    "settings" => [
                        "add_catalog_".$idCatalogInv, "update_lead"
                    ]
                );
                $data = $provider->getHttpClient()
                    ->request('POST', $provider->urlAccount() . 'api/v4/webhooks', [
                        'headers' => $provider->getHeaders($accessToken),
                        'form_params' => $dataw
                    ]);
            }

        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            //var_dump((string)$e);
        }
        //Устанавливаем поле для чеков
        try {
            $dataw = array(
                "name"=> "Ссылка на чек Ecom",
                "type"=> "url",
                "code"=> "URL_ECOM_KASSA"
            );
            $data = $provider->getHttpClient()
                ->request('POST', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogInv.'/custom_fields', [
                    'headers' => $provider->getHeaders($accessToken),
                    'form_params' => $dataw
                ]);
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            //var_dump((string)$e);
        }
        //--------------------------------------------------------------------------------------------------------------
        $findSystemNalog = false;
        try {
            $data = $provider->getHttpClient()
                ->request('GET', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogInv.'/custom_fields', [
                    'headers' => $provider->getHeaders($accessToken)
                ]);
            $parsedBody = json_decode($data->getBody()->getContents(), true);
            foreach ($parsedBody['_embedded']['custom_fields'] as $value) {
                if( $value['name'] == 'Система налогооблажения' ){
                    $findSystemNalog = true;
                }
            }
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            //var_dump((string)$e);
        }
        if( $findSystemNalog == false ){
            try {
                $dataw = array(
                    "name" => "Система налогооблажения",
                    "type" => "select",
                    "code" => "SYS_NALOG_ECOM_KASSA",
                    "sort" => "500",
                    "enums"=> [
                        ["value"=> "общая СН"],
                        ["value"=> "упрощенная СН (доходы)"],
                        ["value"=> "упрощенная СН(доходы минус расходы)"],
                        ["value"=> "единый налог на вмененный доход"],
                        ["value"=> "единый сельскохозяйственный налог"],
                        ["value"=> "патентная СН"]
                    ]
                );
                $data = $provider->getHttpClient()
                    ->request('POST', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogInv.'/custom_fields', [
                        'headers' => $provider->getHeaders($accessToken),
                        'form_params' => $dataw
                    ]);
            } catch (GuzzleHttp\Exception\GuzzleException $e) {
                //var_dump((string)$e);
            }
        }
    }
}