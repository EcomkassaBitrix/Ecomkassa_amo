<?php
    require_once (__DIR__.'/lib.php');

    if( $_REQUEST['sendParam'] ){

        $updateParams = json_decode($_REQUEST['sendParam']);

        if($updateParams->ecomhash){
            $stmt = $db->prepare("SELECT * FROM users WHERE `member_id` = ? and `ecomhash` = ?");
            $stmt->execute([$updateParams->member_id, $updateParams->ecomhash]);
        } else {
            $stmt = $db->prepare("SELECT * FROM users WHERE `member_id` = ?");
            $stmt->execute([$updateParams->member_id]);
        }
        $userData = $stmt->fetch(PDO::FETCH_LAZY);
        if(!$userData['id']){exit;}

        if( $updateParams->typeAction == 'updateWebHook' ){
            $alertText = "Веб хук обновлен";
            $query = "UPDATE `users` SET `webHookUrl` = :webHookUrl WHERE `member_id` = :member_id";
            $params = [
                ':member_id' => $updateParams->member_id,
                ':webHookUrl' => $updateParams->webHookUrl
            ];
            $stmt = $db->prepare($query);
            $stmt->execute($params);
        }
        else if( $updateParams->typeAction == 'updateSettings' ){
            $alertText = "Настройки обновлены";
            if( !$updateParams->ecomLogin || !$updateParams->ecomPass || !$updateParams->ecomKassaId || !$updateParams->company_email || !$updateParams->company_sno || !$updateParams->vatShipment || (! isset($updateParams->vatOrder) && $updateParams->vatOrderCheck == 1 ) || !$updateParams->company_inn || !$updateParams->company_payment_address
                || !$updateParams->payment_method || !$updateParams->payment_object ){
                $alertText = "Не все поля настроек заполнены";
            } else {
                if( $updateParams->ecomPass && $updateParams->ecomPass != "***" ){
                    $pass = $updateParams->ecomPass;
                } else {
                    $pass = $userData['ecomPass'];
                }
                $token = GetToken( $updateParams->ecomLogin, $pass );
                if( $token == -1 ){
                    $alertText = "Неверный логин или пароль EcomKassa";
                }
                else{
                    $query = "UPDATE `users` SET `tokenEcomKassa` = :token WHERE `id` = :id";
                    $params = [
                        ':id' => $userData['id'],
                        ':token' => $token
                    ];
                    $stmt = $db->prepare($query);
                    $stmt->execute($params);

                    $vatOrder = null;
                    if( $updateParams->vatOrderCheck ){
                        $vatOrder = $updateParams->vatOrder;
                    }
                    $query = "UPDATE `users` SET `defPipeline` = :defPipeline, `defStatus` = :defStatus, `ecomLogin` = :ecomLogin, `ecomPass` = :ecomPass, `ecomKassaId` = :ecomKassaId, `emailDefCheck` = :emailDefCheck, `company_email` = :company_email, `company_sno` = :company_sno, `company_inn` = :company_inn, `company_payment_address` = :company_payment_address, `vatShipment` = :vatShipment, `vatOrder` = :vatOrder, `vat100` = :vat100, `payment_method` = :payment_method, `payment_object` = :payment_object WHERE `id` = :id";
                    $params = [
                        ':defPipeline' => $updateParams->defPipeline,
                        ':defStatus' => $updateParams->defStatus,
                        ':id' => $userData['id'],
                        ':ecomLogin' => $updateParams->ecomLogin,
                        ':ecomPass' => $pass,
                        ':ecomKassaId' => $updateParams->ecomKassaId,
                        ':emailDefCheck' => $updateParams->emailDefCheck,
                        //О компании
                        ':company_email' => $updateParams->company_email,
                        ':company_sno' => $updateParams->company_sno,
                        ':vatShipment' => $updateParams->vatShipment,
                        ':vatOrder' => $vatOrder,
                        ':company_inn' => $updateParams->company_inn,
                        ':company_payment_address' => $updateParams->company_payment_address,
                        ':vat100' => $updateParams->vat100,
                        ':payment_method' => $updateParams->payment_method,
                        ':payment_object' => $updateParams->payment_object
                    ];
                    $stmt = $db->prepare($query);
                    $stmt->execute($params);
                }
            }
        }
        $test = array('alertText' => $alertText);
        echo json_encode($test);
        exit;
    }


    $stmt = $db->prepare("SELECT * FROM users WHERE (`ecomhash` = ? and `referer` = ? and `install` = ?) or (`account_id` = ? and `referer` = ? and `install` = ?)");
    $stmt->execute([$_REQUEST['ecomhash'], $_REQUEST['domain'], 1, $_REQUEST['amouser_id'], $_REQUEST['domain'], 1]);
    $userData = $stmt->fetch(PDO::FETCH_LAZY);
    if(!$userData['id']){
        $stmt = $db->prepare("SELECT * FROM users WHERE `account_id` = ? and `referer` = ? and `install` = ?");
        $stmt->execute([$_REQUEST['amouser_id'], $_REQUEST['domain'], 0]);
        $userData = $stmt->fetch(PDO::FETCH_LAZY);
        if(!$userData['id']){exit;}

        $query = "UPDATE `users` SET `install` = :install, `ecomhash` = :ecomhash WHERE `member_id` = :member_id";
        $params = [
            ':member_id' => $userData['member_id'],
            ':install' => 1,
            ':ecomhash' => $_REQUEST['ecomhash']
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);

        $stmt = $db->prepare("SELECT * FROM users WHERE `member_id` = ?");
        $stmt->execute([$userData['member_id']]);
        $userData = $stmt->fetch(PDO::FETCH_LAZY);
        if(!$userData['id']){exit;}
    }
    if( strlen( $userData['ecomPass'] ) > 0 ){$pass = "***";}else{$pass = "";}
    $vatOrderCheck = 0;
    if( $userData['vatOrder'] != null ){
        $vatOrderCheck = 1;
    }
    //------------------------------------------------------------------------------------------------------------------
    use AmoCRM\OAuth2\Client\Provider\AmoCRM;
    include_once '../vendor/autoload.php';
    include_once '../src/AmoCRM.php';
    session_start();
    $provider = new AmoCRM([
        'clientId' => C_REST_CLIENT_ID,
        'clientSecret' => C_REST_CLIENT_SECRET,
        'redirectUri' => C_REST_REDIRECT_URI,
    ]);
    if (isset($_GET['referer'])) {
        $provider->setBaseDomain($_GET['referer']);
    }
    $accessToken = amoGetToken( $userData['member_id'] );
    $provider->setBaseDomain($accessToken->getValues()['baseDomain']);

    if ($accessToken->hasExpired()) {
        try {
            $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\RefreshToken(), [
                'refresh_token' => $accessToken->getRefreshToken(),
            ]);
            amoSaveToken( $userData['member_id'], $userData['account_id'] , [
                'accessToken' => $accessToken->getToken(),
                'refreshToken' => $accessToken->getRefreshToken(),
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
        } catch (Exception $e) {
            //die((string)$e);
            exit;
        }
    }
    $token = $accessToken->getToken();
    //------------------------------------------------------------------------------------------------------------------
    $pipelines = array();
    try {
        $data = $provider->getHttpClient()
            ->request('GET', $provider->urlAccount() . 'api/v4/leads/pipelines', [
                'headers' => $provider->getHeaders($accessToken)
            ]);
        $content = $data->getBody()->getContents();
        if( $content ){
            $content = json_decode($content, true);
            foreach ($content['_embedded']['pipelines'] as $pipeline) {
                $statuses = array();
                foreach ($pipeline['_embedded']['statuses'] as $status) {
                    array_push($statuses, array('id' => $status['id'],'name' => $status['name']));
                }
                array_push($pipelines, array('id' => $pipeline['id'],'name' => $pipeline['name'], 'statuses' => $statuses));
            }
        }
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
        exit;
    }
    //------------------------------------------------------------------------------------------------------------------
    $test = array('pipelines' => $pipelines,
        'sendParam' =>
        array(
            "ecomLogin" => htmlspecialchars($userData['ecomLogin'], ENT_QUOTES, 'UTF-8'),
            "ecomPass" => $pass,
            "ecomKassaId" => htmlspecialchars($userData['ecomKassaId'], ENT_QUOTES, 'UTF-8'),
            "emailDefCheck" => htmlspecialchars($userData['emailDefCheck'], ENT_QUOTES, 'UTF-8'),
            "payment_method" => htmlspecialchars($userData['payment_method'], ENT_QUOTES, 'UTF-8'),
            "payment_object" => htmlspecialchars($userData['payment_object'], ENT_QUOTES, 'UTF-8'),
            "vatOrderCheck" => $vatOrderCheck,
            "vatOrder" => htmlspecialchars($userData['vatOrder'], ENT_QUOTES, 'UTF-8'),
            "defStatus" => htmlspecialchars($userData['defStatus'], ENT_QUOTES, 'UTF-8'),
            "defPipeline" => htmlspecialchars($userData['defPipeline'], ENT_QUOTES, 'UTF-8'),
            "vatShipment" => htmlspecialchars($userData['vatShipment'], ENT_QUOTES, 'UTF-8'),
            "vat100" => (int)$userData['vat100'],
            "company_email" => htmlspecialchars($userData['company_email'], ENT_QUOTES, 'UTF-8'),
            "company_sno" => htmlspecialchars($userData['company_sno'], ENT_QUOTES, 'UTF-8'),
            "company_inn" => htmlspecialchars($userData['company_inn'], ENT_QUOTES, 'UTF-8'),
            "company_payment_address" => html_entity_decode($userData['company_payment_address'], ENT_QUOTES, 'UTF-8'),
            "typeAction" => htmlspecialchars($userData['typeAction'], ENT_QUOTES, 'UTF-8'),
            "ecomhash" => htmlspecialchars($userData['ecomhash'], ENT_QUOTES, 'UTF-8'),
            "member_id" => htmlspecialchars($userData['member_id'], ENT_QUOTES, 'UTF-8'),
            "webHookUrl" => html_entity_decode($userData['webHookUrl'], ENT_QUOTES, 'UTF-8'),
            "makePayUrl" => "https://".C_REST_MAIN_DOMAIN."/pay/".$userData['member_id']."?did=:invoice_id"
        )
    );
    echo json_encode($test);