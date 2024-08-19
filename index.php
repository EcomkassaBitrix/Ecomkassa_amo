<?php
    require_once (__DIR__.'/lib.php');

    $q=trim(substr($_SERVER['REQUEST_URI'],0,strpos($_SERVER['REQUEST_URI'],'?')),"\/"); // обрезать лишние слеши
    list($view,$act) = explode('/',$q); // превратить в 2 переменных
    if( $view != "pay" ){exit;}

    if( !$act ){
        echo(ShowErrorToPay( 'User not found | Пользователь не найден' ));
        exit;
    }
    if( !isset($_REQUEST['did']) ){
        echo(ShowErrorToPay( 'Invoice not found | Не передан ид счёта' ));
        exit;
    }
    $stmt = $db->prepare("SELECT * FROM users WHERE `member_id` = ?");
    $stmt->execute([$act]);
    //$stmt->exec("SET NAMES = utf8");
    $userData = $stmt->fetch(PDO::FETCH_LAZY);
    if( !$userData['id'] ){
        echo(ShowErrorToPay( 'User not found | Пользователь не найден' ));
        exit;
    }
    $login = $userData['ecomLogin'];
    $pass = $userData['ecomPass'];
    $payment_object = $userData['payment_object'];
    $kassaid = round( $userData['ecomKassaId'] );
    $tokenEcom = $userData['tokenEcomKassa'];

    $paymentMethodDef = $userData['payment_method'];
    $vat100 = $userData['vat100'];
    $vatValueShipment = $userData['vatShipment'];
    if( $vatValueShipment != 'none' ){
        $vatValueShipment = "vat".$vatValueShipment;
    }
    $vatValueOrder = $userData['vatOrder'];
    if( $vatValueOrder != 'none' && $vatValueOrder != null ){//none - без ндс
        $vatValueOrder = "vat".$vatValueOrder;
    }
    $companyArray = array(
        "email" => $userData['company_email'],
        "sno" => $userData['company_sno'],
        "inn" => $userData['company_inn'],
        "payment_address" => $userData['company_payment_address']
    );
    //---------------------------------------Авторизация AMO------------------------------------------------------------
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
            echo(ShowErrorToPay( 'User token invalid | Токен продавца не корректен' ));
            exit;
        }
    }
    $token = $accessToken->getToken();
    $idCatalogInv = -1;
    $idCatalogProduct = -1;
    $saleOrderGet = array();
    $saleItemsGet = array();
    $saleProductGet = array();
    //------------------------------------Поиск идов каталогов----------------------------------------------------------
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
            if( $value['type'] == 'products' ){
                $idCatalogProduct = $value['id'];
            }
        }
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
        SendAmoLog( (string)$e, 'errorPay-userID'.$userData['id'] );
        echo(ShowErrorToPay( 'Undefined error | Неизвестная ошибка' ));
        exit;
    }
    if( $idCatalogInv == -1 ){
        echo(ShowErrorToPay( 'Catalog id incorrect | Неверный ид каталога' ));
        exit;
    }
    //----------------------------------------------Содержимое инвойса--------------------------------------------------
    $successurl = "";
    try {
        $data = $provider->getHttpClient()
            ->request('GET', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogInv.'/elements/'.$_REQUEST['did'].'?with=invoice_link', [
                'headers' => $provider->getHeaders($accessToken)
            ]);
        $content = $data->getBody()->getContents();
        if( !$content ){
            echo(ShowErrorToPay( 'Invoice not found | Не найден счёт' ));
            exit;
        }
        $saleOrderGet = json_decode($content, true);
        if( isset($saleOrderGet['invoice_link']) && $saleOrderGet['invoice_link'] ){
            $successurl = $saleOrderGet['invoice_link'];
        }
    } catch (GuzzleHttp\Exception\GuzzleException $e) {
        //var_dump((string)$e);
        SendAmoLog( (string)$e, 'errorPay-userID'.$userData['id'] );
        echo(ShowErrorToPay( 'Undefined error | Неизвестная ошибка' ));
        exit;
    }
    //-------------------------------------------Содержимое списка товаров согласно инвойсу-----------------------------
    $arrayProductsId = "";
    $idProductInt = 0;

    foreach ($saleOrderGet['custom_fields_values'] as $value) {
        if( $value['field_type'] == 'items' ){
            $saleItemsGet = $value['values'];
            foreach ($value['values'] as $valueOrder) {
                $arrayProductsId = $arrayProductsId.'filter[id]['.$idProductInt.']='.$valueOrder['value']['product_id'].'&';
                $idProductInt++;
            }
        }
    }
    if( $arrayProductsId && $idCatalogProduct != -1 ){
        try {
            $data = $provider->getHttpClient()
                ->request('GET', $provider->urlAccount() . 'api/v4/catalogs/'.$idCatalogProduct.'/elements?'.$arrayProductsId, [
                    'headers' => $provider->getHeaders($accessToken)
                ]);
            $content = $data->getBody()->getContents();
            if( $content ){
                $saleProductGet = json_decode($content, true);
            }
        } catch (GuzzleHttp\Exception\GuzzleException $e) {
            //var_dump((string)$e);
            SendAmoLog( (string)$e, 'errorPay-userID'.$userData['id'] );
            echo(ShowErrorToPay( 'Undefined error | Неизвестная ошибка' ));
            exit;
        }
    }
    //-----------------------------------------------Обработка данных---------------------------------------------------
    $emailCheckDef = "";
    $billVatType = 'vat_included';
    foreach ($saleOrderGet['custom_fields_values'] as $value) {
        if( $value['field_type'] == 'payer' && $emailCheckDef == "" ){
            foreach ($value['values'] as $valueOf) {
                if( $valueOf['value']['entity_type'] == 'contacts' ){
                    if( isset($valueOf['value']['email']) && $valueOf['value']['email'] != '' ){
                        $emailCheckDef = $valueOf['value']['email'];
                        break;
                    }
                }
            }
        }
        if( $value['field_code'] == 'BILL_VAT_TYPE' ){
            $billVatType = $value['values'][0]['enum_code'];
        }
        if( $value['field_code'] == 'BILL_STATUS' ){
            if( $value['values'][0]['enum_code'] == "paid"){
                echo(ShowErrorToPay( 'Invoice is paid | Счёт уже оплачен' ));
                exit;
            }
        }
    }
    if (!filter_var($emailCheckDef, FILTER_VALIDATE_EMAIL)) {
        $emailCheckDef = $userData['emailDefCheck'];
    }
    if (!filter_var($emailCheckDef, FILTER_VALIDATE_EMAIL)) {
        $emailCheckDef = "";
    }
    //------------------------------------------------------------------------------------------------------------------
    $getEmail = 0;
    if( !$emailCheckDef ){
        $getEmail = 1;
    }
    $paySystemIdPay = -1;
    $paymentTypes = GetPaymentTypes( $tokenEcom, $kassaid );
    if( isset($paymentTypes->error->code) && $paymentTypes->error->code == 11 || isset($paymentTypes->code) && $paymentTypes->code == 4 ){
        $tokenEcom = GetToken( $login, $pass );
        if( $tokenEcom == -1 ){
            echo(ShowErrorToPay( 'EcomKassa error Auth | Неверный логин или пароль EcomKassa' ));
            exit;
        }
        $query = "UPDATE `users` SET `tokenEcomKassa` = :token WHERE `id` = :id";
        $params = [
            ':id' => $userData['id'],
            ':token' => $tokenEcom
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        $paymentTypes = GetPaymentTypes( $tokenEcom, $kassaid );
    }
    if( count( $paymentTypes ) > 1 && !isset($_REQUEST['pid']) || $getEmail && !isset($_REQUEST['email']) ){
        echo ShowGetDataToPay( $act, $_REQUEST['did'], $getEmail, $paymentTypes );
        exit;
    }
    if( count( $paymentTypes ) == 1 ){
        $paySystemIdPay = $paymentTypes[0]->id;
    }
    if( isset($_REQUEST['pid']) ){
        $paySystemIdPay = $_REQUEST['pid'];
    }

    if( isset($_REQUEST['email']) && strlen( $_REQUEST['email'] ) > 0 ){
        $emailCheckDef = urldecode( $_REQUEST['email']);
    }
    if (!filter_var($emailCheckDef, FILTER_VALIDATE_EMAIL)) {
        echo(ShowErrorToPay( 'Email incorrect | Неверный формат Email' ));
        exit;
    }
    //------------------------------------------------------------------------------------------------------------------
    $totalPaySum = 0;
    $arrayItems = array();
    if( $saleItemsGet ) {
        foreach ($saleItemsGet as $valueItemPay) {
            $paymentObject = $payment_object;
            $paymentMethod = $paymentMethodDef;
            foreach ($saleProductGet['_embedded']['elements'] as $valueProduct) {
                if( $valueProduct['id'] == $valueItemPay['value']['product_id'] ){
                    foreach ($valueProduct['custom_fields_values'] as $valueProductCustom) {
                        if( $valueProductCustom['field_name'] == 'Признак способа расчета' ){
                            if( $valueProductCustom['values'][0]['value'] == "предоплата 100% - полная предварительная оплата, которая осуществляется клиентом до получения товара/оказания услуги" ){ $paymentMethod = "full_prepayment"; }
                            if( $valueProductCustom['values'][0]['value'] == "предоплата - частичная предварительная оплата, которая осуществляется клиентом до получения товара/оказания услуги" ){ $paymentMethod = "prepayment"; }
                            if( $valueProductCustom['values'][0]['value'] == "аванс - предоплата в случаях, когда заранее нельзя определить перечень товаров/работ/услуг" ){ $paymentMethod = "advance"; }
                            if( $valueProductCustom['values'][0]['value'] == "полный расчет" ){ $paymentMethod = "full_payment"; }
                            if( $valueProductCustom['values'][0]['value'] == "частичный расчет и кредит" ){ $paymentMethod = "partial_payment"; }
                            if( $valueProductCustom['values'][0]['value'] == "передача в кредит" ){ $paymentMethod = "credit"; }
                            if( $valueProductCustom['values'][0]['value'] == "оплата кредита" ){ $paymentMethod = "credit_payment"; }
                        }
                        if( $valueProductCustom['field_name'] == 'Признак товара' ){
                            if( $valueProductCustom['values'][0]['value'] == "товар" ){ $paymentObject = "commodity"; }
                            if( $valueProductCustom['values'][0]['value'] == "подакцизный товар" ){ $paymentObject = "excise"; }
                            if( $valueProductCustom['values'][0]['value'] == "работа" ){ $paymentObject = "job"; }
                            if( $valueProductCustom['values'][0]['value'] == "услуга" ){ $paymentObject = "service"; }
                            if( $valueProductCustom['values'][0]['value'] == "ставка азартной игры" ){ $paymentObject = "gambling_bet"; }
                            if( $valueProductCustom['values'][0]['value'] == "выигрыш азартной игры" ){ $paymentObject = "gambling_prize"; }
                            if( $valueProductCustom['values'][0]['value'] == "лотерейный билет" ){ $paymentObject = "lottery"; }
                            if( $valueProductCustom['values'][0]['value'] == "выигрыш лотереи" ){ $paymentObject = "lottery_prize"; }
                            if( $valueProductCustom['values'][0]['value'] == "предоставление результатов интеллектуальной деятельности" ){ $paymentObject = "intellectual_activity"; }
                            if( $valueProductCustom['values'][0]['value'] == "платеж" ){ $paymentObject = "payment"; }
                            if( $valueProductCustom['values'][0]['value'] == "агентское вознаграждение" ){ $paymentObject = "agent_commission"; }
                            if( $valueProductCustom['values'][0]['value'] == "составной предмет расчета" ){ $paymentObject = "composite"; }
                            if( $valueProductCustom['values'][0]['value'] == "иной предмет расчета" ){ $paymentObject = "another"; }
                        }
                    }
                }
            }
            $valueVat = "none";//без ндс

            //Задаем скидку касаемо 1 единицы товара
            if( $valueItemPay['value']['quantity'] > 0 ) {
                $valueItemPay['value']['unit_price'] = $valueItemPay['value']['unit_price'] * 100 - ($valueItemPay['value']['discount']['value'] * 100 / $valueItemPay['value']['quantity']);
            } else {
                $valueItemPay['value']['unit_price'] = $valueItemPay['value']['unit_price'] * 100;
            }
            if( $billVatType == 'vat_not_included' ){//N - не включён
                $vatRate = $valueItemPay['value']['vat_rate_value'];
                $vatRate = $vatRate / 100;
                $valueItemPay['value']['unit_price'] = round( $valueItemPay['value']['unit_price'] * $vatRate + $valueItemPay['value']['unit_price'] );
                $valueItemPay['value']['unit_price'] = ceil($valueItemPay['value']['unit_price']) / 100;
            } else {
                $valueItemPay['value']['unit_price'] = ceil($valueItemPay['value']['unit_price']) / 100;
            }

            if( $billVatType != 'vat_exempt' ){
                //Налог включён
                //значение 0.1, 0.2, 0, null - без НДС (none без ндс ) ---> null то что передаёт amo
                $valueVat = "vat".$valueItemPay['value']['vat_rate_value'];
            }
            if( $vat100 == 1 && $valueItemPay['value']['vat_rate_value'] ){
                $valueVat = "vat".( 100 + $valueItemPay['value']['vat_rate_value'] );
            }
            if( $vatValueOrder != null ){
                $valueVat = $vatValueOrder;
            }
            $arrayObj = array(
                "name" => $valueItemPay['value']['description'],
                "price" => $valueItemPay['value']['unit_price'],
                "quantity" => $valueItemPay['value']['quantity'],
                "sum" => (ceil( ($valueItemPay['value']['quantity'] * $valueItemPay['value']['unit_price']) * 100 )) / 100,
                "measurement_unit" => $valueItemPay['value']['unit_type'],
                "payment_method" => $paymentMethod,
                "payment_object" => $paymentObject,
                "vat" => [
                    "type" => $valueVat
                ]
            );
            array_push($arrayItems, $arrayObj);
            $totalPaySum = $totalPaySum + (ceil( ($valueItemPay['value']['quantity'] * $valueItemPay['value']['unit_price']) * 100 )) / 100;
        }
    }
    //-------------------------------------------Перевыпуск просроченного токена--------------------------------------------
    $externalId = format_uuidv4(random_bytes(16));
    $secret = md5( rand(1,10000000) );
    if( $totalPaySum <= 0 ){
        echo(ShowErrorToPay( 'Summ invalid | Сумма оплаты не валидная обратитесь к магазину' ));
        exit;
    }
    $urlPay = GetPayUrl( $tokenEcom, $kassaid, $paySystemIdPay, $emailCheckDef, $totalPaySum, $arrayItems, $companyArray, $externalId, $secret, "https://".C_REST_MAIN_DOMAIN."/wait.php?did=".$_REQUEST['did']."&mem=".$userData['member_id'] );
    //----------------------------------------------------------------------------------------------------------------------
    if( isset( $urlPay->code )  ){
        echo(ShowErrorToPay( $urlPay->code.' '. $urlPay->text ));
        exit;
    }
    else if( !$urlPay->error == null ){
        echo(ShowErrorToPay( $urlPay->error->code.' '. $urlPay->error->text ));
        exit;
    }
    else {
        $permaLink = null;
        if( isset( $urlPay->permalink )){
            $permaLink = $urlPay->permalink;
        }
        //----------------------------------------------------------------------------------------------------------------------
        $query = "INSERT INTO `bills`(`member_id`, `external_id`, `url`, `secret`, `PAYMENT_ID`, `PAYSYSTEM_ID`, `RETURN_URL`, `permalink`) VALUES (:memberid,:externalid,:url,:secret,:PAYMENT_ID,:PAYSYSTEM_ID,:RETURN_URL,:permalink)";
        $params = [
            ':memberid' => $userData['member_id'],
            ':externalid' => $externalId,
            ':url' => $urlPay->invoice_payload->link,
            ':secret' => $secret,
            ':PAYMENT_ID' => $_REQUEST['did'],
            ':PAYSYSTEM_ID' => $paySystemIdPay,
            ':RETURN_URL' => $successurl,
            ':permalink' => $permaLink
        ];
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        //------------------------------------------------------------------------------------------------------------------
        if( isset( $urlPay->invoice_payload->link ) ){
            echo('<meta http-equiv="refresh" content="1; url='.$urlPay->invoice_payload->link.'">');
            exit;
        }
        else if( isset( $urlPay->permalink ) ){
            echo('<meta http-equiv="refresh" content="1; url='.$urlPay->permalink.'">');
            exit;
        }

    }
    echo(ShowErrorToPay( 'Undefined error | Неизвестная ошибка' ));
    exit;