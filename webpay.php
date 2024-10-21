<?php
    $typePage = 1;//( 1 выбор способа, 0 ошибка, 2 email ), 3 неверный email

    $errorContext = "";
    if( isset($_REQUEST['emailvalue']) && !filter_var($_REQUEST['emailvalue'], FILTER_VALIDATE_EMAIL) ){
        $typePage = 3;
        $errorContext = 'Email incorrect | Неверный формат Email';
    }
    else if($_REQUEST['error']){
        $typePage = 0;
        $errorContext = urldecode($_REQUEST['error']);
    }
    else if( isset($_REQUEST['email']) && !isset($_REQUEST['emailvalue']) && $_REQUEST['email'] == 1){
        $typePage = 2;
    }
    $paysystem = array();
    if( $_REQUEST['paysystem'] ){
        $paysystem = json_decode(urldecode($_REQUEST['paysystem']),true);
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
        function SelectEmail() {
            window.location.href = window.location.href + '&emailvalue=' + document.getElementById('email').value;
        }
        function SelectToPay( paySystemId ) {
            let urlParams = '<?php echo($_REQUEST['accid'].'?did='.$_REQUEST['did'].'&email='.urlencode($_REQUEST['emailvalue']));?>';
            window.location.href = '/pay/'+urlParams + '&pid=' + paySystemId;
        }
        <?php
            if(count($paysystem) == 1 && $typePage != 2 && $typePage != 3){
                echo("SelectToPay( ".$paysystem[0]['id']." );");
            }
        ?>
    </script>
</head>
<body style="background-color: grey">
    <div class="h-100 d-flex align-items-center justify-content-center">
        <div>
            <div class="alert alert-danger" role="alert" <?php echo( (($typePage == 0 || $typePage == 3) ? "" : "hidden") ); ?> >
                <?php echo( (($typePage == 0 || $typePage == 3 ) ? $errorContext : "") ); ?>
            </div>
            <div <?php echo( (($typePage == 1) ? "" : "hidden") ); ?>>
                <div class="alert alert-secondary" role="alert" >
                    Выберите способ оплаты
                </div>
                <?php
                foreach ($paysystem as $value) {
                    echo('
                        <div class="alert alert-light hoverClass" role="alert" style="background-color: grey;" onclick="SelectToPay('.$value['id'].')">
                            <img src="/img/'.$value['id'].'.png" style="width: 300px;" class="card-img-top" alt="...">
                        </div>
                    ');
                }
                ?>
            </div>
            <div class="alert alert-secondary" style="width: 350px;" role="alert" <?php echo( (($typePage == 2 || $typePage == 3) ? "" : "hidden") ); ?> >
                <div class="mb-3">
                    <label for="email" class="form-label">Введите Email (для чека)</label>
                    <input type="email" class="form-control" id="email" aria-describedby="emailHelp">
                </div>
                <button type="submit" class="btn btn-primary" onclick="SelectEmail()">Продолжить</button>
            </div>

        </div>
    </div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
</body>
</html>
