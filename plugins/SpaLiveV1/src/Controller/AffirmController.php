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

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\PaymentsController;

use Cake\I18n\FrozenTime;
use Cake\Http\Response;

class AffirmController extends AppPluginController {

    private $total_subscriptionmsl = 3995;
	private $total_subscriptionmd = 17900;
    private $basic_course_price = 79500;
    private $advanced_course_price = 89500;
    private $advanced_techniques = 99500;
    private $weight_loss_with_affirm = 178500;
    private $level_3_fillers = 150000;//level 3
    private $level_1_to_1 = 19900;//level 1 to 1
    private $level_3_medical = 99500;//level 3 medical

    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";
    private $full_comission = 10000;

	public function initialize() : void {
        parent::initialize();
		$this->loadModel('SpaLiveV1.AppToken');
		$this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');
		$this->loadModel('SpaLiveV1.DataPayment');
		$this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.CatProducts');

        $this->URL_API = env('URL_API', 'https://api.spalivemd.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.spalivemd.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.spalivemd.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.spalivemd.com/');
        $this->API_KEY = env('API_KEY', '');

        $is_dev = env('IS_DEV', false);
        if($is_dev){
            $this->AFFIRM_PUBLIC_KEY = "0CTUEGQ3DGOA3E3X";
            $this->AFFIRM_PRIVATE_KEY = "FgT1Z7dJNrHFsAy5w3xiRwUsjAdN0ANz";
        }else{
            $this->AFFIRM_PUBLIC_KEY = env('AFFIRM_PUBLIC_KEY', '6VXQXNQZQZQZQZQZ');
            $this->AFFIRM_PRIVATE_KEY = env('AFFIRM_PRIVATE_KEY', '6VXQXNQZQZQZQZQZ');
        }


        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){
                $state = $this->CatStates->find()->select(['CatStates.price_sub_msl', 'CatStates.price_sub_md'])->where(['CatStates.id' => $user['user_state']])->first();
                if(!empty($state)){
                    $this->total_subscriptionmsl = $state->price_sub_msl > 0 ? $state->price_sub_msl : $this->total_subscriptionmsl;
                    $this->total_subscriptionmd = $state->price_sub_md > 0 ? $state->price_sub_md : $this->total_subscriptionmd;
                }
            }
        }

        $product = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 44])->first();
        if(!empty($product)){
            $this->advanced_course_price = $product->unit_price > 0 ? $product->unit_price : $this->advanced_course_price;
        }

        $product_advanced_techniques = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 178])->first();
        if(!empty($product_advanced_techniques)){
            $this->advanced_techniques = $product_advanced_techniques->unit_price > 0 ? $product_advanced_techniques->unit_price : $this->advanced_techniques;
        }
    }

    #region ENDPOINTS

    function create_payment_record_course(){
        if(!$this->validate_session()) return;

        $course = get('course', '');
        $total = 0;
        $subtotal = 0;
        $promo_code = get('promo_code', '');
        $service_uid = "";
        $type_promo = 'REGISTER';

        if(empty($course)) {
            $this->message('Invalid course.');
            return;
        }else{

            if($course == 'BASIC COURSE'){
                $total = $this->basic_course_price;
                $type_promo = 'TRAINING';
            }else if($course == 'ADVANCED COURSE'){
                $total = $this->advanced_course_price;
                $type_promo = 'TRAINING';
            }else if($course == 'WEIGHT LOSS'){
                $total = $this->weight_loss_with_affirm;
                $service_uid = "1q2we3-r4t5y6-7ui8o990";
            }else if($course == 'LEVEL 1-1 NEUROTOXINS'){
                $total = $this->level_1_to_1;
                $type_promo = 'TRAINING';
            }else if($course == 'FILLERS COURSE'){
                $total = $this->level_3_fillers;
                $type_promo = 'TRAINING';
            }else if($course == 'MEDICAL COURSE'){
                $course = 'ADVANCED TECHNIQUES MEDICAL';
                $total = $this->level_3_medical;
                $type_promo = 'TRAINING';
            }
        }

        $this->set('code_valid', false);
        $Payments = new \SpaLiveV1\Controller\PaymentsController();
        // $subtotal = $Payments->validateCode($promo_code,$total, $type_promo);
        
        /* $promo_discount = $total - $subtotal;

        if($promo_discount == 0){
            $promo_code = '';
        } */

        $uid = Text::uuid();
        $array_save = [
            'id_from'           => USER_ID,
            'id_to'             => 0,
            'uid'               => $uid,
            'service_uid'       => $service_uid,
            'type'              => $course,
            'promo_discount'    => 0,
            'promo_code'        => $promo_code,
            'subtotal'          => $total,
            'total'             => $total,
            'prod'              => 1,
            'is_visible'        => 0,
            'payment_platform'  => 'affirm',
            'created'           => date('Y-m-d H:i:s'),
            'createdby'         => defined('USER_ID') ? USER_ID : 0,
            'state'          => defined('USER_STATE') ? USER_STATE : 0,
        ];        
        
        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPayment->save($c_entity)) {
                $token = get('token', '');
                $url = env('URL_API', '');
                //$url = "http://localhost/apispalive/";

                $this->set('uid', $uid);
                $this->set('checkout_url', $url . '?key='. $this->API_KEY. '&action=Affirm____web_client&uid=' . $uid . '&token=' . $token);
                $this->success();
            }
        }
    }

    function check_payment_status(){
        if(!$this->validate_session()) return;
        
        $payment_uid     = get('uid', '');
        $request_details = get('request_details', 0); 

        $status = $this->getStatusPayment($payment_uid);
        
        if($status != "NOT FOUND"){
            if($request_details == 1){
                $details = $this->getDetailsPayment($payment_uid);
                $this->set('details', $details);

                if($status=="AUTHORIZED" && $details["type"] == 'WEIGHT LOSS'){
                    $this->loadModel('SpaLiveV1.SysUsers');
                    $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
                    $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
                    $this->loadModel('SpaLiveV1.DataPayment');

                    $pay = $this->DataPayment->find()->select(["DataPayment.id","DataPayment.uid","DataPayment.payment_platform","DataPayment.id_from",
                        "DataPayment.intent"])
                    ->where([
                        "DataPayment.uid" => $details["id"],
                        "DataPayment.payment_platform" => "affirm"
                    ])
                    ->first();

                    if(!empty($pay->intent)){

                        $consultation = $this->DataConsultationOtherServices->find()->select(
                            ["DataConsultationOtherServices.uid","DataConsultationOtherServices.service_uid","DataConsultationOtherServices.payment",
                            "DataConsultationOtherServices.payment_intent"])
                        ->where([
                            "DataConsultationOtherServices.payment" => $pay->intent,
                            "DataConsultationOtherServices.payment_intent" => $pay->intent,
                        ])
                        ->first();

                        if(!empty($consultation)){

                            $user_info = $this->SysUsers->find()->select(
                                ["SysUsers.id","SysUsers.name","SysUsers.city","SysUsers.street","SysUsers.suite","SysUsers.zip","SysUsers.state"])
                            ->where([
                                "SysUsers.id" => $pay->id_from,
                            ])
                            ->first();

                            $check_in = $this->DataOtherServicesCheckIn->find()->select(
                                ["DataOtherServicesCheckIn.id"])
                            ->where([
                                "DataOtherServicesCheckIn.call_type" => "FIRST CONSULTATION",
                                "DataOtherServicesCheckIn.consultation_uid" => $consultation->uid,
                            ])
                            ->first();

                            $data = array(
                                'name' => $user_info->name,
                                'city' => $user_info->city,
                                'street' => $user_info->street,
                                'suite' => $user_info->suite,
                                'zip' => $user_info->zip,
                                'state' => $user_info->state,
                                'consultationUid' => $consultation->uid,
                                'serviceUid' => $consultation->service_uid,
                                'checkinId' => $check_in->id,
                            );

                            $this->set('weight_loss_data', $data);
                        }
                    }
                }
            }
            
            $this->set('status', $status);  
            $this->success();
        }else{
            $this->message('Payment not found.');
        }
    }

    function check_method_available(){
        if(!$this->validate_session()) return;

        $purchase_type = get('purchase_type', '');

        $purchase_type = $purchase_type;

        if(empty($purchase_type)) {
            $this->message('Invalid purchase type.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_payment = $this->DataPayment->find()
            ->where([
                "DataPayment.id_from" => USER_ID,
                "DataPayment.type" => $purchase_type == 'MEDICAL COURSE' ? 'ADVANCED TECHNIQUES MEDICAL' : $purchase_type,
                "DataPayment.payment_platform" => "affirm"                
            ])
            ->order(['DataPayment.id' => 'DESC'])
            ->first();

        $available = true;
        $status    = 'AVAILABLE';

        if(!empty($ent_payment)){
            $status = $this->getStatusPayment($ent_payment->uid);

            if($status == 'DENIED'){
                $available = false;
                $status    = "We're sorry that you weren't approved, please pay with your CC.";
            }
            
        }

        $this->set('available', $available);
        $this->set('status', $status);
        $this->success();
    }

    function request_refund(){
        $l3n4p = get('l3n4p', '');
        if($l3n4p != '6092482f7ce858.91169218') {
            $this->message('Not allowed');
            return;
        }   

        $payment_uid = get('uid', '');

        if(empty($payment_uid)) {
            $this->message('Invalid payment.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "affirm"                
            ])
            ->order(['DataPayment.id' => 'DESC'])
            ->first();

        if(!empty($ent_payment)){

            $status = $this->getStatusPayment($ent_payment->uid);

            if($status == 'AUTHORIZED'){
                $affirm_response = json_decode($ent_payment->payment);

                $id_transaction = $affirm_response->id;
                $amount         = $affirm_response->amount;

                // SEND REQUEST TO AFFIRM

                $host = env('IS_DEV', false) 
                    ? 'https://sandbox.affirm.com'
                    : 'https://api.affirm.com';

                $public_api_key = $this->AFFIRM_PUBLIC_KEY;
                $private_api_key = $this->AFFIRM_PRIVATE_KEY;
                $url = "$host/api/v2/charges/$id_transaction/refund";

                $data = array(
                    'amount' => $amount,
                );

                $headers = array(
                    'accept: */*',
                    'authorization: Basic '.base64_encode($public_api_key.':'.$private_api_key),
                    'content-type: application/json'
                );
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
                $response = curl_exec($ch);

                if (curl_errno($ch)) {
                    echo 'cURL error: ' . curl_error($ch);
                    $this->message('Error requesting refund to Affirm, try again later. (1)');
                    return;
                }

                $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

                curl_close($ch);

                $success = $httpStatus == 200;
                if($success){

                    $array_save = array(
                        'id_from' => 0,
                        'id_to' => $ent_payment->id_from,
                        'uid' => $ent_payment->uid,
                        'type' => 'REFUND',
                        'intent' => $ent_payment->intent,
                        'payment' => $ent_payment->payment,                        
                        'receipt' => $response,
                        'promo_discount' => $ent_payment->promo_discount,
                        'promo_code' => $ent_payment->promo_code,
                        'discount_credits' => $ent_payment->discount_credits,
                        'subtotal' => $ent_payment->subtotal,
                        'total' => $ent_payment->total,
                        'prod' => $ent_payment->prod,                        
                        'payment_platform' => $ent_payment->payment_platform,
                        'comission_payed' => 1,
                        'is_visible' => 1,
                        'createdby'=> 1,                        
                        'created'  => date('Y-m-d H:i:s'),
                        'modified' => date('Y-m-d H:i:s'),
                        'state' => $ent_payment->state
                    );

                    $ent_refund = $this->DataPayment->newEntity($array_save);
                    $ent_refund = $this->DataPayment->save($ent_refund);
                    
                    //$ent_payment->is_visible = 0;
                    $ent_payment->refund_id  = $ent_refund->id;
                    $ent_payment->modified   = date('Y-m-d H:i:s');
                    //$main = new MainController();
                    $this->DataPayment->save($ent_payment);
                    //Deactivate the course for the injectors who want to get a refund for the training classes

                    $this->loadModel('SpaLiveV1.SysUsers');
                    $user = $this->SysUsers->find()->where(['id' => $ent_payment->id_from])->first();

                    if($ent_payment->type =='ADVANCED TECHNIQUES COURSE'){
                        //advanced course
                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $advanced_course = $this->DataTrainings->find()->
                        join([
                            'Training' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Training.id = DataTrainings.training_id'],
                        ])->
                        where([
                            'DataTrainings.user_id' => $ent_payment->id_from,
                            'DataTrainings.deleted' => 0,
                            'Training.level' => 'LEVEL 3'
                        ])->first();
                        if(!empty($advanced_course)){
                            // update table DataTrainings deleted  = 0
                            $this->DataTrainings->updateAll(['deleted' => 1], ['id' => $advanced_course->id]);
                        }

                    }

                    if($ent_payment->type =='ADVANCED COURSE' ){//|| $ent_payment->type =='BASIC COURSE'){
                        //advanced course
                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $advanced_course = $this->DataTrainings->find()->
                        join([
                            'Training' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Training.id = DataTrainings.training_id'],
                        ])->
                        where([
                            'DataTrainings.user_id' => $ent_payment->id_from,
                            'DataTrainings.deleted' => 0,
                            'Training.level' => 'LEVEL 2'
                        ])->first();
                        if(!empty($advanced_course)){
                            // update table DataTrainings deleted  = 0
                            $this->DataTrainings->updateAll(['deleted' => 1], ['id' => $advanced_course->id]);
                        }

                        //has basic course
                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $basic_course = $this->DataTrainings->find()->
                        join([
                            'Training' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Training.id = DataTrainings.training_id'],
                        ])->
                        where([
                            'DataTrainings.user_id' => $ent_payment->id_from,
                            'DataTrainings.deleted' => 0,
                            'Training.level' => 'LEVEL 1'
                        ])->first();
                        $this->loadModel('SpaLiveV1.SysUsers');
                        if(empty($basic_course)){               
                            //not course founded, need buy the basic course
                            if($user->steps != 'HOME'){
                                $this->SysUsers->updateAll(['steps' => 'BASICCOURSE'], ['id' => $ent_payment->id_from]); 
                            }
                        }else{
                            if($basic_course->attended ==1){
                                $main = new MainController();
                                $main->validate_step();
                            }else{
                                if($user->steps != 'HOME'){
                                    $this->SysUsers->updateAll(['steps' => 'MATERIALS'], ['id' => $ent_payment->id_from]); 
                                }
                            }
                        }

                    }

                    if($ent_payment->type =='BASIC COURSE' ){//|| $ent_payment->type ==''){
                        //advanced course
                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $advanced_course = $this->DataTrainings->find()->
                        join([
                            'Training' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Treatment.id = DataTrainings.training_id'],
                        ])->
                        where([
                            'DataTrainings.user_id' => $ent_payment->id_from,
                            'DataTrainings.deleted' => 0,
                            'Training.level' => 'LEVEL 1'
                        ])->first();
                        if(!empty($advanced_course)){
                            // update table DataTrainings deleted  = 0
                            $this->DataTrainings->updateAll(['deleted' => 1], ['id' => $advanced_course->id]);
                        }

                        //has advanced course
                        $this->loadModel('SpaLiveV1.DataTrainings');
                        $basic_course = $this->DataTrainings->find()->
                        join([
                            'Training' => ['table' => 'cat_trainings', 'type' => 'LEFT', 'conditions' => 'Treatment.id = DataTrainings.training_id'],
                        ])->
                        where([
                            'DataTrainings.user_id' => $ent_payment->id_from,
                            'DataTrainings.deleted' => 0,
                            'Training.level' => 'LEVEL 2'
                        ])->first();
                        $this->loadModel('SpaLiveV1.SysUsers');
                        if(empty($basic_course)){               
                            //not course founded, need buy the basic course

                            if($user->steps != 'HOME'){
                                $this->SysUsers->updateAll(['steps' => 'BASICCOURSE'], ['id' => $ent_payment->id_from]); 
                            }
                        }else{
                            if($basic_course->attended ==1){
                                $main->validate_step();
                            }else{
                                if($user->steps != 'HOME'){
                                    $this->SysUsers->updateAll(['steps' => 'BASICCOURSE'], ['id' => $ent_payment->id_from]); 
                                }
                            }
                        }

                    }
                    $this->success();
                }else{
                    $this->message('Error requesting refund to Affirm, try again later. (2)');
                    return;
                }

            }else{            
                $this->set('status', $status);
                $this->set('message', 
                    $status == 'REFUNDED' 
                        ? 'This payment was already refunded.'
                        : 'You can\'t request a refund for this payment.'
                );
                $this->success();
                return;
            }

        }else{
            $this->message('Payment not found. (4)');
        }
    }

    #endregion

    #region WEB CLIENT

    function web_client(){

        if(!$this->validate_session()) return;

        $token = get('token', '');
        $payment_uid = get('uid', '');
        $products = [];
        $total = 0;

        $this->loadModel('SpaLiveV1.DataPayment');
        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "affirm"
            ])
            ->first();                

        if(!empty($payment)) {
            
            $course = $payment->type;            
            if($course == 'BASIC COURSE'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Neurotoxin Course - Level 1',
                    'uid' => 'basic-course',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }else if($course == 'ADVANCED COURSE'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Advanced Course',
                    'uid' => 'advanced-course',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }else if($course == 'ADVANCED TECHNIQUES COURSE'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Advanced Techniques Course',
                    'uid' => 'advanced-techniques-course',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }else if($course == 'LEVEL 1-1 NEUROTOXINS'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Level 1-1 Neurotoxins',
                    'uid' => 'level-1-1-neurotoxins',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }else if($course == 'FILLERS COURSE'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Fillers Course',
                    'uid' => 'Fillers-course',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }else if($course == 'WEIGHT LOSS'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Weight Loss',
                    'uid' => 'weight-loss',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }else if($course == 'ADVANCED TECHNIQUES MEDICAL'){
                $total = $payment->subtotal;
                $products[] = array(
                    'name' => 'Advanced Techniques Medical',
                    'uid' => 'advanced-techniques-medical',
                    'price' => $payment->subtotal,
                    'qty' => 1
                );
            }
        
            $htmlHeader = <<< HTML
                <!DOCTYPE html>
                <html>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
                    <link href="https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap" rel="stylesheet">                    
                    <title>Affirm Checkout - MySpaLive</title>               
                    <head>
                        <title>Affirm Checkout - MySpaLive</title>
                        <style>
                            body {
                                background-color: #0091AE;
                                font-family: 'Roboto', sans-serif;
                            }
                            img {
                                width: 200px;
                                height: auto;
                            }
                            .container {
                                margin-top: 5vw;
                                display: flex;
                                height: 100%;
                                width: 100%;
                                justify-content: center;
                                flex-direction: column;
                                align-items: center;
                            }

                            h1 {
                                color: white;
                                font-size: 20px;
                                text-align: center;
                                margin-bottom: 1.2rem;
                            }
                            p {
                                font-size: 15px;
                                color: white;
                                text-align: center;
                            }

                            .spinner {
                                width: 40px;
                                height: 40px;
                                border: 4px solid rgba(255, 255, 255, 0.3);
                                border-top: 4px solid #fff;
                                border-radius: 50%;
                                animation: spin 1s linear infinite;
                            }

                            @keyframes spin {
                                0% { transform: rotate(0deg); }
                                100% { transform: rotate(360deg); }
                            }
                        </style>
                    </head>
            HTML;

            $htmlContent = '
                <body>
                    <div class="container" id="parent">
                        <img src="https://blog.myspalive.com/wp-content/uploads/2023/05/logo-1-17-1024x379.png">                    
                        <h1>Your payment is being process.</h1>
                        <p>Thanks for choosing Affirm!</p>
                        <div class="spinner"></div>
                    </div>
                </body> 
            ';

            $htmlFooter = '</html>';

            $html = 
                $htmlHeader  . 
                $htmlContent . 
                $this->web_client_script_affirm($payment->uid) . 
                $this->web_client_scripts(
                    $token,
                    USER_ID,
                    $payment_uid,
                    $this->products_to_json($products),
                    $total,
                    '',
                    0,
                    0
                ) .
                $htmlFooter;

                $this->set('html', $html);
                

            echo $html;
            exit;
                                   
        }else{
            $this->message('Invalid payment.');
            return;
        }        
    }

    function web_client_script_affirm($uid){
        $host = env('IS_DEV', false) ? 'https://cdn1-sandbox.affirm.com/js/v2/affirm.js' : 'https://cdn1.affirm.com/js/v2/affirm.js';
        $public_key = '_affirm_config = { public_api_key: "'.$this->AFFIRM_PUBLIC_KEY.'", script: "'.$host.'" };';

        $htmlScript = "<script>";
        $htmlScript .= $public_key;
        $htmlScript .= '(function(m,g,n,d,a,e,h,c){var b=m[n]||{},k=document.createElement(e),p=document.getElementsByTagName(e)[0],l=function(a,b,c){return function(){a[b]._.push([c,arguments])}};b[d]=l(b,d,"set");var f=b[d];b[a]={};b[a]._=[];f._=[];b._=[];b[a][h]=l(b,a,h);b[c]=function(){b._.push([h,arguments])};a=0;for(c="set add save post open empty reset on off trigger ready setProduct".split(" ");a<c.length;a++)f[c[a]]=l(b,d,c[a]);a=0;for(c=["get","token","url","items"];a<c.length;a++)f[c[a]]=function(){};k.async= !0;k.src=g[e];p.parentNode.insertBefore(k,p);delete g[e];f(g);m[n]=b})(window,_affirm_config,"affirm","checkout","ui","script","ready","jsReady");';        
        $htmlScript .= "</script>";
        return $htmlScript;
    }

    function web_client_scripts(
        $token,
        $user_id,
        $purchase_uid,
        $products,
        $total,
        $discounts = '',        
        $shipping_amount = 0,
        $tax_amount = 0
    ){
        $success_url = env('URL_API', '');
        //$success_url = "http://localhost/apispalive/";
        $apiKey = env('API_KEY', '');
        $success_url .= "?action=Affirm____web_checkout_authorize&uid=".$purchase_uid."&key=".$apiKey."&token=".$token;
        $cancel_url = env('URL_API', '');
        $cancel_url .= "?action=Affirm____cancel_checkout&uid=".$purchase_uid."&key=".$apiKey."&token=".$token; 
        $merchantName = "MySpaLive";
        /*$reject_url = env('URL_API', '');
        $reject_url .= "?action=Affirm____reject_checkout&uid=".$purchase_uid."&key=".$apiKey."&token=".$token;*/

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->log(__LINE__ . ' ' . json_encode($this->validate_session()));
        $select = [
            'SysUsers.id',
            'SysUsers.name',
            'SysUsers.lname',
            'SysUsers.email',
            'SysUsers.phone',
            'SysUsers.street',
            'SysUsers.suite',
            'SysUsers.city',
            'SysUsers.state',
            'SysUsers.zip',
            'State.name'
        ];

        $user = $this->SysUsers->find()
            ->select($select)
            ->join([
                "State" => [ "table" => "cat_states", "type" => "INNER", "conditions" => "State.id = SysUsers.state" ]
            ])
            ->where(['SysUsers.id' => $user_id])->first();

        $name = $user->name;
        $lname = $user->lname;
        $email = $user->email;
        $phone = $user->phone;
        $address = $user->street;
        $address2 = $user->suite;
        $city = $user->city;
        $state = $user["State"]["name"];
        $zip = $user->zip;
        

        $message = '
            <h1 class="thank-you">Affirm\'s financing rejected</h1>
            <p>We are sorry that your financing wasn\'t approved by Affirm. You can close this window and purchase with your credit card.</p>
            <p class="note">Note: You can now close this window</p>
        ';        
        $htmlContent = '
        <body>
            <div id="container">
                <img src="https://blog.myspalive.com/wp-content/uploads/2023/07/logo_black.png" alt="Affirm Logo">
                '.$message.'
            </div>
        </body>
        ';
        $htmlFooter = '</html>';
        $htmlSuccess =         
        $htmlContent . 
        $htmlFooter;
    
        return '
            <script>
                function yourAffirmCheckoutValidationFunction(a,e){
                    console.log("onValidationError, respond to bad data here", a);
                    
                    const elem = document.getElementById("parent");
                    elem.innerHTML = 
                        "<img src=\"https://blog.myspalive.com/wp-content/uploads/2023/07/logo_black.png\" alt=\"Affirm Logo\">"+
                        "<h1 class=\"thank-you\">Error with your basic information</h1>"+
                        "<p>Please correct your information.</p>"+
                        "<p class=\"note\">Note: You can now close this window</p>";
                        
                        fetch("'.$cancel_url.'&from_app=1")
                          .then(response => response.json())
                          .then(data => {
                            console.log("reject");
                            console.log(data);
                          })
                          .catch(error => {
                            console.error("Error:", error);
                          });
                        
                }
                function close() { 
                    console.log("close");
                    window.open("","_parent",""); 
                    window.close(); 
                } 

                window.onload = (event) => {
                    affirm.checkout({
                        "merchant": {
                            "user_confirmation_url":    "'.$success_url.'",
                            "user_cancel_url":          "'.$cancel_url.'",
                            "public_api_key":           "'.$this->AFFIRM_PUBLIC_KEY.'",
                            "user_confirmation_url_action": "GET",
                            "name": "'.$merchantName.'"
                        },
                        "shipping":{
                            "name":{
                                "first":"'.$name.'",
                                "last":"'.$lname.'"
                            },
                            "address":{
                                "line1":"'.$address.'",
                                "line2":"'.$address2.'",
                                "city":"'.$city.'",
                                "state":"'.$state.'",
                                "zipcode":"'.$zip.'",
                                "country":"USA"
                            },
                            "phone_number": "'.$phone.'",
                            "email": "'.$email.'"
                        },
                        "billing":{
                            "name":{
                                "first":"'.$name.'",
                                "last":"'.$lname.'"
                            },
                            "address":{
                                "line1":"'.$address.'",
                                "line2":"'.$address2.'",
                                "city":"'.$city.'",
                                "state":"'.$state.'",
                                "zipcode":"'.$zip.'",
                                "country":"USA"
                            },
                            "phone_number": "'.$phone.'",
                            "email": "'.$email.'"
                        },
                        "items": [
                            '.$products.'
                        ],
                        '.$discounts.'
                        "metadata":{
                            "mode":"redirect"
                        },
                        "order_id":"'.$purchase_uid.'",
                        "currency":"USD",
                        "shipping_amount":'.$shipping_amount.',
                        "tax_amount":'.$tax_amount.',
                        "total":'.$total.'
                    });
                    affirm.ui.ready(
                        function(e) {
                            affirm.ui.error.on("close", function(a,e){
                                //alert("Please check your contact information for accuracy.");
                                //console.log("Please check your contact information for accuracy.");
                                yourAffirmCheckoutValidationFunction(a,e);
                            });
                        }
                    );
                    affirm.checkout.open(
                        {onValidationError: function(a){yourAffirmCheckoutValidationFunction(a)}}
                    );
                };
            </script>
        ';
    }

    #endregion

    #region AUTHORIZE CHECKOUT

    function web_checkout_authorize(){
        if(!$this->validate_session()) return;
        $checkout_token = get('checkout_token', '');
        $uid = get('uid', '');

        $charge_id = $this->authorize_affirm_payment($checkout_token, $uid);

        $success = $charge_id != '';

        if($success){
            $Main = new MainController();
            $payments = new PaymentsController();
            $nt = $this->capture_transaction($charge_id, $uid);
            $this->loadModel('SpaLiveV1.DataAssignedToRegister');
            $this->loadModel('SpaLiveV1.DataPayment');
            $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
            $pay = $this->DataPayment->find()->select(['User.id', 'User.name', 'User.lname', 'User.phone', 'DataPayment.id','DataPayment.type','DataPayment.total'])
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from'],
            ])->where(['DataPayment.uid' => $uid, 'DataPayment.is_visible' => 1, 'DataPayment.payment <>' => '', 'DataPayment.type IN' => array('BASIC COURSE','ADVANCED COURSE')])->first();

            if(!empty($pay)){
                $course = $pay['type'];
                // create an agreement refund, charge 50 dls
                $this->create_agrrement_refund($pay->type, $pay['User']['name'] ." ". $pay['User']['lname'],  $pay['User']['id']);

                if($course == 'BASIC COURSE'){
                    $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                        'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                    ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();

                    if(empty($assignedRep)){
                        $Login = new LoginController();
                        $Login->assignRep(true);
                        $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                            'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                        ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
                    }
                } else{
                    $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                        'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                    ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'INSIDE'])->last();

                    if(empty($assignedRep)){
                        $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                            'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                        ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
                    }
                }
    
            if (!empty($assignedRep)) {
                $amount_comission = 0;
                $description_comission = '';
                /*$pay = $this->DataPayment->find()
                ->where(['DataPayment.id_from' => USER_ID, 
                        'DataPayment.uid' => $uid, 
                        'DataPayment.type' => $type_string])->first();*/

                $this->loadModel('SpaLiveV1.DataSalesRepresentative');
                $representative = $this->DataSalesRepresentative->find()->where(['DataSalesRepresentative.user_id' => $assignedRep['User']['id']])->first();

                if($course == 'BASIC COURSE'){  $this->log(__LINE__ . ' ' . json_encode('NEUROTOXINS BASIC'));
                    $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the basic training purchase for $' . $pay['total'] / 100, $Main);
                    $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the basic training purchase for $' . $pay['total'] / 100;
                    $Main->send_email_after_register($assignedRep['User']['email'],'Basic training purchase',$msg);//($to, $subject, $body) 
                    
                    #region Pay Commision invitation injector
                    $this->loadModel('SpaLiveV1.DataNetworkInvitations');

                    $existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower(USER_EMAIL)])->first();

                    if(!empty($existUser)){
                        $invite_user = $this->SysUsers->find()->where(['id' => $existUser->parent_id, 'deleted' => 0, 'active' => 1])->first();

                        if(!empty($invite_user)){
                            $array_save_invitation = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => 5000,
                                'user_id' => $existUser->parent_id,
                                'payment_uid' => '',
                                'description' => 'PAY INVITATION',
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => defined('USER_ID') ? USER_ID : 0,
                            );
            
                            $c_entity_invitation = $this->DataSalesRepresentativePayments->newEntity($array_save_invitation);
                            $this->DataSalesRepresentativePayments->save($c_entity_invitation);
                            $this->full_comission = 5000;
                            $service = 'Neurtoxins';
                            // $this->send_email_sales_team_member(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep);
                        }
                    }
                    #endregion
                    $value_discount = 0;#$this->getParams('discount_amount', 0);
                    /* if($value_discount <= 20000){ // 795 - 200 = 595
                        $amount_comission = $this->full_comission;
                    } else */ 
                    if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                        // $amount_comission = $this->half_comission;
                        $amount_comission = $this->full_comission;
                    } else if($value_discount >= 30100){ // 795 - 300 = 494
                        $amount_comission = 0;
                    }
                    
                    $description_comission = 'SALES TEAM BASIC';
                    $this->log(__LINE__ . ' ' . json_encode($representative->rank));
                    if($representative->rank == 'JUNIOR' && !empty($existUser)){ // Si el representante es JUNIOR y hay invitacion entonces solo cambiamos el monto de la comision a $50
                        $amount_comission = $amount_comission == 0 ? 0 : 5000;
                    }else if($representative->rank == 'JUNIOR' && empty($existUser)){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission = $amount_comission == 0 ? 0 : 5000;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay)){
                            $array_save_comission = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => $amount_comission,
                                'user_id' => $senior_rep->user_id,
                                'payment_uid' => '',
                                'description' => $description_comission,
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => defined('USER_ID') ? USER_ID : 0,
                            );
                            $this->log(__LINE__ . ' ' . json_encode($array_save_comission));
                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                            $service = 'Training';
                            $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission, $senior_rep);
                        }
                    } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay)){
                            $array_save_comission = array(
                                'uid' => Text::uuid(),
                                'payment_id' => $pay->id,
                                'amount' => $amount_comission_senior,
                                'user_id' => $senior_rep->user_id,
                                'payment_uid' => '',
                                'description' => $description_comission,
                                'payload' => '',
                                'deleted' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'createdby' => defined('USER_ID') ? USER_ID : 0,
                            );
                            $this->log(__LINE__ . ' ' . json_encode($array_save_comission));
                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                            $service = 'Training';
                            $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission_senior, $senior_rep);
                        }
                    }

                    /*if(!env('IS_DEV', false)){
                        $Ghl = new GhlController();
                        $array_ghl = array(
                            'email' => USER_EMAIL,
                            'name' => USER_NAME,
                            'lname' => USER_LNAME,
                            'phone' => USER_PHONE,
                            'costo' => 0,
                            'column' => 'Purchased basic',
                        );
                        $contactId = $Ghl->updateOpportunityTags($array_ghl);
                        $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Purchased basic');
                        $this->set('tag', $tag);
                    }*/

                }else if($course == 'ADVANCED COURSE'){$this->log(__LINE__ . ' ' . json_encode('NEUROTOXINS ADVANCED'));
                    $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the advanced training purchase for $' .$pay['total'] / 100;
                    $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the advanced training purchase for $' . $pay['total'] / 100, $Main);
                    $Main->send_email_after_register($assignedRep['User']['email'],'Advanced training purchase',$msg);

                    $value_discount = $this->getParams('discount_amount', 0);
                    /* if($value_discount <= 20000){ // 795 - 200 = 595
                        $amount_comission = $this->full_comission;
                    } else */ 
                    if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                        // $amount_comission = $this->half_comission;
                        $amount_comission = $this->full_comission;
                    } else if($value_discount >= 30100){ // 795 - 300 = 494
                        $amount_comission = 0;
                    }

                    $description_comission = 'SALES TEAM ADVANCED';
                    $this->log(__LINE__ . ' ' . json_encode($representative->rank));

                    if($representative->team == 'INSIDE'){ 
                        if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                            $amount_comission = $amount_comission == 0 ? 0 : 5000;
                            $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                            ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 
    
                            if(!empty($pay)){
                                $array_save_comission = array(
                                    'uid' => Text::uuid(),
                                    'payment_id' => $pay->id,
                                    'amount' => $amount_comission,
                                    'user_id' => $senior_rep->user_id,
                                    'payment_uid' => '',
                                    'description' => $description_comission,
                                    'payload' => '',
                                    'deleted' => 1,
                                    'created' => date('Y-m-d H:i:s'),
                                    'createdby' => defined('USER_ID') ? USER_ID : 0,
                                );
                
                                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                $this->DataSalesRepresentativePayments->save($c_entity_comission);
                                $service = 'Training';
                                $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission, $senior_rep);
                            }
                        } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                            $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
                            $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                            ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 
    
                            if(!empty($pay)){
                                $array_save_comission = array(
                                    'uid' => Text::uuid(),
                                    'payment_id' => $pay->id,
                                    'amount' => $amount_comission_senior,
                                    'user_id' => $senior_rep->user_id,
                                    'payment_uid' => '',
                                    'description' => $description_comission,
                                    'payload' => '',
                                    'deleted' => 1,
                                    'created' => date('Y-m-d H:i:s'),
                                    'createdby' => defined('USER_ID') ? USER_ID : 0,
                                );
                
                                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                $this->DataSalesRepresentativePayments->save($c_entity_comission);
                                $service = 'Training';
                                $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission_senior, $senior_rep);
                            }
                        }
                    } else if($representative->team == 'OUTSIDE'){
                        $amount_comission = $amount_comission == 0 ? 0 : 10000;
                    }
                }else if($course == 'ADVANCED TECHNIQUES MEDICAL'){$this->log(__LINE__ . ' ' . json_encode('ADVANCED TECHNIQUES MEDICAL'));
                    $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the advanced training purchase for $' . $pay['total'] / 100;
                    $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the advanced training purchase for $' . $pay['total'] / 100, $Main);
                    $Main->send_email_after_register($assignedRep['User']['email'],'Training purchase',$msg);

                    $value_discount = $this->getParams('discount_amount', 0);
                    /* if($value_discount <= 20000){ // 795 - 200 = 595
                        $amount_comission = $this->full_comission;
                    } else */ 
                    if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                        // $amount_comission = $this->half_comission;
                        $amount_comission = $this->full_comission;
                    } else if($value_discount >= 30100){ // 795 - 300 = 494
                        $amount_comission = 0;
                    }

                    $description_comission = 'SALES TEAM LEVEL 3';
                    $this->log(__LINE__ . ' ' . json_encode($representative->rank));

                    if($representative->team == 'INSIDE'){
                        if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                            $amount_comission = $amount_comission == 0 ? 0 : 5000;
                            $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                            ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 
    
                            if(!empty($pay)){
                                $array_save_comission = array(
                                    'uid' => Text::uuid(),
                                    'payment_id' => $pay->id,
                                    'amount' => $amount_comission,
                                    'user_id' => $senior_rep->user_id,
                                    'payment_uid' => '',
                                    'description' => $description_comission,
                                    'payload' => '',
                                    'deleted' => 1,
                                    'created' => date('Y-m-d H:i:s'),
                                    'createdby' => defined('USER_ID') ? USER_ID : 0,
                                );
                
                                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                $this->DataSalesRepresentativePayments->save($c_entity_comission);
                                $service = 'Training';
                                $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission, $senior_rep);
                            }
                        } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                            $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
                            $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                            ])->where(['DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 
    
                            if(!empty($pay)){
                                $array_save_comission = array(
                                    'uid' => Text::uuid(),
                                    'payment_id' => $pay->id,
                                    'amount' => $amount_comission_senior,
                                    'user_id' => $senior_rep->user_id,
                                    'payment_uid' => '',
                                    'description' => $description_comission,
                                    'payload' => '',
                                    'deleted' => 1,
                                    'created' => date('Y-m-d H:i:s'),
                                    'createdby' => defined('USER_ID') ? USER_ID : 0,
                                );
                
                                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                $this->DataSalesRepresentativePayments->save($c_entity_comission);
                                $service = 'Training';
                                $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission_senior, $senior_rep);
                            }
                        }
                    }else if($representative->team == 'OUTSIDE'){
                        $amount_comission = $amount_comission == 0 ? 0 : 10000;
                    }
                }
                
                if(!empty($pay)){
                    $array_save_comission = array(
                        'uid' => Text::uuid(),
                        'payment_id' => $pay->id,
                        'amount' => $amount_comission,
                        'user_id' => $assignedRep['User']['id'],
                        'payment_uid' => '',
                        'description' => $description_comission,
                        'payload' => '',
                        'deleted' => 1,
                        'created' => date('Y-m-d H:i:s'),
                        'createdby' => defined('USER_ID') ? USER_ID : 0,
                    );
                    $this->log(__LINE__ . ' ' . json_encode($array_save_comission));
                    $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                    $this->DataSalesRepresentativePayments->save($c_entity_comission);
                    $service = 'Training';
                    $payments->send_email_team_member_courses(USER_ID, $service, $pay['type'], $amount_comission, $assignedRep);
                }

                if($course == 'BASIC COURSE'){
                    $Pay = new PaymentsController(); 
                    $Pay->assignRepInside();
                }
            }

        }        
        
        $htmlHeader = <<<HTML
            <!DOCTYPE html>
            <html>                               
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
                    <link href="https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap" rel="stylesheet"> 
                    <title>Payment Confirmation - MySpaLive</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            background-color: #f4f4f4;
                            text-align: center;
                            margin: 0;
                            padding: 20px;
                        }

                        #container {
                            max-width: 600px;
                            margin: 0 auto;
                            background-color: #0091AE;
                            border-radius: 10px;
                            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
                            padding: 110px 40px;
                            color: white;
                        }

                        img {
                            max-width: 200px;
                            margin-bottom: 20px;
                        }

                        h1 {
                            font-size: 28px;
                            color: #333;
                        }

                        p {
                            font-size: 18px;
                            color: white;
                            margin-bottom: 20px;
                        }

                        .note {
                            font-size: 14px;
                            color: white;
                        }

                        .thank-you {
                            color: white;
                            font-weight: bold;
                        }

                        .close-button {
                            background-color: #b9e8ff;
                            color: #fff;
                            border: none;
                            border-radius: 5px;
                            padding: 10px 20px;
                            font-size: 16px;
                            cursor: pointer;
                        }

                        .close-button:hover {
                            background-color: #a0daf7;
                        }
                    </style>
                    <script language="javascript" type="text/javascript"> 
                        function close() { 
                            window.open("","_parent",""); 
                            window.close(); 
                        } 
                    </script>
                </head>                
        HTML;        

        if($success){
            $message = '
                <h1 class="thank-you">Thanks for using Affirm!</h1>
                <p>Your payment has been received.<br>You may now continue in the MySpaLive App.</p>
                <p class="note">Note: You can now close this window</p>
            ';
        }else{
            $message = '
                <h1 class="thank-you">Affirm\'s financing rejected</h1>
                <p>We are sorry that your financing wasn\'t approved by Affirm. You can close this window and purchase with your credit card.</p>
                <p class="note">Note: You can now close this window</p>
            ';
        }

        $htmlContent = '
        <body>
            <div id="container">
                <img src="https://blog.myspalive.com/wp-content/uploads/2023/07/logo_black.png" alt="Affirm Logo">
                '.$message.'
            </div>
        </body>
        ';

        $htmlFooter = '</html>';

        $htmlSuccess = 
            $htmlHeader  . 
            $htmlContent . 
            $htmlFooter;

        echo $htmlSuccess;        
        exit;
    }

}

    #endregion

    /*public function reject_checkout(){
        if(!$this->validate_session()) return;

        $payment_uid = get('uid', '');

        $this->loadModel('SpaLiveV1.DataPayment');

        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "affirm"
            ])
            ->order(['DataPayment.id' => 'DESC'])
            ->first();

        if(!empty($payment)){
            $this->DataPayment->updateAll(
                [
                    'cancelled' => 1,
                    'is_visible' => 1
                ],
                ['uid' => $payment_uid]
            );            
        }

        $this->success();
        return;
    }*/


    #region CANCEL CHECKOUT

    public function cancel_checkout(){
         
        if(!$this->validate_session()) return;

        $payment_uid = get('uid', '');
        $from_app = get('from_app', 0);

        $this->cancel_payment($payment_uid);
        
        if($from_app == 1){
            $this->success();
            return;
        }

        $htmlHeader = <<<HTML
            <!DOCTYPE html>
            <html>                               
                <head>
                    <meta charset="utf-8">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="preconnect" href="https://fonts.googleapis.com">
                    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="">
                    <link href="https://fonts.googleapis.com/css2?family=Roboto&amp;display=swap" rel="stylesheet"> 
                    <title>Payment Confirmation - MySpaLive</title>
                    <style>
                        body {
                            font-family: Arial, sans-serif;
                            background-color: #f4f4f4;
                            text-align: center;
                            margin: 0;
                            padding: 20px;
                        }

                        #container {
                            max-width: 600px;
                            margin: 0 auto;
                            background-color: #0091AE;
                            border-radius: 10px;
                            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
                            padding: 110px 40px;
                            color: white;
                        }

                        img {
                            max-width: 200px;
                            margin-bottom: 20px;
                        }

                        h1 {
                            font-size: 28px;
                            color: #333;
                        }

                        p {
                            font-size: 18px;
                            color: white;
                            margin-bottom: 20px;
                        }

                        .note {
                            font-size: 14px;
                            color: white;
                        }

                        .thank-you {
                            color: white;
                            font-weight: bold;
                        }

                        .close-button {
                            background-color: #b9e8ff;
                            color: #fff;
                            border: none;
                            border-radius: 5px;
                            padding: 10px 20px;
                            font-size: 16px;
                            cursor: pointer;
                        }

                        .close-button:hover {
                            background-color: #a0daf7;
                        }
                    </style>
                </head>                
        HTML;

        $message = '
            <h1 class="thank-you">Purchase not completed</h1>
            <p>Affirm will send you an email with their decision shortly if you applied to the buy now pay later option.</p>
            <p class="note">Note: You can now close this window</p>
        ';
        

        $htmlContent = '
        <body>
            <div id="container">
                <img src="https://blog.myspalive.com/wp-content/uploads/2023/07/logo_black.png" alt="Affirm Logo">
                '.$message.'
            </div>
        </body>
        ';

        $htmlFooter = '</html>';

        $htmlSuccess = 
            $htmlHeader  . 
            $htmlContent . 
            $htmlFooter;

        echo $htmlSuccess;   
        exit;
    }


    #region FUNCTIONS

    public function validate_session() : bool 
    {
        $token = get('token', '');
        if(empty($token)) return false;
        
        $user = $this->AppToken->validateToken($token, true);
        $validate = $user != false;

        if(!$validate){ $this->message('Invalid token.'); }
        $this->set('session', $validate);        
        return $validate;            
    }

    public function authorize_affirm_payment(
        $checkout_token,
        $order_id
    ){

        $this->loadModel('SpaLiveV1.DataPayment');

        $this->DataPayment->updateAll(
            ['intent' => $checkout_token],
            ['uid' => $order_id]
        );

        $public_api_key = $this->AFFIRM_PUBLIC_KEY;
        $private_api_key = $this->AFFIRM_PRIVATE_KEY;        

        $url = env('IS_DEV', false) 
            ? 'https://sandbox.affirm.com/api/v1/transactions'
            : 'https://api.affirm.com/api/v1/transactions';

        $data = array(
            'transaction_id' => $checkout_token,
            'order_id' => $order_id
        );

        $headers = array(
            'Content-Type: application/json'
        );

        $curl = curl_init($url);

        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_USERPWD, $public_api_key . ':' . $private_api_key);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($httpCode == 200) {            
            $responseData = json_decode($response, true);            
            
            $success = isset($responseData['id']);

            $this->DataPayment->updateAll(
                [
                    'payment' => json_encode($responseData),
                    'is_visible' => $success ? 1 : 0
                ],
                ['uid' => $order_id]
            );
            return $responseData['id'];            
        } else {
            return '';
        }
    }

    public function products_to_json(
        $products = []
    ){
        $str_array = [];
        
        foreach ($products as $product) {
            $str_product = '{
                "display_name":         "'.$product["name"].'",
                "sku":                  "'.$product["uid"].'",
                "unit_price":           '.$product["price"].',
                "qty":                  '.$product["qty"].',
                "item_image_url":       "https://blog.myspalive.com/wp-content/uploads/2023/05/logo-1-17-1024x379.png",
                "item_url":             ""
            }';
            $str_array[] = $str_product;
        }

        return implode(',', $str_array);

    }

    public function cancel_payment(
        $payment_uid
    ){
        $this->loadModel('SpaLiveV1.DataPayment');

        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                // "DataPayment.payment_platform" => "affirm"
            ])
            ->order(['DataPayment.id' => 'DESC'])
            ->first();

        if(!empty($payment)){
            $this->DataPayment->updateAll(
                [
                    'cancelled' => 1
                ],
                ['uid' => $payment_uid]
            );            
        }
    }

    public function getStatusPayment($payment_uid){

        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                // "DataPayment.payment_platform" => "affirm"
            ])
            ->order(['DataPayment.id' => 'ASC'])
            ->first();

        $status = "NOT FOUND";

        if(!empty($payment)){
            $is_set_intent  = $payment->intent != "" && $payment->intent != null;
            $is_set_payment = $payment->payment != "" && $payment->payment != null;

            if($payment->refund_id != 0){
                $status = "REFUNDED";   
            }else if($payment->is_visible == 1){
                $status = "AUTHORIZED";
            }else if($payment->cancelled == 1){
                $status = "CANCELLED";
            }else{
                if($is_set_intent && $is_set_payment){
                    $status = "DENIED";                
                }else{
                    if($is_set_intent){
                        $status = "PENDING AUTHORIZATION";
                    }else{
                        $status = "CREATED";
                    }
                }
            }
        }

        return $status;
    }

    public function getDetailsPayment(
        $payment_uid
    ){
        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform <>" => "affirm"
            ])
            ->order(['DataPayment.id' => 'DESC'])
            ->first();

        $details = [];

        if(!empty($payment)){            
            $details["id"]          = $payment->uid;
            $details["data"]        = json_decode($payment->payment, true);
            $details["type"]        = $payment->type;
            $details["total"]       = $payment->total;
            $details["subtotal"]    = $payment->subtotal;
            $details["promo_code"]  = $payment->promo_code;
            $details["discount"]    = $payment->promo_discount;
            $details["products"]    = [];
            if($payment->type == "BASIC COURSE"){
                $details["products"] = [
                    array(
                        "name" => "Basic Course",
                        "price" => $this->basic_course_price,
                        "qty" => 1
                    )
                ];
            } else if($payment->type == "ADVANCED COURSE"){
                $details["products"] = [
                    array(
                        "name" => "Advanced Course",
                        "price" => $this->advanced_course_price,
                        "qty" => 1
                    )
                ];
            } else if($payment->type == "ADVANCED TECHNIQUES COURSE"){
                $details["products"] = [
                    array(
                        "name" => "Advanced Techniques in Neurotoxin Injections - Level 3",
                        "price" => $this->advanced_techniques,
                        "qty" => 1
                    )
                ];
            } else if($payment->type == "WEIGHT LOSS"){
                $details["products"] = [
                    array(
                        "name" => "3 months of Semaglutide",
                        "price" => $this->weight_loss_with_affirm,
                        "qty" => 1
                    )
                ];
            }
        }

        return $details;
    }

    public function capture_transaction(
        $charge_id,
        $payment_uid
    ){
        $charge_id = $charge_id;
        $public_api_key = $this->AFFIRM_PUBLIC_KEY;
        $private_api_key = $this->AFFIRM_PRIVATE_KEY;
        $order_id = $payment_uid;

        $is_dev = env('IS_DEV', false);

        $url = $is_dev 
            ? "https://sandbox.affirm.com/api/v1/transactions/{$charge_id}/capture"
            : "https://api.affirm.com/api/v1/transactions/{$charge_id}/capture";
        
        $data = array(
            'order_id' => $order_id
        );
        
        $headers = array(
            'accept: */*',
            'authorization: Basic '.base64_encode($public_api_key.':'.$private_api_key),
            'content-type: application/json'
        );
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        if ($response) { 
            $decoded_response = json_decode($response, true);
            $this->DataPayment->updateAll(
                [
                    'receipt' => json_encode($decoded_response),
                    'comission_payed' => 1,
                    'is_visible' => 1
                ],
                ['uid' => $order_id]
            );

            $ent_payment = $this->DataPayment->find()->where(['DataPayment.uid' => $order_id])->first();
            
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_payment->id_from])->first();

            if($ent_payment->type=="BASIC COURSE"){
                
                if(!empty($ent_user)){                    
                    $this->SysUsers->updateAll(
                        [
                            'steps' => 'SELECTBASICCOURSE'                            
                        ],
                        ['id' => $ent_payment->id_from]
                    );
                }
                $Main = new MainController();
                $Main->notify_devices('AFTER_BUY_BASIC_COURSE_EN',array(USER_ID),false,true);
            }
            if($ent_payment->type=="ADVANCED COURSE"){
                $Main = new MainController();
                $Main->notify_devices('AFTER_BUY_BASIC_COURSE_EN',array(USER_ID),false,true);
            }
            
            if($ent_payment->type=="ADVANCED TECHNIQUES COURSE"){
                $Main = new MainController();
                $Main->notify_devices('AFTER_BUY_BASIC_COURSE_EN',array(USER_ID),false,true);
            }

            if($ent_payment->type=="WEIGHT LOSS"){
                //create consulation
                $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->loadModel('SpaLiveV1.DataOtherServices');
                $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');
                $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
                $this->loadModel('SpaLiveV1.DataPurchases');

                $consultation_uid = Text::uuid();
                $schedule_date = date('Y-m-d H:i:s');

                $array_save = array(
                    'uid' => $consultation_uid,
                    'patient_id' => $ent_payment->id_from,
                    'assistance_id' => 0,
                    'service_uid' => $ent_payment->service_uid,
                    'payment' => $ent_payment->intent,
                    'payment_method' => $ent_payment->payment,
                    'payment_intent' => $ent_payment->intent,
                    'meeting' => '',
                    'meeting_pass' => '',
                    'schedule_date' => $schedule_date,
                    'status' => 'PAID',
                    'schedule_by' => 0,
                    'deleted' => 0,
                    'participants' => 0,
                    'createdby' => $ent_payment->id_from,
                    'goals' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'monthly_payment' => '3 MONTHS',
                );

                $c_entity = $this->DataConsultationOtherServices->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataConsultationOtherServices->save($c_entity);

                    /*$Payment = new PaymentsController();
                    $Payment->payment_sales_rep_weight_loss($ent_payment->id_from);*/

                    $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_payment->id_from])->first();
                    $pay_service = true;
                    $isDev = env('IS_DEV', false);
                    if (strpos(strtolower($ent_user->name), 'test') === false || strpos(strtolower($ent_user->lname), 'test') === false) {
                        
                        if(!$isDev){
                            $msj_test = 'month-to-month weight loss';

                            try {  
                                $sid    = env('TWILIO_ACCOUNT_SID'); 
                                $token  = env('TWILIO_AUTH_TOKEN');          
                                $twilio = new Client($sid, $token);
                                    
                                $message = $twilio->messages 
                                            ->create( '+1' . '9034366629', // to 
                                                    array(  
                                                        "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                                        "body" => 'Patient ' . $ent_user->name . $ent_user->lname . '(' . $ent_user->phone .') has purchased ' . $msj_test
                                                    ) 
                                            ); 
                                } catch (TwilioException $e) {
                                    
                                }
                        }
                    }

                    //hacer la purchase igual que en payment_intent_msl_service_purchases
                    $tentative_date = date('Y-m-d');
                    $flag_purchase = true;
                    $purchase_id = 0;
                    $call_number = 1;

                    for ($i = 0; $i < 3; $i++) {
                        $total_pur = 0;
                        $show_pur = 1;
                        $call_type = 'FIRST CONSULTATION';
                        $purchaseUid = $ent_payment->uid;

                        if($i<1){
                            $total_pur = $this->weight_loss_with_affirm;
                        }else{
                            if($i<2){
                                $call_number++;
                            }else{
                                $call_number = $call_number * 2;
                            }

                            $call_type = 'CHECK IN';
                            $show_pur = 0;
                            //$tentative_date = date('Y-m-d', strtotime($tentative_date . ' +30 day'));
                            $purchaseUid = Text::uuid();
                        }

                        $array_purchases = array(
                                'uid' => $purchaseUid,
                                'user_id' => $ent_user->id,
                                'status' => 'NEW',
                                'payment' =>  $ent_payment->intent,
                                'payment_intent' => $ent_payment->intent,
                                'amount' => $total_pur,
                                'status' => 'WAITING TO RECEIVE THE PRODUCT',
                                'shipping_date' => $tentative_date,
                                'deleted' => 0,
                                'call_type' => $call_type,
                                'call_number' => $call_number,
                                'created' => date('Y-m-d H:i:s'),
                                'show' => $show_pur
                        );

                        $c_entity_p = $this->DataPurchases->newEntity($array_purchases);

                        $array_purchases_os = array(
                            'uid' => $purchaseUid,
                            'user_id' => $ent_user->id,
                            'consultation_uid' => $consultation_uid,
                            'status' => 'NEW',
                            'payment' =>  $ent_payment->intent,
                            'payment_intent' => $ent_payment->intent,
                            'amount' => $total_pur,
                            'status' => 'WAITING TO RECEIVE THE PRODUCT',
                            'shipping_date' => $tentative_date,
                            'deleted' => 0,
                            'call_type' => $call_type,
                            'call_number' => $call_number,
                            'created' => date('Y-m-d'),
                            'show' => $show_pur
                        );
                        
                        $c_entity_p_os = $this->DataPurchasesOtherServices->newEntity($array_purchases_os);
                        
                        if ((!$this->DataPurchases->save($c_entity_p))||(!$this->DataPurchasesOtherServices->save($c_entity_p_os))) {
                            $flag_purchase = false;
                            break;
                        }else{
                            if($i<1){
                                $purchase_id = $c_entity_p->id;
                            }
                        }

                    }

                    if ($flag_purchase) {
                        $array_save = array(
                            'uid' => Text::uuid(),
                            'consultation_uid' => $consultation_uid,
                            'date_start' => date('Y-m-d'),
                            'date_expiration' => date('Y-m-d', strtotime(date('Y-m-d') . ' +90 days')),
                            'deleted' => 0,
                            'createdby' => 1,
                            'created' => date('Y-m-d H:i:s'),
                        );
                        $c_entity = $this->DataOtherServices->newEntity($array_save);
                        $ent_saved = $this->DataOtherServices->save($c_entity);
                
                        if ($ent_saved) {
                            $this->set('data_other_services', $ent_saved);
                            // Guarda la tabla "check in"
                            $arr_save = array(
                                'uid' => Text::uuid(),
                                'consultation_uid' => $consultation_uid,
                                'patient_id' => $ent_user->id,
                                'call_date' => date('Y-m-d'),
                                'status' => 'INCOMPLETED',
                                'call_number' => 1,
                                'call_type' => 'FIRST CONSULTATION',
                                'call_title' => 'First Consultation',
                                'show' => 1,
                                'created' => date('Y-m-d H:i:s'),
                                'purchase_id' => $purchase_id,
                            );

                            $cq_entity_first_consultation = $this->DataOtherServicesCheckIn->newEntity($arr_save);

                            if (!$this->DataOtherServicesCheckIn->save($cq_entity_first_consultation)) {
                                $this->message('Check in dont save.');
                                return;
                            }
    
                        }

                    }
                    

                } else {
                    $this->message('Invalid consultation.');
                    return;
                }
            }

            return true;     
        } else {
            return false;
        }
    }

    public function webhook_checkout_event(){
        $checkout_token = get('checkout_token', '');
        $order_id = get('order_id', '');
        $event = get('event', '');
        //$webhook_session_id = get('webhook_session_id', '');
        
        if($event=="not_approved"){
            $this->loadModel('SpaLiveV1.DataPayment');

            $ent_pay = $this->DataPayment->find()->where(['DataPayment.uid' => $order_id])->first();

            if(!empty($ent_pay)){
                
                $ent_pay->intent = $checkout_token;
                $ent_pay->payment = "No (Rejected by Affirm)";
                $ent_pay->is_visible = 1;
                $ent_pay->prod = 1;

                $this->DataPayment->save($ent_pay);
                $this->success();
            }
            
        }
        
    }

    #endregion
// create an agreement refund, charge 50 dls
    public function create_agrrement_refund($type, $name, $id_from) {

        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataAgreements');

        if($type == 'BASIC COURSE'){
            $agreement = $this->CatAgreements->find()->where(['CatAgreements.user_type' => "INJECTOR",
                'CatAgreements.agreement_type' => "PARTIAL_REFUND_BASICCOURSE",
                'CatAgreements.deleted' => 0,                                                          
                'CatAgreements.state_id' => 43])->first();                                                               
        }else if($type == 'ADVANCED COURSE' || $type == 'ADVANCED TECHNIQUES COURSE'){
            $agreement = $this->CatAgreements->find()->where(['CatAgreements.user_type' => "INJECTOR",
                'CatAgreements.agreement_type' => "PARTIAL_REFUND_ADVANCEDCOURSE",
                'CatAgreements.deleted' => 0,                                                          
                'CatAgreements.state_id' => 43])->first();                                                               
        }
        if(!empty($agreement)){                        
            $content = $agreement->content;            
            $constants = [                                    
                '[CP/INJECTOR]'  => $name
            ];
            foreach($constants as $key => $value){
                $content = str_replace($key, $value, $content);
            }            
            
            $array_save = array(
                'uid' => Text::uuid(),
                'user_id' => $id_from,
                'sign' => 0,
                'agreement_uid' => $agreement->uid,
                'file_id' => 0,
                'content' => $content,
                'created' => date('Y-m-d H:i:s'),
            );
            $this->set('array_save',$array_save);
            $find  = $this->DataAgreements->find()->where(['DataAgreements.user_id' => $id_from,
            'DataAgreements.agreement_uid' => $agreement->uid,
            'DataAgreements.deleted' => 0 ])->all();

            if(!empty($find)){
                $this->DataAgreements->updateAll(
                    ['deleted' => 1], 
                    ['DataAgreements.user_id' => $id_from,
                    'DataAgreements.agreement_uid' => $agreement->uid,
                    'DataAgreements.deleted' => 0 ]);
            }
            $entity = $this->DataAgreements->newEntity($array_save);
            if(!$entity->hasErrors()){
                if($this->DataAgreements->save($entity)){                                        
                }else{
                    $this->message('Error trying to save agreement.');                                        
                }
            }
        }        
    }

    private function formatPhoneNumber($str_phone) {
        //(469) 891 9026
        if (strlen($str_phone) != 10) return $str_phone;
        $restul = '(' . $str_phone[0] . $str_phone[1] . $str_phone[2] . ')' . ' ' . $str_phone[3] .  $str_phone[4] . $str_phone[5] . ' ' . $str_phone[6] .  $str_phone[7] .  $str_phone[8] .  $str_phone[9];
        return $str_phone;
    }

    private function notificateSMS($user_id,$body,$Main) {
        $users_array = array( $user_id );
       $Main->notify_devices($body,$users_array,false,false, true, array(), '', array(), true, true);
   }

}

?>