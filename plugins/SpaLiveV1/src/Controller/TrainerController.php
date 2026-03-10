<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException; 
require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;
use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;
use App\Command\RemindersCommand;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use \PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TrainerController extends AppPluginController {
     
    private $total = 3900;
    private $paymente_gfe = 1800;
    private $register_total = 79500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 3000;
    private $shipping_cost_inj = 2000;
    private $shipping_cost_mat = 1000;
    private $shipping_cost_misc = 1000;
    private $training_advanced = 89500;
    private $emergencyPhone = "9035301512";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";

    public function initialize() : void {
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.myspalive.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.myspalive.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.CatProducts');
        
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));   
        
        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){
                $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost', 'CatStates.cost_gfe', 'CatStates.payment_gfe','CatStates.phone_number'])->where(['CatStates.id' => $user['user_state']])->first();
                if(!empty($state)){
                    $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                    $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                    $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                    
                    $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                    $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                    $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;

                    $this->total = $state->cost_gfe > 0 ? $state->cost_gfe : $this->total;
                    $this->paymente_gfe = $state->payment_gfe > 0 ? $state->payment_gfe : $this->paymente_gfe;
                    $this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
                    $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                    $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
                }
            }

            $ver = get('version', '');
            $ver = str_replace('version ', '', $ver);
        } else {
            // TEXAS
            $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost', 'CatStates.cost_gfe', 'CatStates.payment_gfe'])->where(['CatStates.id' => 43])->first();
            if(!empty($state)){
                $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                
                $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;

                $this->total = $state->cost_gfe > 0 ? $state->cost_gfe : $this->total;
                $this->paymente_gfe = $state->payment_gfe > 0 ? $state->payment_gfe : $this->paymente_gfe;
                $this->emergencyPhone = $state->phone_number != '' ? $state->phone_number : $this->emergencyPhone;
                $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
            }
        }
        $product = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 44])->first();
        if(!empty($product)){
            $this->training_advanced = $product->unit_price > 0 ? $product->unit_price : $this->training_advanced;
        }
    }

    public function test_n() {
        //$last_day_month = date('Y-m-t');
        $last_day_month = date('Y-m-t', strtotime('2022-02-01'));
        //$now = date('Y-m-d');
        $now = date('Y-m-d', strtotime('2022-02-28'));
        $last_payment = date('Y-m-d', strtotime('2022-01-31'));
        $last_day_month_ago = date('Y-m-t', strtotime($last_payment));
        
        if($now == $last_day_month){
            if($last_payment == $last_day_month_ago){
                pr("Cobrale hoy");
                exit;
            }
        }
        $date = date('Y-m-d', strtotime('2022-01-31'.'+ 1 month'));
        pr($date);
        exit;
    }

    public function get_request_trainer() {
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        if(USER_TYPE == 'patient' || USER_TYPE == 'examiner'){
            $this->message('Invalid user type.');
            $this->set('show_trainer', false);
            return;
        }

        $this->loadModel('SpaLiveV1.DataTrainers');

        $ent_trainer = $this->DataTrainers->find()->where(['DataTrainers.injector_id' => USER_ID, 'DataTrainers.deleted' => 0])->first();

        if(empty($ent_trainer)){
            $this->set('show_trainer', true);
            $this->success();
        }else{
            $this->set('show_trainer', false);
            $this->success();
        }
    }

    public function request_trainer(){
        $token = get('token',"");

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $experience = get('experience', '');
        $courses    = get('courses', '');   
        
        if(USER_TYPE == 'patient' || USER_TYPE == 'examiner'){
            $this->message('Invalid user type.');
            return;
        }

        if(empty($experience)){
            $this->message('Experience is required.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTrainers');

        // $ent_trainer = $this->DataTrainers->find()->where(['DataTrainers.injector_id' => USER_ID, 'DataTrainers.deleted' => 0])->first();

        $array_save = array(
            'uid' => Text::uuid(),
            'injector_id' => USER_ID,
            'training_id' => 0,
            'status' => 'PENDING',
            'experience' => $experience,
            'courses' => $courses, 
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0
        );

        $c_entity = $this->DataTrainers->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataTrainers->save($c_entity);
            $this->set('uid', $c_entity->uid);
            $this->success();
        }else{
            $this->success(false);
        }
    }

    public function upload_trainer_image(){
        $this->loadModel('SpaLiveV1.DataTrainers');
        $this->loadModel('SpaLiveV1.DataTrainersImage');
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return;
        }

        $trainer = $this->DataTrainers->find()->where(['DataTrainers.uid' => get('uid', '')])->first();

        if(empty($trainer)){
            $this->message('Invalid trainer.');
            return;
        }

        if (!isset($_FILES['file'])) {
            $this->set('error_file',$_FILES);
            return;
        }

        if (!isset($_FILES['file']['name'])) {
            $this->set('error_name',$_FILES['file']);
            return;
        }

        $str_name = $_FILES['file']['name'];
        $_file_id = $this->Files->upload([
            'name' => $str_name,
            'type' => $_FILES['file']['type'],
            'path' => $_FILES['file']['tmp_name'],
            'size' => $_FILES['file']['size'],
        ]);

        if($_file_id <= 0){
            $this->message('Error in save content file.');
            return;
        }
        
        $description = get('description', '');

        $arrSave = [
            'trainer_id' => $trainer->id,
            'file_id' => $_file_id,
            'description' => $description,
        ];

        $tr_entity = $this->DataTrainersImage->newEntity($arrSave);
        if(!$tr_entity->hasErrors()) {
            if ($this->DataTrainersImage->save($tr_entity)) {
                $this->set('image_id', $_file_id);
                $this->success();
            }
        }else{
            $this->message('Error in save file to treatment.');
        }
    }


    public function validate_training(){
        $key = get('code', '');
        
        if(empty($key)){
            $this->message('Empty key.');
            return;
        }
        
        $rec = get('rec', '');
        if(empty($rec)){
            $this->message('Empty rec.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataValidateTraining');

        $ent_validate = $this->DataValidateTraining->find()->where(['DataValidateTraining.key1' => $key, 'DataValidateTraining.active' => 1])->first();


        if(empty($ent_validate)){
            $this->message('Invalid key.');

            echo '
                <!doctype html>
                <html>
                    <head>
                    <meta name="viewport" content="width=device-width">
                    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                    <title>MySpaLive Message</title>
                    <style>
                    .box-succes {
                        display: block;
                        margin-left: auto;
                        margin-right: auto;
                        width: 50%;
                    }

                    .logo {
                        margin-right: 70%;
                        width: 164px;
                    }

                    .box{
                    margin-top:60px;
                    display:flex;
                    justify-content:space-around;
                    flex-wrap:wrap;
                    }

                    .alert{
                    margin-top:25px;
                    background-color:#fff;
                    font-size:25px;
                    font-family:sans-serif;
                    text-align:center;
                    width:300px;
                    height:100px;
                    padding-top: 150px;
                    position:relative;
                    }

                    .alert::before{
                    width:100px;
                    height:100px;
                    position:absolute;
                    border-radius: 100%;
                    inset: 20px 0px 0px 100px;
                    font-size: 60px;
                    line-height: 100px;
                    border : 5px solid gray;
                    animation-name: reveal;
                    animation-duration: 1.5s;
                    animation-timing-function: ease-in-out;
                    }

                    .alert>.alert-body{
                    opacity:0;
                    animation-name: reveal-message;
                    animation-duration:1s;
                    animation-timing-function: ease-out;
                    animation-delay:1.5s;
                    animation-fill-mode:forwards;
                    }

                    @keyframes reveal-message{
                    from{
                        opacity:0;
                    }
                    to{
                        opacity:1;
                    }
                    }

                    .success{
                    color:#58D68D;
                    }

                    .info{
                    color: #EB984E;
                    }

                    .info::before{
                    content: "!";
                    border : 5px solid #EB984E;
                    }

                    .error{
                    color: #E74C3C;
                    }


                    @keyframes reveal {
                    0%{
                        border: 5px solid transparent;
                        color: transparent;
                        box-shadow: 0px 0px 12px 7px rgba(255,250,250,0.8) inset;
                        transform: rotate(1000deg);
                    }
                    25% {
                        border-top:5px solid gray;
                        color: transparent;
                        box-shadow: 0px 0px 17px 10px rgba(255,250,250,0.8) inset;
                        }
                    50%{
                        border-right: 5px solid gray;
                        border-left : 5px solid gray;
                        color:transparent;
                        box-shadow: 0px 0px 17px 10px rgba(200,200,200,0.8) inset;
                    }
                    75% {
                        border-bottom: 5px solid gray;
                        color:gray;
                        box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                        }
                    100%{
                        border: 5px solid gray;
                        box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                    }
                    }
                    @media only screen and (max-width: 620px) {
                        table[class=body] h1 {
                        font-size: 28px !important;
                        margin-bottom: 10px !important;
                        }
                        table[class=body] p,
                            table[class=body] ul,
                            table[class=body] ol,
                            table[class=body] td,
                            table[class=body] span,
                            table[class=body] a {
                        font-size: 16px !important;
                        }
                        table[class=body] .wrapper,
                            table[class=body] .article {
                        padding: 10px !important;
                        }
                        table[class=body] .content {
                        padding: 0 !important;
                        }
                        table[class=body] .container {
                        padding: 0 !important;
                        width: 100% !important;
                        }
                        table[class=body] .main {
                        border-left-width: 0 !important;
                        border-radius: 0 !important;
                        border-right-width: 0 !important;
                        }
                        table[class=body] .btn table {
                        width: 100% !important;
                        }
                        table[class=body] .btn a {
                        width: 100% !important;
                        }
                        table[class=body] .img-responsive {
                        height: auto !important;
                        max-width: 100% !important;
                        width: auto !important;
                        }
                    }

                    /* -------------------------------------
                        PRESERVE THESE STYLES IN THE HEAD
                    ------------------------------------- */
                    @media all {
                        .ExternalClass {
                        width: 100%;
                        }
                        .ExternalClass,
                            .ExternalClass p,
                            .ExternalClass span,
                            .ExternalClass font,
                            .ExternalClass td,
                            .ExternalClass div {
                        line-height: 100%;
                        }
                        .apple-link a {
                        color: inherit !important;
                        font-family: inherit !important;
                        font-size: inherit !important;
                        font-weight: inherit !important;
                        line-height: inherit !important;
                        text-decoration: none !important;
                        }
                        #MessageViewBody a {
                        color: inherit;
                        text-decoration: none;
                        font-size: inherit;
                        font-family: inherit;
                        font-weight: inherit;
                        line-height: inherit;
                        }
                        .btn-primary table td:hover {
                        background-color: #34495e !important;
                        }
                        .btn-primary a:hover {
                        background-color: #34495e !important;
                        border-color: #34495e !important;
                        }
                    }
                    </style>
                    </head>
                    <body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
                    <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span>
                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                        <tr>
                        <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                        <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                            <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">                                
                            <br><br>
                            <!-- START CENTERED WHITE CONTAINER -->
                            <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
                                <!-- START MAIN CONTENT AREA -->
                                <tr>
                                <td class="wrapper" style="font-family: sans-serif; font-size: 14px; text-align: center; box-sizing: border-box; padding: 20px;">
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                    <tr>
                                        <div style="padding-top: 2vw;">
                                            <center>
                                                <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                                            </center>
                                        </div>

                                        <div class="info alert box-succes">
                                            <div class="alert-body">
                                                <span style="font-weight: bold; color: black !important; font-size: 16px;">Important: </span><span style="color: black !important; font-size: 16px;">You have already used this link</span>
                                            </div>
                                        </div>
                                    </tr>
                                    <br><br><br>
                                    </table>
                                </td>
                                </tr>

                            <!-- END MAIN CONTENT AREA -->
                            <br><br>
                            </table>

                            <!-- START FOOTER -->
                            <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                <tr>
                                    <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                    <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a  style="color: #1D6782 !important;font-weight: bold !important;" href="https://blog.myspalive.com/">MySpaLive</a></span>
                                    </td>
                                </table>
                            </div>
                            <!-- END FOOTER -->

                            <!-- END CENTERED WHITE CONTAINER -->
                            </div>
                        </td>
                        <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                        </tr>
                    </table>
                    </body>
                </html>
            ';
            exit;
        }

        $this->loadModel('SpaLiveV1.DataParticipantTrainers');
        // pr($ent_validate);exit;
        $this->DataParticipantTrainers->updateAll(
            ['status' => $rec],
            ['DataParticipantTrainers.user_id' => $ent_validate->user_id, 'DataParticipantTrainers.deleted' => 0, 'DataParticipantTrainers.training_id' => $ent_validate->training_id]
        );

        $this->DataValidateTraining->updateAll(
            ['active' => 0],
            ['id' => $ent_validate->id]
        );

        if ($rec == 'ACCEPTED'){
            $validation = '
                <div class="success alert box-succes">
                    <div class="alert-body">
                        Congratulations, you have confirmed!
                    </div>
                </div>
            ';
        } else if ($rec == 'REJECTED'){
            $validation = '
                <div class="error alert box-succes">
                    <div class="alert-body">
                        Cancellation Confirmed
                    </div>
                </div>
            ';
        }

        $html = ' 
            <!doctype html>
            <html>
                <head>
                <meta name="viewport" content="width=device-width">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <title>MySpaLive Message</title>
                <style>
                .box-succes {
                    display: block;
                    margin-left: auto;
                    margin-right: auto;
                    width: 50%;
                }

                .logo {
                    margin-right: 70%;
                    width: 164px;
                }

                .box{
                margin-top:60px;
                display:flex;
                justify-content:space-around;
                flex-wrap:wrap;
                }

                .alert{
                margin-top:25px;
                background-color:#fff;
                font-size:25px;
                font-family:sans-serif;
                text-align:center;
                width:300px;
                height:100px;
                padding-top: 150px;
                position:relative;
                }

                .alert::before{
                width:100px;
                height:100px;
                position:absolute;
                border-radius: 100%;
                inset: 20px 0px 0px 100px;
                font-size: 60px;
                line-height: 100px;
                border : 5px solid gray;
                animation-name: reveal;
                animation-duration: 1.5s;
                animation-timing-function: ease-in-out;
                }

                .alert>.alert-body{
                opacity:0;
                animation-name: reveal-message;
                animation-duration:1s;
                animation-timing-function: ease-out;
                animation-delay:1.5s;
                animation-fill-mode:forwards;
                }

                @keyframes reveal-message{
                from{
                    opacity:0;
                }
                to{
                    opacity:1;
                }
                }

                .success{
                color:#58D68D;
                }

                .success::before{
                    content: "✓";
                border : 5px solid #58D68D;
                }

                .error{
                color: #E74C3C;
                }

                .error::before{
                content: "✗";
                border : 5px solid #E74C3C;
                }

                @keyframes reveal {
                0%{
                    border: 5px solid transparent;
                    color: transparent;
                    box-shadow: 0px 0px 12px 7px rgba(255,250,250,0.8) inset;
                    transform: rotate(1000deg);
                }
                25% {
                    border-top:5px solid gray;
                    color: transparent;
                    box-shadow: 0px 0px 17px 10px rgba(255,250,250,0.8) inset;
                    }
                50%{
                    border-right: 5px solid gray;
                    border-left : 5px solid gray;
                    color:transparent;
                    box-shadow: 0px 0px 17px 10px rgba(200,200,200,0.8) inset;
                }
                75% {
                    border-bottom: 5px solid gray;
                    color:gray;
                    box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                    }
                100%{
                    border: 5px solid gray;
                    box-shadow: 0px 0px 12px 7px rgba(200,200,200,0.8) inset;
                }
                }
                @media only screen and (max-width: 620px) {
                    table[class=body] h1 {
                    font-size: 28px !important;
                    margin-bottom: 10px !important;
                    }
                    table[class=body] p,
                        table[class=body] ul,
                        table[class=body] ol,
                        table[class=body] td,
                        table[class=body] span,
                        table[class=body] a {
                    font-size: 16px !important;
                    }
                    table[class=body] .wrapper,
                        table[class=body] .article {
                    padding: 10px !important;
                    }
                    table[class=body] .content {
                    padding: 0 !important;
                    }
                    table[class=body] .container {
                    padding: 0 !important;
                    width: 100% !important;
                    }
                    table[class=body] .main {
                    border-left-width: 0 !important;
                    border-radius: 0 !important;
                    border-right-width: 0 !important;
                    }
                    table[class=body] .btn table {
                    width: 100% !important;
                    }
                    table[class=body] .btn a {
                    width: 100% !important;
                    }
                    table[class=body] .img-responsive {
                    height: auto !important;
                    max-width: 100% !important;
                    width: auto !important;
                    }
                }

                /* -------------------------------------
                    PRESERVE THESE STYLES IN THE HEAD
                ------------------------------------- */
                @media all {
                    .ExternalClass {
                    width: 100%;
                    }
                    .ExternalClass,
                        .ExternalClass p,
                        .ExternalClass span,
                        .ExternalClass font,
                        .ExternalClass td,
                        .ExternalClass div {
                    line-height: 100%;
                    }
                    .apple-link a {
                    color: inherit !important;
                    font-family: inherit !important;
                    font-size: inherit !important;
                    font-weight: inherit !important;
                    line-height: inherit !important;
                    text-decoration: none !important;
                    }
                    #MessageViewBody a {
                    color: inherit;
                    text-decoration: none;
                    font-size: inherit;
                    font-family: inherit;
                    font-weight: inherit;
                    line-height: inherit;
                    }
                    .btn-primary table td:hover {
                    background-color: #34495e !important;
                    }
                    .btn-primary a:hover {
                    background-color: #34495e !important;
                    border-color: #34495e !important;
                    }
                }
                </style>
                </head>
                <body class="" style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
                <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive Message.</span>
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                    <tr>
                    <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                    <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                        <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">                                
                        <br><br>
                        <!-- START CENTERED WHITE CONTAINER -->
                        <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 30px;">
                            <!-- START MAIN CONTENT AREA -->
                            <tr>
                            <td class="wrapper" style="font-family: sans-serif; font-size: 14px; text-align: center; box-sizing: border-box; padding: 20px;">
                                <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                <tr>
                                    <div style="padding-top: 2vw;">
                                        <center>
                                            <img src="https://panel.myspalive.com/img/MySpaLive-logo-email.png" width="200px"/>
                                        </center>
                                    </div>
                                    '.$validation.'
                                </tr>
                                <br><br><br>
                                </table>
                            </td>
                            </tr>

                        <!-- END MAIN CONTENT AREA -->
                        <br><br>
                        </table>

                        <!-- START FOOTER -->
                        <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                            <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                            <tr>
                                <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a  style="color: #1D6782 !important;font-weight: bold !important;" href="https://blog.myspalive.com/">MySpaLive</a></span>
                                </td>
                            </table>
                        </div>
                        <!-- END FOOTER -->

                        <!-- END CENTERED WHITE CONTAINER -->
                        </div>
                    </td>
                    <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                    </tr>
                </table>
                </body>
            </html>';
        echo $html;exit;
    }

    public function delete_trainee_24_after_course_passed(){        
        $this->loadModel('SpaLiveV1.DataTrainings');
        //datatrainings 
        $now = date('Y-m-d H:i:s');        
        $yesterday = date('Y-m-d', strtotime($now . ' -1 day'));
        
        $trainees = $this->DataTrainings->find()        
        ->join([
        'Courses' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Courses.id = DataTrainings.training_id'],
        ])
        ->where(['DataTrainings.attended' => 0 ,'DataTrainings.deleted' => 0 , '(DATE_FORMAT(Courses.scheduled, "%Y-%m-%d") < "' . $yesterday . '")'])->all();

        foreach($trainees as $trainee){
            //update DataTrainings delete = 0            
            $this->DataTrainings->updateAll(['deleted' => 1, 'deleted_by'=>1,'deleted_date'=> $now], ['id' => $trainee->id]);            
        }
    }

}