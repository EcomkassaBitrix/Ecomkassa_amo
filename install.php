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

if (!isset($_GET['request']) &&  isset($_GET['code'])) {
    /**
     * Ловим обратный код
     */
    try {
        /** @var \League\OAuth2\Client\Token\AccessToken $access_token */
        $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\AuthorizationCode(), [
            'code' => $_GET['code'],
        ]);

        if (!$accessToken->hasExpired()) {
            amoSaveToken( $_REQUEST['client_id'] ,[
                'accessToken' => $accessToken->getToken(),
                'refreshToken' => $accessToken->getRefreshToken(),
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
        }
    } catch (Exception $e) {
        //die((string)$e);
    }
    //------------------------------------Поиск идов каталогов----------------------------------------------------------
    $idCatalogInv = -1;
    try {
        $data = $provider->getHttpClient()
            ->request('GET', $provider->urlAccount() . 'api/v4/catalogs', [
                'headers' => $provider->getHeaders($accessToken)
            ]);
        $result = $data->getBody()->getContents();

        SendAmoLog( $result, 'api/v4/catalogs-ins-'.$_REQUEST['client_id'] );
        $parsedBody = json_decode($result, true);
        foreach ($parsedBody['_embedded']['catalogs'] as $value) {
            if( $value['type'] == 'invoices' ){
                $idCatalogInv = $value['id'];
            }
        }
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
    }
    if( $idCatalogInv != -1 ){
        //Устанавливаем хуки
        try {
            $dataw = array(
                "destination" => "https://".C_REST_MAIN_DOMAIN."/webhook.php?code=".substr($_REQUEST['client_id'],0,8),
                "settings" => [
                    "add_catalog_".$idCatalogInv, "update_lead"
                ]
            );
            $data = $provider->getHttpClient()
                ->request('POST', $provider->urlAccount() . 'api/v4/webhooks', [
                    'headers' => $provider->getHeaders($accessToken),
                    'form_params' => $dataw
                ]);
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
    }
}