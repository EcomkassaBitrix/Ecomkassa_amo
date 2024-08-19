<?php
    require_once (__DIR__.'/lib.php');
    $stmt = $db->prepare("SELECT * FROM bills WHERE `PAYMENT_ID` = ? and `member_id` = ?");
    $stmt->execute([$_REQUEST['did'],$_REQUEST['mem']]);
    $bill = $stmt->fetch(PDO::FETCH_LAZY);
    if( !$bill['id'] )
    {
        echo(ShowErrorToPay( 'Invoice not found | Не найден счёт' ));
        exit;
    }
    $stmt = $db->prepare("SELECT * FROM bills WHERE `PAYMENT_ID` = ? and `member_id` = ? and `status` = ?");
    $stmt->execute([$_REQUEST['did'],$_REQUEST['mem'], 'paid']);
    $bill = $stmt->fetch(PDO::FETCH_LAZY);
    if( $bill['id'] ){
        echo ('<meta http-equiv="refresh" content="1; url='.$bill['RETURN_URL'].'">');
        exit;
    }
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>EcomKassa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-9ndCyUaIbzAi2FUVXJi0CjmCapSmO7SnpJef0486qhLnuZ2cdeRhO02iuK6FUUVM" crossorigin="anonymous">
    <style>
        html,
        body {
            height: 100%
        }
        .hoverClass{
            cursor: pointer;
        }
        .hoverClass:hover,
        .hoverClass:focus,
        .hoverClass:active {
            opacity: 0.7;
        }
    </style>
    <script type="application/javascript">
        setTimeout(function(){
            window.location.reload(1);
        }, 5000);
    </script>
</head>
<body style="background-color: grey">
<div class="h-100 d-flex align-items-center justify-content-center">
    <div>
        <div>
            <div class="alert alert-secondary" role="alert" >
                Wait for approve payment<br>Ожидание подтверждения платежа ( 1-2мин)<br><center><img src="/img/wait.gif"></center>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
</body>
</html>