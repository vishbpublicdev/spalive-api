<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
use PHPUnit\Framework\Constraint\Count;
use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\CourseController;
use SpaLiveV1\Controller\TherapyController;
use SpaLiveV1\Controller\FillersController;
use SpaLiveV1\Controller\SummaryController;

use SpaLiveV1\Controller\Data\ServicesHelper;
use Twilio\Rest\Chat\V2\Service\UserList;

class LoginController extends AppPluginController{

    private $register_total = 89500;
    private $training_advanced = 89500;
    private $level_3_fillers = 150000;//level 3 fillers 
    private $level_3_medical = 99500;//level 3 medical
    private $level_1_1 = 19900;//level
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $total_subscriptionmdBoth = 26400;
    private $total_subscriptionmslBoth = 2995;

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

    public function initialize() : void{
        parent::initialize();
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
                $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
            }
        }
        $product = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 44])->first();
        if(!empty($product)){
            $this->training_advanced = $product->unit_price > 0 ? $product->unit_price : $this->training_advanced;
        }

        $product_advanced_techniques = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 178])->first();
        if(!empty($product_advanced_techniques)){
            $this->level_3_fillers = $product_advanced_techniques->unit_price > 0 ? $product_advanced_techniques->unit_price : $this->level_3_fillers;
        }
    }

    public function create_code_verification(){
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

        $method = get('method','');

        if($method == ''){
            $this->message('Invalid method.');
            $this->set('session', false);
            return;
        }
        $now = date('Y-m-d H:i:s');
        $expiration = date('Y-m-d H:i:s', strtotime($now."+ 30 minute"));
        $code_conf = rand(1000, 9999);
        $this->loadModel('SpaLiveV1.DataCodeConfirm');

        $this->DataCodeConfirm->updateAll(
            ['deleted' => 1],
            ['user_id' => USER_ID]
        );

        $array_save = array(
            'user_id' => USER_ID,
            'code' => $code_conf,
            'method' => $method,
            'status' => 'NOTCONFIRMED',
            'expiration' => $expiration,
            'created' => $now,
            'deleted' => 0,
        );

        $c_entity = $this->DataCodeConfirm->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataCodeConfirm->save($c_entity); 
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        
        if (!empty($user)){
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => 0,
                'course' => 'Partial Registration',
            );

            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $Ghl = new GhlController();
                $Ghl->updateOpportunity($array_data);
            }
        }    

        if($method == 'EMAIL'){
            $body = '
            <!doctype html>
            <html>
            
            <head>
                <meta name="viewport" content="width=device-width">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <title>MySpaLive Message</title>
                <style>
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
            
            <body class=""
                style="background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
                <span class="preheader"
                    style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">MySpaLive
                    Message.</span>
                <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body"
                    style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                    <tr>
                        <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                        <td class="container"
                            style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                            <div class="content"
                                style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
                                <table role="presentation" class="main"
                                    style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
                                    <tr>
                                        <td class="wrapper"
                                            style="font-family: sans-serif; font-size: 14px; box-sizing: border-box; padding: 20px;">
                                            <table role="presentation" border="0" cellpadding="0" cellspacing="0"
                                                style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                                <br>
                                                <tr>
                                                    <div style="color: #655489; font-size: 40px; text-align: center;"><b>Your
                                                            verification code is:</b></div><br>
                                                    <div style="text-align: center; color:#666666; font-size: 30px;">
                                                         ' . $code_conf . '
                                                    </div><br><br><br><br>
                                                </tr>
                                            </table> <br><br>
                                        </td>
                                    </tr>
                                </table>
                                <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0"
                                        style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                        <tr>
                                            <td class="content-block"
                                                style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                                <span class="apple-link"
                                                    style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a
                                                        href="https://blog.myspalive.com/">MySpaLive</a></span> </td>
                                    </table>
                                </div>
                            </div>
                        </td>
                        <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                    </tr>
                </table>
            </body>
            
            </html>    
            ';
            
            $data = array(
                'from'    => 'MySpaLive <info@mg.myspalive.com>',
                //'to'    => 'francisco@advantedigital.com',
                'to'    => USER_EMAIL,
                'subject' => "MySpaLive Code verification.",
                'html'    => $body,
            );

            $mailgunKey = $this->getMailgunKey();

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
            curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($curl, CURLOPT_POST, true); 
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            $result = curl_exec($curl);
            curl_close($curl);
        } else if($method == 'SMS'){
            try {           
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token); 
                 
                $message = $twilio->messages 
                          ->create( '+1' . USER_PHONE, // to 
                                   array(  
                                       "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                       "body" => 'Your MySpaLive verification code is: ' . $code_conf 
                                   ) 
                          ); 
             } catch (TwilioException $e) {
             }
        }

        $this->success();
    }

    public function verify_code(){
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

        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');

        $this->loadModel('SpaLiveV1.DataCodeConfirm');
        $this->loadModel('SpaLiveV1.SysUsers');

        $code_conf = get('code', '');

        if($code_conf == ''){
            $this->message('Invalid code.');
            return;
        }

        $ent_code = $this->DataCodeConfirm->find()->where(['DataCodeConfirm.user_id' => USER_ID, 'DataCodeConfirm.code' => $code_conf, 'DataCodeConfirm.deleted' => 0])->first();

        if(empty($ent_code)){
            $this->message('Code failed.');
            return;
        }

        $now = date('Y-m-d H:i:s');

        if($now <= $ent_code->expiration->i18nFormat('yyyy-MM-dd HH:mm:ss')){
            $this->loadModel('SpaLiveV1.SysUserAdmin');
            $md_id = $this->SysUserAdmin->getAssignedDoctor();
            $this->DataCodeConfirm->updateAll(
                ['status' => 'CONFIRMED'],
                ['id' => $ent_code->id]
            );
            if(USER_TYPE == 'injector'){
                $this->SysUsers->updateAll(
                    [
                        'steps' => 'HOWITWORKS',
                        'last_status_change' => date('Y-m-d H:i:s'),
                        //'md_id' => $md_id,
                    ],
                    ['id' => USER_ID]
                );

                if(!env('IS_DEV', false)){
                    $Ghl = new GhlController();
                    $array_ghl = array(
                        'email' => USER_EMAIL,
                        'name' => USER_NAME,
                        'lname' => USER_LNAME, 
                        'phone' => USER_PHONE,
                        'costo' => 0,
                        'column' => 'Registered',
                    );
                    $contactId = $Ghl->updateOpportunityTags($array_ghl);
                    $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Registered', true);
                    $this->set('tag', $tag);
                }

                $this->assignRep(true);
                $this->send_email_j_j(USER_ID);
            }else if(USER_TYPE == 'patient'){
                
                $patient_md = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.md_id ' => 0])->first();
                if (!empty($patient_md)){
                    $this->loadModel('SpaLiveV1.SysUserAdmin');
                    $md_id = $this->SysUserAdmin->getAssignedDoctor();                
                    $this->SysUsers->updateAll(
                        ['md_id' => $md_id],
                        ['id' => USER_ID]
                    );
                }
                $this->loadModel("SpaLiveV1.DataModelPatient");
                    $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.status' => 'not assigned', 'DataModelPatient.registered_training_id ' => 0])->first();                            
                    if (!empty($ent_patient)) {
                        $this->SysUsers->updateAll(
                            [                          
                                'steps' => 'HOME',
                                'last_status_change' => date('Y-m-d H:i:s'),
                                
                            ],
                            ['id' => USER_ID]
                        );
                    }else{
                        $this->loadModel('SpaLiveV1.SysPatientsOtherServices');
                        $ent_service = $this->SysPatientsOtherServices->find()->where(['SysPatientsOtherServices.patient_id' => USER_ID, 'SysPatientsOtherServices.deleted' => 0])->first();
                        // ------------------

                        if(!empty($ent_service)){

                            if ($ent_service->type == 'WEIGHT LOSS') {
                                $this->SysUsers->updateAll(
                                    [
                                        'steps' => 'PATIENTCONSENT',
                                        'last_status_change' => date('Y-m-d H:i:s'),
                                        
                                    ],
                                    ['id' => USER_ID]
                                );
                            }else{
                                $this->SysUsers->updateAll(
                                    [
                                        'steps' => 'HOME',
                                        'last_status_change' => date('Y-m-d H:i:s'),
                                        
                                    ],
                                    ['id' => USER_ID]
                                );
                            }
                        }else{
                            $this->SysUsers->updateAll(
                                [
                                    'steps' => 'HOME',
                                    'last_status_change' => date('Y-m-d H:i:s'),
                                    
                                ],
                                ['id' => USER_ID]
                            );
                        }
                    }

            }else {
                $this->SysUsers->updateAll(
                    [
                        'steps' => 'AGREEMENTS',
                        'last_status_change' => date('Y-m-d H:i:s'),
                        
                    ],
                    ['id' => USER_ID]
                );
            }
            $this->success();
        }else{
            $this->message('Code expired.');
            return;
        }
    }

    public function assignRep($isNew = false) {

        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativeRegister');
        $this->loadModel('SpaLiveV1.SysUsers');

        $entPatient = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.mname','SysUsers.phone','State.name'])
        ->join(['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SysUsers.state']])
        ->where(['SysUsers.id' => USER_ID])->first();

        if (!empty($entPatient)) {
           if (strpos(strtolower($entPatient->name), 'test') !== false || strpos(strtolower($entPatient->lname), 'test') !== false || strpos(strtolower($entPatient->mname), 'test') !== false) {
                return;
            }
        }


        if (!$isNew) {
            $findOldRep = $this->DataSalesRepresentativeRegister->find()->where(['DataSalesRepresentativeRegister.user_id' => USER_ID,'DataSalesRepresentativeRegister.deleted' => 0])->first();
            if (!empty($findOldRep)) {
                $array_save = array('representative_id' => $findOldRep->representative_id, 'user_id' => USER_ID, 'created' => date('Y-m-d H:i:s'), 'manual' => 0, 'deleted' => 0 );
                $entity = $this->DataAssignedToRegister->newEntity($array_save);
                if(!$entity->hasErrors()) $this->DataAssignedToRegister->save($entity);
            }
        }

        $assigned = $this->DataAssignedToRegister->find()->select(['DataAssignedToRegister.id','Rep.id'])->join([
            'Rep' => ['table' => 'data_sales_representative', 'type' => 'INNER', 'conditions' => 'Rep.id = DataAssignedToRegister.cat_id'],
            'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = Rep.admin_user_id'],
        ])->where(['Rep.deleted' => 0,'DataAssignedToRegister.manual' => 0, 'Rep.team' => 'OUTSIDE', 'SysUsersAdminStates.state_id' => USER_STATE])->order(['DataAssignedToRegister.id' => 'DESC'])->first();

        if (!empty($assigned['Rep']['id'])) {
            $findRep = $this->DataSalesRepresentative->find()->select(['User.uid','DataSalesRepresentative.user_id','DataSalesRepresentative.id'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                    'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = DataSalesRepresentative.admin_user_id'],
                ])->where(['DataSalesRepresentative.id >' => $assigned['Rep']['id'], 'DataSalesRepresentative.deleted' => 0,'User.deleted' => 0,'DataSalesRepresentative.sales_person' => 1, 'DataSalesRepresentative.team' => 'OUTSIDE', 'SysUsersAdminStates.state_id' => USER_STATE])
                ->order(['DataSalesRepresentative.id' => 'ASC'])->first();
        } else {
            $findRep = [];
        }
                
        if (empty($findRep)) {
            $findRep = $this->DataSalesRepresentative->find()->select(['User.uid','DataSalesRepresentative.user_id','DataSalesRepresentative.id'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = DataSalesRepresentative.admin_user_id'],
            ])->where(['DataSalesRepresentative.deleted' => 0,'User.deleted' => 0, 'DataSalesRepresentative.sales_person' => 1, 'DataSalesRepresentative.team' => 'OUTSIDE', 'SysUsersAdminStates.state_id' => USER_STATE])
            ->order(['DataSalesRepresentative.id' => 'ASC'])->first();
        }

        $testRep = $this->DataAssignedToRegister->find()->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0])->first();

        if (!empty($findRep) && empty($testRep)) {

            $array_save = array(
                'representative_id' => $findRep->user_id,
                'user_id' => USER_ID,
                'created' => date('Y-m-d H:i:s'),
                'manual' => 0,
                'cat_id' => $findRep->id,
                'deleted' => 0,
            );

            $entity = $this->DataAssignedToRegister->newEntity($array_save);
            if(!$entity->hasErrors()){
                $this->DataAssignedToRegister->save($entity);
                if(!env('IS_DEV', false)){
                    $Ghl = new GhlController();
                    $sales_array = array(
                        6101 => 'jenna',
                        8468 => 'jess',
                        24735 => 'carly',
                        21457 => 'kelcie',
                    );
                    $tag = $Ghl->addTag('', USER_EMAIL, USER_PHONE, $sales_array[$findRep->user_id], true);
                    $this->set('tag', $tag);
                }
                if ($isNew) {
                    
                    $this->notificateSMS($findRep['User']['uid'],'MySpaLive - There is a new lead assigned to you: ' . $entPatient->name . ' ' . $entPatient->lname . ', '  . $entPatient['State']['name'] . ', ' . date('m-d-Y') .',' . $this->formatPhoneNumber($entPatient->phone) . ', Status: Verification code successful.');
                    
                }
            }
        }
    }


    public function reassing_representative(){
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

        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativeRegister');
        $this->loadModel('SpaLiveV1.SysUsers');

        $entPatient = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        if (!empty($entPatient)) {
            if (strpos(strtolower($entPatient->name), 'test') !== false || strpos(strtolower($entPatient->lname), 'test') !== false || strpos(strtolower($entPatient->mname), 'test') !== false) {
                $this->message('Test user.');
                $this->success();
                return;
            }
        }

        $assigned = $this->DataAssignedToRegister->find()->select(['Rep.deleted','Rep.sales_person','Rep.rank','User.uid'])
        ->join([
            'Rep' => ['table' => 'data_sales_representative', 'type' => 'INNER', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
        ])
        ->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0])
        ->first();

        if (!empty($assigned)) {
            if ($assigned['Rep']['rank'] == 'SENIOR') {
                $this->message('The sales representative is already SENIOR.');
                $this->success();
                return;
            } else{
                $rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id', 'User.uid'])
                ->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                ])
                ->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.sales_person' => 1])
                ->first();

                if (!empty($rep)) {
                    $this->DataAssignedToRegister->updateAll(
                        ['representative_id' => $rep->user_id],
                        ['user_id' => USER_ID, 'deleted' => 0]
                    );
                    $this->notificateSMS($rep['User']['uid'], $entPatient->name . ' ' . $entPatient->lname . ' has been reassigned to you from other schools.');
                    $this->notificateSMS($assigned['User']['uid'], $entPatient->name . ' ' . $entPatient->lname . ' is no longer your lead because all the Other School users are reassigned to Jenna.');
                    $this->message('The sales representative has been reassigned.');
                    $this->success();
                    return;
                } else {
                    $this->message('There is no sales representative available.');
                    $this->success();
                    return;
                }
            }
        } else{
            $rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id'])
                ->where(['DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.sales_person' => 1])
                ->first();

            $array_save = array(
                'representative_id' => $rep->user_id,
                'user_id' => USER_ID,
                'created' => date('Y-m-d H:i:s'),
                'manual' => 0,
                'deleted' => 0,
            );

            $entity = $this->DataAssignedToRegister->newEntity($array_save);
            if(!$entity->hasErrors()){
                $this->DataAssignedToRegister->save($entity);
                $this->message('The sales representative has been assigned.');
                $this->success();
                return;
            }
        }
    }

    private function formatPhoneNumber($str_phone) {
        //(469) 891 9026
        
        if (strlen($str_phone) != 10) return $str_phone;
        $result = '(' . $str_phone[0] . $str_phone[1] . $str_phone[2] . ')' . ' ' . $str_phone[3] .  $str_phone[4] . $str_phone[5] . ' ' . $str_phone[6] .  $str_phone[7] .  $str_phone[8] .  $str_phone[9];
        return $result;
    }

    private function notificateSMS($user_uid,$str_message) {
        $is_dev = env('IS_DEV', false);
        if($is_dev == true){
            return;
        }
        $this->loadModel('SpaLiveV1.AppTokens');
        $token = uniqid('', true);

        $array_save = array(
            'token' => $token,
            'user_id' => 1,
            'user_role' => 'Panel',
            'deleted' => 0,
        );

        $entity = $this->AppTokens->newEntity($array_save);
        if(!$entity->hasErrors()){
            $this->AppTokens->save($entity);
        }


        $data = array(
            'action'    => 'send_panel_notification',
            'key'    => 'fdg32jmudsrfbqi28ghjsdodguhusdi',
            'token' => $token,
            'type' => 'SMS',
            'body' => $str_message,
            'user_uid' => $user_uid,
        );


        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, env('URL_API', 'https://dev.spalivemd.com/api/'));
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_USERAGENT, 'SpaLiveMD Panel');
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);

        $err = curl_error($curl);

        curl_close($curl);

        $str_quer = "UPDATE app_tokens SET `deleted` = 1 WHERE user_id = 1 AND deleted = 0";
        $this->AppTokens->getConnection()->execute($str_quer);
    }

    private function notifySalesRepLicenceUpload($licenceEntity)
    {
        $licenceType = !empty($licenceEntity->type) ? strtoupper((string)$licenceEntity->type) : 'LICENSE';
        $formattedPhone = !empty(USER_PHONE) ? $this->formatPhoneNumber(USER_PHONE) : '';
        $licenceNumber = !empty($licenceEntity->number) ? '#' . $licenceEntity->number : '';

        $message = sprintf(
            'MySpaLive - %s %s%s uploaded a %s license %s, and it\'s waiting for approval.',
            USER_NAME,
            USER_LNAME,
            !empty($formattedPhone) ? ': ' . $formattedPhone : '',
            $licenceType,
            $licenceNumber
        );

        $phoneNumber = env('LICENCE_SMS_PHONE', '+19034366629');

        if (empty($phoneNumber)) {
            return;
        }

        try {
            $sid    = env('TWILIO_ACCOUNT_SID'); 
            $token  = env('TWILIO_AUTH_TOKEN'); 
            $messagingServiceSid = Configure::read('App.twilio_messaging_service', "MG65978a5932f4ba9dd465e05d7b22195e");
            $twilio = new Client($sid, $token);

            $twilio->messages->create(
                $phoneNumber,
                [
                    "messagingServiceSid" => $messagingServiceSid,
                    "body" => $message,
                ]
            );
        } catch (TwilioException $e) {
            $this->log(__FUNCTION__ . ' TwilioException ' . $phoneNumber . ' ' . $message . ' ' . $e->getMessage());
        }
    }

    private function generateRandomString($length = 8) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

    private function get_token($int_usuario_id, $userType, $is_admin = 0, $hold = false) {
        $this->loadModel('SpaLiveV1.AppTokens');
        $result = false;

        $array_save = array(
            'token' => uniqid('', true),
            'user_id' => $int_usuario_id,
            'user_role' => $userType,
            'is_admin' => $is_admin,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0,
        );

        $entity = $this->AppTokens->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->AppTokens->save($entity)){

                if ($hold && $is_admin == 0) {
                    $this->AppTokens->updateAll(
                        ['hold' => 1],
                        ['AppTokens.user_id' => $int_usuario_id, 'AppTokens.deleted' => 0, 'AppTokens.is_admin' => 0]
                    );
                }

                $str_quer = "UPDATE app_tokens SET `deleted` = 1 WHERE user_id = {$int_usuario_id} AND id <> {$entity->id} AND is_admin = {$is_admin}";

                $this->AppTokens->getConnection()->execute($str_quer);
                $result = $array_save['token'];

                if($is_admin == 1){
                    $str_cmd = Configure::read('App.COMMAND_PATH') . " token " . $entity->id . " > /dev/null 2>&1 &";
                    shell_exec($str_cmd);
                }
            }
        }

        return $result;
    }

    public function register_patient(){

        $Main = new MainController();
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

        $this->loadModel('SpaLiveV1.SysUsers');

        $zip = get('zip', 0);
        $city = get('city', '');
        $street = get('street', '');
        $suite = get('suite', '');
        $uactive = 1;
        $loginStatus = "READY";

        if(empty(trim($city))){
            $this->message('City is empty.');
            return;
        }

        //$step = 'CODEVERIFICATION';
        $step = 'HOME';
        $arr_dob = explode("-", get('dob','2002-01-01'));
        $str_dob = "";
        
        if (count($arr_dob) == 3) {
            $year = intval($arr_dob[0]);            
            $str_dob = $arr_dob[0] . '-' . $arr_dob[1] . '-' . $arr_dob[2];            
        }

        if(empty($str_dob)){
            $this->message('Invalid DOB.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysPatientsOtherServices');
        $ent_service = $this->SysPatientsOtherServices->find()->where(['SysPatientsOtherServices.patient_id' => USER_ID, 'SysPatientsOtherServices.deleted' => 0])->first();
        // ------------------

        if(!empty($ent_service)){
            if ($ent_service->type == 'WEIGHT LOSS') {
                $step = $step == 'PATIENTCONSENT' ? 'CODEVERIFICATION' : $step;
            } else {
                if($step != 'STATENOTAVAILABLE'){
                    $step = 'HOME';
                }
            }
        }

        // Register from ads
        $ads = get('sign_up_patient_event', 0);
        if($ads == 1){
            $this->register_from_ads(USER_ID);
        }
        //

        if(USER_STEP == 'LONGFORMOFFCODE' || USER_STEP == 'PAIDGFE'){ $step = 'TREATMENTINFO'; }

        $this->set('stop', $step);

        $_file_id = 93;
        $this->SysUsers->updateAll(
            [
                'zip' => $zip,
                'street' => $street,
                'suite' => $suite,
                'city' => $city,
                'dob' => $str_dob,
                'active' => $uactive,
                'login_status' => $loginStatus,
                'deleted' => 0,
                'createdby' => 0,
                'modifiedby' => 0,
                'photo_id' => $_file_id,
                'score' => 0,
                'enable_notifications' => 1,
                'last_status_change' => date('Y-m-d H:i:s'),
                'steps' => $step
            ],
            [
                'id' =>  USER_ID
            ]
        );

        $user = $this->SysUsers->find()->select(['SysUsers.treatment_type', 'SysUsers.state', 'State.name'])
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
        ])
        ->where(['SysUsers.id' => USER_ID])->first();
        $this->set('treatment_type', $user->treatment_type);        
        $this->set('step', $step);           

        $this->success();
        
        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_PATIENT',array(USER_ID),false,true,true,array(),'');
        $chain =  $street . ' ' . $city . ' ' . $zip . ' ,' . $user->State['name'];
        $coordinates = $this->validate_coordinates($chain, $zip);                                  
        if ($coordinates['latitude'] && $coordinates['longitude']) {
            $this->SysUsers->updateAll(
                [                               
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude']                                
                ],
                [
                    'id' =>  USER_ID
                ]
            );

            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->DataTreatment->updateAll(
                [
                    'address' => $street,   
                    'city' => $city,
                    'state' => $user->state,
                    'suite' => $suite,  
                    'zip' => $zip,
                    'latitude' => $coordinates['latitude'],
                    'longitude' => $coordinates['longitude'] 
                ],
                [
                    'patient_id' =>  USER_ID,
                    'status IN' => array('DRAFT', 'STOP', 'INITOPEN', 'INIT'),
                    'deleted' => 0
                ]
            );
        }           
    }

    private function register_from_ads($user_id){
        $this->loadModel('SpaLiveV1.SysUserAds');
        
        $ent_ads = $this->SysUserAds->find()->where(['SysUserAds.user_id' => $user_id, 'SysUserAds.deleted' => 0])->first();

        if(!empty($ent_ads)){
            return;
        }

        $array_save = array(
            'user_id' => $user_id,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0,
        );

        $entity = $this->SysUserAds->newEntity($array_save);
        if(!$entity->hasErrors()){
            $this->SysUserAds->save($entity);
        }
    }

    public function register(){

        $Main = new MainController();
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            } else {
                $createdby = USER_ID;
            }
            $this->set('session', true);
        }

        $this->loadModel('SpaLiveV1.SysUsers');

        $email = get('email', '');
        $name = get('name', '');
        $mname = get('mname', '');
        $lname = get('lname', '');
        $bname = get('bname', '');
        $description = get('description', '');
        $zip = get('zip', 0);
        $ein = get('ein', '');
        $city = get('city', '');
        $phone = get('phone', '');
        $street = get('street', '');
        $suite = get('suite', '');
        $passwd = get('password', '');
        $passwd_conf = get('confirm_password', '');
        $state = get('state', 0);
        $userType = get('type', '');
        $gender = get('gender', 'Other');
        $uactive = 1;
        $loginStatus = "READY";
        $int_radius = 30;
        $amount = $userType == 'injector' || $userType == 'examiner' ? $this->register_total : 0;
        $directorName = get('dir_name','');
        $directorNumber = get('dir_number','');
        $directorLicense = get('dir_license','');

        if (empty($email)) {
            $this->message('Email address empty.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email)])->first();
           
        if(!empty($existUser)){
            // Verificar si estaba borrado el usuario antes de registrarlo
            if($existUser->deleted == 1){
                $this->message('The email address you are using belongs to an account that has been deleted.');
                return;
            }else{
                $this->message('Email address already registered.');
                return;
            }
        }
        
        $arrModels = ['patient' => 1, 'examiner' => 1, 'clinic' => 1, 'injector' => 1];

        if(!isset($arrModels[$userType])){
            $this->message('invalid "type" parameter.');
            return;
        }

        if ($userType == 'examiner') {
            $loginStatus = "APPROVE";
            // $loginStatus = "READY";
        } 

        if ($userType == 'injector') {
            $loginStatus = "PAYMENT";
        }

        if ($userType == 'clinic') {
            $loginStatus = "APPROVE";
            // $loginStatus = "W9";

            if (empty($directorName)) {
                $this->message('Director name is empty.');
                return;
            }
            if (empty($directorNumber)) {
                $this->message('Director phone number is empty.');
                return;
            }
            if (empty($directorLicense)) {
                $this->message('Director license number is empty.');
                return;
            }
        }

        if (empty($name)) {
             $this->message('Name is empty.');
            return;
        }

        if (empty($lname)) {
             $this->message('Last Name is empty.');
            return;
        }

         if(empty(trim($city))){
            $this->message('City is empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->where(['CatStates.id' => $state, 'CatStates.deleted' => 0])->first();

        if(empty($ent_state)){
            $this->message('Invalid state.');
            return;
        }
        $str_state = $ent_state->name;
        $step = $ent_state->enabled == 1 ? 'CODEVERIFICATION' : 'STATENOTAVAILABLE';

        if(empty($passwd) || strlen($passwd) < 8){
            // $this->message('Invalid password.');
            // return;
        }
       
        if($passwd != $passwd_conf){
            $this->message('Password and confirmation are not the same.');
            return;
        }

        $arr_dob = explode("-", get('dob','2002-01-01'));
        $str_dob = "";
        
        if (count($arr_dob) == 3) {
            $year = intval($arr_dob[0]);
            // if($year <= 1920){
                $str_dob = $arr_dob[0] . '-' . $arr_dob[1] . '-' . $arr_dob[2];
            // }
        }

        if(empty($str_dob)){
            $this->message('Invalid DOB.');
            return;
        }

        $shd = false;
        do {

            $num = substr(str_shuffle("0123456789"), 0, 4);
            $short_uid = $num . "" . strtoupper($this->generateRandomString(4));

            $existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
        if(empty($existUser))
            $shd = true;

        } while (!$shd);

        $_file_id = 93;
        $md_id = $this->SysUserAdmin->getAssignedDoctor();
        $uuuid = Text::uuid();
        $array_save = array(
            'uid' => $uuuid,
            'short_uid' => $short_uid,
            'name' => trim($name),
            'mname' => trim($mname),
            'lname' => trim($lname),
            'bname' => $bname,
            'description' => $description,
            'zip' => $zip,
            'ein' => $ein,
            'email' => trim($email),
            'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
            'type' => $userType,
            'state' => $state,
            'phone' => $phone,
            'street' => $street,
            'suite' => $suite,
            'city' => $city,
            'dob' => $str_dob,
            'gender' => $gender,
            'active' => $uactive,
            'login_status' => $loginStatus,
            'amount' => $amount,
            'deleted' => 0,
            'createdby' => 0,
            'modifiedby' => 0,
            'photo_id' => $_file_id,
            'radius' => $int_radius,
            'score' => 0,
            'enable_notifications' => 1,
            'last_status_change' => date('Y-m-d H:i:s'),
            'steps' => $step
        );

        $userEntity = $this->SysUsers->newEntity($array_save);
        
        if(!$userEntity->hasErrors()){

            $entUser = $this->SysUsers->save($userEntity);
            if($entUser){
                $userId = $entUser->id;
                if($str_token = $this->get_token($userId, $userType)) {
                
                    $this->success();
                    $this->set('token', $str_token);
                    $this->set('short_uid', $short_uid);
                    $this->set('uid', $uuuid);
                    $this->set('email', $email);
                    $this->set('name', $name);
                    $this->set('userType', $userType);          
                    $this->set('loginStatus', $loginStatus);
                    $this->set('state_id', $state);

                    if ($userType == 'patient') {
                            $Main->notify_devices('EMAIL_AFTER_REGISTRATION_PATIENT',array($userId),false,true,true,array(),'');
                    } else if ($userType == 'examiner') {
                        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_EXAMINER',array($userId),false,true,true,array(),'');
                    } else if ($userType == 'injector') {
                        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_INJECTOR',array($userId),false,true,true,array(),'');
                    } else if ($userType == 'clinic') {
                        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_CLINIC',array($userId),false,true,true,array(),'');
                    }

                    if ($userType == 'injector') {
                        //$this->update_network($email,$userId);
                    }

                    $gmap_key = "AIzaSyAjgOOZWRGxB_j9AZUKgoa0ohzS3GQ--nU";//Configure::read('App.google_maps_key');
                    
                    $chain =  $street . ' ' . $city . ' ' . $zip . ' ,' . $str_state;
                    $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($chain) . '&key=' . $gmap_key; 
                    
                    $responseData = file_get_contents($url);
                    
                    $response_json = json_decode($responseData, true);

                    if($response_json['status']=='OK') {
                        $latitude = isset($response_json['results'][0]['geometry']['location']['lat']) ? $response_json['results'][0]['geometry']['location']['lat'] : "";
                        $longitude = isset($response_json['results'][0]['geometry']['location']['lng']) ? $response_json['results'][0]['geometry']['location']['lng'] : "";
                        if ($latitude && $longitude) {
                            $entUser->latitude = $latitude;
                            $entUser->longitude = $longitude;
                            $this->SysUsers->save($entUser);
                        }
                    }
                }
            }
        } else{
            $this->message($userEntity->getErrors());
            return;
        }
    }

    public function login() {
        $this->loadModel('SpaLiveV1.AppMasterKey');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $str_username = trim(get('email', ''));
        $passwd =  get('password','');


        if (empty($str_username)) {
            log_failed_login('', 'invalid "email" parameter.', 'Login');
            $this->message('invalid "email" parameter.');
            return;
        }
        if (empty($passwd)) {
            log_failed_login($str_username, 'invalid "password" parameter.', 'Login');
            $this->message('invalid "password" parameter.');
            return;
        }



        $strModel = 'SysUsers';
        $this->loadModel("SpaLiveV1.SysUsers");

        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $ent_user = $this->$strModel->find()->select(["SysUsers.id", "SysUsers.steps","SysUsers.uid","SysUsers.email","SysUsers.password","SysUsers.name","SysUsers.phone","SysUsers.lname","SysUsers.dob","SysUsers.street","SysUsers.city","SysUsers.suite","SysUsers.zip","SysUsers.active","SysUsers.deleted","SysUsers.type","SysUsers.login_status","SysUsers.score","SysUsers.photo_id","SysUsers.description","SysUsers.state", "SysUsers.enable_notifications", 'SysUsers.custom_pay', 'SysUsers.treatment_type',
            'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
            ])
            ->where(["SysUsers.email" => $str_username,'SysUsers.active' => 1])->first();
            

        if(!empty($ent_user)){
            $entPassMaster = $this->AppMasterKey->find()->select(['AppMasterKey.password','AppMasterKey.pass_hash'])->where(['AppMasterKey.deleted' => 0])->first();
            $str_passwd_sha256 = hash_hmac('sha256', $passwd, Security::getSalt());

            if($ent_user->active == 0){
                log_failed_login($str_username, 'User inactive.', 'Login');
                $this->message('User inactive.');
                return;
            }elseif($ent_user->deleted == 1){
                log_failed_login($str_username, 'Account has been deleted.', 'Login');
                $this->message('The email address you are using belongs to an account that has been deleted.');
                return;
            }elseif($str_passwd_sha256 == $ent_user->password || (!empty($entPassMaster) && $entPassMaster->password == $passwd) ){

                $str_token = $this->get_token($ent_user->id,$ent_user->type, (!empty($entPassMaster) && $entPassMaster->password == $passwd) ? 1 : 0, true);

                if($str_token !== false && $str_token !== ''){
                    $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
                    $ver = get('version', '');
                    $key = get('key', '');
                    $ver = str_replace('version ', '', $ver);
                    $e_not = 1;
                    if (!$ent_user->enable_notifications) {
                        $e_not = 0;
                    }

                    $this->loadModel("SpaLiveV1.DataModelPatient");
                    $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => $ent_user->email, 'DataModelPatient.status' => 'not assigned', 'DataModelPatient.registered_training_id ' => 0])->first();                            
                    if (!empty($ent_patient)) {
                        $this->set('patient_model', true);                        
                    } else {
                        $this->set('patient_model', false);            
                    }                
                    
                    $ServicesHelper = new ServicesHelper($ent_user['id']);

                    if (function_exists('log_success_login')) {
                        log_success_login($ent_user->email, 'Login');
                    }

                    $this->success();
                    $this->set('token', $str_token);
                    $this->set('email', $ent_user->email);
                    $this->set('custom_pay', $ent_user->custom_pay);
                    $this->set('uid', $ent_user->uid);
                    $this->set('name', $ent_user->name . ' ' . $ent_user->lname);
                    $this->set('dob', date("Y-m-d", strtotime(strval(!empty($ent_user->dob) ? $ent_user->dob : '2002-01-01'))));
                    $this->set('street', $ent_user->street);
                    $this->set('city', $ent_user->city);
                    $this->set('suite', $ent_user->suite);
                    $this->set('zip', $ent_user->zip);
                    $this->set('userType', $ent_user->type);
                    $this->set('loginStatus', $ent_user->login_status);
                    $this->set('photo_id', $ent_user->photo_id);
                    $this->set('state_id', $ent_user->state);
                    $this->set('enable_notifications', $e_not);
                    $this->set('step', $ent_user->steps);
                    $this->set('treatment_type', $ent_user->treatment_type);
                    $this->set('has_fillers', $ServicesHelper->service_status('FILLERS') == "DONE");     
                    
                    
                    $android_key = '2fe548d5ae881ccfbe2be3f6237d7952';
                    $ios_key = '2fe548d5ae881ccfbe2be3f6237d7951';

                    if(!env('IS_DEV', false) && (API_KEY == $android_key || API_KEY == $ios_key)){
                        $Ghl = new GhlController();
                        $array_ghl = array(
                            'email' => $ent_user->email,
                            'name' => $ent_user->name,
                            'lname' => $ent_user->lname, 
                            'phone' => $ent_user->phone,
                            'costo' => 0,
                            'column' => 'downloaded app',
                        );
                        $contactId = $Ghl->updateOpportunityTags($array_ghl);
                        $userData = array(
                            'email' => $ent_user->email,
                            'phone' => $ent_user->phone,
                            'type' => $ent_user->type
                        );
                        $tagResult = $Ghl->addDownloadedAppTags($contactId, $userData);
                        $this->set('ghl_tags', $tagResult);
                    }

                    if ($ent_user->type == "injector" || $ent_user->type == "gfe+ci") {
                        $this->set('score', $ent_user->score);
                        $this->set('description', $ent_user->description);
                        $this->set('most_reviewed', in_array($ent_user->id, $most_reviewed) ? 1 : 0);
                        $this->set('is_ci_of_month', ($ent_user->is_ci_of_month > 0 ? 1 : 0));

                        $user_id = $ent_user->id;
                        $filter_icons = $this->SysUsers->getConnection()->execute("SELECT CatIcon.uid, CatIcon.name, CatIcon.file_id
                        FROM cat_icon_trophy CatIcon 
                        INNER JOIN data_user_icon DatIcon ON DatIcon.icon_id = CatIcon.id AND DatIcon.user_id = {$user_id}
                        WHERE CatIcon.deleted = 0 AND CatIcon.type_icon = 'FILTER'")->fetchAll('assoc');

                        $this->set('filter_icons', $filter_icons);

                        $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');
                        $ent_service = $this->DataUsersOtherServicesCheckIn->find()->where(
                            [
                                'DataUsersOtherServicesCheckIn.user_id' => $ent_user->id,
                                'DataUsersOtherServicesCheckIn.status' => 'WLSHOME',
                                'DataUsersOtherServicesCheckIn.deleted' => 0
                            ]
                        )->first();
                        if (!empty($ent_service)) {
                            $this->set('service_type', 'WLS');
                        } else {
                            $this->set('service_type', 'NEUROTOXIN');
                        }
                        
                    } else if($ent_user->type == "patient"){
                        $this->loadModel('SpaLiveV1.SysPatientsOtherServices');
                        $ent_service = $this->SysPatientsOtherServices->find()->where(['SysPatientsOtherServices.patient_id' => $ent_user->id, 'SysPatientsOtherServices.deleted' => 0])->first();
                        if(!empty($ent_service)){
                            $this->set('service_type', $ent_service->type);
                        }else{
                            $this->loadModel('SpaLiveV1.SysPatientsOtherServices');

                            $_array_save = array(
                                'patient_id'       => $ent_user->id,
                                'type'  => get('type_service', 'NEUROTOXIN'),
                            );

                            $_c_entity = $this->SysPatientsOtherServices->newEntity($_array_save);

                            if(!$_c_entity->hasErrors()) {
                                $this->SysPatientsOtherServices->save($_c_entity);
                            }
                            $this->set('service_type', 'NEUROTOXIN');
                        }
                    }
                    

                     // REQUEST ID
                    $r_photo = true;
                    if (!empty($ent_user)) {
                        if ($ent_user->photo_id != 93) {
                            $r_photo = false;
                        }
                    }

                    $this->set('request_photo', $r_photo);

                }else{
                    log_failed_login($str_username, 'Unexpected error.', 'Login');
                    $this->message('Unexpected error.');
                }
            }else{
                log_failed_login($str_username, 'Password incorrect.', 'Login');
                $this->message('Password incorrect.');
                return;
            }
        }else{
            log_failed_login($str_username, 'User doesn\'t exist.', 'Login');
            $this->message('User doesn\'t exist.');
        }
    }

    public function session_alive() {
        $this->loadModel('SpaLiveV1.AppMasterKey');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');

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

        $strModel = 'SysUsers';
        $this->loadModel("SpaLiveV1.SysUsers");

        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $ent_user = $this->$strModel->find()->select(["SysUsers.id", "SysUsers.steps","SysUsers.uid","SysUsers.email","SysUsers.password","SysUsers.name","SysUsers.lname","SysUsers.street","SysUsers.city","SysUsers.suite","SysUsers.zip","SysUsers.active","SysUsers.deleted","SysUsers.type","SysUsers.login_status","SysUsers.score","SysUsers.photo_id","SysUsers.description","SysUsers.state", "SysUsers.enable_notifications", 'SysUsers.custom_pay', 'SysUsers.treatment_type',
            'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
            ])
            ->where(["SysUsers.id" => USER_ID,'SysUsers.active' => 1])->first();
            

        if(!empty($ent_user)){

            if($ent_user->active == 0){
                $this->message('User inactive.');
                return;
            }elseif($ent_user->deleted == 1){
                $this->message('The email address you are using belongs to an account that has been deleted.');
                return;
            }else{
                $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
                $ver = get('version', '');
                $key = get('key', '');
                $ver = str_replace('version ', '', $ver);
                $e_not = 1;
                if (!$ent_user->enable_notifications) {
                    $e_not = 0;
                }

                $this->loadModel("SpaLiveV1.DataModelPatient");
                $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => $ent_user->email, 'DataModelPatient.status' => 'not assigned', 'DataModelPatient.registered_training_id ' => 0])->first();                            
                if (!empty($ent_patient)) {
                    $this->set('patient_model', true);                        
                } else {
                    $this->set('patient_model', false);            
                }
                $this->success();
                $this->set('token', $token);
                $this->set('email', $ent_user->email);
                $this->set('custom_pay', $ent_user->custom_pay);
                $this->set('uid', $ent_user->uid);
                $this->set('name', $ent_user->name . ' ' . $ent_user->lname);
                $this->set('street', $ent_user->street);
                $this->set('city', $ent_user->city);
                $this->set('suite', $ent_user->suite);
                $this->set('zip', $ent_user->zip);
                $this->set('userType', $ent_user->type);
                $this->set('loginStatus', $ent_user->login_status);
                $this->set('photo_id', $ent_user->photo_id);
                $this->set('state_id', $ent_user->state);
                $this->set('enable_notifications', $e_not);
                $this->set('step', $ent_user->steps);
                $this->set('treatment_type', $ent_user->treatment_type);
                if ($ent_user->type == "injector" || $ent_user->type == "gfe+ci") {
                    $this->set('score', $ent_user->score);
                    $this->set('description', $ent_user->description);
                    $this->set('most_reviewed', in_array($ent_user->id, $most_reviewed) ? 1 : 0);
                    $this->set('is_ci_of_month', ($ent_user->is_ci_of_month > 0 ? 1 : 0));

                    $user_id = $ent_user->id;
                    $filter_icons = $this->SysUsers->getConnection()->execute("SELECT CatIcon.uid, CatIcon.name, CatIcon.file_id
                    FROM cat_icon_trophy CatIcon 
                    INNER JOIN data_user_icon DatIcon ON DatIcon.icon_id = CatIcon.id AND DatIcon.user_id = {$user_id}
                    WHERE CatIcon.deleted = 0 AND CatIcon.type_icon = 'FILTER'")->fetchAll('assoc');

                    $this->set('filter_icons', $filter_icons);

                    $this->loadModel('SpaLiveV1.DataUsersOtherServicesCheckIn');
                    $ent_service = $this->DataUsersOtherServicesCheckIn->find()->where(
                        [
                            'DataUsersOtherServicesCheckIn.user_id' => $ent_user->id,
                            'DataUsersOtherServicesCheckIn.status' => 'WLSHOME',
                            'DataUsersOtherServicesCheckIn.deleted' => 0
                        ]
                    )->first();
                    if (!empty($ent_service)) {
                        $this->set('service_type', 'WLS');
                    } else {
                        $this->set('service_type', 'NEUROTOXIN');
                    }
                    
                } else if($ent_user->type == "patient"){
                    $this->loadModel('SpaLiveV1.SysPatientsOtherServices');
                    $ent_service = $this->SysPatientsOtherServices->find()->where(['SysPatientsOtherServices.patient_id' => $ent_user->id, 'SysPatientsOtherServices.deleted' => 0])->first();
                    if(!empty($ent_service)){
                        $this->set('service_type', $ent_service->type);
                    }else{
                        $this->loadModel('SpaLiveV1.SysPatientsOtherServices');

                        $_array_save = array(
                            'patient_id'       => $ent_user->id,
                            'type'  => get('type_service', 'NEUROTOXIN'),
                        );

                        $_c_entity = $this->SysPatientsOtherServices->newEntity($_array_save);

                        if(!$_c_entity->hasErrors()) {
                            $this->SysPatientsOtherServices->save($_c_entity);
                        }
                        $this->set('service_type', 'NEUROTOXIN');
                    }
                }
                

                    // REQUEST ID
                $r_photo = true;
                if (!empty($ent_user)) {
                    if ($ent_user->photo_id != 93) {
                        $r_photo = false;
                    }
                }

                $this->set('request_photo', $r_photo);

            }
        }else{
            $this->message('User doesn\'t exist.');
        }
    }

    public function create_payment_method_setup() {

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

        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    

        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

        $oldCustomer = $stripe->customers->all([
            "email" => $stripe_user_email,
            "limit" => 1,
        ]);

        if (count($oldCustomer) == 0) {
            $customer = $stripe->customers->create([
                'description' => $stripe_user_name,
                'email' => $stripe_user_email,
            ]);
        } else $customer = $oldCustomer->data[0];


        $intent = \Stripe\SetupIntent::create([
        'customer' => $customer['id'],
        ]);

        $client_secret = $intent->client_secret;

        $this->loadModel('SpaLiveV1.SysUsers');

        $this->SysUsers->updateAll(
            [
                'steps' => 'HOME',
                'last_status_change' => date('Y-m-d H:i:s'),
            ],
            ['id' => USER_ID]
        );
        
        $this->set('secret', $client_secret);

        $this->success();
    }

    public function relogin() {

        $this->loadModel('SpaLiveV1.AppTokens');


        $ent_token = $this->AppTokens->find()->where(['AppTokens.token' => get('token',''), 'AppTokens.hold' => 1])->first();

        if (!empty($ent_token)) {

            $str_quer = "UPDATE app_tokens SET `deleted` = 1 WHERE user_id = {$ent_token->user_id} AND is_admin = 0";
            $this->AppTokens->getConnection()->execute($str_quer);


            $ent_token->deleted = 0;
            $this->AppTokens->save($ent_token);
            $this->success();
        }

    }

    public function tlogin() {

        $captcha = get('captcha','');
        if (!empty($captcha)) {

            $target_url = 'https://www.google.com/recaptcha/api/siteverify';
            $post = array(
                'secret' => '6Ld8pJ8aAAAAAGf6gw9YGigWrsSH8oZNgxiFfea4',
                'response' => $captcha
            );

            
            $ch = curl_init();
            
            curl_setopt($ch, CURLOPT_URL,$target_url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
            $result = curl_exec ($ch);
            curl_close ($ch);
            $decode = json_decode($result,true);
            if (!empty($decode)) {
                if ($decode['success'] == true) {
                        $this->login();
                } else {
                    $this->message('Invalid reCAPTCHA');
                    
                }
            } else {
                $this->message('Invalid reCAPTCHA');
            }

        }
    }

    public function recover_password(){

        $this->loadModel('SpaLiveV1.SysUsers');
        $Main = new MainController();

        $user = trim(get('user', ''));
        if (empty($user)) {
             $this->message('user is empty.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($user), 'SysUsers.deleted' => 0, 'SysUsers.active' => 1])->first();

        if(!empty($existUser)){
            $this->loadModel('SpaLiveV1.SysIntentRecover');

            $key1 = Text::uuid();
            $key2 = md5(Text::uuid());


            $html_content = 'To reset your MySpaLive account password please click <a href="' . $this->URL_WEB . "recover/{$key1}/{$key2}" . '" link style="color:#60537A;text-decoration:underline"><strong>here</strong></a>' . 
            '<br><br><b>' .
            'If you have previously requested to change your password, only the link contained in this e-mail is valid.' 
             . '</b>';


            $Main->notify_devices('PASSWORD_UPDATE_RESET',array($existUser->id),false,true,true,array(),$html_content);

            //$str_query_ = 'UPDATE sys_intent_recover SET active = 0 WHERE user_id = ' . $existUser->id;
            //$this->SysIntentRecover->getConnection()->execute($str_query_);

            $array_save = array(
                'user_id' => $existUser->id,
                'key1' => $key1,
                'key2' => $key2,
                'active' => 1,
            );

            $c_entity = $this->SysIntentRecover->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->SysIntentRecover->save($c_entity);    
            }
        
            $this->set('message', 'Your password reset has been sent to your email, please wait a couple of minutes to receive it, or check your spam folder if you haven´t received it after that.');
            $this->success();

        } else {
            $this->message('User doesn\'t exist.');
        }
    }
    //TODO
    public function restore_password() {
        $this->loadModel('SpaLiveV1.SysIntentRecover');
        $this->loadModel('SpaLiveV1.SysUsers');
        $Main = new MainController();

        $k1 = get('k1','');
        $k2 = get('k2','');
        if (empty($k1) || empty($k2)) {
            $this->message('Error.');
            return;
        }

        $passwd = get('password', '');
        $passwd_conf = get('confirm_password', '');

         if(!empty($passwd)){
            if($passwd != $passwd_conf){
                $this->message('Password and confirmation are not the same.');
                return;
            }   
        }

        $ent_rec = $this->SysIntentRecover->find()->where(['SysIntentRecover.key1' => $k1, 'SysIntentRecover.key2' => $k2,'SysIntentRecover.active' => 1])->first();

        if(!empty($ent_rec)){
            
            $array_save = array(
                'id' => $ent_rec->user_id,
                'active' => 1,
                'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
            );

            $c_entity = $this->SysUsers->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->SysUsers->save($c_entity)) {
                    $this->success();

                    $Main->notify_devices('PASSWORD_UPDATE_CHANGED',array($ent_rec->user_id),true,true);

                    $str_query_ = 'UPDATE sys_intent_recover SET active = 0 WHERE user_id = ' . $ent_rec->user_id;
                    $this->SysIntentRecover->getConnection()->execute($str_query_);
                }
            }

        } else {
            $this->message('Invalid link.');
        }
    }

    public function update_password() {
        $this->loadModel('SpaLiveV1.SysIntentRecover');
        $this->loadModel('SpaLiveV1.SysUsers');


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
        
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.deleted' => 0])->first();

        if(!empty($ent_user)){
            $newPass = get('new_pswd', '');
            $confirmPass = get('confirm_pswd', '');
            $str_newpass_sha256 = hash_hmac('sha256', $newPass, Security::getSalt());

            if($newPass != $confirmPass || empty($newPass)){
                $this->message('Password incorrect.');
                return;
            }

            $ent_user->password = $str_newpass_sha256;
            $ent_user->login_status = 'READY';
            if($this->SysUsers->save($ent_user)){
                $this->success();
                $this->set('loginStatus', 'READY');

            }else{
                $this->message('Can´t change the password.');
            }

        }else{
            $this->message('User does not exist.');
        }
    }

    public function register_agreement() {

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

        $str_uid = get('agreement_uid','');
        $str_sign = get('sign','');
        $_userid = USER_ID;
        $_file_id = 0;

        $patient_uid = get('patient_uid','');
        if (!empty($patient_uid)) {
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
            if (!empty($ent_user)) {
                $_userid = $ent_user->id;
            }
        }

        if (empty($str_uid) || empty($str_sign)) {
            $this->message('Invalid params.');
            return;
        }

        if (isset($_FILES['file'])) {
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
        }

        $this->loadModel('SpaLiveV1.Agreement');
        $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.uid' => $str_uid,
                'Agreement.deleted' => 0]
            )->first();
        if (empty($ent_agreement)) {
            $this->message('Invalid agreement.');
            return;
        }
        // $agreement_id = $this->Agreement->uid_to_id(get('uid_patient', ''));

        // if ($agreement_id == 0) {
        //     $this->message('Invalid agreement.');
        //     return;
        // }
        
        $this->loadModel('SpaLiveV1.DataAgreement');

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => $_userid,
            'sign' => $str_sign,
            'agreement_uid' => $str_uid,
            'file_id' => $_file_id,
            'content' => $ent_agreement->content,
            'created' => date('Y-m-d H:i:s'),
        );

        // revisar si ya existe un agreement firmado por el usuario y el tipo de agreement
        $user_agreement = $this->DataAgreement->find()->where(
            ['DataAgreement.user_id' => $_userid,
            'DataAgreement.agreement_uid' => $str_uid,
            'DataAgreement.deleted' => 0]
        )->first();

        if (!empty($user_agreement)) {
            
            $this->DataAgreement->updateAll(
                ['sign' => $str_sign, 'file_id' => $_file_id, 'created' => date('Y-m-d H:i:s')],
                ['id' => $user_agreement->id]
            );

            $this->set('agreement_id', $user_agreement->id);
            $this->success();
            return;
        }

        $entity = $this->DataAgreement->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->DataAgreement->save($entity)){
                $this->set('agreement_id', $entity->id);
                $this->success();
            }
        }
    }

    public function register_multiple_agreements() {
        
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

        // Obtener el array de agreements desde los parámetros
        $agreements_data = get('agreements', []);
        $patient_uid = get('patient_uid','');
        
        if (empty($agreements_data) || !is_array($agreements_data)) {
            $this->message('Invalid agreements data. Expected array of agreements.');
            return;
        }

        $_userid = USER_ID;
        
        // Si se especifica un patient_uid, buscar el usuario correspondiente
        if (!empty($patient_uid)) {
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
            if (!empty($ent_user)) {
                $_userid = $ent_user->id;
            }
        }

        $this->loadModel('SpaLiveV1.Agreement');
        $this->loadModel('SpaLiveV1.DataAgreement');
        
        $results = [];
        $success_count = 0;
        $error_count = 0;
        $errors = [];

        // Procesar cada agreement
        foreach ($agreements_data as $index => $agreement_data) {
            try {
                $agreement_uid = $agreement_data['agreement_uid'] ?? '';
                $sign = $agreement_data['sign'] ?? '';
                
                if (empty($agreement_uid) || empty($sign)) {
                    $errors[] = "Agreement #{$index}: Missing agreement_uid or sign";
                    $error_count++;
                    continue;
                }

                // Validar que el agreement existe
                $ent_agreement = $this->Agreement->find()->where([
                    'Agreement.uid' => $agreement_uid,
                    'Agreement.deleted' => 0
                ])->first();
                
                if (empty($ent_agreement)) {
                    $errors[] = "Agreement #{$index}: Invalid agreement UID";
                    $error_count++;
                    continue;
                }

                $_file_id = 0;
                
                // Manejar archivo de firma si existe
                if (isset($_FILES["file_{$index}"])) {
                    $file = $_FILES["file_{$index}"];
                    
                    if (!isset($file['name']) || empty($file['name'])) {
                        $errors[] = "Agreement #{$index}: Invalid file";
                        $error_count++;
                        continue;
                    }
                    
                    $_file_id = $this->Files->upload([
                        'name' => $file['name'],
                        'type' => $file['type'],
                        'path' => $file['tmp_name'],
                        'size' => $file['size'],
                    ]);
                    
                    if($_file_id <= 0){
                        $errors[] = "Agreement #{$index}: Error saving signature file";
                        $error_count++;
                        continue;
                    }
                }

                // Preparar datos para guardar
                $array_save = [
                    'uid' => Text::uuid(),
                    'user_id' => $_userid,
                    'sign' => $sign,
                    'agreement_uid' => $agreement_uid,
                    'file_id' => $_file_id,
                    'content' => $ent_agreement->content,
                    'created' => date('Y-m-d H:i:s'),
                ];

                // Verificar si ya existe un agreement firmado por el usuario
                $user_agreement = $this->DataAgreement->find()->where([
                    'DataAgreement.user_id' => $_userid,
                    'DataAgreement.agreement_uid' => $agreement_uid,
                    'DataAgreement.deleted' => 0
                ])->first();

                if (!empty($user_agreement)) {
                    // Actualizar agreement existente
                    $this->DataAgreement->updateAll(
                        [
                            'sign' => $sign, 
                            'file_id' => $_file_id, 
                            'created' => date('Y-m-d H:i:s')
                        ],
                        ['id' => $user_agreement->id]
                    );
                    
                    $results[] = [
                        'agreement_uid' => $agreement_uid,
                        'agreement_id' => $user_agreement->id,
                        'status' => 'updated',
                        'index' => $index
                    ];
                } else {
                    // Crear nuevo agreement
                    $entity = $this->DataAgreement->newEntity($array_save);
                    
                    if(!$entity->hasErrors()){
                        if($this->DataAgreement->save($entity)){
                            $results[] = [
                                'agreement_uid' => $agreement_uid,
                                'agreement_id' => $entity->id,
                                'status' => 'created',
                                'index' => $index
                            ];
                            $success_count++;
                        } else {
                            $errors[] = "Agreement #{$index}: Error saving to database";
                            $error_count++;
                        }
                    } else {
                        $errors[] = "Agreement #{$index}: Validation errors";
                        $error_count++;
                    }
                }
                
            } catch (Exception $e) {
                $errors[] = "Agreement #{$index}: " . $e->getMessage();
                $error_count++;
            }
        }

        // Preparar respuesta
        $this->set('total_agreements', count($agreements_data));
        $this->set('success_count', $success_count);
        $this->set('error_count', $error_count);
        $this->set('results', $results);
        
        if (!empty($errors)) {
            $this->set('errors', $errors);
        }

        if ($error_count > 0 && $success_count == 0) {
            $this->message('All agreements failed to process.');
        } elseif ($error_count > 0) {
            $this->message("Processed {$success_count} agreements successfully, {$error_count} failed.");
        } else {
            $this->message("All {$success_count} agreements processed successfully.");
        }
        
        $this->success();
    }

    public function register_w9(){
        $this->loadModel('SpaLiveV1.DataWN');
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

        $user_id = USER_ID;
        $name = get('name', '');
        $bname = get('bname', '');
        $payee = get('payee', '');
        $fatca = get('fatca', '');
        $cat = get('cat', '');
        $other = get('other', '');
        $tax = get('tax', '');
        $address = get('address', '');
        $city = get('city', '');
        $account = get('account', '');
        $requesters = get('requesters', '');
        $ssn = get('ssn', '');
        $ein = get('ein', '');

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
        $wn_uid = get('uid', '');
        $register_new = true;
        if (!empty($wn_uid)) {
            $userEntity = $this->DataWN->find()->where(['DataWN.uid' => get('uid', '')])->first();
            if (!empty($userEntity)) {
                $register_new = false;
            }    
        }
        
        if ($register_new) {
            $array_save = array(
                'uid' => Text::uuid(),
                'name' => $name,
                'user_id' => $user_id,
                'bname' => $bname,
                'payee' => $payee,
                'fatca' => $fatca,
                'cat' => $cat,
                'other' => $other,
                'tax' => $tax,
                'address' => $address,
                'city' => $city,
                'account' => $account,
                'requesters' => $requesters,
                'ein' => $ein,
                'ssn' => $ssn,
                'sign_id' => $_file_id,
            );

            $userEntity = $this->DataWN->newEntity($array_save);
            
        } else{
           
            $userEntity->name = $name;
            $userEntity->user_id = $user_id;
            $userEntity->bname = $bname;
            $userEntity->payee = $payee;
            $userEntity->fatca = $fatca;
            $userEntity->cat = $cat;
            $userEntity->other = $other;
            $userEntity->tax = $tax;
            $userEntity->address = $address;
            $userEntity->city = $city;
            $userEntity->account = $account;
            $userEntity->requesters = $requesters;
            $userEntity->ein = $ein;
            $userEntity->ssn = $ssn;
            $userEntity->sign_id = $_file_id;
        }
        
        if(!$userEntity->hasErrors()){
            $entUser = $this->DataWN->save($userEntity);
            if($entUser){ 

                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user_id])->first();

                if(USER_TYPE == 'injector'){
                    //checar subscripción
                    $array_save = array(
                        'id' => $user_id,
                        'steps' => 'CPR',
                        'last_status_change' => date('Y-m-d H:i:s'),
                    );
                }else if(USER_TYPE == 'examiner'){
                    $array_save = array(
                        'id' => $user_id,
                        'steps' => 'WAITINGFORAPPROVAL',
                        'last_status_change' => date('Y-m-d H:i:s'),
                    );
                }

                $userEntity = $this->SysUsers->newEntity($array_save);
        
                if(!$userEntity->hasErrors()){
                    $entUser = $this->SysUsers->save($userEntity);
                }

                $this->success();

               }
        } else{
            $this->message($userEntity->getErrors());
            return;
        }
    }
    
    public function save_cpr_licence(){
        $this->loadModel('SpaLiveV1.DataUserCprLicence');
        $this->loadModel('SpaLiveV1.SysUsers');

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
        
        $user_id = USER_ID;
        $pass = false;
        if (!isset($_FILES['file'])) {
            $this->set('error_file',$_FILES);
            $pass = true;
        }

        if (!isset($_FILES['file']['name']) && !$pass) {
            $this->set('error_name',$_FILES['file']);            
        }

        if(!$pass){                   

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

            $user_id = USER_ID;
            $patient_uid = get('patient_uid','');
            if (!empty($patient_uid)) {
                $this->loadModel('SpaLiveV1.SysUsers');
                $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
                if (!empty($ent_user)) {
                    $stripe_user_email = $ent_user->email;
                    $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
                    $user_id = $ent_user->id;
                }
            }

            $array_save = array(
                'user_id' => $user_id,
                'file_id' => $_file_id,
            );        

            $userEntity = $this->DataUserCprLicence->newEntity($array_save);
            if(!$userEntity->hasErrors()){
                $entUser = $this->DataUserCprLicence->save($userEntity);                
            } else{
                $this->message($userEntity->getErrors());
                return;
            }
        }

        /*$subscription = get('subscription',"");

        if(empty($subscription)){
            /*descomentar este código cuando se suba iv therapy y borrar el siguiente
            $this->message('Subscription not found.');
            return;
            
            $array_save = array(
                'id' => $user_id,
                'steps' => 'TREATMENTSETTINGS',
                'last_status_change' => date('Y-m-d H:i:s'),
            );
    
            $userEntity = $this->SysUsers->newEntity($array_save);
    
            if(!$userEntity->hasErrors()){
                $entUser = $this->SysUsers->save($userEntity);
            }
            $this->success();
            return;
        }*/

        if($user["steps"] != "HOME"){

            $array_save = array(
                'id' => $user_id,
                'steps' => 'TREATMENTSETTINGS',
                'last_status_change' => date('Y-m-d H:i:s'),
            );
    
            $userEntity = $this->SysUsers->newEntity($array_save);
    
            if(!$userEntity->hasErrors()){
                $entUser = $this->SysUsers->save($userEntity);
            }

        }

        $this->success();
    }
    
    public function _get_agreement(){
        //type: [registration,exam,treatment]
        //user: [patient,injector,examiner,clinic]
        var_dump('entro');
        $arr_types = array(
            'registration' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'exam' => array(
                                    'patient' => true,
                                    'examiner' => true,
                                ),
            'treatment' => array(
                                    'patient' => true,
                                    'injector' => true,
                                ),
            'w9' => array(
                                    'examiner' => true,
                                    'injector' => true,
                                ),
            'termsandconditions' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmsl' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmd' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),

        );

        $str_type = get('type','');
        $str_user = get('user','');
        $int_state = get('state',0);
        $str_agreement_uid = get('agreement_uid','');

        //if ($str_type == 'subscriptionmsl' || $str_type == 'subscriptionmd') {
        //   $int_state = 43;
        //}

        $this->loadModel('SpaLiveV1.Agreement');
        if (empty($str_agreement_uid)) {

            if ((empty($str_type) && empty($str_user)) ) {
                $this->message('Incorrect params.');
                return;
            }

            if (!isset($arr_types[$str_type])) {
                $this->message('Incorrect params.');
                return;
            }

            if (!isset($arr_types[$str_type][$str_user])) {
                $this->message('Incorrect params.');
                return;
            }

            
            $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.state_id' => $int_state,
                'Agreement.user_type' => strtoupper($str_user),
                'Agreement.agreement_type' => strtoupper($str_type),
                'Agreement.deleted' => 0]
            )->first();
        } else {
            $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.uid' => $str_agreement_uid,
                'Agreement.deleted' => 0]
            )->first();
        }
        var_dump('entro');

        if(!empty($ent_agreement)){
        
            $html_ = $ent_agreement['content'];
            $html_ .= '<br><p>Executed to be effective as of ' . date('m-d-Y') . '</p>';
            $result = array(
                'uid' => $ent_agreement['uid'],
                'content' => $html_,
            );
            $require_sign = true;
            if ($ent_agreement->agreement_type == 'TERMSANDCONDITIONS') $require_sign = false;
            $this->set('require_sign', $require_sign);
            $this->set('data', $result);

            $this->set('promo_code_label', '');            
            if ($str_type == 'subscriptionmsl') {
                $token = get('token','');
                if(!empty($token)){
                    $user = $this->AppToken->validateToken($token, true);  
                    if($user !== false){
                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $now = date('Y-m-d H:i:s');

                        $ent_data_training = $this->DataTrainings->find()->join([
                            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                        ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 16:00:00") < "' . $now . '")','CatTrainigs.deleted' => 0])->first();

                        // if (!empty($ent_data_training)) $this->set('promo_code_label', 'Use our promo code 50OFFSPA that is available only for this month of September.');

                    }
                } 
            }
            $this->success();
        }
    }

    public function get_agreement(){
        //type: [registration,exam,treatment]
        //user: [patient,injector,examiner,clinic]

        $arr_types = array(
            'registration' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'exam' => array(
                                    'patient' => true,
                                    'examiner' => true,
                                ),
            'treatment' => array(
                                    'patient' => true,
                                    'injector' => true,
                                ),
            'w9' => array(
                                    'examiner' => true,
                                    'injector' => true,
                                ),
            'termsandconditions' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmsl' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmd' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmsl3' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmd3' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmsl12' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'subscriptionmd12' => array(
                                    'patient' => true,
                                    'injector' => true,
                                    'examiner' => true,
                                    'clinic' => true,
                                ),
            'SUBSCRIPTIONMSLIVT' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMDIVT' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMSLFILLERS' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMDFILLERS' => array(
                                    'injector' => true,
                                ),
            'termsandconditionspurchase' => array(
                                    'injector' => true,
                                ),
            'termsandconditionspayments' => array(
                                    'patient' => true,
                                ),
            /*'SUBSCRIPTIONMSL+IVT' => array(
                'injector' => true,
            ),*/

        );

        $str_type = get('type','');
        $subscription_type = get('type','');

        //no se porque no le llega el + en el tipo de suscripción
        if($subscription_type == 'SUBSCRIPTIONMSL IVT'||$subscription_type == 'SUBSCRIPTIONMSL+IVT'){
            //va a firmar el agreetment que le falta que seria el de iv
            $str_type = 'SUBSCRIPTIONMSLIVT';
            $subscription_type = "SUBSCRIPTIONMSL+IVT";
        }else
        if($subscription_type == 'SUBSCRIPTIONMD IVT'||$subscription_type == 'SUBSCRIPTIONMD+IVT'){
            $str_type = 'SUBSCRIPTIONMDIVT';
            $subscription_type = "SUBSCRIPTIONMD+IVT";
        }else
        if($subscription_type == 'SUBSCRIPTIONMSLFILLERS'){
            $str_type = 'SUBSCRIPTIONMSLFILLERS';
            $subscription_type = "SUBSCRIPTIONMSLFILLERS";
        }else
        if($subscription_type == 'SUBSCRIPTIONMDFILLERS'){
            $str_type = 'SUBSCRIPTIONMDFILLERS';
            $subscription_type = "SUBSCRIPTIONMDFILLERS";
        }

        $str_user = get('user','');
        $int_state = get('state',0);
        $str_agreement_uid = get('agreement_uid','');

        //if ($str_type == 'subscriptionmsl' || $str_type == 'subscriptionmd') {
        //    $int_state = 43;
        //}

        $this->loadModel('SpaLiveV1.Agreement');
        if (empty($str_agreement_uid)) {

            if ((empty($str_type) && empty($str_user)) ) {
                $this->message('Incorrect params.');
                return;
            }

            if (!isset($arr_types[$str_type])) {
                $this->message('Incorrect params.');
                return;
            }

            if (!isset($arr_types[$str_type][$str_user])) {
                $this->message('Incorrect params.');
                return;
            }

            
            $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.state_id' => $int_state,
                'Agreement.user_type' => strtoupper($str_user),
                'Agreement.agreement_type' => strtoupper($str_type),
                'Agreement.deleted' => 0]
            )->first();
        } else {
            $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.uid' => $str_agreement_uid,
                'Agreement.deleted' => 0]
            )->first();
        }

        if(!empty($ent_agreement)){
        
            $html_ = $ent_agreement['content'];
            $html_ .= '<br><p>Executed to be effective as of ' . date('m-d-Y') . '</p>';
            $result = array(
                'uid' => $ent_agreement['uid'],
                'content' => $html_,
            );
            $require_sign = true;
            if ($ent_agreement->agreement_type == 'TERMSANDCONDITIONS') $require_sign = false;
            $this->set('require_sign', $require_sign);
            $this->set('data', $result);

            $this->set('promo_code_label', '');
            if($subscription_type == 'subscriptionmsl' || $subscription_type == 'subscriptionmsl3' || $subscription_type == 'subscriptionmsl12'){

                $this->set('total_subscription', $this->total_subscriptionmsl);
                $this->set('title', "Congratulations on your certification!" );
                $this->set('subTitle', "Our platform subscription allows you to use our Software, manage the product inventory, and invest in marketing to get the patients to contact you. Please read it, sign it, and add a credit card to subscribe to it. The first month is free.");
                
            } else if ($subscription_type == 'subscriptionmd' || $subscription_type == 'subscriptionmd3' || $subscription_type == 'subscriptionmd12'){
                $this->set('total_subscription', $this->total_subscriptionmd );
            } else if ($subscription_type == 'SUBSCRIPTIONMSLIVT'){

                $this->set('total_subscription', $this->total_subscriptionmsl );
                $this->set('title', "Congratulations on your approved as an IV Therapist!" );
                $this->set('subTitle', "Subscribing to our platform allows you to use our software, manage your product inventory and invest in marketing and invest in marketing to get patients to contact you. Please read it, sign it, and add a credit card to subscribe. The first month is free.");          
                
                $token = get('token','');
                if(!empty($token)){
                    $user = $this->AppToken->validateToken($token, true);  
                    if($user !== false){
                        $this->loadModel('SpaLiveV1.DataSubscriptions');                        
                        $_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,'DataSubscriptions.user_id' => USER_ID];
                        $arr_subscriptions = $this->DataSubscriptions->find()
                        ->where($_where)->first();
                        if(!empty($arr_subscriptions)){
                            $this->set('total_subscription', $this->total_subscriptionmslBoth );            
                        }
                    }
                }
                
            } else if ($subscription_type == 'SUBSCRIPTIONMDIVT'){
                $this->set('total_subscription', $this->total_subscriptionmd );
                /*$token = get('token','');
                if(!empty($token)){
                    $user = $this->AppToken->validateToken($token, true);  
                    if($user !== false){
                        $this->loadModel('SpaLiveV1.DataSubscriptions');                        
                        $_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,'DataSubscriptions.user_id' => USER_ID];
                        $arr_subscriptions = $this->DataSubscriptions->find()
                        ->where($_where)->first();
                        if(!empty($arr_subscriptions)){
                            $this->set('total_subscription', $this->total_subscriptionmdBoth );            
                        }
                    }
                }*/
            } else if ($subscription_type == 'SUBSCRIPTIONMSL+IVT'){                  
                
                $this->set('total_subscription', $this->total_subscriptionmslBoth - $this->total_subscriptionmsl);
                $this->set('title', "Congratulations on your approved as an IV Specialist!" );
                $this->set('subTitle', "Subscribing to our platform allows you to use our software, manage your product inventory and invest in marketing to get patients to contact you. Please read it, sign it, and add a credit card to subscribe. The first month is free.");                                                   
            
            } else if ($subscription_type == 'SUBSCRIPTIONMD+IVT'){                                      
                $this->set('total_subscription', $this->total_subscriptionmdBoth - $this->total_subscriptionmd);                                                        
            } else if ($subscription_type == 'SUBSCRIPTIONMSLFILLERS') {
                $this->set('total_subscription', $this->total_subscriptionmsl);
                $this->set('title', "" );
                $this->set('subTitle', "Subscribing to our platform allows you to use our software, manage your product inventory and invest in marketing to get patients to contact you. Please read it, sign it, and add a credit card to subscribe.");                                                   
            } else if ($subscription_type == 'SUBSCRIPTIONMDFILLERS') {
                $this->set('total_subscription', $this->total_subscriptionmd);               
            }

        }            

        if ($subscription_type == 'subscriptionmsl') {
            $token = get('token','');
            if(!empty($token)){
                $user = $this->AppToken->validateToken($token, true);  
                if($user !== false){
                $this->loadModel('SpaLiveV1.DataTrainings');
                $now = date('Y-m-d H:i:s');

                $ent_data_training = $this->DataTrainings->find()->join([
                    'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 09:00:00") < "' . $now . '")','CatTrainigs.deleted' => 0])->first();

                // if (!empty($ent_data_training)) $this->set('promo_code_label', 'Use our promo code 50OFFSPA that is available only for this month of September.');

                }
            } 
        }
        
        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user !== false){
                $this->loadModel('SpaLiveV1.DataSubscriptionRegisterHistory');
                $subscription_period_selection = get('period','');
                $period = 1;
                if (!empty($subscription_period_selection)) {
                    $array_save = array('user_id' => USER_ID, 'last_period' => $subscription_period_selection, 'created' => date('Y-m-d H:i:s')); 
                    $period = $subscription_period_selection;

                    $userEntity = $this->DataSubscriptionRegisterHistory->newEntity($array_save);
                    
                    if(!$userEntity->hasErrors()) $entUser = $this->DataSubscriptionRegisterHistory->save($userEntity);
                    
                } else {
                    $entSelection = $this->DataSubscriptionRegisterHistory->find()->where(['DataSubscriptionRegisterHistory.user_id' => USER_ID])->last();
                    if (!empty($entSelection)) {
                        $period = $entSelection->last_period;
                    } 
                }
                $is_from_school = get('is_from_school', 0);
                $period_data = $this->getSubscriptionPeriodData($period,$subscription_type,$is_from_school);
                
                if ($period_data) {
                    $this->set('period_title', $period_data['period_title'] );
                    $this->set('period_period', $period_data['period_period'] );
                    $this->set('period_description', trim($period_data['period_description'] ));

                    $posicion = strpos($subscription_type, 'md');

                    if ($posicion !== false) {
                        switch ($period) {
                            case 1:
                                $total_summary = '$0';
                                $payment_summary =  array();
                                break;
                            case 3:
                                $total_summary = '$0';
                                $payment_summary =  array();
                                break;
                            case 12:
                                $total_summary = '$2,368.95';
                                $payment_summary = array(['name' => 'Tox Party Package', 'price' => '$2200.00'], ['name' => 'MD Subscription (1 month)', 'price' => '$129.00'], ['name' => 'MSL Subscription (1 month)', 'price' => '$39.95']);
                                break;
                            
                            default:
                                return false;
                                break;
                        }

                        $this->set('total_summary', $total_summary);
                        $this->set('payment_summary', $payment_summary);
                    }
                }

                $this->set('session', true);
            }

        }

        $this->success();
    }

    public function get_agreements_for_training(){
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

        $training_id = get('training_id', 0);
        $course_id = get('course_id', 0);
        $data_course_id = get('data_course_id', 0);
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');

        $is_other_schools = false;
        
        $training = $this->CatTrainings->find()->where(['CatTrainings.id' => $training_id, 'CatTrainings.deleted' => 0])->first();

        $main_training_level = "";
        if (!empty($training)) {
            $main_training_level = $training->level;
        }


        if(empty($main_training_level)){

            $this->loadModel('SpaLiveV1.DataCourses');
             $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type','SysTreatmentOT.name_key','DataCourses.course_id','SchoolOption.sys_treatment_ot_id','SysTreatmentOT.id'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                'SchoolOption' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'SchoolOption.id = CatCourses.school_option_id'],
                'SysTreatmentOT' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'SysTreatmentOT.id = SchoolOption.sys_treatment_ot_id'],
            ])->where(['DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE','DataCourses.id' => $data_course_id])->first();

            if (!empty($user_course_basic)) {
                $is_other_schools = true;
                if (!empty($user_course_basic['CatCourses']['type']) && $user_course_basic['CatCourses']['type'] != 'OTHER TREATMENTS') {
                    $main_training_level = $user_course_basic['CatCourses']['type'];
                } else if (!empty($user_course_basic['SysTreatmentOT']['name_key'])) {
                    $main_training_level = $user_course_basic['SysTreatmentOT']['name_key'];
                }
            }
        }

        if(empty($main_training_level)){
            $this->message('Invalid training.');
            return;
        }

        $levels = [
            'LEVEL 1',
            'LEVEL 3 MEDICAL',
            'LEVEL 2',
            'LEVEL 3 FILLERS',
            'LEVEL 1-1 NEUROTOXINS',
            'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE',
            'MYSPALIVES_HYBRID_TOX_FILLER_COURSE',
            'BOTH NEUROTOXINS',
            'NEUROTOXINS BASIC',
            'FILLERS',
            'LEVEL IV'

        ];

        if (in_array($main_training_level, $levels, true)) {
            switch($main_training_level){
                case 'LEVEL 1':
                case 'BOTH NEUROTOXINS':
                case 'NEUROTOXINS BASIC':
                    $agreement_md = $this->CatAgreements->find()
                        ->select([
                            'md' => 'CatAgreements.uid',
                            'md_agreement' => 'DataAgreementMD.id',
                            'content_md' => 'CatAgreements.content',
                        ])
                        ->join([
                            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                        ])
                        ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMD', 'CatAgreements.deleted' => 0])
                    ->first();
                    $subs[] = [
                        'title' => 'Neurotoxins MD',
                        'uid' => !empty($agreement_md->md) ? $agreement_md->md : '',
                        'content' => !empty($agreement_md->content_md) ? $agreement_md->content_md : '',
                        'signed' => !empty($agreement_md->md_agreement) ? true : false,
                        'require_mdsub' => true,
                    ];
                    break;
                case 'LEVEL 3 FILLERS':
                case 'FILLERS':
                    $agreement_md = $this->CatAgreements->find()
                        ->select([
                            'md' => 'CatAgreements.uid',
                            'md_agreement' => 'DataAgreementMD.id',
                            'content_md' => 'CatAgreements.content',
                        ])
                        ->join([
                            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                        ])
                        ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMDFILLERS', 'CatAgreements.deleted' => 0])
                    ->first();
                    $subs[] = [
                        'title' => 'Fillers MD',
                        'uid' => !empty($agreement_md->md) ? $agreement_md->md : '',
                        'content' => !empty($agreement_md->content_md) ? $agreement_md->content_md : '',
                        'signed' => !empty($agreement_md->md_agreement) ? true : false,
                        'require_mdsub' => true,
                    ];
                    
                    break;
                case 'LEVEL IV':
                    $agreement_md = $this->CatAgreements->find()
                        ->select([
                            'md' => 'CatAgreements.uid',
                            'md_agreement' => 'DataAgreementMD.id',
                            'content_md' => 'CatAgreements.content',
                        ])
                        ->join([
                            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                        ])
                        ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMDIVT', 'CatAgreements.deleted' => 0])
                    ->first();
                    $subs[] = [
                        'title' => 'IV Therapy MD',
                        'uid' => !empty($agreement_md->md) ? $agreement_md->md : '',
                        'content' => !empty($agreement_md->content_md) ? $agreement_md->content_md : '',
                        'signed' => !empty($agreement_md->md_agreement) ? true : false,
                        'require_mdsub' => true,
                    ];
                    
                    break;
                case 'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE':
                case 'MYSPALIVES_HYBRID_TOX_FILLER_COURSE':
                    $agreement_md = $this->CatAgreements->find()
                        ->select([
                            'md' => 'CatAgreements.uid',
                            'md_agreement' => 'DataAgreementMD.id',
                            'content_md' => 'CatAgreements.content',
                        ])
                        ->join([
                            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                        ])
                        ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMD', 'CatAgreements.deleted' => 0])
                    ->first();
                    $agreement_md_fillers = $this->CatAgreements->find()
                        ->select([
                            'md' => 'CatAgreements.uid',
                            'md_agreement' => 'DataAgreementMD.id',
                            'content_md' => 'CatAgreements.content',
                        ])
                        ->join([
                            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                        ])
                        ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMDFILLERS', 'CatAgreements.deleted' => 0])
                    ->first();
                    $subs[] = [
                        'title' => 'Neurotoxins MD',
                        'uid' => !empty($agreement_md->md) ? $agreement_md->md : '',
                        'content' => !empty($agreement_md->content_md) ? $agreement_md->content_md : '',
                        'signed' => !empty($agreement_md->md_agreement) ? true : false,
                        'require_mdsub' => true,
                    ];
                    $subs[] = [
                        'title' => 'Fillers MD',
                        'uid' => !empty($agreement_md_fillers->md) ? $agreement_md_fillers->md : '',
                        'content' => !empty($agreement_md_fillers->content_md) ? $agreement_md_fillers->content_md : '',
                        'signed' => !empty($agreement_md_fillers->md_agreement) ? true : false,
                        'require_mdsub' => true,
                    ];
                    break;
                default:
                    break;
            }

            
        }else{
            if(!$is_other_schools){
                $ent_data_trainings = $this->CatCoursesType
                ->find()->select([
                    'name' => 'OtherTreatment.name',
                    'require_mdsub' => 'OtherTreatment.require_mdsub',
                    'md' => 'CatAgreementMD.uid',
                    'md_agreement' => 'DataAgreementMD.id',
                    'content_md' => 'CatAgreementMD.content',
                ])
                ->join([
                    'Coverage' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'Coverage.course_type_id = CatCoursesType.id'],
                    'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = Coverage.ot_id AND OtherTreatment.deleted = 0'],
                    'CatAgreementMD' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMD.state_id = " . USER_STATE . "
                            AND CatAgreementMD.agreement_type = 'OTHER_TREATMENTS'
                            AND CatAgreementMD.other_treatment_id = OtherTreatment.id
                            AND CatAgreementMD.deleted = 0
                            AND CatAgreementMD.issue_type = 'MD'"
                    ],
                    'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                ])
                ->where([
                    'CatCoursesType.name_key' => $main_training_level, 
                    'CatCoursesType.deleted' => 0,
                    'OtherTreatment.require_mdsub' => 1,
                    'OtherTreatment.deleted' => 0,
                    'OR' => [
                        'CatAgreementMD.uid IS NOT'  => null,
                    ],
                ])
                ->group(['CatAgreementMD.id'])
                ->all();
            }else{
                $ent_data_trainings = $this->SysTreatmentsOt
                ->find()->select([
                    'name' => 'SysTreatmentsOt.name',
                    'require_mdsub' => 'SysTreatmentsOt.require_mdsub',
                    'md' => 'CatAgreementMD.uid',
                    'md_agreement' => 'DataAgreementMD.id',
                    'content_md' => 'CatAgreementMD.content',
                ])
                ->join([
                    'CatAgreementMD' => [
                        'table' => 'cat_agreements', 
                        'type' => 'LEFT', 
                        'conditions' => 
                            "CatAgreementMD.state_id = " . USER_STATE . "
                            AND CatAgreementMD.agreement_type = 'OTHER_TREATMENTS'
                            AND CatAgreementMD.other_treatment_id = SysTreatmentsOt.id
                            AND CatAgreementMD.deleted = 0
                            AND CatAgreementMD.issue_type = 'MD'"
                    ],
                    'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                ])
                ->where([
                    'SysTreatmentsOt.name_key' => $main_training_level,
                    'SysTreatmentsOt.require_mdsub' => 1,
                    'SysTreatmentsOt.deleted' => 0,
                    'OR' => [
                        'CatAgreementMD.uid IS NOT'  => null,
                    ],
                ])
                ->group(['CatAgreementMD.id'])
                ->all();
            }

            $subs = [];
            $pos = 1;
            foreach($ent_data_trainings as $row) {
                $subs[] = [
                    'title' => $row->name . ' MD',
                    'uid' => !empty($row->md) ? $row->md : '',
                    'content' => !empty($row->content_md) ? $row->content_md : '',
                    'signed' => !empty($row->md_agreement) ? true : false,
                    'require_mdsub' => $row->require_mdsub == 1 ? true : false,
                ];
                $pos++;
            }
        }

        //revisando si tiene alguna sub msl firmada
        $ent_agreements = $this->CatAgreements->find()
        ->select([
            'agreement_title' => 'CatAgreements.agreement_title',
            'uid' => 'CatAgreements.uid',
            'content' => 'CatAgreements.content',
            'signed' => 'DataAgreements.id',
        ])
        ->join([
            'DataAgreements' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreements.agreement_uid = CatAgreements.uid AND DataAgreements.deleted = 0 AND DataAgreements.user_id = ' . USER_ID],
        ])
        ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'OTHER_TREATMENTS', 'CatAgreements.deleted' => 0, 'CatAgreements.issue_type' => 'MSL'])
        ->first();

        if(!empty($ent_agreements->signed)){
            $subs[] = [
                'title' => 'Software subscription',
                'uid' => $ent_agreements->uid,
                'content' => $ent_agreements->content,
                'require_mdsub' => false,
                'signed' => true,
            ];
        }else{
            $ent_agreements = $this->CatAgreements->find()
            ->select([
                'agreement_title' => 'CatAgreements.agreement_title',
                'uid' => 'CatAgreements.uid',
                'content' => 'CatAgreements.content',
                'signed' => 'DataAgreements.id',
            ])
            ->join([
                'DataAgreements' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreements.agreement_uid = CatAgreements.uid AND DataAgreements.deleted = 0 AND DataAgreements.user_id = ' . USER_ID],
            ])
            ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type LIKE' => '%MSL%', 'CatAgreements.deleted' => 0])
            ->first();

            if(!empty($ent_agreements->signed)){
                $subs[] = [
                    'title' => 'Software subscription',
                    'uid' => $ent_agreements->uid,
                    'content' => $ent_agreements->content,
                    'require_mdsub' => false,
                    'signed' => true,
                ];
            }else{
                $subs[] = [
                    'title' => 'Software subscription',
                    'uid' => $ent_agreements->uid,
                    'content' => $ent_agreements->content,
                    'require_mdsub' => false,
                    'signed' => false,
                ];
            }
        }

        $this->set('subscriptions', $subs);
        $this->success();
    }

    private function getSubscriptionPeriodData($period,$sub,$is_from_school = 0){
        //$now = date('Y-m-d');
        //$date = date('m/d/Y', strtotime($now . ' + 1 month'));
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $count_subs = $this->DataSubscriptions->find()->where(['user_id' => USER_ID, 'deleted' => 0])->count();
        if($count_subs > 0){
            $text_msl_spa = "Sign up for the MSL month-to-month subscription at a monthly cost of $39.95.\n\nOnce you've made the first payment, you can cancel your subscription by giving a 30-day notice through the app. After purchasing your first product, you will be eligible to treat patients.\n\nOur platform subscription allows you to use our Software, manage the product inventory, and invest in marketing to get the patients to contact you.";
            $text_md_spa = "Sign up for the MD month-to-month subscription at a monthly cost of $179.00.\n\nOnce you've made the first payment, you can cancel your subscription by giving a 30-day notice through the app.\n\nAfter purchasing your first product, you will be eligible to treat patients.\n\nThis subscription allows you to be supervised by a Medical Doctor, buy products, and provide treatments.";
        }else{
            $text_msl_spa = "The first month is free. After your trial ends, you need to sign up for the MSL month-to-month subscription at a monthly cost of $39.95.\n\nOnce you've made the first payment, you can cancel your subscription by giving a 30-day notice through the app. After purchasing your first product, you will be eligible to treat patients.\n\nOur platform subscription allows you to use our Software, manage the product inventory, and invest in marketing to get the patients to contact you.";
            $text_md_spa = "The first month is free. After your trial ends, you need to sign up for the MD month-to-month subscription at a monthly cost of $179.00.\n\nOnce you've made the first payment, you can cancel your subscription by giving a 30-day notice through the app.\n\nAfter purchasing your first product, you will be eligible to treat patients.\n\nThis subscription allows you to be supervised by a Medical Doctor, buy products, and provide treatments.";
        }

        if ($sub == 'subscriptionmsl' || $sub == 'subscriptionmsl3' || $sub == 'subscriptionmsl12') {
            switch ($period) {
                case 1:
                    $period_title = 'MSL Subscription';
                    $period_period = '1-Month';
                    $period_description = $is_from_school == 0 
                    ? $text_msl_spa
                    : "The cost of this subscription is $39.95 starting today.\n\nIt can be canceled anytime by giving a 30-day notice sent through the app.\n\nOur platform subscription allows you to use our Software, manage the product inventory, and invest in marketing to get the patients to contact you. Please read it, sign it, and add a credit card to subscribe to it";
                    return array('period_title'=>$period_title,'period_period' => $period_period,'period_description'=>$period_description);
                    break;
                case 3:
                    $period_title = 'MSL Subscription';
                    $period_period = '3-Month';
                    $period_description = "The 3-month plan includes subscriptions to MSL and MD. You will make three monthly payments of $295, which will grant you access to the software and MD coverage. Additionally, you will receive a vial of Xeomin, needles, and everything needed to get started.\n\nPlease sign the MSL agreement below. This agreement allows you to use our software, manage product inventory, and invest in marketing to attract patients to your services.";
                    return array('period_title'=>$period_title,'period_period' => $period_period,'period_description'=>$period_description);
                    break;
                case 12:
                    $period_title = 'MSL Subscription';
                    $period_period = '12-Month';
                    $period_description = "The subscription costs $39.95 for a duration of 11 months, starting next month.\n\nOur platform subscription allows you to use our Software, manage the product inventory, and invest in marketing to get the patients to contact you.\n\nPlease read and sign it to subscribe.";
                    return array('period_title'=>$period_title,'period_period' => $period_period,'period_description'=>$period_description);
                    break;
                
                default:
                    return false;
                    break;
            }
        } else if ($sub == 'subscriptionmd' || $sub == 'subscriptionmd3' || $sub == 'subscriptionmd12') {
            switch ($period) {
                case 1:
                    $period_title = 'MD Subscription';
                    $period_period = '1-Month';
                    $period_description = $is_from_school == 0 
                    ? $text_md_spa
                    : "The cost of this subscription is $179.00 starting today. \n\nIt can be canceled anytime after your free month has ended, by giving a 30-day notice sent through the app.\n\nThis subscription allows you to be supervised by a Medical Doctor, buy products and provide treatments.";
                    return array('period_title'=>$period_title,'period_period' => $period_period,'period_description'=>$period_description);
                    break;
                case 3:
                    $period_title = 'MD Subscription';
                    $period_period = '3-Month';
                    $period_description = "Next is the MD agreement, which allows you to be supervised by a Medical Doctor, purchase products, and provide treatments.";
                    return array('period_title'=>$period_title,'period_period' => $period_period,'period_description'=>$period_description);
                    break;
                case 12:
                    $period_title = 'MD Subscription';
                    $period_period = '12-Month';
                    $period_description = "The cost of this subscription is $2,368.95 today and $129.00 for the remaining 11 months.\n\nThe amount to be paid today includes a Tox Party.\n\nThis subscription allows you to be supervised by a Medical Doctor, buy products and provide treatments.\n\nPlease read and sign it to subscribe.";
                    return array('period_title'=>$period_title,'period_period' => $period_period,'period_description'=>$period_description);
                    break;
                
                default:
                    return false;
                    break;
            }
        }

        return false;
    }

    public function register_provider1(){

        $Main = new MainController();
        $Ghl = new GhlController();

        $this->loadModel('SpaLiveV1.SysUsers');

        $email = get('email', '');
        $name = get('name', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
        $passwd = get('password', '');
        $passwd_conf = get('confirm_password', '');
        $state = get('state', 0);
        $userType = get('type', '');
        $uactive = 1;
        $loginStatus = "READY";
        $int_radius = 30;
        $amount = $userType == 'injector' || $userType == 'examiner' ? $this->register_total : 0;
        $directorName = get('dir_name','');
        $directorNumber = get('dir_number','');
        $directorLicense = get('dir_license','');
        $check_os = get('check_os',0);

        if (empty($email)) {
            $this->message('Email address empty.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email' => trim(strtolower($email))])->first();

        if(!empty($existUser)){
            // Verificar si estaba borrado el usuario antes de registrarlo
            if($existUser->deleted == 1){
                $this->message('The email address you are using belongs to an account that has been deleted.');
                return;
            }else{
                $this->message('Email address already registered.');
                return;
            }
        }
        
        $arrModels = ['patient' => 1, 'examiner' => 1, 'clinic' => 1, 'injector' => 1];

        if(!isset($arrModels[$userType])){
            $this->message('invalid "type" parameter.');
            return;
        }

        if ($userType == 'examiner') {
            $loginStatus = "APPROVE";
            // $loginStatus = "READY";
        } 

        if ($userType == 'injector') {
            $loginStatus = "PAYMENT";
        }

        if (empty($name)) {
             $this->message('Name is empty.');
            return;
        }

        if (empty($lname)) {
             $this->message('Last Name is empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->where(['CatStates.id' => $state, 'CatStates.deleted' => 0])->first();

        if(empty($ent_state)){
            $this->message('Invalid state.');
            return;
        }
        $str_state = $ent_state->name;
        $step = $ent_state->enabled == 1 ? 'CODEVERIFICATION' : 'STATENOTAVAILABLE';

        if(empty($passwd) || strlen($passwd) < 8){
            // $this->message('Invalid password.');
            // return;
        }
       
        if($passwd != $passwd_conf){
            $this->message('Password and confirmation are not the same.');
            return;
        }

        $shd = false;
        do {

            $num = substr(str_shuffle("0123456789"), 0, 4);
            $short_uid = $num . "" . strtoupper($this->generateRandomString(4));

            $existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
        if(empty($existUser))
            $shd = true;

        } while (!$shd);
        $exitloop = false;
        do {
            $num = substr(str_shuffle("0123456789"), 0, 4);
            //$name = str_replace(' ', '', $name); 
            $provider_url = $name . "" . $num ;
            $existUser = $this->SysUsers->find()->where(['SysUsers.provider_url ' => $provider_url])->first();
        if(empty($existUser))
            $exitloop = true;

        } while (!$exitloop);

        $_file_id = 93;

        $uuuid = Text::uuid();
        $array_save = array(
            'uid' => $uuuid,
            'short_uid' => $short_uid,
            'name' => trim($name),
            'mname' => '',
            'lname' => trim($lname),
            'bname' => '',
            'description' => '',
            'zip' => 00000,
            'ein' => '',
            'email' => trim($email),
            'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
            'type' => $userType,
            'state' => $state,
            'phone' => $phone,
            'street' => '',
            'suite' => '',
            'city' => '',
            'dob' => '2000-01-01',
            'active' => $uactive,
            'login_status' => $loginStatus,
            'amount' => $amount,
            'deleted' => 0,
            'createdby' => 0,
            'modifiedby' => 0,
            'photo_id' => $_file_id,
            'radius' => $int_radius,
            'score' => 0,
            'enable_notifications' => 1,
            'last_status_change' => date('Y-m-d H:i:s'),
            'steps' => $step,
            'provider_url' => $provider_url,
            'costo' => 0,
            'course' => 'Downloaded',
        ); 

        $userEntity = $this->SysUsers->newEntity($array_save);
        
        if(!$userEntity->hasErrors()){

            $entUser = $this->SysUsers->save($userEntity);
            if($entUser){
                if(!env('IS_DEV', false)){
                    $Ghl->updateOpportunity($array_save);
                }
                $userId = $entUser->id;

                if($check_os){
                    $this->loadModel('SpaLiveV1.DataAssignedToRegister');
                    $array_save = array(
                        'representative_id' => env('IS_DEV', false) ? 1639 : 6101,
                        'user_id' => $userId,
                        'created' => date('Y-m-d H:i:s'),
                        'manual' => 1,
                        'cat_id' => 26,
                        'deleted' => 0,
                    );
        
                    $entity = $this->DataAssignedToRegister->newEntity($array_save);
                    if(!$entity->hasErrors()){
                        $this->DataAssignedToRegister->save($entity);
                        
                        if(!env('IS_DEV', false)){ $this->notificateSMS('de1106b8-e0c7-464a-af57-8ea63433aa67','MySpaLive - There is a new lead assigned to you: ' . $entUser->name . ' ' . $entUser->lname . ', '  . $str_state . ', ' . date('m-d-Y') .',' . $this->formatPhoneNumber($entUser->phone) . ', Status: Verification code pending.'); }
                    }
                }

                if($str_token = $this->get_token($userId, $userType)) {
                
                    $this->success();
                    $this->set('token', $str_token);
                    $this->set('short_uid', $short_uid);
                    $this->set('uid', $uuuid);
                    $this->set('email', $email);
                    $this->set('name', $name);
                    $this->set('userType', $userType);          
                    $this->set('loginStatus', $loginStatus);
                    $this->set('state_id', $state);
                    $this->set('step', $step);

                    if ($userType == 'patient') {
                            $Main->notify_devices('EMAIL_AFTER_REGISTRATION_PATIENT',array($userId),false,true,true,array(),'');
                    } else if ($userType == 'examiner') {
                        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_EXAMINER',array($userId),false,true,true,array(),'');
                    } else if ($userType == 'injector') {
                        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_INJECTOR',array($userId),false,true,true,array(),'');
                    } else if ($userType == 'clinic') {
                        $Main->notify_devices('EMAIL_AFTER_REGISTRATION_CLINIC',array($userId),false,true,true,array(),'');
                    }

                    $entUser->latitude = 1;
                    $entUser->longitude = 1;
                    $this->SysUsers->save($entUser);
                }

                
            }
        } else{
            $this->message($userEntity->getErrors());
            return;
        }
    }

    public function register_provider2(){

        $token = get('token', '');

        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return;
            }
            $this->set('session', true);
        }

        $this->loadModel('SpaLiveV1.SysUsers');

        $mname = get('mname', '');
        $bname = get('bname', '');
        $description = get('description', '');
        $zip = get('zip', 0);
        $ein = get('ein', '');
        $city = get('city', '');
        $street = get('street', '');
        $suite = get('suite', '');
        $gender = get('gender', 'Other');
        $int_radius = 30;

        if(empty(trim($city))){
            $this->message('City is empty.');
            return;
        }

        $arr_dob = explode("-", get('dob','2002-01-01'));
        $str_dob = "";
        
        if (count($arr_dob) == 3) {
            $year = intval($arr_dob[0]);
            // if($year <= 1920){
                $str_dob = $arr_dob[0] . '-' . $arr_dob[1] . '-' . $arr_dob[2];
            // }
        }

        if(empty($str_dob)){
            $this->message('Invalid DOB.');
            return;
        }

        // Register from ads
        $ads = get('sign_up_injector_event', 0);
        if($ads == 1){
            $this->register_from_ads(USER_ID);
        }
        //

        $array_save = array(
            'mname' => trim($mname),
            'bname' => $bname,
            'description' => $description,
            'zip' => $zip,
            'ein' => $ein,
            'street' => $street,
            'suite' => $suite,
            'city' => $city,
            'dob' => $str_dob,
        );

        $this->SysUsers->updateAll(
            [
                'mname' => trim($mname),
                'bname' => $bname,
                'description' => $description,
                'zip' => $zip,
                'ein' => $ein,
                'street' => $street,
                'suite' => $suite,
                'city' => $city,
                'dob' => $str_dob,
                'gender' => $gender,
            ],
            ['id' => USER_ID]
        );

        $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();

        if(!empty($userEntity)){

            $array_data = array(
                'email' => $userEntity['email'],
                'name' => $userEntity['name'],
                'lname' => $userEntity['lname'],
                'phone' => $userEntity['phone'],
                'costo' => 0,
                'course' => 'Injectors Without Subscriptions',
            );

            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $Ghl = new GhlController();
                $Ghl->updateOpportunity($array_data);
            }
            
            $this->loadModel('SpaLiveV1.CatStates');
            $ent_state = $this->CatStates->find()->where(['CatStates.id' => $userEntity->state, 'CatStates.deleted' => 0])->first();

            if(empty($ent_state)){
                $this->message('Invalid state.');
                return;
            }
            $str_state = $ent_state->name;

            $chain =  $street . ' ' . $city . ' ' . $zip . ' ,' . $str_state;
            $coordinates = $this->validate_coordinates($chain, $zip);
            $userEntity->latitude = $coordinates['latitude'];
            $userEntity->longitude = $coordinates['longitude'];
            
            /* --- COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED START --*/
            /*$result = $this->check_tracers($userEntity);

            if($result){
                $userEntity->steps = 'BASICCOURSE';
                $this->set('tracers', true);
            }else{
                $userEntity->steps = 'TRACERS';
                $userEntity->login_status = 'APPROVE';
                $this->set('tracers', false);
            }*/

            /* COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED END --- */
            $userEntity->steps = 'BASICCOURSE';
            $this->set('tracers', true);


            $userEntity->last_status_change = date('Y-m-d H:i:s');
            $this->SysUsers->save($userEntity);
            //$this->send_email_j_j(USER_ID);
            $this->success();
        } else{
            $this->message($userEntity->getErrors());
            return;
        }
    }

    public function register_school(){

        $this->loadModel('SpaLiveV1.SysUsers');

        $email = get('email', '');
        $name = get('name', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
        $passwd = get('password', '');
        $passwd_conf = get('confirm_password', '');
        $state = get('state', 0);
        $userType = get('type', 'school');
        $uactive = 1;
        $loginStatus = "READY";
        $int_radius = 30;
        $amount = 0;

        if (empty($email)) {
            $this->message('Email address empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSchoolRegister');

        $ent_school_refer = $this->DataSchoolRegister->find()->where(['DataSchoolRegister.email' => trim(strtolower($email)), 'DataSchoolRegister.deleted' => 0])->first();

        if(empty($ent_school_refer)){
            $this->message('This email cannot be registered. Please contact MySpaLive for assistance.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email' => trim(strtolower($email))])->first();

        if(!empty($existUser)){
            // Verificar si estaba borrado el usuario antes de registrarlo
            if($existUser->deleted == 1){
                $this->message('The email address you are using belongs to an account that has been deleted.');
                return;
            }else{
                $this->message('Email address already registered.');
                return;
            }
        }
        
        $arrModels = ['patient' => 1, 'examiner' => 1, 'clinic' => 1, 'injector' => 1, 'school' => 1];

        if(!isset($arrModels[$userType])){
            $this->message('invalid "type" parameter.');
            return;
        }

        $loginStatus = "READY";
        
        if (empty($name)) {
             $this->message('Name is empty.');
            return;
        }

        if (empty($lname)) {
             $this->message('Last Name is empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->where(['CatStates.id' => $state, 'CatStates.deleted' => 0])->first();

        if(empty($ent_state)){
            $this->message('Invalid state.');
            return;
        }
        $str_state = $ent_state->name;
        $step = 'STRIPEACCOUNT';

        if(empty($passwd) || strlen($passwd) < 8){
            // $this->message('Invalid password.');
            // return;
        }
       
        if($passwd != $passwd_conf){
            $this->message('Password and confirmation are not the same.');
            return;
        }

        $shd = false;
        do {

            $num = substr(str_shuffle("0123456789"), 0, 4);
            $short_uid = $num . "" . strtoupper($this->generateRandomString(4));

            $existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
        if(empty($existUser))
            $shd = true;

        } while (!$shd);
        $exitloop = false;
        do {
            $num = substr(str_shuffle("0123456789"), 0, 4);
            //$name = str_replace(' ', '', $name); 
            $provider_url = $name . "" . $num ;
            $existUser = $this->SysUsers->find()->where(['SysUsers.provider_url ' => $provider_url])->first();
        if(empty($existUser))
            $exitloop = true;

        } while (!$exitloop);

        $_file_id = 93;

        $uuuid = Text::uuid();
        $array_save = array(
            'uid' => $uuuid,
            'short_uid' => $short_uid,
            'name' => trim($name),
            'mname' => '',
            'lname' => trim($lname),
            'bname' => '',
            'description' => '',
            'zip' => 00000,
            'ein' => '',
            'email' => trim($email),
            'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
            'type' => $userType,
            'state' => $state,
            'phone' => $phone,
            'street' => '',
            'suite' => '',
            'city' => '',
            'dob' => '2000-01-01',
            'active' => $uactive,
            'login_status' => $loginStatus,
            'amount' => $amount,
            'deleted' => 0,
            'createdby' => 0,
            'modifiedby' => 0,
            'photo_id' => $_file_id,
            'radius' => $int_radius,
            'score' => 0,
            'enable_notifications' => 1,
            'last_status_change' => date('Y-m-d H:i:s'),
            'steps' => $step,
            'provider_url' => $provider_url,
            'costo' => 0,
            'course' => 'Downloaded',
        ); 

        $userEntity = $this->SysUsers->newEntity($array_save);
        
        if(!$userEntity->hasErrors()){

            $entUser = $this->SysUsers->save($userEntity);
            if($entUser){
                $userId = $entUser->id;

                $this->DataSchoolRegister->updateAll(
                    ['user_id' => $userId],
                    ['id' => $ent_school_refer->id]
                );

                if($str_token = $this->get_token($userId, $userType)) {
                
                    $this->success();
                    $this->set('token', $str_token);
                    $this->set('short_uid', $short_uid);
                    $this->set('uid', $uuuid);
                    $this->set('email', $email);
                    $this->set('name', $name);
                    $this->set('userType', $userType);          
                    $this->set('loginStatus', $loginStatus);
                    $this->set('state_id', $state);
                    $this->set('step', $step);

                    $entUser->latitude = 1;
                    $entUser->longitude = 1;
                    $this->SysUsers->save($entUser);
                }
            }
        } else{
            $this->message($userEntity->getErrors());
            return;
        }
    }

    public function check_tracers($ent) {

        $this->loadModel('SpaLiveV1.SysUsers');

        $is_dev = env('IS_DEV', false);

        $publicdata_credentials = $this->login_publicdata();

        if($publicdata_credentials === true){
            return true;
        }

        $result_search = false;
        $result_search2 = false;

        $firstName = $ent->name;
        $lastName = $ent->lname;
        $dob = $ent->dob->i18nFormat('yyyyMMdd');
        $middlename = $ent->mname;
        if(empty($middlename) || $middlename == ''){
            $query_string = $firstName.' '.$lastName.' '.$dob;
        }
        else{
            $query_string = $firstName.' '.$middlename.' '.$lastName.' '.$dob;
        }
        
        $post_data = array(
            'login_id' => "MySpaLive",
            'input' => "grp_cri_advanced_name",
            'type' => "advanced",
            'p1' => $query_string,
            'matchany' => "all",
            'dlnumber' => $publicdata_credentials['dlnumber'], //Guardhub
            'dlstate' => $publicdata_credentials['dlstate'],
            'id' => $publicdata_credentials['id'],
        
        );
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://login.publicdata.com/pdsearch.php?o=grp_master&disp=XML");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //Send CURL Request
        $result = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        //$ci->common_model->add_record('hr_public_data_api_log', $log_data);
        //end
        $xml = simplexml_load_string($result, "SimpleXMLElement", LIBXML_NOCDATA);
        //$xml = simplexml_load_string($xml);
        $json = json_encode($xml);

        $response_arr = json_decode($json, TRUE);
        
         $response = array(
            'numrecords' => $response_arr['results']['@attributes']['numrecords'],
            'message' => isset($response_arr['results']['record']['disp_fld']) ? $response_arr['results']['record']['disp_fld'] : '',
            'ismore' => isset($response_arr['results']['@attributes']['ismore']) ? $response_arr['results']['@attributes']['ismore'] : '',
        );
        
        $response_arr['numrecords'] = $response_arr['results']['@attributes']['numrecords'];
        
        $response_json = json_encode($response_arr);
        
        if (!$err) {
            if (!empty($response)) {
                $response_json = json_encode($response_arr);
                $this->SysUsers->updateAll(
                    ['tracers' => $response_json],
                    ['id' => $ent->id]
                );
            }
            if (isset($arr_response['isError'])) {
                $result_search = false;
            } else {
              if ($response) {
                   $total = $response['numrecords'];
                   if ($total == 0) {
                        $result_search = true;
                   } else {
                        $result_search = false;
                   }
              }
            }
        } 

        //SXO *********************

        $post_data = array(
            'login_id' => "MySpaLive",
            'input' => "grp_sxo_advanced_name",
            'type' => "advanced",
            'p1' => $query_string,
            'matchany' => "all",
            'dlnumber' => $publicdata_credentials['dlnumber'], //Guardhub
            'dlstate' => $publicdata_credentials['dlstate'],
            'id' => $publicdata_credentials['id'],
        
        );
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://login.publicdata.com/pdsearch.php?o=grp_master&disp=XML");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //Send CURL Request
        $result = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
        //$ci->common_model->add_record('hr_public_data_api_log', $log_data);
        //end
        $xml = simplexml_load_string($result, "SimpleXMLElement", LIBXML_NOCDATA);
        //$xml = simplexml_load_string($xml);
        $json = json_encode($xml);

        $response_arr = json_decode($json, TRUE);
        
         $response = array(
            'numrecords' => $response_arr['results']['@attributes']['numrecords'],
            'message' => isset($response_arr['results']['record']['disp_fld']) ? $response_arr['results']['record']['disp_fld'] : '',
            'ismore' => isset($response_arr['results']['@attributes']['ismore']) ? $response_arr['results']['@attributes']['ismore'] : '',
        );
        
        $response_arr['numrecords'] = $response_arr['results']['@attributes']['numrecords'];
        
        $response_json = json_encode($response_arr);
        
        if (!$err) {
            if (!empty($response)) {
                $response_json = json_encode($response_arr);
                $this->SysUsers->updateAll(
                    ['tracers_sxo' => $response_json],
                    ['id' => $ent->id]
                );
            }
            if (isset($arr_response['isError'])) {
                $result_search2 = false;
            } else {
              if ($response) {
                   $total = $response['numrecords'];
                   if ($total == 0) {
                        $result_search2 = true;
                   } else {
                        $result_search2 = false;
                   }
              }
            }
        }

        if(!$result_search || !$result_search2){
            return false;
        }else{
            return true;
        }
    }

    private function login_publicdata() {
        $post_data = array(
            'login_id' => "MySpaLive",
            'state_id' => "UID",
            'password' => "1987CHECK"
        );
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "https://login.publicdata.com/pdmain.php/logon/checkAccess?disp=XML");
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //Send CURL Request
        $result = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if(empty($result)){
            return true;
        }

        $xml = simplexml_load_string($result, "SimpleXMLElement", LIBXML_NOCDATA);

        if ($xml === false) {
            return true; // XML inválido
        }

        $json = json_encode($xml);

        $response_arr = json_decode($json, TRUE);

        // Verifica si hay atributo de error
        if (isset($response_arr['@attributes']['type']) && $response_arr['@attributes']['type'] === 'error') {
            $msg = isset($response_arr['pdheaders']['pdheader1']) ? $response_arr['pdheaders']['pdheader1'] : 'Unknown error';
            return true;
        }

        return $response_arr['user'];
    }

    public function validate_coordinates($chain, $zip){
        $latitude = 0;
        $longitude = 0;
        $mapsResponse = $this->get_coordinates($chain);                 
        if( $mapsResponse['status']=='OK' ) {
            $latitude  = isset($mapsResponse['results'][0]['geometry']['location']['lat']) ? $mapsResponse['results'][0]['geometry']['location']['lat'] : "";
            $longitude = isset($mapsResponse['results'][0]['geometry']['location']['lng']) ? $mapsResponse['results'][0]['geometry']['location']['lng'] : "";
        } else {            
            $zipResponse = $this->get_coordinates($zip . '');
            if( $zipResponse['status']=='OK' ) {
                $latitude  = isset($zipResponse['results'][0]['geometry']['location']['lat']) ? $zipResponse['results'][0]['geometry']['location']['lat'] : "";
                $longitude = isset($zipResponse['results'][0]['geometry']['location']['lng']) ? $zipResponse['results'][0]['geometry']['location']['lng'] : "";
            }                        
        }

        if( $latitude == 0 && $longitude == 0){
            require_once(ROOT . DS . 'vendor' . DS  . 'zipcodes' . DS . 'init.php');
            
            $data = isset(\zipcodes\Zipcodes::DATA[$zip]) ? \zipcodes\Zipcodes::DATA[$zip] : null;
            if ($data) {
                $latitude = $data['lat'];
                $longitude = $data['lng']; 
            } else {

                for($i = 1; $i <= 100; $i++) {
                     $nzip = intval($zip)+$i;
                     $bnzip = 5 - strlen(strval($nzip));
                     $rzip = $nzip;
                     if (strlen(strval($nzip)) < 5) {
                        for($c = 0; $c < $bnzip ;$c++) {
                            $rzip = '0' . $rzip;
                        }
                    }
                    
                     $data = isset(\zipcodes\Zipcodes::DATA[$rzip]) ? \zipcodes\Zipcodes::DATA[$rzip] : null;
                     if ($data) {
                        $latitude = $data['lat'];
                        $longitude = $data['lng'];
                        break;
                     }

                 }

            }
        }

        $coordinates = array(
            'latitude'  => $latitude,
            'longitude' => $longitude
        );
        return $coordinates;
    }

    public function get_coordinates($address){
        $gmap_key = "AIzaSyAjgOOZWRGxB_j9AZUKgoa0ohzS3GQ--nU";
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($address) . '&key=' . $gmap_key;
                    
        $responseData = file_get_contents($url);
        
        return json_decode($responseData, true);
    }

    public function save_licence(){
        $this->loadModel('SpaLiveV1.SysLicence');
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }

        $type = get('type', '');
        $number = get('number', '');
        $state = get('state', '');
        $start_date = get('start_date', '');
        $exp_date = get('exp_date', '');

        if(empty($type)){
            $type = 'MD';
            // $this->message('invalid type');
            // return;
        }
        if(empty($number)){
            $this->message('invalid licence number');
            return;
        }
        if(empty($state)){
            $this->message('invalid state');
            return;
        }
        if(empty($start_date)){
            $this->message('invalid date');
            return;
        }
        if(empty($exp_date)){
            $this->message('invalid date');
            return;
        }

        $arrSave = [
            'user_id' => USER_ID,
            'type' => $type,
            'number' => $number,
            'state' => $state,
            'start_date' => $start_date,
            'exp_date' => $exp_date,
        ];

        $licence_entity = $this->SysLicence->newEntity($arrSave);
        if(!$licence_entity->hasErrors()){
            if($this->SysLicence->save($licence_entity)){
                $this->success();
                $this->set('licence_id', $licence_entity->id);
                $this->notifySalesRepLicenceUpload($licence_entity);

                // Add a validation for iv_therapy licenses
                // If it comes from iv do not create a record
                /*$from_iv = get('from_iv', '');
                if (USER_TYPE == "injector" && $from_iv != true) {
                    $this->loadModel('SpaLiveV1.DataRequestGfeCi');

                    $requestItem = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => USER_ID])->first();
                    if(empty($requestItem)){

                         $request_save = [
                            'user_id' => USER_ID,
                            'created' => date('Y-m-d H:i:s'),
                            'status' => 'INIT',
                        ];

                        $entRequestSave = $this->DataRequestGfeCi->newEntity($request_save);
                        if(!$entRequestSave->hasErrors()){
                            if($this->DataRequestGfeCi->save($entRequestSave)){
                            }
                        }

                    }
                }*/
            }
        }
    }

    public function upload_licence_file(){
        $this->loadModel('SpaLiveV1.SysLicence');

        $arrTypes = ['Back','Front'];
        $type = get('type', '');
        if(!in_array($type, $arrTypes)){
            $this->message('Invalid model.');
            return;
        }

        $licenceItem = $this->SysLicence->find()->select(['SysLicence.id'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = SysLicence.user_id']
        ])
        ->where(['SysLicence.id' => get('licence_id','')])->first();
        if(empty($licenceItem)){
            $this->message('License does not exist.');
            return;
        }
        $licence = $this->SysLicence->find()->where(['SysLicence.id' => $licenceItem->id])->first();

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

        if($type == 'Back'){
            $licence->back = $_file_id;
        }else{
            $licence->front = $_file_id;
        }

        if($this->SysLicence->save($licence)){
            $this->success();
        }else{
            $this->message('Error in save file information');
        }
    }

    public function info_basic_course(){
        $token = get('token','');
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

        $default_discount = get('default_discount', 0);

        $this->loadModel('SpaLiveV1.DataSchoolRegister');
        
        $ent_schools = $this->DataSchoolRegister->find()->select(['DataSchoolRegister.id', 'DataSchoolRegister.uid', 'DataSchoolRegister.nameschool'])->where(['DataSchoolRegister.deleted' => 0])->all();

        if(Count($ent_schools) > 0) {
            $this->set('course_school', true);
        }else{
            $this->set('course_school', false);
        }

        if($default_discount){
            $this->set('discount', 300);
            $this->register_total = $this->register_total - 30000;
            $this->set('text_discount', "Today's Discount: -$300 (valid for today only!)");
        }

        $this->set('promo_code', 'ELITE300B');

        $training_amount = $this->register_total/100;
        $training_amount_not_cross = $training_amount - 300;
        $stripe_fee = ($this->register_total * 0.0315) / 100;
        $total = (($this->register_total * 0.0315) / 100) + ($this->register_total/100);
        // $total = ($this->register_total/100);
        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $deferred_payment = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type IN' => ['BASIC COURSE', 'LEVEL 1'], 'deleted' => 0])->first();
        if(!empty($deferred_payment)){
            if ($deferred_payment->source == 'stripe')
                $this->set('deferred', 1);
            else    
                $this->set('deferred', 0);
        }else{
            $this->set('deferred', 0);
        }

        $Partially = new \SpaLiveV1\Controller\PartiallyController();
        $Partially->initialize();
        $deferred_offer_id = isset($Partially->deferred_offers['basic']) && !empty($Partially->deferred_offers['basic']) ? $Partially->deferred_offers['basic'] : null;
        $this->set('installments_deferred', !empty($deferred_offer_id) ? true : false);

        $this->set('title_option', 'Neurotoxin Course - Level 1: $' . $training_amount);
        $this->set('training_amount', $training_amount);
        $this->set('training_amount_cross', $training_amount);
        $this->set('training_amount_not_cross', $training_amount_not_cross);
        $this->set('stripe_fee', number_format($stripe_fee, 2, '.', ''));
        $this->set('total', number_format($total, 2, '.', ''));
        $this->set('image', 'https://qtrypzzcjebvfcihiynt.supabase.co/storage/v1/object/public/base44-prod/public/698b5fc6911059f60909a4d0/f853bc3a9_Screenshot2026-02-12at115610AM.png');
        $this->set('text', '
            <h3>Course Description:</h3>
            <p>THE FUNDAMENTALS OF INJECTING BOTOX & OTHER BOTULINUM NEUROTOXINS + HANDS-ON TRAINING (FOREHEAD AND CROWS FEET) In this full-day course, participants will begin instruction with a didactic presentation from one a highly skilled and experienced injectors. The presentation will cover facial anatomy, the types of neurotoxins available, how to draw up and store product, give correct and thorough consultations, contraindications, injection techniques, and more! After a brief intermission for lunch, students will then begin the hands-on portion of the lesson. Everyone will be paired up to simulate injector-client interactions. Trainers will ensure that all students master the skills necessary for an effective appointment. Master injectors will double-check that everyone is marked up correctly and supervise the treatment performed.</p>');
        $this->set('seemore', 'https://blog.myspalive.com/certified-schools');
        //iv Therapy
        $this->set('title_optionIV', 'Apply to be IV Therapist');
        $this->set('totalIV', $this->register_total/100);

        $this->getSalesRep();

        $this->success();
    }

    private function getSalesRep() {
        // Assigned to
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $assignedRep = $this->DataAssignedToRegister->find()->select(['User.name','User.lname','User.phone'])->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
        ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0])->first();

        if (empty($assignedRep)) {
            $this->assignRep(false);
             $assignedRep = $this->DataAssignedToRegister->find()->select(['User.name','User.lname','User.phone'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
            ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0])->first();
        } 

        if (!empty($assignedRep)) {
            // $this->set('rep_name', $assignedRep['User']['name'] . ' ' . $assignedRep['User']['lname']);
            $this->set('rep_name', $assignedRep['User']['name']);
            $this->set('rep_phone', $assignedRep['User']['phone']);
            if($assignedRep['User']['phone'] != '') {
                $this->set('rep_phone_label', $this->formatPhoneNumber($assignedRep['User']['phone']));
            } else {
                $this->set('rep_phone_label', '00000000');
            }
        }
    }

    public function sales_rep_info() {
        $token = get('token','');
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
        //$support_number = USER_TYPE == 'patient' ? '8332434255' : '9727553038';
        $support_number = '9727553038';
        //$support_number_ext = USER_TYPE == 'patient' ? '0' : '5';
        $support_number_ext = '5';
        $this->set('support_number_label', $this->formatPhoneNumber($support_number));
        $support_number = '+1'.$support_number;
        $this->set('support_number', $support_number);
        $this->set('support_number_ext', $support_number_ext);
        if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){        
            $this->getSalesRep();
        }
        $this->success();
    }

    public function sales_rep_info_new_user() {
        
        $support_number = '9727553038';
        $support_number_ext = '5';
        $this->set('support_number_label', $this->formatPhoneNumber($support_number));
        $support_number = '+1'.$support_number;
        $this->set('support_number', $support_number);
        $this->set('support_number_ext', $support_number_ext);
        $this->success();
    }

    public function info_advanced_course(){
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }

        $this->getSalesRep();

        $default_discount = get('default_discount', 0);

        if($default_discount){
            $this->set('discount', 300);
            $this->training_advanced = $this->training_advanced - 30000;
            $this->set('text_discount', "Today's Discount: -$300 (valid for today only!)");
        }

        $this->set('promo_code', 'ELITE300A');

        $training_amount = $this->training_advanced/100;
        $training_amount_not_cross = $training_amount - 300;
        $stripe_fee = ($this->training_advanced * 0.0315) / 100;
        $total = (($this->training_advanced * 0.0315) / 100) + ($this->training_advanced/100);
        // $total = ($this->training_advanced/100);

        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $deferred_payment = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type' => 'LEVEL 2', 'deleted' => 0])->first();
        if(!empty($deferred_payment)){
            if ($deferred_payment->source == 'stripe')
                $this->set('deferred', 1);
            else    
                $this->set('deferred', 0);
        }else{
            $this->set('deferred', 0);
        }

        $Partially = new \SpaLiveV1\Controller\PartiallyController();
        $Partially->initialize();
        $deferred_offer_id = isset($Partially->deferred_offers['advanced']) && !empty($Partially->deferred_offers['advanced']) ? $Partially->deferred_offers['advanced'] : null;
        $this->set('installments_deferred', !empty($deferred_offer_id) ? true : false);

        $this->set('title_option', 'Level Two Advanced Botulinum Toxin Techniques: Enhancing Lips, Brows, and Chin $' . $training_amount);
        $this->set('training_amount', $training_amount);
        $this->set('training_amount_cross', $training_amount);
        $this->set('training_amount_not_cross', $training_amount_not_cross);
        $this->set('stripe_fee', number_format($stripe_fee, 2, '.', ''));
        $this->set('total', number_format($total, 2, '.', ''));
        $this->set('image', 'https://blog.myspalive.com/wp-content/uploads/2022/06/imagen_2022-06-16_135810439.png');
        $this->set('text', '
            <h3>Course Description:</h3>
            <p>Elevate your aesthetic practice with our Level 2 half-day workshop, designed for practitioners ready to advance their expertise in botulinum toxin applications. This next-level course focuses on sophisticated injection techniques for lip enhancement (Lip Flip), brow lifting, and chin treatments, integrating both theory and practical skills.</p>
            
            <h3>Morning Session: Advanced Theoretical Framework</h3>
            <ul>
                <li>Start with a recap of essential principles from Level One, setting a strong foundation.</li>
                <li>Delve into advanced treatment areas, including the Lip Flip, a three-point brow lift, and enhancements to the chin and DAO area, highlighting the latest strategies and additional units for optimal results.</li>
                <li>Explore the anatomical considerations and precision required for these specialized procedures.</li>
            </ul>
            
            <h3>Afternoon Session: Hands-On Mastery</h3>
            <ul>
                <li>Transition to the practical phase, where participants apply what they´ve learned in real-life scenarios, working in pairs for a comprehensive understanding of client interactions and treatment execution.</li>
                <li>Practice the three-point brow lift technique and advanced applications for the mentalis (chin) and DAO, ensuring a grasp of the nuanced adjustments needed for these areas.</li>
                <li>Receive direct supervision from master injectors who provide immediate feedback and ensure accurate technique and patient safety.</li>
            </ul>
            
            <p>This workshop is tailored for professionals who have completed our foundational course and are seeking to refine their skills in advanced botulinum toxin treatments. With an emphasis on complex areas, this course promises to enhance your capabilities, ensuring you can offer a broader range of aesthetic solutions with confidence and precision.</p>');

                    $this->set('seemore', 'https://blog.myspalive.com/certified-schools');
        $this->set('seemore', 'https://blog.myspalive.com/certified-schools');
        $this->success();
    }

    public function info_advanced_level_three(){
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }

        $this->getSalesRep();

        $this->set('title_option', 'Foundations in Aesthetic Filler Techniques');
        $this->set('total', $this->level_3_fillers/100);
        $this->set('training_amount_cross', $this->level_3_fillers/100);
        $this->set('image', 'https://blog.myspalive.com/wp-content/uploads/2024/01/level3filler.png');
        $this->set('text', '<p>This comprehensive one-day course, priced at $1500, is meticulously designed for licensed medical professionals seeking to master the art of cosmetic injections, specifically focusing on hyaluronic acid dermal fillers.</p>

        <p>The course covers an extensive curriculum, including an overview of anatomy and physiology related to skin and fillers, the history of dermal fillers, their FDA approvals, and their mechanisms of action. Emphasis is placed on managing client expectations, understanding contraindications, and navigating potential risks, ensuring participants are well-equipped to handle any scenario.</p>
        
        <p>What sets this course apart is its intensive, hands-on training approach. Participants will have the opportunity to practice injection techniques on live models, focusing on key treatment areas such as the lips, nasolabial folds, melomental folds, and fine lines. Techniques covered include bolus, serial puncture, retrograde and antegrade injections, linear threading, fanning, cross-hatching, and layering, utilizing Allegan Juvederm products.</p>
        
        <p>Additionally, the course delves into the critical aspects of patient consultation and facial assessment, guiding students through the process of achieving optimal aesthetic outcomes. Whether you´re looking to integrate dermal fillers into your practice or refine your existing skills, this course promises a rich learning experience!</p>');

        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $deferred_payment = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type' => 'LEVEL 3 MEDICAL', 'deleted' => 0])->first();
        if(!empty($deferred_payment)){
            $this->set('deferred', 1);
        }else{
            $this->set('deferred', 0);
        }

        $this->set('details', '');
        $this->set('seemore', 'https://blog.myspalive.com/certified-schools');
        $this->set('title_check_box', 'I certify that I am a RN or above');
        $this->success();
    }

    public function info_medical(){
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }

        $this->getSalesRep();

        $default_discount = get('default_discount', 0);

        if($default_discount){
            $this->set('discount', 300);
            $this->level_3_medical = $this->level_3_medical - 30000;
            $this->set('text_discount', "Today's Discount: -$300 (valid for today only!)");
        }

        $this->set('promo_code', 'ELITE300');
        
        $training_amount = $this->level_3_medical/100;
        $training_amount_not_cross = $training_amount - 300;
        $stripe_fee = ($this->level_3_medical * 0.0315) / 100;
        $total = (($this->level_3_medical * 0.0315) / 100) + ($this->level_3_medical/100);

        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $deferred_payment = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type' => 'LEVEL 3 FILLERS', 'deleted' => 0])->first();
        if(!empty($deferred_payment)){
            if ($deferred_payment->source == 'stripe')
                $this->set('deferred', 1);
            else    
                $this->set('deferred', 0);
        }else{
            $this->set('deferred', 0);
        }

        $Partially = new \SpaLiveV1\Controller\PartiallyController();
        $Partially->initialize();
        $deferred_offer_id = isset($Partially->deferred_offers['level_3']) && !empty($Partially->deferred_offers['level_3']) ? $Partially->deferred_offers['level_3'] : null;
        $this->set('installments_deferred', !empty($deferred_offer_id) ? true : false);

        $this->set('title_option', 'Level 3 Elite Techniques Course $' . $this->level_3_medical/100);
        $this->set('training_amount', $training_amount);
        $this->set('training_amount_cross', $training_amount);
        $this->set('training_amount_not_cross', $training_amount_not_cross);
        $this->set('stripe_fee', number_format($stripe_fee, 2, '.', ''));
        $this->set('total', number_format($total, 2, '.', ''));
        $this->set('image', 'https://blog.myspalive.com/wp-content/uploads/2024/01/level_3_medical.png');
        $this->set('text', "
            <p>This in-person, interactive program focuses on sophisticated,
                real-world injection methods for transformative facial enhancements.</p>
            <p>In this course, you'll gain hands-on experience in key areas:</p>
            <ul>
                <li><strong>Anterior Platysma Bands:</strong> Achieve face lifting and jawline contouring by mastering injections along
                    the platysma muscle, ensuring a detailed understanding of dosage and technique.</li>
                <li><strong>Nasalis (Bunny Lines):</strong> Learn precise methods to minimize dynamic wrinkles around the nose,
                    focusing on the accurate treatment of the nasalis muscle.</li>
                <li><strong>Masseter Muscle:</strong> Delve into jaw slimming procedures that create a 'Youthful Triangle,' enhancing
                    your skills in masseter injections for aesthetic jawline contouring.</li>
                <li><strong>Brow Lift Techniques:</strong> Understand the complex interplay of muscles in eyebrow movement and practice
                    targeted injection techniques for subtle yet effective brow lifts.</li>
            </ul>
            <p>With a mix of expert-led demonstrations and practical sessions, this course provides a deep dive into advanced facial
                injection techniques. You'll leave with enhanced skills, ready to offer your clients the latest facial aesthetic
                treatments.</p>");
        $this->set('details', '');
        $this->set('seemore', '');
        $this->success();
    }

    public function info_level_1_1(){
        $token = get('token', '');
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                return;
            }  
        }

        $this->getSalesRep();

        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $deferred_payment = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type' => 'LEVEL 1-1 NEUROTOXINS', 'deleted' => 0])->first();
        if(!empty($deferred_payment)){
            if ($deferred_payment->source == 'stripe')
                $this->set('deferred', 1);
            else    
                $this->set('deferred', 0);
        }else{
            $this->set('deferred', 0);
        }

        $Partially = new \SpaLiveV1\Controller\PartiallyController();
        $Partially->initialize();
        $deferred_offer_id = isset($Partially->deferred_offers['elite']) && !empty($Partially->deferred_offers['elite']) ? $Partially->deferred_offers['elite'] : null;
        $this->set('installments_deferred', !empty($deferred_offer_id) ? true : false);

        $this->set('title_option', 'ToxTune-Up Sessions $' . $this->level_1_1/100);
        $this->set('total', $this->level_1_1/100);
        $this->set('image', 'https://blog.myspalive.com/wp-content/uploads/2024/01/level_1_1.jpeg');
        $this->set('text', '
        <p>Participants who have completed our foundational courses are welcomed to join our specialized sessions, designed for a more focused and personalized learning experience. </p>
        <p>Held at our Prosper location, these gatherings are perfect for those seeking extra guidance or eager to explore specific areas of practice in greater depth. Limited to just four attendees, including our Director of Training, we guarantee personalized attention and instruction tailored to each participant\'s needs. </p>
        <p>With our ToxTune-Up Sessions we aim to create an intimate learning environment, ensuring you leave ready to skillfully apply facial injection techniques in your business. Benefit from live, hands-on patient interactions in a supportive setting, ideal for elevating your skills to new heights.</p>
        ');
        $this->set('details', '');
        $this->set('seemore', '');
        $this->success();
    }

    public function getReferred() {

        $this->loadModel('SpaLiveV1.DataSalesRepresentative');
        $array_result = array();
        $findUsers = $this->DataSalesRepresentative->find()->select(['SysUser.name','SysUser.lname','SysUser.uid'])->join([
                'SysUser' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUser.id = DataSalesRepresentative.user_id'],
                ])->where(['DataSalesRepresentative.deleted' => 0,'SysUser.deleted' => 0, 'SysUser.active' => 1])->all();
        foreach($findUsers as $row) {
            $array_result[] = array(
                'uid' => $row['SysUser']['uid'],
                'name' => $row['SysUser']['name'] . ' ' . $row['SysUser']['lname'],
            );
        }
        $this->set('data',$array_result);
        $this->success();   
    }

    public function setReferred() {
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

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativeRegister');

        $uid = get('uid','');

        $findUser = $this->SysUsers->find()->join([
                'DataSalesRepresentative' => ['table' => 'data_sales_representative', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataSalesRepresentative.user_id'],
                ])->where(['SysUsers.uid' => $uid,'SysUsers.deleted' => 0,'DataSalesRepresentative.deleted' => 0])->first();

        
        if (!empty($findUser)) {

            $findRep = $this->DataSalesRepresentativeRegister->find()->where(['DataSalesRepresentativeRegister.user_id' => USER_ID,'DataSalesRepresentativeRegister.representative_id' => $findUser->id,'DataSalesRepresentativeRegister.deleted' => 0])->first();
            if (!empty($findRep)) return;

            $array_save = array(
                'user_id' => USER_ID,
                'representative_id' => $findUser->id,
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s')
            );

            $c_entity = $this->DataSalesRepresentativeRegister->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataSalesRepresentativeRegister->save($c_entity)) {
                    $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                    $userEntity->steps = 'SELECTBASICCOURSE';
                    $userEntity->last_status_change = date('Y-m-d H:i:s');
                    $this->SysUsers->save($userEntity);
                    $this->success(); 
                }
            } 
        }
    }

    public function get_trainings_register(){

        $token = get('token','');
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
        $mint = get('mint','');  
        $this->loadModel('SpaLiveV1.CatTrainigs');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataCourses');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataRescheduleTrainings');
        $this->loadModel('SpaLiveV1.CatCoursesType');

        $now = date('Y-m-d H:i:s');
        
        $trainings_data = array();

        $t_level = get('level','');

        $conitnue_ot = false;
        $fillers_course = false;

        $fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city'];
        $fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";

        if(!empty($t_level)){
            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => $t_level, 'DataRescheduleTrainings.deleted' => 0])->first();

            if(!empty($data_reschedule) && $data_reschedule->reschedule_count >= 1){
                $this->set('reschedule_fee', true);
                $this->set('reschedule_text', 'I agree to allow the $50 rescheduling fee to be charged to my saved credit card.');
            } else {
                $this->set('reschedule_fee', false);
                $this->set('reschedule_text', '');
            }
        }
        
        if ($t_level == 'LEVEL 1') {

            // FIND AVAILABLE TRAININGS
           
            $_join = [
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
            if($mint==''){
                $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 1', 'CatTrainigs.mint <> 1','CatTrainigs.state_id' => USER_STATE];
            }else{
                $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 1', 'CatTrainigs.mint' => 1];
            }
            $tr_result = array();
            $trainingsavailable  = $this->CatTrainigs->find()->select($fields)
            ->join($_join)
            ->where($_where)->order(['CatTrainigs.scheduled' => 'ASC'])->toArray();
            foreach ($trainingsavailable as $row) {
                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) continue;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'JOIN',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }

            $trainings_data[] = array(
                'title' => 'Basic trainings',
                'data' => $tr_result,
            );
        } else if($t_level == 'LEVEL 2'){
            $ent_data_training = $this->DataTrainings->find()->select(['CatTrainigs.scheduled'])->join([
                'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

            
            $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city','data_training_id' => 'DataTrainigs.id'];
            $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
            
            $_join = [
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
            $_where = '';

            if(empty($ent_data_training)){
                if($mint=='')
                    $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 2', 'CatTrainigs.mint <>' => 1, 'CatTrainigs.state_id' => USER_STATE];
                else                    
                    $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 2', 'CatTrainigs.mint' => 1, 'CatTrainigs.state_id' => USER_STATE];
            } else {
                if($mint=='')
                    $_where = ['CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 2','CatTrainigs.scheduled >' => $ent_data_training['CatTrainigs']['scheduled'], 'CatTrainigs.mint <>' => 1, 'CatTrainigs.state_id' => USER_STATE];
                else
                    $_where = ['CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 2','CatTrainigs.scheduled >' => $ent_data_training['CatTrainigs']['scheduled'], 'CatTrainigs.mint' => 1, 'CatTrainigs.state_id' => USER_STATE];
            }

            $trainingsavailable  = $this->CatTrainigs->find()->select($fields)
            ->join($_join)
            ->where($_where)->order(['CatTrainigs.scheduled' => 'ASC'])->toArray();
            $tr_result = array();
            foreach ($trainingsavailable as $row) {
                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) continue;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'JOIN',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }

            $trainings_data[] = array(
                'title' => 'Advanced trainings',
                'data' => $tr_result,
            );
        } else if($t_level == 'LEVEL 3'){

            $ent_data_training = $this->DataTrainings->find()->select(['CatTrainigs.scheduled'])->join([
                'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

            $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city','data_training_id' => 'DataTrainigs.id'];
            $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
            
            $_join = [
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
            $_where = '';

            if(empty($ent_data_training)){
                if($mint=='')
                    $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 3 MEDICAL', 'CatTrainigs.mint <>' => 1, 'CatTrainings.state_id' => USER_STATE];
                else                    
                    $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 3 MEDICAL', 'CatTrainigs.mint' => 1, 'CatTrainings.state_id' => USER_STATE];
            } else {
                if($mint=='')
                    $_where = ['CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 3 MEDICAL','CatTrainigs.scheduled >' => $ent_data_training['CatTrainigs']['scheduled'], 'CatTrainigs.mint <>' => 1, 'CatTrainigs.state_id' => USER_STATE];
                else
                    $_where = ['CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 3 MEDICAL','CatTrainigs.scheduled >' => $ent_data_training['CatTrainigs']['scheduled'], 'CatTrainigs.mint' => 1, 'CatTrainigs.state_id' => USER_STATE];
            }

            $trainingsavailable  = $this->CatTrainigs->find()->select($fields)
            ->join($_join)
            ->where($_where)->order(['CatTrainigs.scheduled' => 'ASC'])->toArray();
            $tr_result = array();
            foreach ($trainingsavailable as $row) {
                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) continue;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'JOIN',
                    'address' => $address,
                    'level' => 'LEVEL 3'
                );
            }

            $trainings_data[] = array(
                'title' => 'Level 3',
                'data' => $tr_result,
            );
        } else if($t_level == 'Filler Foundations'){
            // FIND AVAILABLE TRAININGS
            $fillers_course = true;
            $_join = [
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];

            $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => 'LEVEL 3 FILLERS', 'CatTrainigs.mint <> 1','CatTrainigs.state_id' => USER_STATE];

            $tr_result = array();
            $trainingsavailable  = $this->CatTrainigs->find()->select($fields)
            ->join($_join)
            ->where($_where)->order(['CatTrainigs.scheduled' => 'ASC'])->toArray();
            foreach ($trainingsavailable as $row) {
                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) continue;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'JOIN',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }

            $trainings_data[] = array(
                'title' => 'Filler Foundations',
                'data' => $tr_result,
            );
        } else{

            $ot = get('other_treatments', 0);
            $course_type_id = get('course_type_id', 0);

            if($ot == 0){
                $this->message('No course selected.');
                return;
            }
            $__where = ['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1];
            
            if (!empty($course_type_id) && $course_type_id != 0){
                $__where['CatCoursesType.id'] = $course_type_id;
            }

            $ot_data = $this->CatCoursesType->find()->where($__where)->all();

            if(empty($ot_data)){
                $this->message('No course found.');
                return;
            }

            $t_level = '';
            $this->set('course_title', '');

            foreach($ot_data as $ot){
                $this->loadModel('SpaLiveV1.DataPayment');

                $payment = $this->DataPayment->find()->where(['id_from' => USER_ID,'type' => $ot->name_key, 'is_visible' => 1, 'payment <>' => ''])->first();

                if(!empty($payment)){

                    $__join = [
                        'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                    ];
                    
                    $_where_dynamic = [
                        'DataTrainigs.user_id' => USER_ID,
                        'DataTrainigs.deleted' => 0,
                        'CatTrainigs.deleted' => 0,
                        'CatTrainigs.level' => $ot->name_key
                    ];
                        
                    $dynamic_trainings = $this->CatTrainigs->find()
                        ->join($__join)
                        ->where($_where_dynamic)
                        ->first();
                    if (empty($dynamic_trainings)) {
                        $this->set('course_title', $ot->title);
                        $t_level = $ot->name_key;
                        break;
                    }
                }
            }
            
            $_join = [
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];

            $_where = ['CatTrainigs.scheduled >' => $now, 'CatTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => $t_level];
            
            $tr_result = array();
            $trainingsavailable  = $this->CatTrainigs->find()->select($fields)
            ->join($_join)
            ->where($_where)->order(['CatTrainigs.scheduled' => 'ASC'])->toArray();

            foreach ($trainingsavailable as $row) {
                $seats = $row['available_seats'] - $row['assistants'];
                if($seats <= 0) continue;

                $address = $row->address.', '.$row->city.', '.$row->State['abv'].' '.$row->zip;
                $tr_result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'scheduled'   => $row['scheduled']->i18nFormat('MM-dd-Y hh:mm a'),
                    'available_seats' => $seats,
                    'status' => 'JOIN',
                    'address' => $address,
                    'level' => $row['level'],
                );
            }

            $trainings_data[] = array(
                'title' => $t_level,
                'data' => $tr_result,
            );

            $conitnue_ot = true;

        }

        // validar si el usuario tiene algun otro curso agendado para poner boton de back en la app
        $has_back = false;
        $trainings_data_back = $this->DataTrainings->find() 
            ->join([
                'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id']
            ])
            ->where(['DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level IN' => array('LEVEL 1', 'LEVEL 3 FILLERS')])
        ->first();

        if(!empty($trainings_data_back)){
            $has_back = true;
        }else{
            $__where = ['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1];
            
            $ot_data = $this->CatCoursesType->find()->where($__where)->all();
            foreach($ot_data as $ot){
                $trainings_data_back = $this->DataTrainings->find()
                    ->join([
                        'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id']
                    ])
                    ->where(['DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0, 'CatTrainigs.deleted' => 0, 'CatTrainigs.level' => $ot->name_key])
                ->first();

                if(!empty($trainings_data_back)){
                    $has_back = true;
                    break;
                }
            }
        }
        // end boton back

        $advanced_pay = $this->DataPayment->find()->where(['id_from' => USER_ID,'type' => 'ADVANCED COURSE', 'is_visible' => 1, 'payment <>' => ''])->first();

        $show_advanced = true;
        if(empty($advanced_pay)){
            $show_advanced = false;
        }

        //find payed basic
        $_fields = ['CatTrainigs.id', 'CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'CatTrainigs.available_seats', 'CatTrainigs.level','State.name','State.abv','CatTrainigs.address','CatTrainigs.zip','CatTrainigs.city', 'data_training_id' => 'DataTrainigs.id', 'attended' => 'DataTrainigs.attended'];
        $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0)";
        $_fields['enrolled'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainigs.id AND DT.deleted = 0 AND DT.user_id = " . USER_ID . " )";
        $_join = [
                'DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id'],
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = CatTrainigs.state_id']
            ];
        $_where = ['DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0,
        'CatTrainigs.scheduled IS NOT NULL', 'CatTrainigs.level' => 'LEVEL 1'];

        $done_trainings = $this->CatTrainigs->find()->select($_fields)
        ->join($_join)
        ->where($_where)->order(['CatTrainigs.scheduled' => 'ASC'])->toArray();

        $school = $this->DataCourses->find()->where(['DataCourses.user_id' => USER_ID, 'DataCourses.deleted' => 0, 'DataCourses.status IN' => array('PENDING','DONE')])->first();
        //
        $show_basic_done = false;
        if($t_level == 'LEVEL 1'){
            $show_basic_done = true;
        }else {
            if(!empty($done_trainings) || !empty($school)){
                $show_basic_done = true;
            } else {
                if($conitnue_ot || $fillers_course){
                    $show_basic_done = true;
                } else {
                    $show_basic_done = false;
                }
            }
        }
        
        $this->set('has_back', $has_back);
        $this->set('done_trainings', $done_trainings);
        $this->set('show_basic_done', $show_basic_done);
        $this->set('data_trainings', $trainings_data);
        $this->set('show_advanced_purchase', $show_advanced);
        $this->success();
    }

    public function join_training(){
        $Main = new MainController();
        $this->loadModel('SpaLiveV1.DataTrainings');
        $token = get('token','');
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

        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.DataRescheduleTrainings');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.SysUsers');

        $join_delete = get('join_delete', 0);
        if($join_delete == 1){

            $this->DataTrainings->updateAll(['deleted' => 1], ['user_id' => USER_ID]);
        }

        $tid = get('training_id', 0);
        if($tid == 0){
            $this->message('Invalid training.');
            return;
        }

        $array_save_c = array(
            'user_id' => USER_ID,
            'training_id' => $tid,
            'deleted' => 0,
        );

        $fields = [
            'CatTrainings.id',
            'CatTrainings.title',
            'CatTrainings.scheduled',
            'CatTrainings.address',
            'CatTrainings.city',
            'CatTrainings.state_id',
            'CatTrainings.zip',
            'CatTrainings.level',    
            'CatTrainings.created',

            'attended' => 'DataTrainings.attended'
        ];
        $has_advanced_course = $this->CatTrainings->find('all', [
            'conditions' => [
                'DataTrainings.user_id' => USER_ID
            ],            
        ])
        ->select($fields)
        ->join([
            'table' => 'data_trainings',
            'alias' => 'DataTrainings',
            'type' => 'INNER',
            'conditions' => 'CatTrainings.id = DataTrainings.training_id'
        ])
        ->where([
            'DataTrainings.deleted' => 0,
            'CatTrainings.deleted' => 0,
            'CatTrainings.level' => 'LEVEL 2',
        ])->first();

        $has_advanced_course ?? [];

        if(!empty($has_advanced_course)){
            $scheduled_advanced = $has_advanced_course->scheduled->i18nFormat('yyyy-MM-dd');
        }

        $ent_data_training = $this->DataTrainings->find()->where(['DataTrainings.training_id' => $tid,'DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0])->first();
        
        if (!empty($ent_data_training)) {
            $this->message('User already in training.');
            return;
        }

        $ent_training = $this->CatTrainings->find()->where(['CatTrainings.id' => $tid])->first();
        if ($ent_training->level == 'LEVEL 2') {
            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => 'LEVEL 2', 'DataRescheduleTrainings.deleted' => 0])->first();
            
            if(!empty($data_reschedule)){
                $data_reschedule->reschedule_count ++;
                if($data_reschedule->reschedule_count >= 2){
                    $this->save_agreement(USER_ID, USER_NAME . ' ' . USER_LNAME, '56we45vt6w-reg635s-d486w-fe65r156b', 0, array('[CLASS_NAME]' => 'Advanced training'));
                    $Payments = new PaymentsController();
                    $pay = $Payments->payment_intent_reschedule_fee();
                    if(!$pay['res_flag']){
                        $this->message($pay['error']);
                        return;
                    }
                }
                $this->DataRescheduleTrainings->save($data_reschedule);
            } else{
                $array_res_save = array(
                    'user_id' => USER_ID,
                    'reschedule_count' => 0,
                    'level_training' => 'LEVEL 2',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                );

                $resentityresc = $this->DataRescheduleTrainings->newEntity($array_res_save);
                $this->DataRescheduleTrainings->save($resentityresc);
            }
        } else if($ent_training->level == 'LEVEL 1'){
            $data_reschedule = $this->DataRescheduleTrainings->find()
            ->where(['DataRescheduleTrainings.user_id' => USER_ID, 'DataRescheduleTrainings.level_training' => 'LEVEL 1', 'DataRescheduleTrainings.deleted' => 0])->first();
            
            if(!empty($data_reschedule)){
                $data_reschedule->reschedule_count ++;
                if($data_reschedule->reschedule_count >= 2){
                    $this->save_agreement(USER_ID, USER_NAME . ' ' . USER_LNAME, '56we45vt6w-reg635s-d486w-fe65r156b', 0, array('[CLASS_NAME]' => 'Basic training'));
                    $Payments = new PaymentsController();
                    $pay = $Payments->payment_intent_reschedule_fee();
                    if(!$pay['res_flag']){
                        $this->message($pay['error']);
                        return;
                    }
                }
                $this->DataRescheduleTrainings->save($data_reschedule);
            } else{
                $array_res_save = array(
                    'user_id' => USER_ID,
                    'reschedule_count' => 0,
                    'level_training' => 'LEVEL 1',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                );
                $entity_res_save = $this->DataTrainings->newEntity($array_res_save);
                $this->DataRescheduleTrainings->save($entity_res_save);
            }
        }

        $level_training = $this->CatTrainings->find()
        ->where(['CatTrainings.id' => $tid, 'CatTrainings.deleted' => 0])->first();

        if(!empty($level_training)){
            $training = $this->DataTrainings->find()
            ->join([
                'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id']
            ])
            ->where(
                ['CatTrainings.level' => $level_training->level, 
                 'DataTrainings.user_id' => USER_ID ,
                 'CatTrainings.deleted' => 0, 
                 'DataTrainings.deleted' => 0, 
                 'DataTrainings.attended' => 0])->last();

            if(!empty($training)){
                $this->DataTrainings->updateAll(['deleted' => 1], ['id' => $training->id, 'user_id' => USER_ID]);
            }
        }

        $cpc_entity = $this->DataTrainings->newEntity($array_save_c);
        if(!$cpc_entity->hasErrors()){
            $this->DataTrainings->save($cpc_entity);
            $this->success();

            $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();

            $ent_scheduled = $ent_training->scheduled->i18nFormat('yyyy-MM-dd');

            if (!empty($ent_training)) {
                $temp_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.deleted' => 0])->first();
                
                $userEntity->last_status_change = date('Y-m-d H:i:s');

                if ($ent_training->level == 'LEVEL 2') {

                    /* if($temp_user->steps != 'HOME' && $temp_user->steps != 'WAITINGSCHOOLAPPROVAL'){
                        $step = 'MATERIALS';
                    }else{
                        $step = $temp_user->steps;
                    }

                    $userEntity->steps = $step; */

                    $this->SysUsers->save($userEntity);
                    $level2_id = 44; // FIEX ID OF Advanced training
                    $ent_purchases =$this->DataPurchases->find()
                    ->join([
                        'DataPurchasesDetail' => ['table' => 'data_purchases_detail', 'type' => 'INNER', 'conditions' => 'DataPurchasesDetail.purchase_id = DataPurchases.id']
                    ])
                    ->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => '','DataPurchasesDetail.product_id' => $level2_id,'DataPurchases.deleted' => 0])->last();
                    if (!empty($ent_purchases)) {
                        $ent_purchases->status = 'ATTENDING TO CLASS';
                        $this->DataPurchases->save($ent_purchases);
                    }
                }
                else if($ent_training->level == 'LEVEL 1'){

                    if($temp_user->steps != 'HOME' /*&& $temp_user->steps != 'WAITINGSCHOOLAPPROVAL'*/){
                        $step = 'MATERIALS';
                    }else{
                        $step = $temp_user->steps;
                    }
                    
                    $userEntity->steps = $step;

                    if(!empty($has_advanced_course) && ($ent_scheduled > $scheduled_advanced)){
                        $this->set('warning', true);
                        $this->set('warning_message', 'This date needs to be earlier than the other course\'s date. If you accept to change it, you\'ll need to reschedule your other course as well.');
                    } else {
                        $this->set('warning', false);
                    }

                    $this->SysUsers->save($userEntity);
                    $this->loadModel('SpaLiveV1.DataInjectorRegistered');
                    $exist = $this->DataInjectorRegistered->find()->where([
                        'DataInjectorRegistered.user_id' => USER_ID ,
                        'DataInjectorRegistered.deleted' => 0 ,
                    ])->first();

                    $array_s = array(
                        'user_id' => USER_ID,
                        'date_start' => date('Y-m-d H:i:s'),
                        'type' => 'NEUROTOXIN',
                        'deleted' => 0
                    );
                    if(empty($exist)){              
                        $entity = $this->DataInjectorRegistered->newEntity($array_s);
                        if(!$entity->hasErrors()){
                            $this->DataInjectorRegistered->save($entity);
                            $this->log(__LINE__ . ' DataInjectorRegistered ' );
                        }
                    }
                    
                    $Main->notify_devices('EMAIL_AFTER_JOIN_TRAINING',array(USER_ID),false,true);
                } else{
                    if($temp_user->steps != 'HOME' /*&& $temp_user->steps != 'WAITINGSCHOOLAPPROVAL'*/){
                        $step = 'MATERIALS';
                    }

                    $userEntity->steps = $step;

                    $this->SysUsers->save($userEntity);
                    $this->loadModel('SpaLiveV1.DataInjectorRegistered');
                    $exist = $this->DataInjectorRegistered->find()->where([
                        'DataInjectorRegistered.user_id' => USER_ID ,
                        'DataInjectorRegistered.deleted' => 0 ,
                    ])->first();

                    $array_s = array(
                        'user_id' => USER_ID,
                        'date_start' => date('Y-m-d H:i:s'),
                        'type' => 'OTHER_TREATMENTS',
                        'deleted' => 0
                    );
                    if(empty($exist)){              
                        $entity = $this->DataInjectorRegistered->newEntity($array_s);
                        if(!$entity->hasErrors()){
                            $this->DataInjectorRegistered->save($entity);
                            $this->log(__LINE__ . ' DataInjectorRegistered ' );
                        }
                    }
                    
                    //$Main->notify_devices('EMAIL_AFTER_JOIN_TRAINING',array(USER_ID),false,true);
                }
            }

            // Validando si el training tiene shared seats
            if($ent_training->shared_seats == 1){
                $array_save_am = array(
                    'user_id' => USER_ID,
                    'training_id' => $ent_training->shared_am_course,
                    'deleted' => 0,
                    'attended' => 0,
                    'deleted_by' => null,
                    'deleted_date' => null,
                );

                $entity_am = $this->DataTrainings->newEntity($array_save_am);
                $this->DataTrainings->save($entity_am);

                $array_save_pm = array(
                    'user_id' => USER_ID,
                    'training_id' => $ent_training->shared_pm_course,
                    'deleted' => 0,
                    'attended' => 0,
                    'deleted_by' => null,
                    'deleted_date' => null,
                );

                $entity_pm = $this->DataTrainings->newEntity($array_save_pm);
                $this->DataTrainings->save($entity_pm);

                $this->success();
            }else{
                //$fields = ['CatTrainings.shared_am_course', 'CatTrainings.shared_pm_course'];
                //$fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainings.id AND DT.deleted = 0)";
                //$fields['assistants_am'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainings.shared_am_course AND DT.deleted = 0)";
                //$fields['assistants_pm'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainings.shared_pm_course AND DT.deleted = 0)";
                $shared_seat = $this->CatTrainings->find()
                //->select($fields)
                ->where([
                    'OR' => [
                        'CatTrainings.shared_am_course' => $tid,
                        'CatTrainings.shared_pm_course' => $tid
                    ],
                    'CatTrainings.deleted' => 0
                ])->first();

                if(!empty($shared_seat)){
                    $this->CatTrainings->updateAll(
                        ['available_seats' => $shared_seat->available_seats - 1],
                        ['id' => $shared_seat->id]
                    );

                    $this->success();
                }
            }
        }
    }

    private function save_agreement($user_id, $str_sign, $str_uid, $file_id, $constants = array()){
        $this->loadModel('SpaLiveV1.Agreement');
        $ent_agreement = $this->Agreement->find()->where(
                ['Agreement.uid' => $str_uid,
                'Agreement.deleted' => 0]
            )->first();
        if (empty($ent_agreement)) {
            $this->message('Invalid agreement.');
            return;
        }

        foreach($constants as $key => $value){
            $content = str_replace($key, $value, $ent_agreement->content);
        }

        $this->loadModel('SpaLiveV1.DataAgreement');

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => $user_id,
            'sign' => $str_sign,
            'agreement_uid' => $str_uid,
            'file_id' => $file_id,
            'content' => $content,
            'created' => date('Y-m-d H:i:s'),
        );

        $entity = $this->DataAgreement->newEntity($array_save);
        if(!$entity->hasErrors()){
            $this->DataAgreement->save($entity);
        }
    }

    public function cancel_training(){
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatTrainings');
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('token','');
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

        $tid = get('training_id', 0);
        if($tid == 0){
            $this->message('Invalid training.');
            return;
        }

        $ent_training = $this->CatTrainings->find()->where(['CatTrainings.id' => $tid])->first();

        if (empty($ent_training)) {
            $this->message('Invalid training.');
            return;
        }

        if($ent_training->level == 'LEVEL 1'){
            $this->SysUsers->updateAll(
                [
                    'steps'   => 'SELECTBASICCOURSE',
                    'last_status_change' => date('Y-m-d H:i:s'),
                ],
                ['id' =>  USER_ID]
            );
        }

        $this->DataTrainings->updateAll(
            [
                'deleted'   => 1
            ],
            ['user_id' =>  USER_ID, 'training_id' => $tid]
        );

        // validacion para cursos compartidos
        if($ent_training->shared_seats == 1){
            $this->DataTrainings->updateAll(
                ['deleted' => 1],
                ['user_id' => USER_ID, 'training_id' => $ent_training->shared_am_course]
            );

            $this->DataTrainings->updateAll(
                ['deleted' => 1],
                ['user_id' => USER_ID, 'training_id' => $ent_training->shared_pm_course]
            );
        }else{

            $course = $this->CatTrainings->find()
            ->where(['OR' => [
                'CatTrainings.shared_am_course' => $tid,
                'CatTrainings.shared_pm_course' => $tid
            ], 'deleted' => 0])
            ->first();

            if(!empty($course)){
                $_fields = ['available_seats', 'models_per_class'];
                $_fields['assistants'] = "(SELECT COUNT(id) from data_trainings DT WHERE DT.training_id = CatTrainings.id AND DT.deleted = 0)"; // assistants
                $amCourse = $this->CatTrainings->find()
                    ->select($_fields)
                    ->where(['id' => $course->shared_am_course, 'deleted' => 0])
                    ->first();
                    
                $pmCourse = $this->CatTrainings->find()
                    ->select($_fields)
                    ->where(['id' => $course->shared_pm_course, 'deleted' => 0])
                    ->first();

                if ($amCourse && $pmCourse) {
                    // Calcular los valores combinados
                    $amCourseAvailable = $amCourse->available_seats - $amCourse->assistants;
                    $pmCourseAvailable = $pmCourse->available_seats - $pmCourse->assistants;
                    $combinedSeats = $amCourseAvailable < $pmCourseAvailable ? $amCourseAvailable : $pmCourseAvailable;

                    $this->CatTrainings->updateAll(
                        ['available_seats' => $combinedSeats],
                        ['id' => $course->id]
                    );

                } else {
                    $this->message('One or both courses not found');
                }
            }
        }

        $this->success();

        $this->loadModel('SpaLiveV1.DataPurchases');
        $level2_id = 44; // FIEX ID OF Advanced training
        $ent_purchases =$this->DataPurchases->find()
        ->join([
            'DataPurchasesDetail' => ['table' => 'data_purchases_detail', 'type' => 'INNER', 'conditions' => 'DataPurchasesDetail.purchase_id = DataPurchases.id']
        ])
        ->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => '','DataPurchasesDetail.product_id' => $level2_id,'DataPurchases.deleted' => 0])->last();
        if (!empty($ent_purchases)) {
            $ent_purchases->status = 'NEW';
            $this->DataPurchases->save($ent_purchases);
        }
    }

    public function get_step_validate(){
        $token = get('token','');
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
        $this->loadModel("SpaLiveV1.SysUsers");        

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        if (USER_ID == 36703) {
            $this->loadModel("SpaLiveV1.DataSubscriptions");
            $ent_subscriptions_cancelled = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0])->all();
            $this->set('subssubs', Count($ent_subscriptions_cancelled));
            if(Count($ent_subscriptions_cancelled) > 0){
                $this->SysUsers->updateAll(
                ['steps' => 'HOME'], 
                ['id' =>  USER_ID]
            );
            $this->set('step', 'HOME');
            $this->success();
            return;
            }
        }
        
        if($ent_user->spa_work == 1){
            $this->SysUsers->updateAll(
                ['steps' => 'HOME'], 
                ['id' =>  USER_ID]
            );
            $this->set('step', $ent_user->steps);
            $this->success();
            return;
        }

        $this->loadModel("SpaLiveV1.DataPayment");
        $this->loadModel("SpaLiveV1.DataCertificates");
        $this->loadModel("SpaLiveV1.DataTrainings");
        //w9 step added
        if($ent_user->steps == 'FILLERSAPPROVED'){            
            $this->SysUsers->updateAll(
                ['steps' => 'FILLERSAPPROVED'], 
                ['id' =>  USER_ID]
            );            
        }
        if($ent_user->steps == 'SCHOOLVIDEOWATCHED' || $ent_user->steps == 'IVTHERAPYVIDEOWATCHED' ||  $ent_user->steps == 'HOWITWORKS' || $ent_user->steps == 'STARTPROVIDINGTREATMENTS' || $ent_user->steps == 'CERTIFICATESCHOOLDENIED' || 
           $ent_user->steps == 'CERTIFICATESCHOOLAPPROVED' || $ent_user->steps == 'CODEVERIFICATION' || $ent_user->steps == 'WAITINGSCHOOLAPPROVAL' || $ent_user->steps == 'STATENOTAVAILABLE' || $ent_user->steps == 'TRACERS' || $ent_user->steps == 'DENIED' || 
           $ent_user->steps == 'MSLSCHOOLSUBSCRIPTION' || $ent_user->steps == 'MDSCHOOLSUBSCRIPTION' || $ent_user->steps == 'TREATMENTSETTINGS' || $ent_user->steps == 'STRIPEACCOUNT' || $ent_user->steps == 'WAITINGIVAPPROVAL' || $ent_user->steps == 'MSLIVTSUBSCRIPTION' || 
           $ent_user->steps == 'MDIVTSUBSCRIPTION' || $ent_user->steps == 'MSL+IVTSUBSCRIPTION' || $ent_user->steps == 'MD+IVTSUBSCRIPTION' || $ent_user->steps == 'CPR' || $ent_user->steps == 'W9' || $ent_user->steps == 'APPIVAPPROVED' || $ent_user->steps == 'APPIVDENIED' || 
           $ent_user->steps == 'WAITINGFILLERSAPPROVAL' || $ent_user->steps == 'FILLERSAPPROVED' || $ent_user->steps == 'SUBSCRIPTIONMSLFILLERS' || $ent_user->steps == 'SUBSCRIPTIONMDFILLERS' || $ent_user->steps == 'SHIPPINGADDRESS' || $ent_user->steps == 'LICENCEOT' || $ent_user->steps == 'SELECTFILLERS'){
            if($ent_user->steps == 'WAITINGIVAPPROVAL' || $ent_user->steps == 'WAITINGSCHOOLAPPROVAL' || $ent_user->steps == 'CERTIFICATESCHOOLAPPROVED' || $ent_user->steps == 'CERTIFICATESCHOOLDENIED' ){

                //check user type
                //check if cpr
                if($ent_user->steps == 'W9'){
                    $this->loadModel("SpaLiveV1.DataWN");
                    //check datalicecnces cpr
                    $wnlicense = $this->DataWN->find()->where(['DataWN.user_id' => USER_ID])->first();
                    $this->set('w9', $wnlicense);
                    if(!empty($wnlicense)){
                        $ent_user->steps = 'CPR';
                        $this->SysUsers->updateAll(
                            ['steps' => 'CPR'], 
                            ['id' =>  USER_ID]
                        );
                    }


                }

                if($ent_user->steps == 'CPR'){
                    $this->loadModel("SpaLiveV1.DataUserCprLicence");
                    //check datalicecnces cpr
                    $cprlicense = $this->DataUserCprLicence->find()->where(['DataUserCprLicence.user_id' => USER_ID])->first();
                    $this->set('cpr', $cprlicense);

                    if(!empty($cprlicense)){
                        $ent_user->steps = 'HOME';
                        $this->SysUsers->updateAll(
                            ['steps' => 'HOME'], 
                            ['id' =>  USER_ID]
                        );
                    }
                }

            }

            $this->set('step', $ent_user->steps);
            $this->success();
            return;
        }
        
        $this->loadModel("SpaLiveV1.DataSubscriptions"); 
        
        $this->loadModel("SpaLiveV1.CatStates"); 
        $now = date('Y-m-d H:i:s');
        $nowDay = date('Y-m-d');

        $ent_state = $this->CatStates->find()->where(['CatStates.id' => $user['user_state'], 'CatStates.deleted' => 0])->first();
        if ($ent_state->enabled == 0) {
            $this->SysUsers->updateAll(
                ['steps' => 'STATENOTAVAILABLE'], 
                ['id' =>  USER_ID]
            );
            $this->set('step', 'STATENOTAVAILABLE');
            $this->success();
            return;
        }
        
        $ent_payments_basic = $this->DataPayment->find()->select(['DataPayment.id', 'Refund.id','DataPayment.total','DataPayment.uid','DataPayment.refund_id'])
        ->join([
            'Refund' => ['table' => 'data_payment', 'type' => 'LEFT', 'conditions' => 'Refund.uid = DataPayment.uid AND Refund.type = "REFUND" AND Refund.total = DataPayment.total'],
        ])
        ->where(['DataPayment.id_from' => USER_ID, 'DataPayment.type IN' => array('BASIC COURSE', 'CI REGISTER'), 'DataPayment.payment <>' => ''])->last();
        
        $user_training = $this->DataTrainings->find()->select(['CatTrainigs.scheduled', 'CatTrainigs.created', 'DataTrainings.attended'])->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            ])->where(['CatTrainigs.level' => 'LEVEL 1','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();

            
        $entCertificate = $this->DataCertificates->find()
            ->join([
                'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataConsultation.patient_id']
            ])
            ->where(['DataConsultation.patient_id' => USER_ID,'DataConsultation.deleted' => 0, 'DataCertificates.deleted' => 0])->first();

        $user_training_advanced = $this->DataTrainings->find()->select(['CatTrainigs.scheduled', 'CatTrainigs.created', 'DataTrainings.attended'])->join([
            'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
            ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => USER_ID,'DataTrainings.deleted' => 0])->first();
        
        
        /*************** CHECK COURSES ***************/
        $this->loadModel("SpaLiveV1.DataCourses");
        $this->loadModel("SpaLiveV1.CatCourses");

        $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS', 'OTHER TREATMENTS', 'FILLERS'),'DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

        $user_course_school_advanced = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS', 'OTHER TREATMENTS', 'FILLERS'),'DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();


        /*************** CHECK IV THERAPY ***************/
        $Therapy = new TherapyController();
        $therapy_status = $Therapy->get_status_for_login(USER_ID);
        /*************** CHECK FILLERS ***************/
        $FC = new FillersController();
        $valid_fillers = $FC->valid_step_application(USER_ID);
        /*************** CHECK OT COURSE ***************/
        // Parchase ot course
        $ot_course = $this->get_ot_course(USER_ID);

        /*if($ot_course >= 2){
            $this->SysUsers->updateAll(
                ['steps' => 'MATERIALS'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'MATERIALS');
            return;
        }*/

        // Parchase basic course
        if((empty($ent_payments_basic) && empty($user_course_basic)&&$therapy_status=="HAS NOT APPLIED")&& empty($user_course_school_advanced) && !$valid_fillers && $ot_course < 1){
            $this->SysUsers->updateAll(
                ['steps' => 'BASICCOURSE'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'BASICCOURSE');
            return;
        } else if(!empty($ent_payments_basic) && empty($user_course_basic)&&empty($user_course_school_advanced)&&$therapy_status=="HAS NOT APPLIED"&& !$valid_fillers && $ot_course < 1){
            $twice_pay_refund = $this->DataPayment->find()
            ->where(['DataPayment.uid' => $ent_payments_basic->uid, 'DataPayment.is_visible' => 1,'DataPayment.id_to' => USER_ID,'DataPayment.id' => $ent_payments_basic->refund_id])->last();
            
            if (isset($twice_pay_refund)){
                if($twice_pay_refund->type == 'REFUND' && $twice_pay_refund->total == $ent_payments_basic->total){
                    $this->SysUsers->updateAll(
                        ['steps' => 'BASICCOURSE'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'BASICCOURSE');
                    return;
                }else if ($twice_pay_refund->type == 'BASIC COURSE' && $twice_pay_refund->refund_id  == 0 && empty($user_training)){
                    $this->success();
                    $this->set('step', 'BASICCOURSE');
                    return;
                }else{
                    $this->set('step', 'BASICCOURSE');
                    return;
                }             
            }else if(!empty($ent_payments_basic) && empty($user_training)&&empty($user_course_basic)&&empty($user_course_school_advanced)&&$therapy_status=="HAS NOT APPLIED" && !$valid_fillers && $ot_course < 1){ // the injector paid for the course but, he has not selected  the course yet, and a refund has not been found
                $this->SysUsers->updateAll(
                    ['steps' => 'SELECTBASICCOURSE'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'SELECTBASICCOURSE');
                return;
            }                    
        }
        if(empty($user_training)&&empty($user_course_basic)&&empty($user_course_school_advanced)&&$therapy_status=="HAS NOT APPLIED" && !$valid_fillers && $ot_course < 1){
            // check exists payments
            if(empty($ent_payments_basic)){
                $this->SysUsers->updateAll(
                    ['steps' => 'BASICCOURSE'], 
                    ['id' =>  USER_ID]
                );
                $this->set('step', 'BASICCOURSE');
                $this->success();
                return;
            }       
            $this->success();
            $this->SysUsers->updateAll(
                ['steps' => 'SELECTBASICCOURSE'], 
                ['id' =>  USER_ID]
            );
            $this->set('step', 'SELECTBASICCOURSE');
            return;
        } 

        $sub_active = 0;

        if(empty($user_course_basic)&&empty($user_course_school_advanced)&&$therapy_status=="HAS NOT APPLIED"&& !$valid_fillers && $ot_course < 1){
            if ($ent_payments_basic['Refund'] != null && $ent_payments_basic['Refund']['id'] != null) { // This add because a user with refund dont return to pay basic course
                $this->SysUsers->updateAll(
                    ['steps' => 'BASICCOURSE'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'BASICCOURSE');
                $this->set('val', '0');
                return;
            } /*else if(date('Y-m-d', strtotime($user_training['CatTrainigs']['scheduled'])) > "2023-02-03" && date('Y-m-d', strtotime($user_training['CatTrainigs']['scheduled'])) >= $nowDay && empty($entCertificate)){
                $this->SysUsers->updateAll(
                    ['steps' => 'MATERIALS'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'MATERIALS');
                $this->set('val', '1');
                return;
            }*/ else if($user_training->attended != 1) {
                $this->SysUsers->updateAll(
                    ['steps' => 'MATERIALS'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'MATERIALS');
                $this->set('val', '2');
                return;
            }
            
            if(!empty($user_training_advanced) && $user_training_advanced->attended != 1 && $user_training->attended != 1){
                $this->SysUsers->updateAll(
                    ['steps' => 'MATERIALS'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'MATERIALS');
                $this->set('val', '3');
                return;
            }

            $ent_subscriptions_cancelled = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0])->all();
            $sub_active = 0;

            if(Count($ent_subscriptions_cancelled) > 0){
                foreach ($ent_subscriptions_cancelled as $key => $value) {
                    if($value->status == 'ACTIVE'){
                        $sub_active ++;
                    }
                }

                if($sub_active < 2){
                    $step = "SUBSCRIPTIONPENDING";

                    if($therapy_status=="ACCEPTED"){
                        $step = "STARTPROVIDINGTREATMENTS";
                    }

                    $this->SysUsers->updateAll(
                        ['steps' => $step], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', $step);  
                    return;
                }
            }else{
                $step = "SUBSCRIPTIONPENDING";

                if($therapy_status=="ACCEPTED"){
                    $step = "STARTPROVIDINGTREATMENTS";
                }

                $this->SysUsers->updateAll(
                    ['steps' => $step], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', $step);  
                return;
            }
        }else{

            
            $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0, 'DataSubscriptions.status' => 'ACTIVE'])->all();
            $sub_active = 0;

             $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                ])->where(['CatCourses.type IN' => array('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS', 'OTHER TREATMENTS', 'FILLERS'),'DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE'])->first();

         
            $sub_active = Count($ent_subscriptions);

            if(Count($ent_subscriptions) > 0 && (!empty($user_course_basic) && !empty($user_course_school_advanced)) && $ent_user->steps != 'HOME'){

                if ($ent_user->steps == 'SUBSCRIPTIONPENDING') {
                    $this->set('step', 'SUBSCRIPTIONPENDING');
                    $this->success();
                    return;
                }
                
                $step = 'SCHOOLVIDEOWATCHED';

                $ServicesHelper = new ServicesHelper(USER_ID);
                $apply_for_fillers = $ServicesHelper->applied_fillers();
                $this->set('apply_for_fillers', $apply_for_fillers);

                if($therapy_status == "ACCEPTED"){
                        $step = "STARTPROVIDINGTREATMENTS";
                }

                $this->SysUsers->updateAll(
                    ['steps' => $step], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', $step);  
                return;

                // if($sub_active<1){
                //     $step = "MSLSCHOOLSUBSCRIPTION";

                //     if($therapy_status=="ACCEPTED"){
                //         $step = "STARTPROVIDINGTREATMENTS";
                //     }

                //     $this->SysUsers->updateAll(
                //         ['steps' => $step], 
                //         ['id' =>  USER_ID]
                //     );
                //     $this->success();
                //     $this->set('step', $step);  
                //     return;
                // }else if($sub_active<2){
                //     $ServicesHelper = new ServicesHelper(USER_ID);
                //     $apply_for_fillers = $ServicesHelper->applied_fillers();
                //     $this->set('apply_for_fillers', $apply_for_fillers);
                //     $step = "MDSCHOOLSUBSCRIPTION";
                    
                //     if($therapy_status=="ACCEPTED"){
                //         $step = "STARTPROVIDINGTREATMENTS";
                //     }else if($apply_for_fillers){
                //         $step = "SUBSCRIPTIONMDFILLERS";
                //     }

                //     $this->SysUsers->updateAll(
                //         ['steps' => $step], 
                //         ['id' =>  USER_ID]
                //     );
                //     $this->success();
                //     $this->set('step', $step);  
                //     return;
                // }
            } else {
                $ent_subscriptions_cancelled = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status' => 'CANCELLED'])->all();

                if(Count($ent_subscriptions_cancelled) > 0 && $sub_active == 0){
                    $this->SysUsers->updateAll(
                        ['steps' => 'SUBSCRIPTIONPENDING'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'SUBSCRIPTIONPENDING');  
                    return;
                }

                $ent_subscriptions_hold = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status' => 'HOLD'])->all();

                if(Count($ent_subscriptions_hold) > 0){
                    $this->SysUsers->updateAll(
                        ['steps' => 'SUBSCRIPTIONPENDING'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'SUBSCRIPTIONPENDING');
                    return;
                }

                $ent_subscriptions_trial = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status' => 'TRIALONHOLD'])->all();
                
                if(Count($ent_subscriptions_trial) > 0){
                    $this->SysUsers->updateAll(
                        ['steps' => 'SUBSCRIPTIONPENDING'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'SUBSCRIPTIONPENDING');
                    return;
                }

                $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status' => 'ACTIVE'])->all();

                if(Count($ent_subscriptions) < 1){
                    $this->SysUsers->updateAll(
                        ['steps' => 'SUBSCRIPTIONPENDING'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'SUBSCRIPTIONPENDING');
                    return;
                }
            }
        }


        $this->loadModel('SpaLiveV1.DataWN');
        $w9 = $this->DataWN->find()->where(['DataWN.user_id' => USER_ID])->first();
        if($sub_active > 0 && empty($w9)){
            $this->SysUsers->updateAll(
                ['steps' => 'W9'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'W9');
            return;
        }

        if($sub_active > 0 && !empty($w9)){
            $this->SysUsers->updateAll(
                ['steps' => 'HOME'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'HOME');
            return;
        }

        // Validate Settings

        $array_response = array();
        $arr_pro = array();

        if(USER_TYPE == 'patient'){
            $this->success();
            $this->set('step', $ent_user->steps);
            return;
        }
        $model = USER_TYPE == 'gfe+ci' ? 'injector' : USER_TYPE;

        $array_response['radius'] = $ent_user->radius;

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $lm_entity = $this->DataScheduleModel->find()->where(['DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.deleted' => 0, 'DataScheduleModel.days LIKE' => '%,%', 'DataScheduleModel.model' => $model])->first();

        if (empty($lm_entity)) {
            $str_days = "";
            $int_start = 9;
            $int_end = 15;
        } else {
            $str_days = $lm_entity->days;
            $int_start = $lm_entity->time_start;
            $int_end = $lm_entity->time_end;
        }

        $array_response['days'] = $str_days;
        $array_response['time_start'] = $int_start;
        $array_response['time_end'] = $int_end;

        $skd_days = $this->DataScheduleModel->find()->select(['DataScheduleModel.id', 'DataScheduleModel.days', 'DataScheduleModel.time_end', 'DataScheduleModel.time_start'])
        ->where(['DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.deleted' => 0, 'DataScheduleModel.days NOT LIKE' => '%,%', 'DataScheduleModel.model' => $model])->toArray();

        $array_response['days_settings'] = [];
        if(!empty($skd_days)){
            $array_response['days_settings'] = $skd_days;
        }else if(!empty($lm_entity)){
            $days = explode(',', $lm_entity->days);
            foreach($days as $item){
                if(!empty($item)){
                    $array_response['days_settings'][] = [
                        'days' => $item,
                        'time_start' => $lm_entity->time_start,
                        'time_end' => $lm_entity->time_end
                    ];
                }
            }
        }

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $p_entity = $this->DataTreatmentsPrice->find()->join([
            'CTCI' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'DataTreatmentsPrice.treatment_id = CTCI.id AND CTCI.deleted = 0']
        ])
        ->where(['DataTreatmentsPrice.user_id' => USER_ID, 'DataTreatmentsPrice.deleted' => 0])->all();

        if (!empty($p_entity)) {
            
            foreach ($p_entity as $row) {
                $arr_pro[] = $row->treatment_id . ',' . $row->price;
            }
        }

        /* if(count($arr_pro) > 0 && count($array_response['days_settings']) > 0){
            $this->SysUsers->updateAll(
                ['steps' => 'HOME'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'HOME');
            return;
        } else {
            $this->SysUsers->updateAll(
                ['steps' => 'TREATMENTSETTINGS'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'TREATMENTSETTINGS');
            return;
        } */
             
        $this->success();
        $this->set('step', $ent_user->steps);
    }

    public function get_step(){

        $this->get_step_validate();
        // $token = get('token','');
        // if(!empty($token)){
        //     $user = $this->AppToken->validateToken($token, true);
        //     if($user === false){
        //         $this->message('Invalid token.');
        //         $this->set('session', false);
        //         return;
        //     }
        //     $this->set('session', true);
        // } else {
        //     $this->message('Invalid token.');
        //     $this->set('session', false);
        //     return;
        // }
        
        // $this->loadModel("SpaLiveV1.SysUsers");        
        
        // $ent_user = $this->SysUsers->find()->
        //     select(["SysUsers.steps"])            
        //     ->where(['SysUsers.id' => USER_ID])->first();
        // if(!empty($ent_user)){            
        //     $this->success();
        //     $this->set('step', $ent_user->steps);
        // }
    }

    public function get_step_patient(){
        $token = get('token','');
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
        $is_dev = env('IS_DEV', false);
        
        $this->loadModel("SpaLiveV1.SysUsers");        
         // Model Patient
        $this->loadModel('SpaLiveV1.DataModelPatient');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
         //Verificar si esta registrado como patient model
         $one_class = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL, 'DataModelPatient.deleted' => 0, 'registered_training_id > 0', 'assistance'=> 1])->all();
        // ------------------
        // Verificar a cuantas clases asistio el patient model
        $ent_patient_model = $this->DataModelPatient->find()->where(['DataModelPatient.email' => USER_EMAIL])->first();
        // ------------------
        
        #region PATIENT WEIGTH LOSS

        // Obtener el sercivio
        $this->loadModel('SpaLiveV1.SysPatientsOtherServices');
        $ent_service = $this->SysPatientsOtherServices->find()->where(['SysPatientsOtherServices.patient_id' => USER_ID, 'SysPatientsOtherServices.deleted' => 0])->first();
        // ------------------

        if(!empty($ent_service)){

            if ($ent_service->type == 'WEIGHT LOSS') {
                if ($ent_user->steps == 'STATENOTAVAILABLE') {
                    $this->success();
                    $this->set('step', $ent_user->steps);
                    return;
                }
                
                if ($ent_user->zip == 0 || $ent_user->city == '' || $ent_user->street == '') {
                    if($ent_user->steps == 'SHORTFORM'){
                        $this->success();
                        $this->set('step', $ent_user->steps);
                        return;
                    }
                }

                $this->loadModel('SpaLiveV1.DataCodeConfirm');
                $ent_code = $this->DataCodeConfirm->find()->where(['DataCodeConfirm.user_id' => USER_ID, 'DataCodeConfirm.status' => 'CONFIRMED', 'DataCodeConfirm.deleted' => 0])->first();

                if (empty($ent_code)) {
                    if($ent_user->steps == 'CODEVERIFICATION'){
                        $this->success();
                        $this->set('step', $ent_user->steps);
                        return;
                    }
                }

                $this->loadModel('SpaLiveV1.DataAgreements');
                $ent_agreements = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID])->all();
                if (count($ent_agreements) <= 0) {
                    $this->SysUsers->updateAll(
                        ['steps' => 'PATIENTCONSENT'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'PATIENTCONSENT');
                    return;
                }

                $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
                $ent_consultation_os = $this->DataConsultationOtherServices->find()
                ->where([
                    'DataConsultationOtherServices.patient_id' => USER_ID,
                    'DataConsultationOtherServices.deleted' => 0,
                    'DataConsultationOtherServices.status' => 'PAID'])->first();

                if (empty($ent_consultation_os)) {
                    $this->SysUsers->updateAll(
                        ['steps' => 'ADDTOCARDWL'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'ADDTOCARDWL');
                    return;
                } else{
                    $this->SysUsers->updateAll(
                        ['steps' => 'HOME'], 
                        ['id' =>  USER_ID]
                    );
                    $this->success();
                    $this->set('step', 'HOME');
                    return;
                }
            }
        }

        #endregion

        if ($ent_user->zip == 0 || $ent_user->city == '' || $ent_user->street == '') {
            /*if($ent_user->steps == 'CODEVERIFICATION'){
                $this->success();
                $this->set('step', $ent_user->steps);
                return;
            }

            $this->SysUsers->updateAll(
                ['steps' => 'LONGFORM'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'LONGFORM');
            return;*/
        }


        if(!empty($ent_patient_model) && $ent_user->steps == 'HOME'){
            // Cambiar step si tiene menos de 2 cursos y es patient model
            if(count($one_class)>1){
                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'HOME');
                    return;
            } else{
                $this->SysUsers->updateAll(
                    ['steps' => 'NOTATTENDED'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'NOTATTENDED');
                return;
            }
        }

        /*if ($ent_user->steps == 'GFEPAYMENT') {
            $this->loadModel("SpaLiveV1.DataPayment");
            $gfe_payment = $this->DataPayment->find()->where(
                [
                    'DataPayment.id_from' => USER_ID, 
                    'DataPayment.type' => 'GFE', 
                    'DataPayment.payment <>' => ''
                ]
            )->first();
            if (!empty($gfe_payment)) {
                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'], 
                    ['id' =>  USER_ID]
                );
                $this->success();
                $this->set('step', 'HOME');
                return;
            }
        }*/
       
        if(!empty($ent_user)){            
            $this->success();
            $this->set('step', $ent_user->steps);
        }

    }

    public function get_step_examiner(){
        $token = get('token','');
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
        $this->loadModel("SpaLiveV1.SysUsers");    
        $this->loadModel("SpaLiveV1.DataAgreements");

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        if($ent_user->steps == 'CODEVERIFICATION' || $ent_user->steps == 'STATENOTAVAILABLE' || $ent_user->steps == 'TRACERS' || $ent_user->steps == 'DENIED' || $ent_user->steps == 'WAITINGFORAPPROVAL' || $ent_user->steps == 'LICENCEEXAMINER'){
            $this->set('step', $ent_user->steps);
            $this->success();
            return;
        }

        $this->loadModel('SpaLiveV1.SysLicence');

        $ent_licence = $this->SysLicence->find()->select(['SysLicence.id'])
        ->where(['SysLicence.user_id' => USER_ID, 'SysLicence.deleted' => 0])->all();

        if(Count($ent_licence) < 1){
            $this->SysUsers->updateAll(
                ['steps' => 'LICENCEEXAMINER'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'LICENCEEXAMINER');
            return;
        }

        $ent_agreements = $this->DataAgreements->find()->where(['DataAgreements.user_id' => USER_ID])->all();

        if(Count($ent_agreements) < 1){
            $this->SysUsers->updateAll(
                ['steps' => 'AGREEMENTS'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'AGREEMENTS');
            return;
        }

        $this->loadModel('SpaLiveV1.DataWN');
        $w9 = $this->DataWN->find()->where(['DataWN.user_id' => USER_ID])->first();
        if(empty($w9)){
            $this->SysUsers->updateAll(
                ['steps' => 'W9'], 
                ['id' =>  USER_ID]
            );
            $this->success();
            $this->set('step', 'W9');
            return;
        }

        $this->SysUsers->updateAll(
            ['steps' => 'HOME'], 
            ['id' =>  USER_ID]
        );
        $this->success();
        $this->set('step', 'HOME');
        return;

    }

    private function get_ot_course($user_id){
        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.DataPayment');

        $type_trainings = $this->CatCoursesType->find()->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1])->all();

        $ent_payments = $this->DataPayment->find()->where(['DataPayment.id_from' => $user_id, 'DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1])->all();

        $type_ot = '';

        foreach($type_trainings as $type_training){
            foreach($ent_payments as $ent_payment){
                if($ent_payment->type == $type_training->name_key){
                    $type_ot = $type_training->name_key;
                    break 2;
                }
            }
        }

        if(empty($type_ot)){
            return 0;
        }

        $this->loadModel('SpaLiveV1.DataTrainings');

        $ent_trainings = $this->DataTrainings->find()->select(['DataTrainings.id', 'DataTrainings.attended'])
        ->join([
            'Cat' => [
                'table' => 'cat_trainings',
                'type' => 'INNER',
                'conditions' => 'DataTrainings.training_id = Cat.id'
            ]
        ])
        ->where(['DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0, 'Cat.level' => $type_ot])->first();


        if(empty($ent_trainings)){
            return 1;
        }

        if($ent_trainings->attended == 0){
            return 2;
        }

        return 3;
    }

    public function load_injector_settings() {
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
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

        $model = empty(get('model','')) ? 'injector' : get('model','');
        if($model != 'injector' && $model != 'examiner'){
            $this->message('Invalid user model.');    
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $e_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $array_response = array();

        $array_response['radius'] = $e_user->radius;

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $lm_entity = $this->DataScheduleModel->find()->where(['DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.deleted' => 0, 'DataScheduleModel.days LIKE' => '%,%', 'DataScheduleModel.model' => $model])->first();

        if (empty($lm_entity)) {
            $str_days = "";
            $int_start = 9;
            $int_end = 15;
        } else {
            $str_days = $lm_entity->days;
            $int_start = $lm_entity->time_start;
            $int_end = $lm_entity->time_end;
        }

        $array_response['days'] = $str_days;
        $array_response['time_start'] = $int_start;
        $array_response['time_end'] = $int_end;

        $skd_days = $this->DataScheduleModel->find()->select(['DataScheduleModel.id', 'DataScheduleModel.days', 'DataScheduleModel.time_end', 'DataScheduleModel.time_start'])
        ->where(['DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.deleted' => 0, 'DataScheduleModel.days NOT LIKE' => '%,%', 'DataScheduleModel.model' => $model])->toArray();

        $array_response['days_settings'] = [];
        if(!empty($skd_days)){
            $array_response['days_settings'] = $skd_days;
        }else if(!empty($lm_entity)){
            $days = explode(',', $lm_entity->days);
            foreach($days as $item){
                if(!empty($item)){
                    $array_response['days_settings'][] = [
                        'days' => $item,
                        'time_start' => $lm_entity->time_start,
                        'time_end' => $lm_entity->time_end
                    ];
                }
            }
        }

        $days_off = $this->DataScheduleDaysOff->find()->select(['DataScheduleDaysOff.date_off'])
            ->where(['DataScheduleDaysOff.user_id' => USER_ID, 'DataScheduleDaysOff.deleted' => 0])->toArray();
        $array_response['days_off'] = (!empty($days_off) ? Hash::extract($days_off, '{n}.date_off') : []);

        $str_services = "";

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $p_entity = $this->DataTreatmentsPrice->find()->join([
            'CTCI' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'DataTreatmentsPrice.treatment_id = CTCI.id AND CTCI.deleted = 0'],
            'StateAvailability' => ['table' => 'data_treatments_enabled_by_state', 'type' => 'LEFT', 'conditions' => 'StateAvailability.treatment_id = CTCI.id'],
        ])
        ->where(['DataTreatmentsPrice.user_id' => USER_ID, 'DataTreatmentsPrice.deleted' => 0, 'StateAvailability.state_id' => USER_STATE])->all();

        if (!empty($p_entity)) {
            $arr_pro = array();
            foreach ($p_entity as $row) {
                $arr_pro[] = $row->treatment_id . ',' . $row->price;
            }

            $str_services = implode("|", $arr_pro);
        }

        $array_response['services'] = $str_services;

        $this->set('data', $array_response);

        $this->success(); 
    }

    public function save_injector_settings() {
        $Main = new MainController();
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
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

        $model = empty(get('model','')) ? 'injector' : get('model','');
        if($model != 'injector' && $model != 'examiner'){
            $this->message('Invalid user model.');    
            return;
        }

        // if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
        //     $this->message('Invalid user type.');    
        //     return;
        // }

        $body_areas = get('body_areas', '');

        $this->loadModel("SpaLiveV1.DataBodyAreas");

        $ent_ba = $this->DataBodyAreas->find()
            ->where([
                'DataBodyAreas.user_id' => USER_ID,
                'DataBodyAreas.treatment_type' => 'FILLERS'
            ])
            ->first();
        
        if(empty($ent_ba)){
            $arr_save = array(
                'user_id' => USER_ID,
                'body_areas' => $body_areas,
                'treatment_type' => 'FILLERS'
            );

            $ba_entity = $this->DataBodyAreas->newEntity($arr_save);
            if(!$ba_entity->hasErrors()) {
                $this->DataBodyAreas->save($ba_entity);                
            }
        }else{
            $this->DataBodyAreas->updateAll(
                ['body_areas' => $body_areas], ['user_id' =>  USER_ID, 'treatment_type' => 'FILLERS']
            );
        }

        $string_prices = get('services','');
        $string_prices_names = get('services_name','');
        
        $arr_prices = explode('|', $string_prices);
        $arr_prices_names = explode('|', $string_prices_names);

        $has_names = empty($arr_prices_names);
        if ($model == "injector" && empty($arr_prices)) {
            $this->message('Invalid services string format.');
            return;
        }
        

        $array_save = array(
                'id' => USER_ID,
                'steps' => 'HOME',
                'radius' => intval(get('radius',10)),
                'last_status_change' => date('Y-m-d H:i:s'),
            );

        $this->loadModel('SpaLiveV1.SysUsers');
        $c_entity = $this->SysUsers->newEntity($array_save);

        if(!$c_entity->hasErrors()) {
            $this->SysUsers->save($c_entity);
        }


        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $lm_entity = $this->DataScheduleModel->find()->where(['DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.deleted' => 0])->first();

        if (!empty($lm_entity)) 
            $s_id = $lm_entity->id;
        else 
            $s_id = 0;

        $t_start = get('time_start',9);
        $t_end = get('time_end',20);

        if ($t_end <= $t_start) {
            $this->message('End Time should be greater than Start Time.');
            return;
        }
        
        $array_save_m = array(
            'id' => $s_id,
            'injector_id' => USER_ID,
            'days' => get('days',''),
            'time_start' => $t_start,
            'time_end' => $t_end,
            'model' => $model
        );

        $m_entity = $this->DataScheduleModel->newEntity($array_save_m);
        if(!$m_entity->hasErrors()) {
            $this->DataScheduleModel->save($m_entity);    
            
        }

        $skd_dates = json_decode(get('skd_dates', '[]'), true);
        $inj_id = USER_ID;
        $this->DataScheduleModel->getConnection()->execute("UPDATE data_schedule_model SET deleted = 1 WHERE injector_id = {$inj_id} AND days NOT LIKE '%,%' AND model = '{$model}'");
        foreach($skd_dates as $item){
            $arrSaveDay = array(
                'injector_id' => USER_ID,
                'days'        => $item['days'],
                'time_start'  => $item['time_start'],
                'time_end'    => $item['time_end'],
                'model'       => $model
            );
            $day_entity = $this->DataScheduleModel->newEntity($arrSaveDay);
            if(!$day_entity->hasErrors()) {
                $this->DataScheduleModel->save($day_entity);
            }
        }

        $days_off = json_decode(get('days_off', '[]'), true);
        $this->DataScheduleDaysOff->updateAll(
            ['deleted'   => 1], ['user_id' =>  USER_ID]
        );
        foreach ($days_off as $item) {
            $arrSaveDayOff = array(
                'user_id' => USER_ID,
                'date_off' => $item,
            );
            $day_entity = $this->DataScheduleDaysOff->newEntity($arrSaveDayOff);
            if(!$day_entity->hasErrors()) {
                $this->DataScheduleDaysOff->save($day_entity);
            }
        }

        if($model == 'injector'){
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

            if (count($arr_prices) > 0) {
                $str_query_delete = "
                    UPDATE data_treatments_prices SET deleted = 1 WHERE user_id = " . USER_ID;
                $this->DataTreatmentsPrice->getConnection()->execute($str_query_delete);
            }

            $services_array = array();

            foreach ($arr_prices as $index => $row) {
                // services: id,price|id,price
                $arr_inter = explode(",", $row);
            
               
            
                $p_entity = $this->DataTreatmentsPrice->find()->where(['DataTreatmentsPrice.treatment_id' => $arr_inter[0], 'DataTreatmentsPrice.user_id' => USER_ID])->first();
            
                if (!empty($p_entity)) {
                    $p_id = $p_entity->id;
                } else {
                    $p_id = 0;
                }
            
                $services_array[] = $arr_inter[0];
            
                // Buscar el nombre en arr_prices_names utilizando el mismo índice
                $service_name = '';
                foreach ($arr_prices_names as $ondex => $name) {

                    $arr_inter_name = explode(",", $name);
                    if($arr_inter_name[0] == $arr_inter[0]){
                        if (count($arr_inter_name) < 2) {
                            continue;
                        }
                        $service_name = isset($arr_inter_name) ? $arr_inter_name[1] : ''; 
                    }
                }

                if($service_name == ''){
                    if(!empty($p_entity['alias'])){
                        $service_name = $p_entity['alias'];

                    }
                }

            
                if(!empty($arr_inter[0]) && !empty($arr_inter[1])){
                    $arr_save_q = [
                        'id' => $p_id,
                        'user_id' => USER_ID,
                        'treatment_id' => $arr_inter[0],
                        'price' => $arr_inter[1],
                        'alias' => $service_name, // Agregar el nombre del servicio aquí
                        'deleted' => 0,
                    ];
            
                    $cq_entity = $this->DataTreatmentsPrice->newEntity($arr_save_q);
                    if (!$cq_entity->hasErrors()) {
                        $this->DataTreatmentsPrice->save($cq_entity);
                    }

                }
            }

            // $this->loadModel('SpaLiveV1.DataTreatment');
            // $this->loadModel('SpaLiveV1.CatTreatmentsCi');

            // $p_entity = $this->DataTreatment->find()->where(['DataTreatment.assistance_id' => USER_ID, 'DataTreatment.status IN' => ['INIT','CONFIRM'], 'DataTreatment.deleted' => 0])->all();
            // if(!empty($p_entity)) {
            //     foreach ($p_entity as $row) {
            //         $should_notify = false;
            //         $treatments_to_notify = array();
            //         $treatments = $row->treatments;
            //         $arr_requested_treatments = explode(',', $treatments);
            //         foreach($arr_requested_treatments as $treatment) {
            //             if (!empty($treatments) && !empty($services_array)) {
            //                 if (!in_array($treatment,$services_array)) {
            //                     $should_notify = true;
            //                     $ent_trci = $this->CatTreatmentsCi->find()->where(['CatTreatmentsCi.id' => $treatment, 'CatTreatmentsCi.deleted' => 0])->first();
            //                     if (!empty($ent_trci)) $treatments_to_notify[] = $ent_trci->name;    
            //                 }
            //             }
            //         }
            //         if (count($treatments_to_notify) > 0) {

            //             $constants_not = [
            //                 '[CNT/Treatments]' => implode(",", $treatments_to_notify)
            //             ];
            //             $Main->notify_devices('TREATMENTS_DELETED',array($row->patient_id), true, true, true, array(),'', $constants_not, true);

            //         }
            //     }

            // }
        }
        if(get('update_step', 0) == 1){
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->SysUsers->updateAll(
                ['steps' => 'STRIPEACCOUNT'], ['id' =>  USER_ID]
            );
        }
        $this->success(); 
    }

    public function cat_treatments_ci(){
        $this->loadModel('SpaLiveV1.CatTrainigs');
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

        // $validateTraining = get('validate_training', false);
        // if($validateTraining){
        //     $now = date('Y-m-d H:i:s');
        //     $fields = ['CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials','CatTrainigs.flip','CatTrainigs.lift','Training.id'];
        //     $fields['Advanced'] = "(SELECT DT.id FROM data_trainings DT 
        //                         JOIN cat_trainings CT ON CT.level = 'LEVEL 2' AND DT.training_id = CT.id AND DATE_FORMAT(CT.scheduled, '%Y-%m-%d 12:00:00') < '" . $now . "' AND CT.deleted = 0 
        //                         WHERE DT.user_id = " . USER_ID . " AND DT.deleted = 0 LIMIT 1)";
        //     $trains_user = $this->CatTrainigs->find()->select($fields)
        //     ->join([
        //         'Training' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = Training.training_id AND CatTrainigs.level = "LEVEL 1"'],
        //     ])->where(['Training.user_id' => USER_ID, 'Training.deleted' => 0, 'CatTrainigs.deleted' => 0])->first();


        //     /* $hasNeur = empty($trains_user) ? 0 : max(Hash::extract($trains_user, '{n}.neurotoxins'));
        //     $hasFill = empty($trains_user) ? 0 : max(Hash::extract($trains_user, '{n}.fillers'));
        //     $hasFlip = empty($trains_user) ? 0 : max(Hash::extract($trains_user, '{n}.flip'));
        //     $hasLift = empty($trains_user) ? 0 : max(Hash::extract($trains_user, '{n}.lift')); */
        // }
        
        $cc = new CourseController();
        $ccc = new SummaryController();
        $trainings_user = $cc->get_courses_user(USER_ID);
        $has_basic_course = $trainings_user['has_basic_course'];
        $has_advanced_course = $trainings_user['has_advanced_course'];
        $this->set('has_basic_course',$has_basic_course);
        $this->set('has_advanced_course',$has_advanced_course);
        $this->set('has_level3_course',$ccc->check_training_medical(USER_ID));
        /*if(!$has_basic_course){ // check in school 
            $result = array();            
            $this->loadModel('SpaLiveV1.DataCourses');
            $fields = ['DataCourses.id', 'DataCourses.status', 'DataCourses.front', 'DataCourses.back', 'CC.title', 'DSR.nameschool'];
            $courses = $this->DataCourses->find()->select($fields)
            ->join([
                'CC' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CC.id = DataCourses.course_id'],
                'DSR' => ['table' => 'data_school_register', 'type' => 'INNER', 'conditions' => 'DSR.id = CC.school_id'],
            ])->where(['DataCourses.deleted' => 0, 'DataCourses.user_id' => USER_ID, 'CC.deleted' => 0, 'DSR.deleted' => 0,'DataCourses.status' => 'DONE', 'CC.type in ("NEUROTOXINS BASIC","BOTH NEUROTOXINS")' ])->all();
            $this->set('basic_course', $courses);
            if(Count($courses)>0){
                $has_basic_course = true;                
            }
        }
        if(!$has_advanced_course){ // check in school 
            $result = array();            
            $this->loadModel('SpaLiveV1.DataCourses');
            $fields = ['DataCourses.id', 'DataCourses.status', 'DataCourses.front', 'DataCourses.back', 'CC.title', 'DSR.nameschool'];
            $coursesadv = $this->DataCourses->find()->select($fields)
            ->join([
                'CC' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CC.id = DataCourses.course_id'],
                'DSR' => ['table' => 'data_school_register', 'type' => 'INNER', 'conditions' => 'DSR.id = CC.school_id'],
            ])->where(['DataCourses.deleted' => 0, 'DataCourses.user_id' => USER_ID, 'CC.deleted' => 0, 'DSR.deleted' => 0,'DataCourses.status' => 'DONE', 'CC.type in ("NEUROTOXINS ADVANCED","BOTH NEUROTOXINS")' ])->all();
            $this->set('coursesadv', $coursesadv);
            if(Count($coursesadv)>0){
                $has_advanced_course = true;
            }
        }*/
        $result = array();
        $has_ivt = false;
        $c_ivt = new TherapyController();
        $status_iv_therapy = $c_ivt->get_status_for_login(USER_ID);
        
        $FC = new FillersController();
        $has_filler_certificate = $FC->has_fillers_certificate(USER_ID);
        $body_areas = $FC->get_body_areas_fillers();
        $areas_injector = array();

        // other courses validate

        $courses_user = $this->get_treatments_by_other_courses(USER_ID);

        //$this->set('ivt', 'No IVT');
        $this->set('c_ivt', $status_iv_therapy);        
        if($status_iv_therapy ==! null){
            $this->set('ivt', $status_iv_therapy);
            if($status_iv_therapy=='ACCEPTED'){
                $has_ivt = true;
            }
        }

        if($has_filler_certificate){   

            $this->loadModel('SpaLiveV1.CatCITreatments');
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.description',
                                                                      'CatCITreatments.treatment_id','CatCITreatments.name',
                                                                      'CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details',
                                                                      'CatCITreatments.min','CatCITreatments.qty','CatCITreatments.std_price',
                                                                      'Product.comission_spalive','Exam.name', 'Exam.type_trmt', 'CTC.name', 
                                                                      'CTC.type','CatCITreatments.category_treatment_id'])
            ->where(['CatCITreatments.deleted' => 0,
                    'CatCITreatments.name NOT LIKE' => 'Let my provider%',
                    'CTC.type' => 'FILLERS',
                    'StateAvailability.state_id' => USER_STATE
                                          
            ])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
                'StateAvailability' => ['table' => 'data_treatments_enabled_by_state', 'type' => 'LEFT', 'conditions' => 'StateAvailability.treatment_id = CatCITreatments.id'],
            ])->all();

            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $row) {     
                    $ent_treatments_price = $this->DataTreatmentsPrice->find()
                    ->where(['DataTreatmentsPrice.deleted' => 0,
                            'DataTreatmentsPrice.user_id' => USER_ID,
                             'DataTreatmentsPrice.treatment_id' => $row['id'],
                    ])->first();

                    if(!empty($ent_treatments_price['alias'])){
                        $row['name'] = $ent_treatments_price['alias'];
                    }

                    $description = "";

                    if ($row['category_treatment_id'] == 1002) {

                        $aux_description = $row['description'];

                        $aux_description = strtolower(trim($aux_description));
                        
                        $description = $row['description'];

                        if (strpos($aux_description, "detail") !== false) {
                            $description = "";
                        }
                    }

                    $t_array = array(
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'details' => $row['details'],
                        'exam_id' => $row['treatment_id'],
                        'product_id' => $row['product_id'],
                        'exam_name' => $row['Exam']['name'],
                        'type' => $row['CTC']['type'],
                        'category' => $row['CTC']['name'],
                        'description' => $description,
                    );

                    if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                        $t_array['max'] = $row['max'];
                        $t_array['min'] = $row['min'];
                        $t_array['qty'] = $row['qty'];
                        $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                    }
                    
                    $result[] = $t_array;
                }
            }

            $areas_injector_arr = $FC->get_body_areas_injector(USER_ID);            
            $areas_injector = !empty($areas_injector_arr['ids']) ?  explode(',', $areas_injector_arr['ids']) : array();            
        }

        foreach($body_areas as $area){
            if(in_array($area['id'], $areas_injector)){
                $area['selected'] = true;
            }else{
                $area['selected'] = false;
            }
        }

        if($has_ivt){   

            $this->loadModel('SpaLiveV1.CatCITreatments');
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.description','CatCITreatments.treatment_id',
                                                                      'CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max',
                                                                      'CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty',
                                                                      'CatCITreatments.std_price','Product.comission_spalive','Exam.name', 
                                                                      'Exam.type_trmt', 'CTC.name', 'CTC.type', 'CatCITreatments.category_treatment_id'])
            ->where(['CatCITreatments.deleted' => 0,      
                    'CatCITreatments.name NOT LIKE' => 'Let my provider%',
                     'CTC.type' => 'IV THERAPY',
                     'StateAvailability.state_id' => USER_STATE
            ])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
                'StateAvailability' => ['table' => 'data_treatments_enabled_by_state', 'type' => 'LEFT', 'conditions' => 'StateAvailability.treatment_id = CatCITreatments.id'],
            ])->all();



            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $row) {     
                    $ent_treatments_price = $this->DataTreatmentsPrice->find()
                    ->where(['DataTreatmentsPrice.deleted' => 0,
                            'DataTreatmentsPrice.user_id' => USER_ID,
                             'DataTreatmentsPrice.treatment_id' => $row['id'],
                    ])->first();

                    if(!empty($ent_treatments_price['alias'])){
                        $row['name'] = $ent_treatments_price['alias'];
                    }

                    $name = $row['name'];

                    $name = strtolower(trim($name));
                    
                    $description = "";

                    if ($row['category_treatment_id'] == 1001) {

                        $aux_description = $row['description'];

                        $aux_description = strtolower(trim($aux_description));
                        
                        $description = $row['description'];

                        if (strpos($aux_description, "detail") !== false) {
                            $description = "";
                        }
                    }

                    $t_array = array(
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'details' => $row['details'],
                        'exam_id' => $row['treatment_id'],
                        'product_id' => $row['product_id'],
                        'exam_name' => $row['Exam']['name'],
                        'type' => $row['CTC']['type'],
                        'category' => $row['CTC']['name'],
                        'description' => $description,
                    );

                    if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                        $t_array['max'] = $row['max'];
                        $t_array['min'] = $row['min'];
                        $t_array['qty'] = $row['qty'];
                        $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                    }
                    
                    $result[] = $t_array;
                }
            }
        }
        
        if(!$has_basic_course && !$has_ivt && !$has_advanced_course && !$has_filler_certificate && count($courses_user) == 0){
            $this->message('You must have a training to access this section.');
            return;
        }

        if($has_basic_course){
            $this->set('enter_has_basic_course', 'has_basic_course');
            $this->loadModel('SpaLiveV1.CatCITreatments');
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','CatCITreatments.std_price','Product.comission_spalive','Exam.name', 'Exam.type_trmt', 'CTC.name', 'CTC.type'])
            ->where(['CatCITreatments.deleted' => 0,
                    'CatCITreatments.name NOT LIKE' => 'Let my provider%',
                    'CTC.type' => 'NEUROTOXINS BASIC',
                    'CTC.name NOT IN' => array('Crows Feet', 'Glabella', 'Frontalis'),
                    'StateAvailability.state_id' => USER_STATE
            ])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
                'StateAvailability' => ['table' => 'data_treatments_enabled_by_state', 'type' => 'LEFT', 'conditions' => 'StateAvailability.treatment_id = CatCITreatments.id'],
            ])->all();

            
            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $row) {
                    /* $type = $row->Exam['type_trmt'];
                    if($validateTraining == true && (($hasNeur < 1 && $type == 'NEUROTOXINS') || ($hasFill < 1 && $type == 'FILLERS') || ($hasLift < 1 && $type == 'LIFT') || ($hasFlip < 1 && $type == 'FLIP') )){
                        continue;
                    } */

                    if($row['name'] == 'Let my provider help me decide') { continue; }
                    else if ($row['CTC']['name'] == 'Basic Neurotoxins') { $row['CTC']['name'] = 'Crows Feet, Frontalis, Glabella'; }

                    $t_array = array(
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'details' => $row['details'],
                        'exam_id' => $row['treatment_id'],
                        'product_id' => $row['product_id'],
                        'exam_name' => $row['Exam']['name'],
                        'type' => $row['CTC']['type'],
                        'category' => $row['CTC']['name'],
                        'description' => "",
                    );

                    if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                        $t_array['max'] = $row['max'];
                        $t_array['min'] = $row['min'];
                        $t_array['qty'] = $row['qty'];
                        $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                    }
                    
                    $result[] = $t_array;
                }
            }
        }

        if($has_advanced_course){   
            $this->set('enter_has_advanced_course', 'has_advanced_course');
            $this->loadModel('SpaLiveV1.CatCITreatments');
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','CatCITreatments.std_price','Product.comission_spalive','Exam.name', 'Exam.type_trmt', 'CTC.name', 'CTC.type'])
            ->where(['CatCITreatments.deleted' => 0,
                     'CatCITreatments.name NOT LIKE' => 'Let my provider%',
                     'CTC.type' => 'NEUROTOXINS ADVANCED',
                     'CatCITreatments.id <' => 80,
                     'StateAvailability.state_id' => USER_STATE

            ])
            ->join([
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
                'StateAvailability' => ['table' => 'data_treatments_enabled_by_state', 'type' => 'LEFT', 'conditions' => 'StateAvailability.treatment_id = CatCITreatments.id'],
            ])->all();

            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $row) {
                    /* $type = $row->Exam['type_trmt'];
                    if($validateTraining == true && (($hasNeur < 1 && $type == 'NEUROTOXINS') || ($hasFill < 1 && $type == 'FILLERS') || ($hasLift < 1 && $type == 'LIFT') || ($hasFlip < 1 && $type == 'FLIP') )){
                        continue;
                    } */

                    // if($row['name'] != $row['CTC']['name']) { continue; }
                    
                    $t_array = array(
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'details' => $row['details'],
                        'exam_id' => $row['treatment_id'],
                        'product_id' => $row['product_id'],
                        'exam_name' => $row['Exam']['name'],
                        'type' => $row['CTC']['type'],
                        'category' => $row['CTC']['name'],
                        'description' => "",
                    );

                    if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                        $t_array['max'] = $row['max'];
                        $t_array['min'] = $row['min'];
                        $t_array['qty'] = $row['qty'];
                        $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                    }
                    
                    $result[] = $t_array;
                }
            }
        }

        if(count($courses_user) > 0){
            $this->loadModel('SpaLiveV1.CatCITreatments');

            $ot_treatments = [];

            foreach($courses_user as $course_user){
                $ot_treatments[] = $course_user->id;
            }

            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','CatCITreatments.std_price','Product.comission_spalive','Exam.name', 'Exam.type_trmt', 'CTC.name', 'CTC.type'])
            ->where(['CatCITreatments.deleted' => 0,
                    'Exam.other_treatment_id IN' => $ot_treatments,
                    //'CatCITreatments.name IN' => $ot_treatments,
                    //'StateAvailability.state_id' => USER_STATE
            ])
            ->join([
                'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
                'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
                //'StateAvailability' => ['table' => 'data_treatments_enabled_by_state', 'type' => 'LEFT', 'conditions' => 'StateAvailability.treatment_id = CatCITreatments.id'],
            ])->all();

            
            if(!empty($ent_treatments)){
                foreach ($ent_treatments as $row) {

                    if($row['name'] == 'Let my provider help me decide') { continue; }
                    else if ($row['CTC']['name'] == 'Basic Neurotoxins') { $row['CTC']['name'] = 'Crows Feet, Frontalis, Glabella'; }

                    $t_array = array(
                        'id' => $row['id'],
                        'name' => $row['name'],
                        'details' => $row['details'],
                        'exam_id' => $row['treatment_id'],
                        'product_id' => $row['product_id'],
                        'exam_name' => $row['Exam']['name'],
                        'type' => $row['CTC']['type'],
                        'category' => $row['CTC']['name'],
                        'description' => "",
                    );

                    if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                        $t_array['max'] = $row['max'];
                        $t_array['min'] = $row['min'];
                        $t_array['qty'] = $row['qty'];
                        $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                    }
                    
                    $result[] = $t_array;
                }
            }
        }

        $ids_in_treatments = array();   
        $this->loadModel('SpaLiveV1.DataTreatment');
        $p_entity = $this->DataTreatment->find()->where(['DataTreatment.assistance_id' => USER_ID, 'DataTreatment.status IN' => ['INIT','CONFIRM'], 'DataTreatment.deleted' => 0])->all();
        if(!empty($p_entity)) {
            foreach ($p_entity as $row) {
                $treatments = $row->treatments;
                $arr_requested_treatments = explode(',', $treatments);
                foreach($arr_requested_treatments as $treatment) {
                    $ids_in_treatments[] = $treatment;
                }
            }
        }

        $validated_result = array();        
        foreach ($result as $key => $row) {
            $validate_arr = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'details' => $row['details'],
                'exam_id' => $row['exam_id'],
                'product_id' => $row['product_id'],
                'exam_name' => $row['exam_name'],
                'type' => $row['type'],
                'category' => $row['category'],
                'description' => $row['description'],    
            );
            if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                $validate_arr['max'] = $row['max'];
                $validate_arr['min'] = $row['min'];
                $validate_arr['qty'] = $row['qty'];
                $validate_arr['ci_comission'] = intval($row['ci_comission']);
                $validate_arr['in_treatment'] = in_array($row['id'], $ids_in_treatments) ? true : false;
            }
            $validated_result[] = $validate_arr;
        }        

        $this->set('body_areas', $body_areas);
        $this->set('data', $validated_result);
        $this->success();
    }

    public function cat_states(){

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_states = $this->CatStates->find()->where(['CatStates.deleted' => 0])->all();
        if(!empty($ent_states)){
            $result = array();
            foreach ($ent_states as $row) {
                $result[] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'abv' => $row['abv'],
                    'enabled' => $row['enabled'],
                    'require_ci_license' => $row['require_ci_license'] == 1 ? true : false,
                );
                
            }
        }

        $this->set('data', $result);
        $this->success();
    }

    public function keepMeInformed() {
        $token = get('token', '');
        $this->loadModel('SpaLiveV1.DataNewsletterState');
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

        $ent_news = $this->DataNewsletterState->find()->where(['DataNewsletterState.email' => USER_EMAIL, 'DataNewsletterState.state_id' => $user['user_state']])->first();

        if (empty($ent_news)) {
             $array_save = array(
                'email' => USER_EMAIL,
                'state_id' => $user['user_state'],
                'created' => date('Y-m-d H:i:s'),
                'deleted' => 0,
            );

            $c_entity = $this->DataNewsletterState->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->DataNewsletterState->save($c_entity); 
            }
        }

      
        
        $this->success();
    }

    public function save_fcm_token(){
        $token = get('token', '');
        $this->loadModel('SpaLiveV1.DataNewsletterState');
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

        $fcm_token = get('fcm_token', '');
        $device_token = $fcm_token;
        $uid = $fcm_token;
        $name = get('name');
        $version = get('version');
        $device = get('device');
        $user_id = USER_ID;

        if(!empty($device)){
            $array_save = array(
                'application_id' => APP_ID,
                'device' => $device,
                'uid' => $fcm_token,
                'token' => $device_token,
                'name' => $name,
                'version' => $version,
                'user_id' => $user_id,
                'is_flutter' => 1,  
            );
            $this->loadModel('SpaLiveV1.ApiDevice');
            $array_device = $this->ApiDevice->find()->select(['ApiDevice.id'])->where([ 'OR' => ['ApiDevice.user_id' => USER_ID, 'ApiDevice.token' => $device_token]])->first();

            if(!empty($array_device)){
                $array_save['id'] = intval($array_device->id);
            }

            $saveDevice = $this->ApiDevice->save($this->ApiDevice->newEntity($array_save));
            if($saveDevice !== false){
                $device_id = !isset($array_save['id']) ? $saveDevice->id : $array_save['id'];
                $this->success();
                $this->set('uid',$uid);
                $this->set('device_token',$device_token);
            }else{
                $this->message('The device could not be registered.');
            }
        }else{
            $this->message('Invalid type device..');
        }
    }

    public function get_nec() {

        $html_bulk = "
                    <page>
                        <div style='width: 210mm; height: 97mm; position:relative;'>
                            <img style='width:210mm; height: 97mm; position:absolute; z-index: 1;' src='" . env('URL_ASSETS', 'https://api.spalivemd.com/assets/') . "nec.jpg' />
                            <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                                
                                <div style='position: absolute;left: 10mm;top: 20mm; max-width: 80mm; background-color: red'>MySpaLive LLC<br>130 N Preston road. #329 Prosper, TX, 75078</div>
                                <div style='position: absolute;left: 10mm;top: 53mm; max-width: 80mm; background-color: red'>Luis Hector Valdez Cervantes.</div>
                                <div style='position: absolute;left: 10mm;top: 64mm; max-width: 80mm; background-color: red'>Street # 100 .</div>
                                <div style='position: absolute;left: 10mm;top: 72mm; max-width: 80mm; background-color: red'>Durango Durango Mexico</div>
                                <div style='position: absolute;left: 150mm;top: 32mm; background-color: red'>22</div>
                                <div style='position: absolute;left: 105mm;top: 41mm; background-color: red'>1,000.00</div>
                                <div style='position: absolute;left: 105mm;top: 68mm; background-color: red'>1,001.00</div>
                                <div style='position: absolute;left: 105mm;top: 77mm; background-color: red'>1,002.00</div>
                                <div style='position: absolute;left: 105mm;top: 81.5mm; background-color: red'>1,003.00</div>
                                <div style='position: absolute;left: 135mm;top: 77mm; background-color: red'>43</div>
                                <div style='position: absolute;left: 135mm;top: 81.5mm; background-color: red'>43</div>
                                <div style='position: absolute;left: 180mm;top: 77mm; background-color: red'>1,006.00</div>
                                <div style='position: absolute;left: 180mm;top: 81.5mm; background-color: red'>1,007.00</div>
                                
                                
                            </div>
                        </div>
                    </page>";


        echo $html_bulk; exit;
        
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));

        $html2pdf->writeHTML($html_bulk);
        // $html2pdf->Output(TMP . 'reports' . DS . $ent_tray->filename, 'F');
        $html2pdf->Output('nec.pdf', 'I'); //,'D'

    }

    public function get_services()
    {
        // SERVICES 🥺
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $array_treatments = array();

        $cat_categorys = $this->CatTreatmentsCategory->find()->where(['CatTreatmentsCategory.type_uber IN' => array('NEUROTOXINS BASIC','NEUROTOXINS ADVANCED'), 'CatTreatmentsCategory.deleted' => 0])->order(['CatTreatmentsCategory.order' => 'ASC'])->all();

        if(Count($cat_categorys) > 0){
            foreach($cat_categorys as $row){
                $array_list = array();
                $cat_treatment = $this->CatTreatmentsCi->find()->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'CatTreatmentsCi.deleted' => 0,'CatTreatmentsCi.id >' => 81])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->all();

                if(Count($cat_treatment) > 0) {
                    foreach($cat_treatment as $row2){
                        if($row2['name'] == $row['name']) { continue; }
                        array_push($array_list, array(
                            'id' => $row2['id'],
                            'name' => $row2['name'],
                            'description' => $row2['description'],
                            'price' => $row2['std_price'],
                        ));
                    }
                }
                $array_treatments[] = array(
                    'title' => $row['name'],
                    'description' => $row['description'],
                    'image' => $row['image'],
                    'data' => $array_list
                );
            }
        }

        $this->set('list_treatments', $array_treatments);
        $this->success();
    }

    public function get_injectors_register() {               
        $this->loadModel('SpaLiveV1.SysUsers');

        // DEFAULT - Have training and active = 1
        $filter = trim(get('filter', ''));
        $targetString = $filter;
        $show_availability = 1;
        $join = '';
        $conditions = '';
        $having = '';
        $join = "INNER JOIN data_schedule_model DSM ON DSM.injector_id = DC.id AND DSM.deleted = 0 ";
        $conditions = " AND DSM.days <> '' AND (SELECT COUNT(TrP.id) FROM data_treatments_prices TrP WHERE TrP.user_id = DC.id AND TrP.deleted = 0) > 0 ";
        $order = '';
        
        if(!empty($filter)){
            $matchValue = str_replace(' ', ' +', $filter);
            $matchValue = str_replace('@', '', $filter);
            $conditions .= " AND ( MATCH(DC.name,DC.mname,DC.lname) AGAINST ('+{$matchValue}' IN BOOLEAN MODE) OR DC.email LIKE '%{$filter}%')";
        }

        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $result = $this->search_by_full_name_or_email($filter, 0, 0, $join, $conditions);
        if(empty($result)){
            if(!empty($str_treatments)){
                $join .= " INNER JOIN data_treatments_prices TrPr ON TrPr.user_id = DC.id AND TrPr.deleted = 0";
                $arr_treatments = explode(",", $str_treatments);
                foreach($arr_treatments as $key => $treatment) {
                    if ($treatment == 0) unset($arr_treatments[$key]);
                }
                $conditions .= " AND TrPr.treatment_id IN ({$str_treatments})";        
                $having = ' HAVING count(distinct TrPr.treatment_id) = ' . count($arr_treatments);
            }

            if(!empty($filter)){
                $matchValue = str_replace(' ', ' +', $filter);
                $matchValue = str_replace('@', '', $filter);
                if(!filter_var($filter, FILTER_VALIDATE_EMAIL)){
                    $conditions .= " AND ( MATCH(DC.name,DC.mname,DC.lname) AGAINST ('+{$matchValue}' IN BOOLEAN MODE) )";
                }else{
                    $conditions .= " AND (DC.email LIKE '%{$filter}%')";
                }
            }

            $filterByMostReview = false;
            $tmp_cond = "";
            if(!empty($icon_filter)){
                $this->loadModel('CatIconTrophy');
                $arr_filter = explode('||', $icon_filter);
                foreach($arr_filter as $item){
                    if($item == 'MOST_REVIEW'){
                        $filterByMostReview = true;
                    }else{
                        $icon = $this->CatIconTrophy->find()->select(['CatIconTrophy.id'])->where(['CatIconTrophy.uid' => $item])->first();
                        if(!empty($icon)){
                            $tmp_cond .= " AND DatIcon.icon_id = ".$icon->id; 
                        }
                    }   
                }

                if(!empty($tmp_cond)){
                    $join .= " INNER JOIN  data_user_icon DatIcon ON DatIcon.user_id = DC.id ";
                }
            }

            // if ($user['user_role'] != 'patient') {
            //     $latitude = 0;
            // }
            
            $first_day = date('Y-m-01');
            $last_day = date('Y-m-t');
            
            $str_query_find = "
                SELECT 
                    *, 
                    DC.id as user_id, DC.city,
                    DC.show_most_review,
                    (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                    9999 distance_in_mi,
                    (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes    
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                {$tmp_cond}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        
            // echo $str_query_find; exit;
            
            $arr_find = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');
            
            $result = array();
            $result2 = array();

            $arr_review_reach = [];
            $arr_review_unreach = [];
            if (!empty($arr_find)) {
                $this->loadModel('SpaLiveV1.DataTreatmentReview');        
                $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();                                

                if(!filter_var($targetString, FILTER_VALIDATE_EMAIL)){
                    usort($arr_find, function($a, $b) use ($targetString) {
                        $full_name_a = $a['mname'] != '' ? $a['name'] . $a['mname'] . $a['lname'] : $a['name'] . $a['lname'];
                        $full_name_b = $b['mname'] != '' ? $b['name'] . $b['mname'] . $b['lname'] : $b['name'] . $b['lname'];
                        $similarityA = similar_text($full_name_a, $targetString);
                        $similarityB = similar_text($full_name_b, $targetString);
                        return $similarityB <=> $similarityA;
                    });
                }

                foreach($arr_find as $row) {
                    $cc = new CourseController();                        
                    $trainings_user = $cc->get_courses_user($row['user_id']);                      
                    if ($row['trainings'] > 0 && $row['active'] == 1) {
                        $first_letter_lname = !empty($row['lname']) ? strtoupper(substr($row['lname'], 0, 1)) : '';
                        $adding = array(
                            'uid' => $row['uid'],
                            'name' => $row['name'] . ' ' . $first_letter_lname,
                            'city' => $row['city'],
                            'short_uid' => $row['short_uid'],
                            'photo_id' => intval($row['photo_id']),
                            'description' => !empty($row['description']) ? $row['description'] : '',
                            'score' => 0,//$score,
                            'state' => $row['state'],
                            'availability' => $show_availability==1 ?$this->schedule_availability($row['uid']) : '',
                            'treatmets_provided' => 
                            $trainings_user['has_advanced_course'] ? 
                                array(
                                    'Neurotoxins (Basic)',
                                    'Neurotoxins (Advanced)',
                                ) : 
                                array(
                                    'Neurotoxins (Basic)',                            
                                ),
                            'likes' => $row['likes'],
                            // 'trainings' => $trainings_user,
                        );
                        $result[] = $adding;
                    }
                }
            }
        }    
        
        $this->set('data', $result);
        $this->success();
    }

    private function search_by_full_name_or_email($filter, $latitude, $longitude, $join, $conditions){
        $conditions = " AND DSM.days <> '' AND (SELECT COUNT(TrP.id) FROM data_treatments_prices TrP WHERE TrP.user_id = DC.id AND TrP.deleted = 0) > 0 ";
        $having = '';
        $order = '';
        if(!empty($filter)){
            $matchValue = str_replace(' ', '', $filter);
            if(!filter_var($filter, FILTER_VALIDATE_EMAIL)){
                $conditions .= " AND ( CONCAT(DC.name,DC.mname,DC.lname) = '{$matchValue}' OR CONCAT(DC.name,DC.lname) = '{$matchValue}')";                
            }else{
                $conditions .= " AND (DC.email = '{$filter}')";
            }
        }

        $filterByMostReview = false;
        $tmp_cond = "";
        if(!empty($icon_filter)){
            $this->loadModel('CatIconTrophy');
            $arr_filter = explode('||', $icon_filter);
            foreach($arr_filter as $item){
                if($item == 'MOST_REVIEW'){
                    $filterByMostReview = true;
                }else{
                    $icon = $this->CatIconTrophy->find()->select(['CatIconTrophy.id'])->where(['CatIconTrophy.uid' => $item])->first();
                    if(!empty($icon)){
                        $tmp_cond .= " AND DatIcon.icon_id = ".$icon->id; 
                    }
                }   
            }

            if(!empty($tmp_cond)){
                $join .= " INNER JOIN  data_user_icon DatIcon ON DatIcon.user_id = DC.id ";
            }
        }

        // if ($user['user_role'] != 'patient') {
        //     $latitude = 0;
        // }
        
        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        if ($latitude == 0 || $longitude == 0) {
            $str_query_find = "
                SELECT 
                    *, 
                    DC.id as user_id, DC.city,
                    DC.show_most_review,
                    (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                    9999 distance_in_mi,
                    (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes    
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                {$tmp_cond}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        } else {
            $str_query_find = "
                SELECT 
                    *, 
                    DC.id as user_id, DC.city,
                    DC.show_most_review,
                    (SELECT abv FROM cat_states CS WHERE CS.id = DC.state) state,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings,
                    69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                        * COS(RADIANS(DC.latitude))
                        * COS(RADIANS({$longitude} - DC.longitude))
                        + SIN(RADIANS({$latitude}))
                        * SIN(RADIANS(DC.latitude))))) AS distance_in_mi,
                    (SELECT Count(DTRT.id) FROM data_treatment_reviews DTRT WHERE DTRT.injector_id = DC.id AND DTRT.like = 'LIKE') likes
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.steps = 'HOME' AND DC.is_test = 0 {$conditions}
                {$tmp_cond}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        }
        
        $arr_find = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');
        
        $result = array();
        $result2 = array();

        $arr_review_reach = [];
        $arr_review_unreach = [];
        if (!empty($arr_find)) {
            $this->loadModel('SpaLiveV1.DataTreatmentReview');
            $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();                                

            if(!filter_var($filter, FILTER_VALIDATE_EMAIL)){
                usort($arr_find, function($a, $b) use ($filter) {
                    $full_name_a = $a['mname'] != '' ? $a['name'] . $a['mname'] . $a['lname'] : $a['name'] . $a['lname'];
                    $full_name_b = $b['mname'] != '' ? $b['name'] . $b['mname'] . $b['lname'] : $b['name'] . $b['lname'];
                    $similarityA = similar_text($full_name_a, $filter);
                    $similarityB = similar_text($full_name_b, $filter);
                    return $similarityB <=> $similarityA;
                });
            }

            foreach($arr_find as $row) {
                if ($row['trainings'] > 0 && $row['active'] == 1) {
                    $cc = new CourseController();                        
                    $trainings_user = $cc->get_courses_user($row['user_id']);    
                    $first_letter_lname = !empty($row['lname']) ? strtoupper(substr($row['lname'], 0, 1)) : '';
                    $adding = array(
                        'uid' => $row['uid'],
                        'name' => $row['name'] . ' ' . $first_letter_lname,
                        'city' => $row['city'],
                        'short_uid' => $row['short_uid'],
                        'photo_id' => intval($row['photo_id']),
                        'description' => !empty($row['description']) ? $row['description'] : '',
                        'score' => 0,//$score,
                        'state' => $row['state'],
                        'availability' => $this->schedule_availability($row['uid']),
                        'treatmets_provided' => 
                            $trainings_user['has_advanced_course'] ? 
                                array(
                                    'Neurotoxins (Basic)',
                                    'Neurotoxins (Advanced)',
                                ) : 
                                array(
                                    'Neurotoxins (Basic)',                            
                                ),
                        'likes' => $row['likes'],
                    );
                    $result[] = $adding;
                }
            }

        }

        return $result;
    }

    public function get_injector_treatments(){                
        $user_uid = get('user_uid', '');

        if(empty($user_uid)){
            $this->message('Invalid user.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');
        $this->loadModel('SpaLiveV1.CatTreatmentsCategory');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $user_uid])->first();

        if(empty($ent_user)){
            $this->message('Invalid user.');
            return;
        }

        $array_treatments = array();

        $cat_categorys = $this->CatTreatmentsCategory->find()->where(['CatTreatmentsCategory.deleted' => 0])->all();

        if(Count($cat_categorys) > 0){
            foreach($cat_categorys as $row){
                $array_list = array();
                $cat_treatment = $this->CatTreatmentsCi->find()
                ->join([
                    'Treatments' => ['table' => 'data_treatments_prices', 'type' => 'INNER', 'conditions' => 'Treatments.treatment_id = CatTreatmentsCi.id AND Treatments.deleted = 0']
                ])
                ->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'Treatments.user_id' => $ent_user->id, 'CatTreatmentsCi.deleted' => 0])
                ->order(['CatTreatmentsCi.id' => 'DESC'])->all();

                if(Count($cat_treatment) > 0) {
                    if(Count($cat_treatment) > 1){
                        $no_preference = $this->CatTreatmentsCi->find()
                            ->where(['CatTreatmentsCi.category_treatment_id' => $row->id, 'CatTreatmentsCi.deleted' => 0, 'CatTreatmentsCi.name' => 'Let my provider help me decide'])
                            ->order(['CatTreatmentsCi.id' => 'DESC'])->first();
                        if(!empty($no_preference)){
                            array_push($array_list, array(
                                'id' => $no_preference['id'],
                                'name' => $no_preference['name'],
                                'description' => $no_preference['description'],
                            ));
                        }
                    }
                    foreach($cat_treatment as $row2){
                        array_push($array_list, array(
                            'id' => $row2['id'],
                            'name' => $row2['name'],
                            'description' => $row2['description'],
                        ));
                    }
                }
                $array_treatments[] = array(
                    'title' => $row['name'],
                    'description' => $row['description'],
                    'image' => $row['image'],
                    'data' => $array_list,
                    'expand' => $row['name'] == 'Basic Neurotoxins' ? true : false,
                );
            }
        }

        $this->set('treatments', $array_treatments);
        $this->success();
    }    

    public function get_injector_schedule() {
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $this->loadModel('SpaLiveV1.DataClaimTreatments');
        $this->loadModel('SpaLiveV1.SysUsers');

        $user_uid = get('injector_uid', '');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $user_uid])->first();
        if(empty($ent_user)){            
            //echo json_encode(array("success"=> false, "treatments"=> [], "message"=>'Invalid user.')); exit;
            $this->message('Invalid user.');
            $this->set('session', false); return;
        }
        
        $mounth = get('mounth', '');
        if(empty($mounth)){
            $mounth = date('Y-m');
        }

        if(!empty($user_uid)){
            $this->loadModel('SpaLiveV1.SysUsers');
            $user_id = $this->SysUsers->uid_to_id($user_uid);
        }

        $dateFinal = date('Y-m-t', strtotime($mounth));
        $dInicio = date('Y-m-d', strtotime($mounth));
        $now = date('Y-m-d');

        $date = $dInicio;
        $arrayFechas = [];
        
        while ($date <= $dateFinal) {
            $name_day = date('l', strtotime($date));

            $_where = [ 
                'DataTreatment.deleted' => 0,
                'DataTreatment.status NOT IN' => array('CANCEL', 'REJECT'),
                '(DATE_FORMAT(DataTreatment.schedule_date, "%Y-%m-%d") = "' . $date . '")'
            ];


            if(!empty($user_uid)){
                $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $user_id, 'DataScheduleModel.days' => strtoupper($name_day)])->first();
                if(!isset($ent_sch_model)){
                    $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $user_id, 'DataScheduleModel.days like "%' . strtoupper($name_day) .'%"'])->first();
                }
                $isDayOff = $this->DataScheduleDaysOff->find()->where(['DataScheduleDaysOff.deleted' => 0, 'DataScheduleDaysOff.date_off' => $date, 'DataScheduleDaysOff.user_id' => $user_id])->first();
                $provider_id = $this->SysUsers->uid_to_id($user_uid);
                if($provider_id >= 0){
                    $_where['DataTreatment.assistance_id'] = $provider_id;
                } else {
                    $_where['DataTreatment.patient_id'] = USER_ID;
                }
            } else {
                $_where['DataTreatment.patient_id'] = USER_ID;
            }

            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.amount','DataTreatment.tip','DataTreatment.status','User.name','User.lname','Provider.name','Provider.lname','DataTreatment.created'];


            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTreatment.patient_id'],
                'Provider' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Provider.id = DataTreatment.assistance_id'],
            ])->where($_where)->all();
            // pr($certTreatment);
            // exit;
            $arrayTimes = [];
            $horaFin="19:30";$horaInicio="08:00";
            if(!empty($user_uid)){
                if(isset($ent_sch_model->time_start) && isset($ent_sch_model->time_end)){ 
                    if($date == date('Y-m-d', strtotime($now))){
                        $hora = date('H:i');//13.10
                        $horaInicio = $ent_sch_model->time_start.':00';                       
                        if($hora < date('H:30')){//18.30
                            $horaInicio = date('H:30');
                        } else if($hora >= date('H:30')){
                            $horaInicio = date('H:00', strtotime($hora."+ 1 hours"));
                        }
                        $horaFin = $ent_sch_model->time_end.':00';
                        $horaFin =  date('H:i', strtotime($horaFin));
                    } else{
                        $horaInicio = $ent_sch_model->time_start.':00';
                        $horaInicio =  date('H:i', strtotime($horaInicio));    
                        $horaFin = $ent_sch_model->time_end.':00';
                        $horaFin =  date('H:i', strtotime($horaFin));    
                    }
                }
            }else{
                if($date == date('Y-m-d', strtotime($now))){
                    $hora = date('H:i');
                    if($hora < date('H:30')){
                        $horaInicio = date('H:30');
                    } else if($hora >= date('H:30')){
                        $horaInicio = date('H:00', strtotime($hora."+ 1 hours"));
                    }
                } else{
                    $horaInicio = '08:00';
                }
            }
            $datetime = $date.' '.$horaInicio;
            $datetimeend = $date.' '.$horaFin;;
            
            while($datetime <= $datetimeend){
                $hora = date('h:i A', strtotime($datetime));
                if(Count($certTreatment) <= 0){
                    array_push($arrayTimes, array(
                        'time' => $hora,
                        'data' => array(
                            'status' => '',
                            'name' => '',
                            'provider' => '',
                            'date' => ''
                        )
                    ));
                    $datetime = date('Y-m-d H:i', strtotime($datetime."+ 30 minutes"));
                    continue;
                }
                
                $hourMatch = false;
                foreach($certTreatment as $row){
                    if ($row['status'] == 'PETITION') {                        
                        $ent_claim = $this->DataClaimTreatments->find()->where(['DataClaimTreatments.treatment_uid' => $row['uid'], 'DataClaimTreatments.deleted' => 0])->count();
                        //$row['status'] = $ent_claim > 0 ? 'CLAIMED' : 'PENDING CLAIM';
                        $now = date('Y-m-d H:i:s');
                        if( ($now > date('Y-m-d H:i:s', strtotime($row['created']->i18nFormat('yyyy-MM-dd HH:mm:ss','America/Chicago') . ' + 2 day')) && $ent_claim <= 0) || $now > date('Y-m-d H:i:s', strtotime($row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss','America/Chicago'))) ) {
                            $row['status'] = 'Expired';
                        }
                    }
                    if($row->schedule_date->i18nFormat('hh:mm a') == $hora){
                        if(!empty($user_uid)){
                            if($row['status'] == 'Expired'){
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => '',
                                        'name' => '',
                                        'provider' => '',
                                        'date' => ''
                                    )
                                ));
                            }else{
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => $row->status,
                                        'name' => '',
                                        'provider' => '',
                                        'date' => $row->schedule_date->i18nFormat('yyyy-MM-dd HH:mm')
                                    )
                                ));
                            }
                            
                        } else {
                            if($row['status'] == 'Expired'){
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => '',
                                        'name' => '',
                                        'provider' => '',
                                        'date' => ''
                                    )
                                ));
                            }else{
                                array_push($arrayTimes, array(
                                    'time' => $hora,
                                    'data' => array(
                                        'status' => $row->status,
                                        'name' => $row['User']['name'] . ' ' . $row['User']['lname'],
                                        'provider' => $row['Provider']['name'] . ' ' . $row['Provider']['lname'],
                                        'date' => $row->schedule_date->i18nFormat('yyyy-MM-dd HH:mm')
                                    )
                                ));
                            }
                        }
                       
                        $hourMatch = true;
                    } 
                }
                if(!$hourMatch){
                    array_push($arrayTimes, array(
                        'time' => $hora,
                        'data' => array(
                            'status' => '',
                            'name' => ' ',
                            'provider' => ' ',
                            'date' => ''
                        )
                    ));
                }
                $datetime = date('Y-m-d H:i', strtotime($datetime."+ 30 minutes"));
            }
            //$index = array_search($row->schedule_date->i18nFormat('HH:mm'), $horas);
            
            if(!empty($user_uid)){
                array_push($arrayFechas, array(
                    'date' => $date,
                    'appointments' => Count($certTreatment),
                    'data' => $arrayTimes,
                    'dayoff' => (!empty($isDayOff) || empty($ent_sch_model)) ? true : false,
                    'ent_sch_model' => $ent_sch_model,
                ));
            }else {
                array_push($arrayFechas, array(
                    'date' => $date,
                    'appointments' => Count($certTreatment),
                    'data' => $arrayTimes,
                    'dayoff' => false,
                ));
            }
            $date = date('Y-m-d', strtotime($date."+ 1 days"));
        }
        $this->set('data', $arrayFechas);
        $this->success();
    }

    private function schedule_availability($injector_uid) {
        $this->loadModel('SpaLiveV1.DataScheduleDaysOff');
        $this->loadModel('SpaLiveV1.DataUserUnavailable');

        $date = date('Y-m-d');
        $show_date = date('Y-m-d');

        $this->loadModel('SpaLiveV1.SysUsers');

        $injector_id = $this->SysUsers->uid_to_id($injector_uid);
        if($injector_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        
        $day_available = true;

        while ($day_available) {
            $first_day = \DateTime::createFromFormat('Y-m-d', $date); // Tipo Fecha
            $day = strtoupper($first_day->format('l'));
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $injector_id, 'DataScheduleModel.days LIKE' => $day, 'DataScheduleModel.model' => 'injector'])->first();
            if(empty($ent_sch_model)){
                $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $injector_id, 'DataScheduleModel.days LIKE' => '%,%'])->first();
            }
    
            if (!empty($ent_sch_model)) {
                $days = $ent_sch_model->days;
                $hour_start = $ent_sch_model->time_start;
                $hour_end = $ent_sch_model->time_end;
    
                $find_date_str = $first_day->format('Y-m-d');
    
                $isDayOff = $this->DataScheduleDaysOff->find()->where(['DataScheduleDaysOff.deleted' => 0, 'DataScheduleDaysOff.date_off' => $find_date_str, 'DataScheduleDaysOff.user_id' => $injector_id])->first();
                if(!empty($isDayOff)){
                    $date = date('Y-m-d', strtotime($date.'+ 1 days'));
                }

                //Search next available day
                
                if (!empty($days) && !empty($day)) {
    
                    if(strpos($days, $day) !== false){
                       
                    } else {
                        $date = date('Y-m-d', strtotime($date.'+ 1 days'));
                    }
                }
    
                $this->loadModel('SpaLiveV1.DataScheduleAppointment');
                $daysUnavObj = $this->DataUserUnavailable->find()->where(['DataUserUnavailable.day_unavailable' => $find_date_str, 'DataUserUnavailable.deleted' => 0, 'DataUserUnavailable.injector_id' => $injector_id])->toArray();
                $treatments_id = !empty($daysUnavObj) ? Hash::extract($daysUnavObj, '{n}.treatment_id') : [];
                $daysUnav = [];
    
                foreach($daysUnavObj as $item){
                    $daysUnav[] = $find_date_str . " " . $item->time_unavailable->format("H:i:s");
                }
                
                $where = ['DataScheduleAppointment.deleted' => 0, 'DataScheduleAppointment.injector_id' => $injector_id,'DATE(DataScheduleAppointment.created)' => $find_date_str];
                if(!empty($treatments_id))$where['DataScheduleAppointment.treatment_id NOT IN'] = $treatments_id;
                $ent_appointments = $this->DataScheduleAppointment->find()->where($where)->all();
    
                $not_hours = array();
                $yes_hours = array();
    
                $today = date('Y-m-d');
    
                $qlimit = intval(date('H'));
                if ($qlimit > 0) $qlimit--;
                if ($date < $today) $qlimit = 24;
                if ($date > $today) $qlimit = 0;
    
                for($q=5;$q<=$qlimit;$q++) {
                    $qq = $q . ':00';
                    $not_hours[$qq] = true;
                    $qq = $q . ':30';   
                    $not_hours[$qq] = true;   
                }
                
                foreach ($ent_appointments as $row) {
                    $not_hours[$row['created']->format("H:i")] = true;
                }
    
                $array_available = array();
                $result = array();
                
                for ($i = $hour_start; $i < $hour_end; $i++) {
                    $ii = $i;
                    $add = "a.m.";
                    $iii = $i . ':30';
                    $iiii = $i . ':00';
    
                    if (!isset($not_hours[$iiii])) {
                        if ($i >= 12)  { $add = "p.m."; if ($ii > 12 ) $ii = $ii - 12; }
                        $array_available[] = array(
                            'label' => $ii . ':00 ' . $add,
                            'save' => $find_date_str . " " . ($i >= 10 ? $i : "0" . $i) . ":00:00"
                        );
                    }
                   
                    if (!isset($not_hours[$iii])) {
                        $array_available[] = array(
                            'label' => $ii . ':30 ' . $add,// . ' - ' . ($ii + 1) . ':00 ' . $add2,
                            'save' => $find_date_str . " " . ($i >= 10 ? $i : "0" . $i) . ":30:00"
                        );
                    }
                }
    
                foreach ($array_available as $key => $item) {
                    if(in_array($item['save'], $daysUnav)){
                        //unset($array_available[$key]);
                        continue;   
                    }
                    $result[] = $item;
                }
    
                if(!empty($ent_appointments)){
                    if(Count($result) > 0){
                        $day_available = false;
                        $show_date = $first_day->format('m-d-Y');
                    }
                    else{
                        $date = date('Y-m-d', strtotime($date.'+ 1 days'));
                    }
                }
    
            } else {
                return 'Not available.';
            }
        }

        return $show_date;
    }

    public function save_treatment_type(){                
        $user_uid = get('user_uid', '');
        $treatment_type= get('treatment_type', '');
        if(empty($user_uid)){
            $this->message('Invalid user.');
            return;
        }
        if(empty($treatment_type)){
            $this->message('Invalid injector.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.SysUsers');        
        $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $user_uid])->first();

        if(empty($ent_user)){
            $this->message('Invalid user.');
            return;
        }     

        $this->SysUsers->updateAll(
            [
                'treatment_type' => $treatment_type,                
            ],
            ['id' => $ent_user->id]
        );        

        $this->success();
    }

    public function get_refer_a_friend_info()
    {
        $token = get('token', '');
        $this->loadModel('SpaLiveV1.DataNewsletterState');
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
        
        $this->loadModel('SpaLiveV1.CatLabels');
        $ent_label = $this->CatLabels->find()->where(['CatLabels.deleted' => 0, 'CatLabels.key_field' => 'refer_friend_cp_register'])->first();

        if(!empty($ent_label)){
            $this->set('info', $ent_label->value);
        } else {
            $this->set('info', '');
        }
        $this->success();
    }

    public function invite_referal_cp()
    {
        $token = get('token', '');
        $this->loadModel('SpaLiveV1.DataNewsletterState');
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
        
        $email = get('email', '');      

        if(empty($email)){
            $this->message('Invalid email.');
            return;
        }   

        $this->loadModel('SpaLiveV1.DataNetworkInvitations');
        $verify_invite = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email' => $email])->all();     
        
        if(count($verify_invite) > 0){
            $this->message('This email has already been invited.');
            return;
        }

        $invite = array(
            'email' => $email,
            'parent_id' => USER_ID,
            'paid' => 0       
        );

        $ent_invite = $this->DataNetworkInvitations->newEntity($invite);
        if($this->DataNetworkInvitations->save($ent_invite)){
            $this->loadModel("SpaLiveV1.CatNotifications");
            $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => 'CI_TO_CI_INVITE'])->first();
            if (!empty($ent_notification)) {

                $msg_mail = $ent_notification['body'];

                $this->loadModel("SpaLiveV1.SysUsers");
                $e_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
    
                $constants = [
                    '[CNT/CIName]' => $e_user['name'],
                    '[CNT/CILastName]' => $e_user['lname'],
                ];
                foreach($constants as $key => $value){
                        $msg_mail = str_replace($key, $value, $msg_mail);
                    }
                                

                $html_content = '<img src="' . $this->URL_PANEL . 'img/logo.png" width="100px"/>' . $msg_mail;

                $data=array(
                    'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                    'to'      => $email,
                    'subject' => 'New message from MySpaLive',
                    'html'    => $html_content,
                );

                $mailgunKey = $this->getMailgunKey();

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
                curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
                curl_setopt($curl, CURLOPT_POST, true); 
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

                $result = curl_exec($curl);
                curl_close($curl);
            }

            $this->success();
        } else {
            $this->message('Error.');
        }
    }
    
    // This helps to create a new patient account
    // that fits the new requirements.
    public function create_patient_account(){
        $isDev = env('IS_DEV', false);
        #region GET DATA
        $name = get('name', '');
        $lname = get('lname', '');
        $gender = get('gender', '');   
        $email = get('email', '');
        $phone = get('phone', '');
        $password = get('password', '');
        $state = get('state', 0);
        $register_flow = get('register_flow', 'LONGFORM');
        $password_confirm = get('password_confirm', '');
        $recommendation = get('recommendation', '');

        $type_service = get('type_service', 'NEUROTOXIN');
        if($type_service == 'NEUROTOXINS'){
            $type_service = 'NEUROTOXIN';
        }

        if(empty($name)){
            $this->message('Invalid name.');
            return;
        }
        if(empty($lname)){
            $this->message('Invalid last name.');
            return;
        }
        if(empty($gender)){
            $this->message('Invalid gender.');
            return;
        }
        if(empty($email)){
            $this->message('Invalid email.');
            return;
        }   
        if(empty($phone)){
            $this->message('Invalid phone.');
            return;
        }
        if(empty($password)){
            $this->message('Invalid password.');
            return;
        }
        if(empty($password_confirm)){
            $this->message('Invalid password confirm.');
            return;
        }

        if($state == 0){
            $this->message('Invalid state.');
            return; 
        }
        
        if($password != $password_confirm){
            $this->message('Passwords do not match.');
            return;
        }

        if ($recommendation == '') {
            $this->message('Invalid option about us.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->where(['CatStates.id' => $state, 'CatStates.deleted' => 0])->first();

        if(empty($ent_state)){
            $this->message('Invalid state.');
            return;
        }
        $str_state = $ent_state->name;

        $step = $ent_state->enabled == 1 ? 'LONGFORM' : 'STATENOTAVAILABLE';

        if($step == 'LONGFORM'){
            $step = $register_flow == 'SKIPGETGFE' ? 'LONGFORMSKIPGFE' : $step;
            $step = get('save_treatment', 0) == 1 ? 'LONGFORMOFFCODE' : $step;
        }

        $this->set('steps', $step);
        #endregion

        #region VALIDATE EMAIL ALREADY EXIST
        $this->loadModel('SpaLiveV1.SysUsers');
        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email)])->first();
        if(!empty($existUser)){
            
            $createdby = $existUser->createdby;
            if($existUser->steps === 'REGISTER' && $existUser->type === 'patient' && $createdby > 0){
                $this->SysUsers->updateAll(
                    [
                        'phone'     => $phone,
                        'name'      => get('name', ''),
                        'lname'     => get('lname', ''),        
                        'password'  => hash_hmac('sha256', $password, Security::getSalt()),            
                        'active'    => 1,      
                        'steps'     => $step,
                        'state'     => $state,
                        'created'   => date('Y-m-d H:i:s'),
                    ], 
                    ['id' => $existUser->id]
                );  

                $this->loadModel('SpaLiveV1.DataPatientClinic');
                $existRelat = $this->DataPatientClinic->find()
                ->where(['DataPatientClinic.injector_id' => $createdby, 'DataPatientClinic.user_id' => $existUser->id ,'DataPatientClinic.type' => 'neurotoxin'])->first();
                if(empty($existRelat)){
                    $arrLinkPat = [
                        'uid' => $this->DataPatientClinic->new_uid(),
                        'injector_id' => $createdby, 
                        'user_id' => $existUser->id,
                    ];
                    
                    $entLink = $this->DataPatientClinic->newEntity($arrLinkPat);
                    if(!$entLink->hasErrors()){
                        $this->DataPatientClinic->save($entLink);
                    }
                }
                #region SAVE USER IN sys_users_register TABLE

                $this->loadModel('SpaLiveV1.SysUsersRegister');

                $array_save_recommendation = array(
                    'user_id'       => $existUser->id,
                    'source'      => $recommendation,
                );

                $c_entity_about = $this->SysUsersRegister->newEntity($array_save_recommendation);

                if(!$c_entity_about->hasErrors()) {
                    $this->SysUsersRegister->save($c_entity_about);
                }

                #region SAVE USER IN sys_patients_other_service TABLE
                $this->loadModel('SpaLiveV1.SysPatientsOtherServices');

                $exist_service = $this->SysPatientsOtherServices->find()->where(['patient_id' => $existUser->id, 'deleted' => 0])->first();

                if(empty($exist_service)){
                    $_array_save = array(
                        'patient_id'       => $existUser->id,
                        'type'  => $type_service,
                    );
    
                    $_c_entity = $this->SysPatientsOtherServices->newEntity($_array_save);
    
                    if(!$_c_entity->hasErrors()) {
                        $this->SysPatientsOtherServices->save($_c_entity);
                    }
                }

                #endregion

                #region save treatmet
                if(get('save_treatment', 0) == 1){
                    $this->create_treatment_with_register($existUser, $state, get('ondemand_flow', 0));
                }
                #endregion

                $this->success();
                return; 
            }else{
                if($existUser->deleted == 1){
                    $this->message('The email address you are using belongs to an account that has been deleted.');
                    return;
                } else {
                    $this->message('The email address you are using already belongs to an active account.');
                    return;
                }
            }
        }
        #endregion

        #region CREATE USER

        $shd = false;
        $short_uid = '';    
        do {

            $num = substr(str_shuffle("0123456789"), 0, 4);
            $short_uid = $num . "" . strtoupper($this->generateRandomString(4));

            $existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
        if(empty($existUser))
            $shd = true;

        } while (!$shd);
        
        $array_save = array(
            'uid'           => $this->SysUsers->new_uid(),
            'short_uid'     => $short_uid,
            'email'         => $email, //
            'name'          => $name, //            
            'lname'         => $lname, //
            'gender'        => $gender, //
            'type'          => 'patient',             
            'password'      => hash_hmac('sha256', $password, Security::getSalt()),            
            'phone'         => $phone, //
            'active'        => 1,      
            'steps'         => $step,
            'state'         => $state
        );


        $c_entity = $this->SysUsers->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $entUser = $this->SysUsers->save($c_entity);
            $Main = new MainController();
            $message = $this->email_emily_after_registration_patient();
            $Main->notify_devices($message,array($entUser->id),false,true,false,array(),'');
            $this->success();
                                    
            $user = $this->SysUsers->find()->where(['SysUsers.short_uid ' => $short_uid])->first();
            
            if(!empty($user)){                 
                $created =  $user->created->i18nFormat('MM-dd-yyyy');                                
            }

            #region SAVE USER IN sys_users_register TABLE

            $this->loadModel('SpaLiveV1.SysUsersRegister');

            $array_save_recommendation = array(
                'user_id'       => $user->id,
                'source'      => $recommendation,
            );

            $c_entity_about = $this->SysUsersRegister->newEntity($array_save_recommendation);

            if(!$c_entity_about->hasErrors()) {
                $this->SysUsersRegister->save($c_entity_about);
            }

            #endregion

            #region save treatmet
            if(get('save_treatment', 0) == 1){
                $this->create_treatment_with_register($entUser, $state, get('ondemand_flow', 0));
            }
            #endregion

            #region SAVE USER IN sys_patients_other_service TABLE
            $this->loadModel('SpaLiveV1.SysPatientsOtherServices');

            $_array_save = array(
                'patient_id'       => $user->id,
                'type'  => $type_service,
            );

            $_c_entity = $this->SysPatientsOtherServices->newEntity($_array_save);

            if(!$_c_entity->hasErrors()) {
                $this->SysPatientsOtherServices->save($_c_entity);
            }

            if(get('type_service', 'NEUROTOXIN') == 'WEIGHT LOSS'){

                if($ent_state->enabled == 0){
                    $stp = "STATENOTAVAILABLE";
                    $this->success(false);
                    $this->message('State Not Available.');
                }else{
                    $stp = 'SHORTFORM';
                } 
                $this->SysUsers->updateAll(
                    ['steps' => $stp], 
                    ['id' =>  $user->id]
                );

                // $Main->notify_devices('REGISTERED_BY_WS',array($entUser->id),false,true,true,array(),'',array(),false);
            }

            #endregion

            // Este correo se enviaba, antes, se quito por la task Emails sent to 
            // patientrelations@myspalive.com must be sent when the patient purchases weight loss and not before
            // $email = get('email', '');
            // if($email!=''){
            //     $type = get('type', '');
            //     if($type!=""){
            //         $type = ucfirst($type);
            //     }
                
            //     $subject="New patient registration alert";
            //     $body= 'A new patient has registered in our system.<br><br>' .
            //     'Name: ' .get('name', '') .' '. get('lname', '') .'<br>'.
            //     'Registration date: ' .$created .'<br>'.
            //     'Patient type: ' .get('type', '') .'<br>'.
            //     'Phone: ' .$phone .'<br>'.
            //     'Email: ' .$email .'<br><br><br>';                                
            //     if($isDev){
            //         $this->send_email_after_register("francisco@advantedigital.com", $subject, $body);
            //     }else{
            //         $this->send_email_after_register("patientrelations@myspalive.com", $subject, $body);
            //     }                               
            // }            
        }      
        #endregion
        
    }

    public function create_account_ondemand(){
        $isDev = env('IS_DEV', false);
        #region GET DATA
        $name = get('name', '');
        $lname = get('lname', '');
        $gender = get('gender', '');   
        $email = get('email', '');
        $phone = get('phone', '');
        $password = get('password', '');
        $state = get('state', 0);
        $register_flow = get('register_flow', 'LONGFORM');
        $password_confirm = get('password_confirm', '');
        $recommendation = get('recommendation', '');

        $type_service = get('type_service', 'NEUROTOXIN');
        if($type_service == 'NEUROTOXINS'){
            $type_service = 'NEUROTOXIN';
        }

        if(empty($name)){
            $this->message('Invalid name.');
            return;
        }
        if(empty($lname)){
            $this->message('Invalid last name.');
            return;
        }
        if(empty($gender)){
            $this->message('Invalid gender.');
            return;
        }
        if(empty($email)){
            $this->message('Invalid email.');
            return;
        }   
        if(empty($phone)){
            $this->message('Invalid phone.');
            return;
        }
        if(empty($password)){
            $this->message('Invalid password.');
            return;
        }
        if(empty($password_confirm)){
            $this->message('Invalid password confirm.');
            return;
        }

        if($state == 0){
            $this->message('Invalid state.');
            return; 
        }
        
        if($password != $password_confirm){
            $this->message('Passwords do not match.');
            return;
        }

        if ($recommendation == '') {
            $this->message('Invalid option about us.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->where(['CatStates.id' => $state, 'CatStates.deleted' => 0])->first();

        if(empty($ent_state)){
            $this->message('Invalid state.');
            return;
        }
        $str_state = $ent_state->name;

        $step = $ent_state->enabled == 1 ? 'LONGFORMOFFCODE' : 'STATENOTAVAILABLE';

        $this->set('steps', $step);
        #endregion

        #region VALIDATE EMAIL ALREADY EXIST
        $this->loadModel('SpaLiveV1.SysUsers');
        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email)])->first();
        if(!empty($existUser)){

            $this->loadModel('SpaLiveV1.DataPatientOndemand');

            $existUserOndemand = $this->DataPatientOndemand->find()->where(['user_id' => $existUser->id, 'deleted' => 0])->first();

            if(empty($existUserOndemand)){ // vacio es que es un registro desde la app
                if($existUser->deleted == 1){
                    $this->message('The email address you are using belongs to an account that has been deleted.');
                    return;
                } else {
                    $this->message('The email address you are using already belongs to an active account.');
                    return;
                }
            }else { // no vacio es un registro desde la pagina de start.myspalive
                $this->loadModel('SpaLiveV1.DataConsultation');

                $existConsultation = $this->DataConsultation->find()->where(['DataConsultation.patient_id' => $existUser->id, 'DataConsultation.deleted' => 0, 'DataConsultation.status' => 'CERTIFICATE'])->first();

                if(!empty($existConsultation)){
                    $this->message('The email address you are using already belongs to an active account.');
                    return;
                }
                // si existe una consulta con status CERTIFICATE 
                // se procede a eliminar el registro de la tabla sys_users y sys_patients_ondemand
                $this->SysUsers->updateAll(
                    [
                        'email' => 'deleted_' . $existUser->email,
                        'deleted' => 1
                    ], 
                    ['id' => $existUser->id]
                );

                $this->DataPatientOndemand->updateAll(
                    [
                        'deleted' => 1
                    ], 
                    ['id' => $existUserOndemand->id]
                );

                //eliminar los payment_methos de stripe
                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
                $oldCustomer = $stripe->customers->all([
                    "email" => $existUser->email,
                    "limit" => 1,
                ]);

                if (count($oldCustomer) > 0) {
                    $customer = $oldCustomer->data[0];

                    $payment_methods = $stripe->customers->allPaymentMethods(
                        $customer->id,
                        ['type' => 'card']
                    );

                    if (count($payment_methods) > 0) {
                        foreach ($payment_methods as $payment_method) {
                            $stripe->paymentMethods->detach(
                                $payment_method->id
                            );
                        }
                    }
                }
            }
        }
        #endregion

        #region CREATE USER

        $shd = false;
        $short_uid = '';    
        do {

            $num = substr(str_shuffle("0123456789"), 0, 4);
            $short_uid = $num . "" . strtoupper($this->generateRandomString(4));

            $existUser = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
        if(empty($existUser))
            $shd = true;

        } while (!$shd);
        
        $array_save = array(
            'uid'           => $this->SysUsers->new_uid(),
            'short_uid'     => $short_uid,
            'email'         => $email, //
            'name'          => $name, //            
            'lname'         => $lname, //
            'gender'        => $gender, //
            'type'          => 'patient',             
            'password'      => hash_hmac('sha256', $password, Security::getSalt()),            
            'phone'         => $phone, //
            'active'        => 1,      
            'steps'         => $step,
            'state'         => $state
        );


        $c_entity = $this->SysUsers->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $entUser = $this->SysUsers->save($c_entity);
            $Main = new MainController();
            $message = $this->email_emily_after_registration_patient();
            $Main->notify_devices($message,array($entUser->id),false,true,false,array(),'');
            $this->success();
                                    
            $user = $this->SysUsers->find()->where(['SysUsers.short_uid ' => $short_uid])->first();
            
            if(!empty($user)){                 
                $created =  $user->created->i18nFormat('MM-dd-yyyy');                                
            }

            #region SAVE USER IN sys_users_register TABLE

            $this->loadModel('SpaLiveV1.SysUsersRegister');

            $array_save_recommendation = array(
                'user_id'       => $user->id,
                'source'      => $recommendation,
            );

            $c_entity_about = $this->SysUsersRegister->newEntity($array_save_recommendation);

            if(!$c_entity_about->hasErrors()) {
                $this->SysUsersRegister->save($c_entity_about);
            }

            #endregion

            #region save treatmet
            $this->create_treatment_with_register($entUser, $state, 1);
            #endregion

            #region SAVE USER IN sys_patients_other_service TABLE
            $this->loadModel('SpaLiveV1.SysPatientsOtherServices');

            $_array_save = array(
                'patient_id'       => $user->id,
                'type'  => $type_service,
            );

            $_c_entity = $this->SysPatientsOtherServices->newEntity($_array_save);

            if(!$_c_entity->hasErrors()) {
                $this->SysPatientsOtherServices->save($_c_entity);
            }

            if(get('type_service', 'NEUROTOXIN') == 'WEIGHT LOSS'){

                if($ent_state->enabled == 0){
                    $stp = "STATENOTAVAILABLE";
                    $this->success(false);
                    $this->message('State Not Available.');
                }else{
                    $stp = 'SHORTFORM';
                } 
                $this->SysUsers->updateAll(
                    ['steps' => $stp], 
                    ['id' =>  $user->id]
                );

                // $Main->notify_devices('REGISTERED_BY_WS',array($entUser->id),false,true,true,array(),'',array(),false);
            }

            #endregion
        }      
        #endregion
        
    }

    private function create_treatment_with_register($entUser, $state, $ondemand_flow = 0){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $treatment_uid = Text::uuid();
        $assistance_id = get('injector_id', 0);
        $string_treatments = get('treatments', '');
        $schedule_date = get('schedule_date','');

        if(empty($assistance_id)){
            $assistance_id = 0;
        }

        if (empty($schedule_date) && $ondemand_flow == 1){
            $fecha_schedule = date('Y-m-d');
            $hora = date('H:i');
            if($hora < date('H:30')){
                $horaschedule = date('H:30');
            } else if($hora >= date('H:30')){
                $horaschedule = date('H:00', strtotime($hora."+ 1 hours"));
            }

            $horaschedule = date('H:i', strtotime($horaschedule."+ 2 hours"));

            $schedule_date = date('Y-m-d H:i:s', strtotime($fecha_schedule . ' ' . $horaschedule));
        }

        if($assistance_id > 0){
            $this->loadModel('SpaLiveV1.CatCITreatments'); 
            $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'Cat.type', 'CatCITreatments.category_treatment_id'])
            ->join([
                'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
            ])
            ->where(['CatCITreatments.id IN' => explode(',', $string_treatments)])->all();

            $array_list = array();
            
            foreach ($ent_treatments as $key => $value) {
                if($value['Cat']['type'] == 'FILLERS'){
                    $this->loadModel('SpaLiveV1.DataSubscriptions');
                    $ent_sub = $this->DataSubscriptions->find()->where(['user_id' => $assistance_id, 'deleted' => 0, 'status' => 'ACTIVE', 'subscription_type LIKE' => '%MD%'])->first();
                    if(strpos($ent_sub->subscription_type, 'FILLERS') !== false){
                        $array_list[] = $value->id;
                    }
                }else{
                    $array_list[] = $value->id;
                }
            }

            $string_treatments = implode(',', $array_list);
        }

        if($ondemand_flow == 1){
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.DataPatientOndemand');

            $array_save = array(
                'user_id' => $entUser->id,
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s'),
            );

            $c_entity = $this->DataPatientOndemand->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $ent_treat = $this->DataPatientOndemand->save($c_entity);
            }
        }

        $array_save = array(
            'uid' => $treatment_uid,
            'notes' => '',
            'patient_id' => $entUser->id,
            'assistance_id' => $assistance_id,
            'treatments' => $string_treatments,
            'amount' => 0,
            'address' => '',
            'suite' => '',
            'zip' => 0,
            'city' => '',
            'state' => $state,
            'schedule_date' => $schedule_date,
            'status' => $assistance_id == 0 ? 'INITOPEN' : 'INIT',
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'schedule_by' => $entUser->id,
            'createdby' => $entUser->id,
            'assigned_doctor' => 0,
            'type_uber' => $assistance_id == 0 ? 1 : 0,
        );

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $ent_treat = $this->DataTreatment->save($c_entity);
            $this->set('treatment_uid', $ent_treat->uid);
            if($ent_treat->type_uber == 1){
                $this->save_promo_first_treatment($ent_treat->id);
            }
        }
    }

    private function save_promo_first_treatment($treatment_id){
        $this->loadModel('SpaLiveV1.DataPromoFirstTreatments');

        $array_save = array(
            'treatment_id' => $treatment_id,
            'amount' => 5000,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0
        );
        $c_entity = $this->DataPromoFirstTreatments->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataPromoFirstTreatments->save($c_entity);
        }
    }

    public function send_email_after_register($to, $subject, $body){

        $data = array(
            'from'    => 'MySpaLive <info@mg.myspalive.com>',
            // 'to'    => 'angel@advantedigital.com'
            'to'    => $to,
            'subject' => $subject,
            'html'    => $this->getEmailFormat($body),
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);
        curl_close($curl);
        
        if(isset($result)){
            return true;
        }
        return false;
    }

    private function getEmailFormat($content) {
        return '
                <!doctype html>
                    <html>
                        <head>
                        <meta name="viewport" content="width=device-width">
                        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                        <title>MySpaLive Message</title>
                        <style>
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
                            <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 30vw; padding: 10px; width: 580px;">
                                <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
                                <!-- START CENTERED WHITE CONTAINER -->
                                <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 20px;">
                                    <br><center><img src="https://blog.myspalive.com/wp-content/uploads/2021/05/MySpaLive-logo-login.png" width="150px"/></center>
                                    <!-- START MAIN CONTENT AREA -->
                                    <tr>
                                    <td class="wrapper" style="font-family: sans-serif; font-size: 14px; vertical-align: top; box-sizing: border-box; padding: 20px;">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                        <tr>
                                           ' . $content.'
                                        </tr>
                                        </table>
                                    </td>
                                    </tr>
    
                                <!-- END MAIN CONTENT AREA -->
                                </table>
    
                                <!-- START FOOTER -->
                                <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                                    <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                    <tr>
                                        <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                        <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://blog.myspalive.com/" style="color: #1D6782 !important;">MySpaLive</a></span>
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
    }

    public function generate_pdf_background_check_consent()
    {   
        $uid = get('uid', '');
        
        $this->loadModel('SpaLiveV1.SysUsers');
        
        $user = $this->SysUsers->find()->where(['SysUsers.uid' => $uid])->toArray();
        
        if(!empty($user))
        {
            $html_bulk = "
                <page>
                    <div style='width: 210mm; height: 97mm; position:relative;'>
                        <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                            <div style='top:30mm;'>
                                <img src='" . $this->URL_API . "img/logo.png' style='width=50mm;'/>
                            </div>
                            <div align='center'><h1><b>Background check consent</b></h1></div>                      
                            <div style='position: absolute;left: 12mm;top: 70mm; width: 190mm; background-color: white'><p>I authorize and give consent to MySpaLive to obtain information regarding myself for the purpose of running a background check.</p></div>
                            <div style='position: absolute;left: 12mm;top: 90mm; width: 190mm;'>
                                <span><b>" . $user[0]->name . " " . $user[0]->lname . "</b></span>
                            </div>
                        </div>
                    </div>
                </page>";

            $filename = 'BackgroundCheckConsent' . $user[0]->id . '.pdf';
            $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
            $html2pdf->writeHTML($html_bulk);
            $html2pdf->Output($filename, 'I'); 
        }
    }
    public function send_email_j_j($id=0){
        //$id = get('id', 7623);
        $isDev = env('IS_DEV', false);
        $this->loadModel('SpaLiveV1.SysUsers');
        $userEntity = $this->SysUsers        
        ->find()
        ->select(['SysUsers.id','SysUsers.name','SysUsers.lname','SysUsers.email','SysUsers.phone',
        'SysUsers.street','SysUsers.suite','SysUsers.city','SysUsers.zip','states.name','representative.email'])
        ->join(['states' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'states.id = SysUsers.state'],        ])
        ->join(['DAR' => ['table' => 'data_assigned_to_register', 'type' => 'LEFT', 'conditions' => 'DAR.user_id = SysUsers.id'],])
        ->join(['representative' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'DAR.representative_id = representative.id'],])
        ->where(['SysUsers.id' => $id])->first();
        $this->set('user', json_encode($userEntity));
        if(empty($userEntity)) {return;};
        $subject="New Injector registration alert";
        $body= '<p  style="font-size: 20px;">A new Injector has registered in our system.</p><br><br>'.
        '<p><span  style="font-weight: bold;">Name:</span> ' .$userEntity->name .' '. $userEntity->lname .'<br>'.
        '<span  style="font-weight: bold;">Email:</span> ' .$userEntity->email .'<br>'.                                
        '<span  style="font-weight: bold;">Phone:</span> ' .$userEntity->phone .'<br>'.
        //'<span  style="font-weight: bold;">Address:</span> ' .$userEntity->street .' '.$userEntity->suite.' '. $userEntity->city.' '. $userEntity->zip  .'<br>'.
        '<span  style="font-weight: bold;">State:</span> ' .$userEntity['states']['name'] .'<br></p>';        
        
        $this->set('body', json_encode($body));
        
        if($isDev){
            $this->send_email_after_register("francisco@advantedigital.com", $subject, $body);
        }else{
            if(isset($userEntity['representative']['email'])){
            $this->send_email_after_register($userEntity['representative']['email'], $subject, $body);
            //$this->send_email_after_register("francisco@advantedigital.com,carlos@advantedigital.com", $subject, $body);
            }else{
                $this->log(__FILE__." ".__LINE__ . " invalid email " );
            }
        }
        return;
    }

    public function reserveNow(){ //RESERVENOW 
        //$token = get('token', '');
        //if (empty($token)) return;
        

        $name = get('name', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
        $state = get('state', '');
        $mname = get('mname', '');
        $google_id = get('google_id', '');
        $statename = get('statename', '');
        $interface = 'Web';
        $type_register = get('type_register','');
        $email = get('email', '');                
        if (preg_match('#android#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "Android";
        if (preg_match('#(iPad|iPhone|iPod)#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "iOS";

        $this->loadModel('SpaLiveV1.DataPreRegister');
        $phone = get('phone', '');
        if (empty($phone)) {
            return;
        }
 
        $ent_preregister = $this->DataPreRegister->new_entity([
            'uid'           => $this->DataPreRegister->new_uid(),
            'email'         => get('email', ''),
            'name'          => get('name', ''),
            'mname'         => get('mname', ''),
            'lname'         => get('lname', ''),
            'type'          => $type_register,
            'state'         => get('statename', 'Texas'),
            'state_id'      => get('state', 43),
            'street'        => get('street', ''),
            'suite'         => get('suite', ''),
            'city'          => get('city', ''),
            'zip'           => get('zip', ''),
            'phone'         => get('phone', ''),
            'business_name' => get('business_name', ''),
            'business_ein'  => get('business_ein', ''),
            'interface'     => $interface,
            // 'origin'    => get('origin', ''),
            'status'        => get('status', 'PENDING FORM'),
        ]);
        $this->DataPreRegister->save($ent_preregister);

        $this->loadModel('SpaLiveV1.DataAnalytics');

        $ent = $this->DataAnalytics->find()->where(['DataAnalytics.google_id' => $google_id])->first();

        if(empty($ent)){
            if($type_register == 'RESERVENOW'){
                $array_save = [
                    'google_id' => $google_id,
                    'phone' => $phone,
                    'register_reserve' => 1,
                    'register_discount' => 0,
                    'register_pay_later' => 0,
                    'use_qr' => 0,
                    'use_button_android' => 0,
                    'use_button_ios' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0
                ];
            }else{
                $array_save = [
                    'google_id' => $google_id,
                    'phone' => $phone,
                    'register_reserve' => 0,
                    'register_discount' => 0,
                    'register_pay_later' => 1,
                    'use_qr' => 0,
                    'use_button_android' => 0,
                    'use_button_ios' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0
                ];
            }
            $c_entity = $this->DataAnalytics->newEntity($array_save);
            $this->DataAnalytics->save($c_entity);
        }else{
            if($type_register == 'RESERVENOW'){
                if($ent->phone == '' && ($ent->register_reserve == 0 || $ent->register_discount == 0 || $ent->register_pay_later == 0)){
                    $this->DataAnalytics->updateAll(
                        ['phone' => $phone, 'register_reserve' => 1],
                        ['google_id' => $google_id]
                    );
                }
            }else{
                if($ent->phone == '' && ($ent->register_reserve == 0 || $ent->register_discount == 0 || $ent->register_pay_later == 0)){
                    $this->DataAnalytics->updateAll(
                        ['phone' => $phone, 'register_pay_later' => 1],
                        ['google_id' => $google_id]
                    );
                }
            }
        }
         
        $this->success();
        $isDev = env('IS_DEV', false);
        $subject="New registration on welcome page";
        $body= '<p  style="font-size: 20px;">The welcome page has a new entry.</p><br><br>'.
        '<p><span  style="font-weight: bold;">Name:</span> ' .$name .' '. $lname .'<br>'.
        //'<span  style="font-weight: bold;">Email:</span> ' .$userEntity->email .'<br>'.                                
        '<span  style="font-weight: bold;">Phone:</span> ' .$phone .'<br>'.
        //'<span  style="font-weight: bold;">Address:</span> ' .$userEntity->street .' '.$userEntity->suite.' '. $userEntity->city.' '. $userEntity->zip  .'<br>'.
        '<span  style="font-weight: bold;">State:</span> ' .$statename .'<br></p>'.
        '<span  style="font-weight: bold;">Form:</span> '. $type_register .' <br></p>'; 
         if($isDev){
            $this->send_email_after_register("francisco@advantedigital.com", $subject, $body);
        }else{
            $this->send_email_after_register("jenna@myspalive.com", $subject, $body);
            try {     
                if($type_register == 'RESERVENOW'){
                    $conf_body_push ="Injector registration from website´. ".$name ." ". $phone;
                } else {
                    $conf_body_push ="Injector buy now pay later from website´. ".$name ." ". $phone;
                }
                
                $phone_number = env('TWILIO_PHONE_NUMBER');
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token);                  
                $twilio_message = $twilio->messages 
                          ->create($phone_number, // to 
                                   array(  
                                       "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                       "body" => $conf_body_push 
                                   ) 
                          ); 
                $account_sid = $twilio_message->accountSid;                                
             } catch (TwilioException $e) {
                $this->log(__LINE__ . " TwilioException ". $phone_number . " ". $conf_body_push. " ". json_encode($e->getCode()));
             }
        }
         $this->message("Thank you we’ve received your information someone will be contacting you to confirm.");
    }

    public function lookingForDiscount(){         

        $name = get('name', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
        $state = get('state', '');
        $mname = get('mname', '');
        $statename = get('statename', '');
        $google_id = get('google_id', '');
        $interface = 'Web';
        $type_register = "LOOKINGFORDISCOUNT";
        $email = get('email', '');                
         if (preg_match('#android#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "Android";
         if (preg_match('#(iPad|iPhone|iPod)#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "iOS";
 
         $this->loadModel('SpaLiveV1.DataPreRegister');
         $phone = get('phone', '');
         if (empty($phone)) {
             return;
         }
 
         $ent_preregister = $this->DataPreRegister->new_entity([
             'uid'           => $this->DataPreRegister->new_uid(),
             'email'         => get('email', ''),
             'name'          => get('name', ''),
             'mname'         => get('mname', ''),
             'lname'         => get('lname', ''),
             'type'          => $type_register,
             'state'         => get('statename', 'Texas'),
             'state_id'      => get('state', 43),
             'street'        => get('street', ''),
             'suite'         => get('suite', ''),
             'city'          => get('city', ''),
             'zip'           => get('zip', ''),
             'phone'         => get('phone', ''),
             'business_name' => get('business_name', ''),
             'business_ein'  => get('business_ein', ''),
             'interface'     => $interface,
             // 'origin'    => get('origin', ''),
             'status'        => get('status', 'PENDING FORM'),
         ]);
         $this->DataPreRegister->save($ent_preregister);
         // echo $ent_preregister; exit;
         $this->loadModel('SpaLiveV1.DataAnalytics');

        $ent = $this->DataAnalytics->find()->where(['DataAnalytics.google_id' => $google_id])->first();

        if(empty($ent)){
            $array_save = [
                'google_id' => $google_id,
                'phone' => $phone,
                'register_reserve' => 0,
                'register_discount' => 1,
                'register_pay_later' => 0,
                'use_qr' => 0,
                'use_button_android' => 0,
                'use_button_ios' => 0,
                'created' => date('Y-m-d H:i:s'),
                'deleted' => 0
            ];
            $c_entity = $this->DataAnalytics->newEntity($array_save);
            $this->DataAnalytics->save($c_entity);
        }else{
            if($ent->phone == '' && ($ent->register_reserve == 0 || $ent->register_discount == 0 || $ent->register_pay_later == 0)){
                $this->DataAnalytics->updateAll(
                    ['register_discount' => 1],
                    ['google_id' => $google_id]
                );
            }
        }
         
        $this->success();
        $this->success();
        $isDev = env('IS_DEV', false);
        $subject="New registration on welcome page";
        $body= '<p  style="font-size: 20px;">The welcome page has a new entry.</p><br><br>'.
        '<p><span  style="font-weight: bold;">Name:</span> ' .$name .'<br>'.
        //'<span  style="font-weight: bold;">Email:</span> ' .$userEntity->email .'<br>'.                                
        '<span  style="font-weight: bold;">Phone:</span> ' .$phone .'<br>'.
        //'<span  style="font-weight: bold;">Address:</span> ' .$userEntity->street .' '.$userEntity->suite.' '. $userEntity->city.' '. $userEntity->zip  .'<br>'.
        '<span  style="font-weight: bold;">Form:</span> Looking for discount<br></p>'; 
         if($isDev){
            $this->send_email_after_register("francisco@advantedigital.com", $subject, $body);
        }else{
            $this->send_email_after_register("jenna@myspalive.com", $subject, $body);
            try {     
                $phone_number = env('TWILIO_PHONE_NUMBER');
                $conf_body_push ="Injector looking for discount from website. ".$name ." ". $phone;
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token);                  
                $twilio_message = $twilio->messages 
                          ->create($phone_number, // to 
                                   array(  
                                       "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                       "body" => $conf_body_push 
                                   ) 
                          ); 
                $account_sid = $twilio_message->accountSid;                                
             } catch (TwilioException $e) {
                $this->log(__LINE__ . " TwilioException ". $phone_number . " ". $conf_body_push. " ". json_encode($e->getCode()));
             }
        }
         $this->message("Thank you we’ve received your information someone will be contacting you to confirm.");
    }

    public function preRegisterPatient(){  // PRE-REGISTER PATIENT patients.myspalive.com
        $name = get('name', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
        $state = get('state', '');
        $mname = get('mname', '');
        $google_id = get('google_id', '');
        $statename = get('statename', '');
        $interface = 'Web';
        $type_register = 'REGISTERPATIENT';
        $email = get('email', '');                
        if (preg_match('#android#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "Android";
        if (preg_match('#(iPad|iPhone|iPod)#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "iOS";

        $this->loadModel('SpaLiveV1.DataPreRegister');
        $phone = get('phone', '');
        if (empty($phone)) {
            return;
        }
 
        $ent_preregister = $this->DataPreRegister->new_entity([
            'uid'           => $this->DataPreRegister->new_uid(),
            'email'         => get('email', ''),
            'name'          => get('name', ''),
            'mname'         => get('mname', ''),
            'lname'         => get('lname', ''),
            'type'          => $type_register,
            'state'         => get('statename', 'Texas'),
            'state_id'      => get('state', 43),
            'street'        => get('street', ''),
            'suite'         => get('suite', ''),
            'city'          => get('city', ''),
            'zip'           => get('zip', ''),
            'phone'         => get('phone', ''),
            'business_name' => get('business_name', ''),
            'business_ein'  => get('business_ein', ''),
            'interface'     => $interface,
            // 'origin'    => get('origin', ''),
            'status'        => get('status', 'PENDING FORM'),
        ]);
        $this->DataPreRegister->save($ent_preregister);

        $this->loadModel('SpaLiveV1.DataAnalyticsPatients');

        $ent = $this->DataAnalyticsPatients->find()->where(['DataAnalyticsPatients.google_id' => $google_id])->first();

        if(empty($ent)){
            $array_save = [
                'phone' => $phone,
                'register' => 1,
                'created' => date('Y-m-d H:i:s'),
            ];
            $c_entity = $this->DataAnalyticsPatients->newEntity($array_save);
            $this->DataAnalyticsPatients->save($c_entity);
        }else{
            if($ent->phone == '' && $ent->register == 0){
                $this->DataAnalyticsPatients->updateAll(
                    ['phone' => $phone, 'register' => 1],
                    ['google_id' => $google_id]
                );
            }
            
        }
         
        $this->success();
        // Este correo se enviaba, antes, se quito por la task Emails sent to 
        // patientrelations@myspalive.com must be sent when the patient purchases weight loss and not before
        /* $isDev = env('IS_DEV', false);
        $subject="New registration on patients page";
        $body= '<p  style="font-size: 20px;">The welcome page has a new entry.</p><br><br>'.
        '<p><span  style="font-weight: bold;">Name:</span> ' .$name .' '. $lname .'<br>'.
        //'<span  style="font-weight: bold;">Email:</span> ' .$userEntity->email .'<br>'.                                
        '<span  style="font-weight: bold;">Phone:</span> ' .$phone .'<br>'.
        //'<span  style="font-weight: bold;">Address:</span> ' .$userEntity->street .' '.$userEntity->suite.' '. $userEntity->city.' '. $userEntity->zip  .'<br>'.
        '<span  style="font-weight: bold;">State:</span> ' .$statename .'<br></p>'.
        '<span  style="font-weight: bold;">Form:</span> '. $type_register .' <br></p>'; 
        if($isDev){
            $this->send_email_after_register("francisco@advantedigital.com", $subject, $body);
        }else{
            $this->send_email_after_register("patientrelations@myspalive.com", $subject, $body);
            try {     
                $conf_body_push ="Neurotoxin registration patient from website. ".$name ." ". $phone;
                $phone_number = env('TWILIO_PHONE_NUMBER');
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token);                  
                $twilio_message = $twilio->messages 
                          ->create($phone_number, // to 
                                   array(  
                                       "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                       "body" => $conf_body_push 
                                   ) 
                          ); 
                $account_sid = $twilio_message->accountSid;                                
             } catch (TwilioException $e) {
                $this->log(__LINE__ . " TwilioException ". $phone_number . " ". $conf_body_push. " ". json_encode($e->getCode()));
             }
        } */
        $this->message("Thank you we’ve received your information someone will be contacting you to confirm.");
    }

    public function weight_loss_site() { // weightloss.myspalive.com registration form

        $name = get('name', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
       
        $google_id = get('google_id', '');
        $statename = get('statename', '');
        $interface = 'Web';
        $type_register = 'WEIGHTLOSSPATIENT';
        $email = get('email', '');                
        if (preg_match('#android#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "Android";
        if (preg_match('#(iPad|iPhone|iPod)#i', $_SERVER ['HTTP_USER_AGENT'])) $interface = "iOS";

        $this->loadModel('SpaLiveV1.DataPreRegister');
        $phone = get('phone', '');
        if (empty($phone)) {
            return;
        }
 
        $ent_preregister = $this->DataPreRegister->new_entity([
            'uid'           => $this->DataPreRegister->new_uid(),
            'email'         => get('email', ''),
            'name'          => get('name', ''),
            'mname'         => get('mname', ''),
            'lname'         => get('lname', ''),
            'type'          => $type_register,
            'state'         => get('statename', 'Texas'),
            'state_id'      => get('state', 43),
            'street'        => get('street', ''),
            'suite'         => get('suite', ''),
            'city'          => get('city', ''),
            'zip'           => get('zip', ''),
            'phone'         => get('phone', ''),
            'business_name' => get('business_name', ''),
            'business_ein'  => get('business_ein', ''),
            'interface'     => $interface,
            // 'origin'    => get('origin', ''),
            'status'        => get('status', 'PENDING FORM'),
        ]);
        $this->DataPreRegister->save($ent_preregister);

        $this->loadModel('SpaLiveV1.DataAnalyticsPatients');

        $ent = $this->DataAnalyticsPatients->find()->where(['DataAnalyticsPatients.google_id' => $google_id])->first();

        if(empty($ent)){
            $array_save = [
                'phone' => $phone,
                'register' => 1,
                'created' => date('Y-m-d H:i:s'),
            ];
            $c_entity = $this->DataAnalyticsPatients->newEntity($array_save);
            $this->DataAnalyticsPatients->save($c_entity);
        }else{
            if($ent->phone == '' && $ent->register == 0){
                $this->DataAnalyticsPatients->updateAll(
                    ['phone' => $phone, 'register' => 1],
                    ['google_id' => $google_id]
                );
            }
            
        }
         
        $this->success();
        // Este correo se enviaba, antes, se quito por la task Emails sent to 
        // patientrelations@myspalive.com must be sent when the patient purchases weight loss and not before
        /* $isDev = env('IS_DEV', false);
        $subject="New registration on weight loss page";
        $body= '<p  style="font-size: 20px;">The weight loss page has a new entry.</p><br><br>'.
        '<p><span  style="font-weight: bold;">Name:</span> ' .$name .' '. $lname .'<br>'.
        //'<span  style="font-weight: bold;">Email:</span> ' .$userEntity->email .'<br>'.                                
        '<span  style="font-weight: bold;">Phone:</span> ' .$phone .'<br>'.
        //'<span  style="font-weight: bold;">Address:</span> ' .$userEntity->street .' '.$userEntity->suite.' '. $userEntity->city.' '. $userEntity->zip  .'<br>'.
        '<span  style="font-weight: bold;">State:</span> ' .$statename .'<br></p>'.
        '<span  style="font-weight: bold;">Form:</span> '. $type_register .' <br></p>'; 
         if($isDev){
            $this->send_email_after_register("francisco@advantedigital.com", $subject, $body);
        }else{
            $this->send_email_after_register("patientrelations@myspalive.com", $subject, $body);
            try {     
                $conf_body_push ="Weight loss registration patient from website. ".$name ." ". $phone;
                $phone_number = env('TWILIO_PHONE_NUMBER');
                $sid    = env('TWILIO_ACCOUNT_SID'); 
                $token  = env('TWILIO_AUTH_TOKEN'); 
                $twilio = new Client($sid, $token);                  
                $twilio_message = $twilio->messages 
                          ->create($phone_number, // to 
                                   array(  
                                       "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                       "body" => $conf_body_push 
                                   ) 
                          ); 
                $account_sid = $twilio_message->accountSid;                                
             } catch (TwilioException $e) {
                $this->log(__LINE__ . " TwilioException ". $phone_number . " ". $conf_body_push. " ". json_encode($e->getCode()));
             }
        } */
        $this->message("Thank you we’ve received your information someone will be contacting you to confirm.");

    }

    private function email_emily_after_registration_patient(){ // EMAIL_EMILY_AFTER_REGISTRATION_PATIENT(){
        $email ="Welcome to the MySpaLive community! We're thrilled to have you onboard. With your new account, a world of transformative beauty and wellness services is just a click away. Allow us to introduce you to our premier offerings:
            <br>
            <br>
            
            <p>🌱 <b>MySpaLive Weight Loss Program</b> 🌱
            <br>
            Achieving your dream weight has never been this seamless:</p>
            
            
            <ul>
            <li><b>Personalized Plans:</b> Tailored to suit your unique goals and preferences.</li>
            <li><b>Semaglutide Treatment:</b> Available both as injections and easy-to-take lozenges. The latter offers reduced side effects, giving you a comfortable weight loss journey.</li>
            <li><b>Expert Guidance:</b> Our team ensures you're supported every step of the way, helping you achieve sustainable results.</li>
            </ul>
            
            <p>💉 <b>Neurotoxin Cosmetic Injections – Delivered by Experts</b> 💉
            <br>
            Get ready to rejuvenate your look without the hassle:</p>
            
            
            <ul>
            <li><b>Concierge Service:</b> Expert injectors are available to offer treatments at your chosen location, be it home or office.</li>
            <li><b>Safe & Effective:</b> Rely on our certified professionals using top-tier products to give you the refreshed appearance you desire.</li>
            <li><b>Immediate Bookings:</b> Seamlessly book appointments with our skilled injectors, ensuring timely and efficient care.</li>
            </ul>
            
            <b>How to Get Started?</b>
            
            
            <ol>
            <li><b>Download Our App:</b> We're available on both Android and iOS, offering you easy access to our services right at your fingertips.</li>
            <li><b>Explore & Book:</b> Browse through our offerings, learn more about what suits you best, and book your desired services instantly.</li>
            <li><b>Stay Connected:</b> Receive tips, updates, and special offers to enhance your MySpaLive experience.</li>
            </ol>
            Thank you for choosing MySpaLive. Our mission is to empower you with services that make you look and feel your best. We can't wait to be a part of your transformative journey!
            
            
            
            Warmly,
            
            The MySpaLive Team.";

        $email = 
        "
        <h3>Welcome to the MySpaLive Community!</h3>
        <p>We're thrilled to have you onboard. With your new account, a world of transformative beauty and wellness services is just a click away. Allow us to introduce you to our premier offerings:</p>
        <hr>
        <div class='section'>
            <h3>Neurotoxin Cosmetic Injections – Delivered by Experts</h3>
            <p>Rejuvenate your look with unparalleled convenience:</p>
            <ul>
                <li><strong>Concierge Service:</strong> Our expert injectors come to you—whether at home or the office.</li>
                <li><strong>Safe & Effective:</strong> Certified professionals use top-tier products to ensure optimal results.</li>
                <li><strong>Immediate Bookings:</strong> Schedule seamlessly through the app for timely, efficient care.</li>
            </ul>
        </div>
        <hr>
        <div class='section'>
            <h3>MySpaLive Weight Loss Program</h3>
            <p>Achieve your dream weight with ease:</p>
            <ul>
                <li><strong>Personalized Plans:</strong> Tailored to your unique goals and preferences.</li>
                <li><strong>Semaglutide Treatment:</strong> Choose from injections or lozenges, with the latter offering reduced side effects.</li>
                <li><strong>Expert Guidance:</strong> Dedicated support for sustainable, long-term results.</li>
            </ul>
        </div>
        <hr>
        <div class='section'>
            <h3>How to Get Started?</h3>
            <ul>
                <li><strong>Download Our App:</strong> Available on Android and iOS for instant access to our services.</li>
                <li><strong>Explore & Book:</strong> Browse our offerings, learn what suits you best, and schedule your services effortlessly.</li>
                <li><strong>Stay Connected:</strong> Receive tips, updates, and exclusive offers to enhance your MySpaLive experience.</li>
            </ul>
        </div>

        <p>Thank you for choosing MySpaLive! Our mission is to empower you with services that make you look and feel your best. We can’t wait to be a part of your transformative journey!</p>

        <p>Warmly,<br>The MySpaLive Team</p>
        ";

        return $email;
    }

    public function register_school_site(){
        $name = get('name', '');
        $lname = get('lname', '');
        $state = get('state', '');
        $nameschool = get('nameschool', '');
        $schoolphone = get('completed_training', '');

        $html_content = '
            <br><br>' .
            '<b>Name: </b>' . $name . ' ' . $lname . 
            '<br><b>State: </b>' . $state . 
            '<br><b>Name of the school: </b>' . $nameschool . 
            '<br><b>completed training: </b>' . $schoolphone
        ;
        $fname = TMP . 'files' . DS . 'botoxaftercare.pdf';
        $data = array(
            'from'    => 'MySpaLive <info@mg.myspalive.com>',
            'to'    => 'jennaleighbichler@gmail.com',
            'subject' => "Affiliate school request",
            'html'    => $html_content,
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.myspalive.com/messages');
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($curl, CURLOPT_POST, true); 
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        $result = curl_exec($curl);
        curl_close($curl);
        $this->success();
    }

    public function patient_label_promo(){
        $this->set('label1', 'Eligible for $50 Off');
        $this->set('label2', '(Includes $50 first-time discount for eligible treatments.)');

        $this->success();
    }

    public function check_link(){
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

        if(USER_TYPE != 'injector'){
            $this->message('Invalid user type.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $link_type = get('link_type', '');

        switch($link_type){
            case 'register_basic':
                $payment = $this->DataPayment->find()->where(['id_from' => USER_ID, 'type' => 'BASIC COURSE', 'is_visible' => 1, 'payment <>' => ''])->first();
                if(empty($payment)){
                    switch (USER_STEP) {
                        case 'MATERIALS':
                            $this->set('redirect', 'materials_basic');
                            break;
                        case 'WAITINGSCHOOLAPPROVAL':
                            $this->set('redirect', 'materials_other_school_basic_waiting');
                            break;
                        case 'CERTIFICATESCHOOLAPPROVED':
                            $this->set('redirect', 'materials_other_school_basic_approved');
                            break;
                        case 'WAITINGIVAPPROVAL':
                            $this->set('redirect', 'materials_iv_basic_waiting');
                            break;
                        case 'APPIVAPPROVED':
                            $this->set('redirect', 'materials_iv_basic_approved');
                            break;
                        case 'WAITINGFILLERSAPPROVAL':
                            $this->set('redirect', 'materials_fillers_basic_waiting');
                            break;
                        case 'FILLERSAPPROVED':
                            $this->set('redirect', 'materials_fillers_basic_approved');
                            break;
                        case 'HOME':
                            $this->set('redirect', 'certifications');
                            break;
                        default:
                            $this->set('redirect', 'get_step');
                            break;
                    }
                }else{
                    $this->set('redirect', 'get_step');
                    return;
                }

                break;
            case 'register_advanced':
                $payment = $this->DataPayment->find()->where(['id_from' => USER_ID, 'type' => 'ADVANCED COURSE', 'is_visible' => 1, 'payment <>' => ''])->first();
                if(empty($payment)){
                    switch (USER_STEP) {
                        case 'MATERIALS':
                            $this->set('redirect', 'materials_advanced');
                            break;
                        case 'WAITINGSCHOOLAPPROVAL':
                            $this->set('redirect', 'materials_other_school_advanced_waiting');
                            break;
                        case 'CERTIFICATESCHOOLAPPROVED':
                            $this->set('redirect', 'materials_other_school_advanced_approved');
                            break;
                        case 'WAITINGIVAPPROVAL':
                            $this->set('redirect', 'materials_iv_advanced_waiting');
                            break;
                        case 'APPIVAPPROVED':
                            $this->set('redirect', 'materials_iv_advanced_approved');
                            break;
                        case 'WAITINGFILLERSAPPROVAL':
                            $this->set('redirect', 'materials_fillers_advanced_waiting');
                            break;
                        case 'FILLERSAPPROVED':
                            $this->set('redirect', 'materials_fillers_advanced_approved');
                            break;
                        case 'HOME':
                            $this->set('redirect', 'certifications');
                            break;
                        default:
                            $this->set('redirect', 'get_step');
                            break;
                    }
                }else{
                    $this->set('redirect', 'get_step');
                    return;
                }
                
                break;
            case 'certification_advanced':
                if(USER_STEP != 'HOME'){
                    $this->set('redirect', 'get_step');
                }else{
                    $this->set('redirect', 'certifications_advanced');
                }
                break;
            case 'certification_level3':
                if(USER_STEP != 'HOME'){
                    $this->set('redirect', 'get_step');
                }else{
                    $this->set('redirect', 'certifications_level3');
                }
                break;
            default:
                $this->message('Invalid link type.');
                return;
        }

        $this->success();
    }

    public function confirm_purchase() {

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

        $this->loadModel('SpaLiveV1.DataPurchases');

        $purchase_uid = get('purchase_uid', '');

        $is_pickup = get('is_pickup', 0);

        if(empty($purchase_uid)){
            $purchase = $this->DataPurchases->find()->where(['user_id' => USER_ID])->last();

            if(empty($purchase)){
                $this->message('No purchase found.');
                return;
            }

            $purchase_uid = $purchase->uid;
        }

        if($is_pickup == 1){
            $this->DataPurchases->updateAll(
                [
                    'is_pickup' => 1,
                ],
                ['uid' => $purchase_uid]
            );
        }else{
            $this->DataPurchases->updateAll(
                [
                    'address' => get('address',''),
                    'suite' => get('suite',''),
                    'city' => get('city',''),
                    'state' => get('state',''),
                    'zip' => get('zip',0),
                ],
                ['uid' => $purchase_uid]
            );
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->SysUsers->updateAll(
            ['steps' => 'W9'],
            ['id' => USER_ID]
        );

        $this->success();

    }

    public function create_patient_request(){
        $isDev = env('IS_DEV', false);
        #region GET DATA
        $name = get('name', '');
        $lname = get('lname', '');
        $email = get('email', '');
        $phone = get('phone', '');
        $zip = get('zip', '');
        $details = get('details', '');

        if(empty($name)){
            $this->message('Invalid name.');
            return;
        }
        if(empty($lname)){
            $this->message('Invalid last name.');
            return;
        }
        if(empty($email)){
            $this->message('Invalid email.');
            return;
        }   
        if(empty($phone)){
            $this->message('Invalid phone.');
            return;
        }
        if(empty($zip)){
            $this->message('Invalid zip.');
            return;
        }
        #endregion

        $this->loadModel('SpaLiveV1.DataPatientContactInfo');

        $array_save = [
            'name' => $name,
            'lname' => $lname,
            'email' => $email,
            'phone' => $phone,
            'zip' => $zip,
            'details' => $details,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0
        ];

        $c_entity = $this->DataPatientContactInfo->newEntity($array_save);
        $this->DataPatientContactInfo->save($c_entity);

        $this->success();
    }

    private function get_treatments_by_other_courses($user_id){

        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataCoverageCourses');
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $this->loadModel('SpaLiveV1.DataCourses');

        $courses_type = $this->CatCoursesType->find()->where(['CatCoursesType.deleted' => 0, 'CatCoursesType.available' => 1, 'CatCoursesType.name_key NOT IN' => ['MYSPALIVES_HYBRID_TOX_FILLER_COURSE', 'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE']])->all();

        $array_courses = [];

        foreach($courses_type as $course_type){
            $array_courses[] = $course_type->name_key;
        }

        $courses_user = $this->DataTrainings->find()
        ->select(['id' => 'CatTrainings.id', 'level' => 'CatTrainings.level'])
        ->join([
            'CatTrainings' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainings.id = DataTrainings.training_id']
        ])
        ->where(['DataTrainings.user_id' => $user_id, 'DataTrainings.deleted' => 0, 'CatTrainings.deleted' => 0, 'CatTrainings.level IN' => $array_courses])
        ->all();

        $array_treatments_ot = [];

        foreach($courses_user as $course_user){
            $course_type = $this->CatCoursesType->find()->where(['CatCoursesType.name_key' => $course_user->level])->first();

            if(empty($course_type)){
                continue;
            }

            $treatments_ot = $this->DataCoverageCourses->find()->where(['DataCoverageCourses.course_type_id' => $course_type->id])->all();

            if(empty($treatments_ot)){
                continue;
            }

            foreach($treatments_ot as $treatment_ot){
                $array_treatments_ot[] = $treatment_ot->ot_id;
            }
        }

        $os_courses = $this->DataCourses->find()
        ->select(['id' => 'STOT.id'])
        ->join([
            'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
            'CSOC' => ['table' => 'cat_school_option_cert', 'type' => 'INNER', 'conditions' => 'CSOC.id = CatCourses.school_option_id'],
            'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = CSOC.sys_treatment_ot_id']
        ])
        ->where([
            'DataCourses.user_id' => $user_id, 
            'DataCourses.deleted' => 0, 
            'DataCourses.status' => 'DONE', 
            'CatCourses.deleted' => 0,
            'CatCourses.type' => 'OTHER TREATMENTS',
            'STOT.deleted' => 0,
        ])
        ->all();
        
        if(count($os_courses) > 0){
            foreach($os_courses as $data_course){
                $array_treatments_ot[] = $data_course->id;
            }
        }

        $unique_array_treatments_ot = array_unique($array_treatments_ot);
        if (count($unique_array_treatments_ot) > 0)
            $sys_treatments_ot = $this->SysTreatmentsOt->find()->where(['SysTreatmentsOt.id IN' => $unique_array_treatments_ot])->all();
        else $sys_treatments_ot = [];

        return $sys_treatments_ot;
    }
}