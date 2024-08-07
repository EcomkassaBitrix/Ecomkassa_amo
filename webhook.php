<?php
require_once (__DIR__.'/lib.php');
use AmoCRM\OAuth2\Client\Provider\AmoCRM;
include_once '../vendor/autoload.php';
include_once '../src/AmoCRM.php';
session_start();

if(!isset($_REQUEST['account']['id'])){exit;}

$stmt = $db->prepare("SELECT * FROM users WHERE `account_id` = ?");
$stmt->execute([$_REQUEST['account']['id']]);
$user = $stmt->fetch(PDO::FETCH_LAZY);
SendAmoLog( json_encode($_REQUEST), 'webhook' );
if( $user['id'] ){

    if( substr($user['member_id'], 0, 8) == $_REQUEST['code'] ){
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
                amoSaveToken( $user['member_id'] , [
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
        $globalArrUpdate = array();
        if( isset($_REQUEST['catalogs']['add']) ){
            foreach ($_REQUEST['catalogs']['add'] as $valueInv) {
                $dataw = array(
                    "id" => (int)$valueInv['id'],
                    "name" => "",
                    "custom_fields_values" => [
                        [
                            "field_code"=> "RECEIPT_LINK",
                            "values"=> [
                                [
                                    "value"=> "https://".C_REST_MAIN_DOMAIN."/pay/".$_REQUEST['account']['id']."?did=".$valueInv['id']
                                ]
                            ]
                        ]
                    ]
                );
                array_push($globalArrUpdate,$dataw);
            }
            if ( $curl = curl_init() ) {
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_USERAGENT, 'amoCRM-API-client/1.0');
                curl_setopt($curl, CURLOPT_URL, 'https://'.$_REQUEST['account']['subdomain'].'.amocrm.ru'.'/api/v4/catalogs/'.$valueInv['catalog_id'].'/elements');
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($globalArrUpdate));
                curl_setopt($curl, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $accessToken,
                ]);
                curl_setopt($curl, CURLOPT_HEADER, false);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                $out = curl_exec($curl);
                SendAmoLog( $out, 'RECEIPT_LINK-userId-'.$user['id'] );
                curl_close($curl);
            }
        }
        if( isset($_REQUEST['leads']['update']) && $user['defPipeline'] > 0 ){
            $filterSearch = "";
            $i = 0;
            foreach ($_REQUEST['leads']['update'] as $lead) {
                $filterSearch = $filterSearch."filter[entity_id][$i]=".$lead['id'].'&';
                $i++;
            }
            $idCatalogInv = -1;
            try {
                $data = $provider->getHttpClient()
                    ->request('GET', $provider->urlAccount() . 'api/v4/catalogs', [
                        'headers' => $provider->getHeaders($accessToken)
                    ]);
                $result = $data->getBody()->getContents();

                SendAmoLog( $result, 'api/v4/catalogs-'.$user['id'] );

                $parsedBody = json_decode($result, true);
                foreach ($parsedBody['_embedded']['catalogs'] as $value) {
                    if( $value['type'] == 'invoices' ){
                        $idCatalogInv = $value['id'];
                    }
                }
            } catch (GuzzleHttp\Exception\GuzzleException $e) {
                //var_dump((string)$e);
            }
            if( $idCatalogInv != -1 && $filterSearch ){
                try {
                    $data = $provider->getHttpClient()
                        ->request('GET', $provider->urlAccount() . 'api/v4/leads/links?'.$filterSearch.'filter[to_catalog_id]='.$idCatalogInv, [
                            'headers' => $provider->getHeaders($accessToken)
                        ]);
                    $result = $data->getBody()->getContents();

                    SendAmoLog( $result, 'api/v4/catalogs-'.$user['id'] );

                    $parsedBody = json_decode($result, true);
                    foreach ($parsedBody['_embedded']['links'] as $link) {
                        $stmt = $db->prepare("SELECT * FROM linkDealPayment WHERE `member_id` = ? AND `PAYMENT_ID` = ?");
                        $stmt->execute([$user['member_id'],$link['to_entity_id']]);
                        $linkDealPayment = $stmt->fetch(PDO::FETCH_LAZY);
                        if( !$linkDealPayment['id'] ){
                            $query = "INSERT INTO `linkDealPayment`(`member_id`, `dealid`, `PAYMENT_ID`) VALUES (:member_id,:dealid,:PAYMENT_ID)";
                            $params = [
                                ':dealid' => $link['entity_id'],
                                ':PAYMENT_ID' => $link['to_entity_id'],
                                ':member_id' => $user['member_id']
                            ];
                            $stmt = $db->prepare($query);
                            $stmt->execute($params);
                        }
                    }
                } catch (GuzzleHttp\Exception\GuzzleException $e) {
                    //var_dump((string)$e);
                }
            }
        }
    }
}