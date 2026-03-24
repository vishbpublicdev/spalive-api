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

use SpaLiveV1\Controller\FillersController;
use SpaLiveV1\Controller\MainController;
use Cake\I18n\FrozenTime;
use SpaLiveV1\Controller\Data\ServicesHelper;

class PartiallyController extends AppPluginController {

    private $total_subscriptionmsl = 3995;
	private $total_subscriptionmd = 17900;
   // private $basic_course_price = 79500;
    private $basic_course_price = 89500;
    private $advanced_course_price = 89500;
    private $advanced_techniques = 99500;
    private $weight_loss_with_partially = 178500;
    private $level_3_fillers = 150000;//level 3
    private $level_1_to_1 = 59500;//level 1 to 1
    private $level_3_medical = 99500;//level 3 medical
    private $full_comission = 5000;
    private $is_dev = false;
    
    // Zeroloan
    // private $apikey = 'QK4SMINZ4/NmmjWU21Hthw';


    private $apikey = 'AB50GUPosXdE976LfZwCgQ';
	private $apiurl = 'https://partial.ly/api/';
    private $checkouturl = 'https://partial.ly/checkout';
    //https://partial.ly/checkout?offer=b4d0e227-896b-408b-8cbe-fd9a7b85edab&amount=700&meta[description]=pago&meta[user_id]=2424&referral_source=shared_link
    //https://demo.partial.ly/checkout?offer=76797cf9-0fce-4bfc-904d-0fef8ecc4cfb&amount=805&meta[description]=&referral_source=shared_link


    private $offers = [
        'basic' => '11a00612-7614-4998-9c0b-79e34ac1c488',
        'advanced' => '73234c3f-8238-4eba-acc2-01da3f586542',
        'level_3' => '4e546921-2991-4f7c-9946-4e77dd33a364',
        'elite' => '8613b979-ec91-4343-8946-bf25771f1a2b',
        'sub' => '6d7db663-055d-49a5-a111-41a0a979b7a7',
        // Zeroloanpro
        // 'basic' => 'dd703e88-a3a3-4280-b41f-e0437d7b0731',
        // 'elite' => 'b8ca798b-c33f-49d6-8850-c02c9c614ba2',
        // 'advanced' => 'b4d0e227-896b-408b-8cbe-fd9a7b85edab',
        // 'sub' => '1a482dbf-ddf0-4544-bcf1-2e6120fcbc59',
    ];

    public $deferred_offers = [
        'basic' => '387ae1e1-b2b2-4f99-8bff-196ea0002b9d',
        'advanced' => '387ae1e1-b2b2-4f99-8bff-196ea0002b9d',
        'level_3' => '387ae1e1-b2b2-4f99-8bff-196ea0002b9d',
        'elite' => '387ae1e1-b2b2-4f99-8bff-196ea0002b9d',
        'sub' => '76797cf9-0fce-4bfc-904d-0fef8ecc4cfb',
    ];

    protected $mailgunKey = null;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

    public function initialize() : void {
        parent::initialize();
		$this->loadModel('SpaLiveV1.SysUsers');
		$this->loadModel('SpaLiveV1.DataPayment');
        $this->is_dev = env('IS_DEV', false);
        if ($this->is_dev) {
            $this->checkouturl = 'https://demo.partial.ly/checkout';
            $this->apikey = 'cngVH+G1RWG6ZaDnWMtRhQ';

            $this->offers = [
                'basic' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'advanced' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'level_3' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'elite' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'sub' => '76797cf9-0fce-4bfc-904d-0fef8ecc4cfb',
            ];

            $this->deferred_offers = [
                'basic' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'advanced' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'level_3' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'elite' => '934e6cad-849c-477f-a27f-eec7925e8e54',
                'sub' => '76797cf9-0fce-4bfc-904d-0fef8ecc4cfb',
            ];
        }

    }



    private function validate_token(){
        $token = get('token','');
        if(!empty($token)){
            $this->loadModel('SpaLiveV1.AppToken');
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                return array(
                    'session' => false,
                );
            }
            return array(
                'user' => $user,
                'session' => true
            );
        } else {
            return array(
                'session' => false,
            );
        }
    }

    function create_payment_record_course(){
        if(!$this->validate_token()) return;

        $course = get('course', '');
        $total = 0;
        $subtotal = 0;
        $promo_code = get('promo_code', '');
        $service_uid = "";
        $type_promo = 'REGISTER';
        $deferred = get('deferred', 0);

        if(empty($course)) {
            $this->message('Invalid course.');
            return;
        }else{

            if($course == 'BASIC COURSE'){
                $total = $this->basic_course_price;
                $course_fetch_name = 'Neurotoxin Course - Level 1';
                $offer_key = 'basic';
            }else if($course == 'ADVANCED COURSE'){
                $total = $this->advanced_course_price;
                $course_fetch_name = 'ADVANCED COURSE';
                $offer_key = 'advanced';
            }else if($course == 'WEIGHT LOSS'){
                $total = $this->weight_loss_with_partially;
                $course_fetch_name = 'WEIGHT LOSS';
                $offer_key = 'elite';
            }else if($course == 'LEVEL 1-1 NEUROTOXINS'){
                $total = $this->level_1_to_1;
                $course_fetch_name = 'LEVEL 1-1 NEUROTOXINS';
                $offer_key = 'elite';
            }else if($course == 'FILLERS COURSE'){
                $total = $this->level_3_fillers;
                $course_fetch_name = 'FILLERS COURSE';
                $offer_key = 'fillers';
            }else if($course == 'MEDICAL COURSE'){
                $course = 'ADVANCED TECHNIQUES MEDICAL';
                $course_fetch_name = 'ADVANCED TECHNIQUES MEDICAL';
                $total = $this->level_3_medical;
                $offer_key = 'level_3';
            }
        }

        $this->set('code_valid', false);
        $Payments = new \SpaLiveV1\Controller\PaymentsController();

        $uid = Text::uuid();
        $array_save = [
            'id_from'           => USER_ID,
            'id_to'             => 0,
            'uid'               => $uid,
            'intent'            => $uid,
            'service_uid'       => $service_uid,
            'type'              => $course,
            'promo_discount'    => 0,
            'promo_code'        => $promo_code,
            'subtotal'          => 10000,//$total,
            'total'             => 10000,//$total,
            'prod'              => 1,
            'is_visible'        => $this->is_dev ? 1 : 0,
            'payment_platform'  => 'partially',
            'created'           => date('Y-m-d H:i:s'),
            'createdby'         => defined('USER_ID') ? USER_ID : 0,
            'state'          => defined('USER_STATE') ? USER_STATE : 0,
        ];        

        $this->loadModel('SpaLiveV1.DataPayment');
        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPayment->save($c_entity)) {
                $token = get('token', '');
                $url = env('URL_API', '');
                $offer_id = $deferred == 1 ? $this->deferred_offers[$offer_key] : $this->offers[$offer_key];
                $metadata = [
                    'offer' => $offer_id,
                    'amount' => ($total / 100),
                    'meta[description]' => $course_fetch_name,
                    'meta[payment_uid]' => $uid, 
                    'meta[totalamount]' => $total, 
                    'referral_source' => 'shared_link',
                ];

                if($deferred == 1){
                    $metadata['meta[deferred]'] = 1;
                }

                $extra_string = '';
                foreach($metadata as $key => $value){
                    $concat_char = empty($extra_string) ? '?' : '&';

                    $extra_string .= $concat_char . $key . '=' . $value;
                } 
                $checkout_url = $this->checkouturl . $extra_string;
                //https://partial.ly/checkout?offer=b4d0e227-896b-408b-8cbe-fd9a7b85edab&amount=700&meta[description]=pago&meta[user_id]=2424&referral_source=shared_link
            
                $this->set('uid', $uid);
                $this->set('checkout_url', $checkout_url);
                $this->success();
            }
        }
    }

    function create_payment_record_subscription(){
        if(!$this->validate_token()) return;

        $monthly_type = get('monthly_type', 3);
        $total = 0;
        $subtotal = 0;
        $promo_code = get('promo_code', '');
        $service_uid = "";

        if(empty($monthly_type)) {
            $this->message('Invalid course.');
            return;
        }else{
            if($monthly_type == 3){
                $total = 88500;
                $offer_key = 'sub';
            }
        }

        $uid = $this->save_subscriptions();
        if(!$uid){
            $this->message('Error saving subscription.');
            return;
        }

        $token = get('token', '');
        $url = env('URL_API', '');

        $metadata = [
            'offer' => $this->offers[$offer_key],
            'amount' => ($total / 100),
            'meta[description]' => 'subscription',
            'meta[uid_msl]' => $uid['msl'],
            'meta[uid_md]' => $uid['md'], 
            'meta[totalamount]' => $total, 
            'referral_source' => 'shared_link',
        ];
        $extra_string = '';
        foreach($metadata as $key => $value){
            $concat_char = empty($extra_string) ? '?' : '&';

            $extra_string .= $concat_char . $key . '=' . $value;
        } 
        $checkout_url = $this->checkouturl . $extra_string;
        https://partial.ly/checkout?offer=1a482dbf-ddf0-4544-bcf1-2e6120fcbc59&amount=885&meta[description]=MySpaLive%20Xeomin%20Starter%20Kit&referral_source=shared_link
        //https://partial.ly/checkout?offer=b4d0e227-896b-408b-8cbe-fd9a7b85edab&amount=700&meta[description]=pago&meta[user_id]=2424&referral_source=shared_link
    
        $this->set('uid', $uid);
        $this->set('checkout_url', $checkout_url);
        $this->success();
    }

    function save_subscriptions(){
        $main_service = 'NEUROTOXINS';
        $other_school = get('is_from_school', 0);

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        // sub msl
        $uid = Text::uuid();
        $array_save = array(
            'user_id' => USER_ID,
            'uid' => $uid,
            'event' => 'create_payment_record_subscription',
            'payload' => '',
            'request_id' => '',
            'data_object_id' => '',
            'customer_id' => '',
            'payment_method' => '',
            'subscription_type' => 'SUBSCRIPTIONMSL',
            'promo_code' =>  get('promo_code',''),
            'subtotal' => 3995,
            'total' => 3500,
            'status' => 'ACTIVE',
            'deleted' => 1,
            'created' => date('Y-m-d H:i:s'),
            'agreement_id' => 0,
            'comments' => '',
            'main_service' => $main_service,
            'addons_services' => '',
            'payment_details' => json_encode(array($main_service => 3500)),
            'state' => USER_STATE,
            'other_school' => $other_school,
            'monthly' => '3',
        );

        $c_entity = $this->DataSubscriptions->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $submsl = $this->DataSubscriptions->save($c_entity);
            // sub md
            $array_save = array(
                'user_id' => USER_ID,
                'uid' => Text::uuid(),
                'event' => 'create_payment_record_subscription',
                'payload' => '',
                'request_id' => '',
                'data_object_id' => '',
                'customer_id' => '',
                'payment_method' => '',
                'subscription_type' => 'SUBSCRIPTIONMD',
                'promo_code' =>  get('promo_code',''),
                'subtotal' => 17900,
                'total' => 26000,
                'status' => 'ACTIVE',
                'deleted' => 1,
                'created' => date('Y-m-d H:i:s'),
                'agreement_id' => 0,
                'comments' => '',
                'main_service' => $main_service,
                'addons_services' => '',
                'payment_details' => json_encode(array($main_service => 26000)),
                'state' => USER_STATE,
                'other_school' => $other_school,
                'monthly' => '3',
            );

            $c_entity = $this->DataSubscriptions->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $submd = $this->DataSubscriptions->save($c_entity);
            }

            return array('msl' => $submsl->uid, 'md' => $submd->uid);
        }

        return false;
    }

    function check_method_available(){
        if(!$this->validate_token()) return;

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
                "DataPayment.payment_platform" => "partially"                
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

    public function cancel_checkout(){

        if(!$this->validate_token()) return;

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
            <br>
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

    public function cancel_checkout_sub(){
        
        if(!$this->validate_token()) return;

        $msl_uid = get('msl_uid', '');
        $md_uid = get('md_uid', '');
        $from_app = get('from_app', 0);

        $this->cancel_payment_sub($msl_uid, $md_uid);
        
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
            <h1 class="thank-you">Payment not completed</h1>
            <br>
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

    public function getStatusPayment($payment_uid){
        $this->loadModel('SpaLiveV1.DataPayment');
        $payment = $this->DataPayment->find()
            ->select([
                'DataPayment.id',
                'DataPayment.uid',
                'DataPayment.payment_platform',
                'DataPayment.payment',
                'DataPayment.intent',
                'DataPayment.refund_id',
                'DataPayment.is_visible',
                'DataPayment.cancelled',
                'deferred_status' => 'DataDeferredPayments.status',
                'deferred_payment_id' => 'DataDeferredPayments.id'
            ])
            ->join([
                'DataDeferredPayments' => [
                    'table' => 'data_deferred_payments',
                    'type' => 'LEFT',
                    'conditions' => 'DataDeferredPayments.id = DataPayment.deferred_payment_id'
                ]
            ])
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "partially"
            ])
            ->order(['DataPayment.id' => 'ASC'])
            ->first();

        $status = "NOT FOUND";

        if(!empty($payment)){
            $is_set_intent  = $payment->intent != "" && $payment->intent != null;
            $is_set_payment = $payment->payment != "" && $payment->payment != null;

            if($payment->deferred_payment_id != null){
                $status = 'DEFERRED';
            }else if($payment->refund_id != 0){
                $status = "REFUNDED";   
            }else if($payment->is_visible == 1 && $is_set_payment){
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

    public function getStatusPaymentSub($payment_uid){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $payment = $this->DataSubscriptions->find()
            ->where([
                "uid" => $payment_uid,
            ])
            ->order(['id' => 'ASC'])
            ->first();

        $status = "NOT FOUND";

        if(!empty($payment)){
            $is_set_intent  = $payment->intent != "" && $payment->intent != null;
            $is_set_payment = $payment->payment != "" && $payment->payment != null;

            if($payment->status == 'ACTIVE' && $payment->deleted == 0){
                $status = "AUTHORIZED";  
            }else if($payment->status == 'ACTIVE' && $payment->deleted == 1){
                $status = "PENDING AUTHORIZATION";
            }else{
                $status = "DENIED";
            }
        }

        return $status;
    }

    public function cancel_payment($payment_uid){
        $this->loadModel('SpaLiveV1.DataPayment');

        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "partially"
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

    public function cancel_payment_sub($msl_uid, $md_uid){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        // msl
        $payment = $this->DataSubscriptions->find()
            ->where([
                "uid" => $msl_uid,
            ])
            ->order(['id' => 'DESC'])
            ->first();

        if(!empty($payment)){
            $this->DataSubscriptions->updateAll(
                [
                    'status' => 'CANCELLED'
                ],
                ['id' => $payment->id]
            );            
        }

        // md
        $payment = $this->DataSubscriptions->find()
            ->where([
                "uid" => $md_uid,
            ])
            ->order(['id' => 'DESC'])
            ->first();

        if(!empty($payment)){
            $this->DataSubscriptions->updateAll(
                [
                    'status' => 'CANCELLED'
                ],
                ['id' => $payment->id]
            );            
        }
    }

    function check_payment_status(){
        if(!$this->validate_token()) return;
        
        $payment_uid     = get('uid', '');
        $request_details = get('request_details', 0); 

        $status = $this->getStatusPayment($payment_uid);
        
        if($status != "NOT FOUND"){
            if($request_details == 1){
                $details = $this->getDetailsPayment($payment_uid);
                $this->set('details', $details);

                /*if($status=="AUTHORIZED" && $details["type"] == 'WEIGHT LOSS'){
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
                */
            }
            
            $this->set('status', $status);  
            $this->success();
        }else{
            $this->message('Payment not found.');
        }
    }

    function check_payment_status_subs(){
        if(!$this->validate_token()) return;
        
        $md_uid     = get('md_uid', '');
        $msl_uid     = get('msl_uid', '');
        $request_details = get('request_details', 0); 

        $status_md = $this->getStatusPaymentSub($md_uid);

        $status_msl = $this->getStatusPaymentSub($msl_uid);
        
        if($status_md != "NOT FOUND"){
            if($request_details == 1){
                $details = array(
                    'id' => $md_uid,
                    'data' => '',
                    'type' => 'SUBSCRIPTION',
                    'promo_code' => '',
                    'discount' => 0,
                    'products' => [
                        array(
                            "name" => "3 Month Subscription",
                            "price" => 88500,
                            "qty" => 1
                        )
                    ],
                );
                $this->set('details', $details);
            }
            
            $this->set('status', $status_md);  
            $this->success();
        }else{
            $this->message('Payment not found.');
        }
    }

    public function getDetailsPayment($payment_uid){
        $payment = $this->DataPayment->find()
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "partially"
            ])
            ->order(['DataPayment.id' => 'DESC'])
            ->first();

        $details = [];

        if(!empty($payment)){
            $total = $payment->total;
            $details["id"]          = $payment->uid;
            $details["data"]        = json_decode($payment->payment, true);
            $details["type"]        = $payment->type;
            $details["promo_code"]  = $payment->promo_code;
            $details["discount"]    = $payment->promo_discount;
            $details["products"]    = [];
            if($payment->type == "BASIC COURSE"){
                $total = $this->basic_course_price;
                $details["products"] = [
                    array(
                        "name" => "Neurotoxin Course - Level 1",
                        "price" => $this->basic_course_price,
                        "qty" => 1
                    )
                ];
            } else if($payment->type == "ADVANCED COURSE"){
                $total = $this->advanced_course_price;
                $details["products"] = [
                    array(
                        "name" => "Advanced Course",
                        "price" => $this->advanced_course_price,
                        "qty" => 1
                    )
                ];
            } else if($payment->type == "ADVANCED TECHNIQUES COURSE" || $payment->type == "ADVANCED TECHNIQUES MEDICAL"){
                $total = $this->advanced_techniques;
                $details["products"] = [
                    array(
                        "name" => "Advanced Techniques in Neurotoxin Injections - Level 3",
                        "price" => $this->advanced_techniques,
                        "qty" => 1
                    )
                ];
            } else if($payment->type == "WEIGHT LOSS"){
                $total = $this->weight_loss_with_partially;
                $details["products"] = [
                    array(
                        "name" => "3 months of Semaglutide",
                        "price" => $this->weight_loss_with_affirm,
                        "qty" => 1
                    )
                ];
            }else{
                $this->loadModel('SpaLiveV1.CatCoursesType');
                $training = $this->CatCoursesType->find()->where(['CatCoursesType.name_key' => $payment->type])->first();
                if(!empty($training)){
                    $total = $training->price;
                    $details["products"] = [
                        array(
                            "name" => $training->title,
                            "price" => $training->price,
                            "qty" => 1
                        )
                    ];
                }
            }
            $details["subtotal"]    = $total;
            $details["total"]       = $total;
            
        }

        return $details;
    }

    function wbh() {
        

        $input = file_get_contents('php://input');

        $headers = $this->getRequestHeaders();

        $data = json_decode($input, true);

        
        $signature = ''; $user_agent = '';
        if (isset($headers['partially-signature'])) {
            $signature = $headers['partially-signature'];
             // Calcula el HMAC para validar la firma
            $computedSignature = hash_hmac('sha256', $input, $this->apikey);

            if (!hash_equals($signature, $computedSignature)) {
                $this->message('Invalid siganture');
                // return;
            }

        }
        if (isset($headers['User-Agent'])) {
            $user_agent = $headers['User-Agent'];
            if ($user_agent != 'Elixir/Partial.ly') {
                $this->message('Invalid user agent.');
                //return;
            }
        }
        
        $array_save = [
                'input' => $input,
                'headers' => json_encode($headers),
                'created' => date('Y-m-d H:i:s'),
            ];
        $this->loadModel('SpaLiveV1.PartiallyWebhook');
        $c_entity = $this->PartiallyWebhook->newEntity($array_save);
        $this->PartiallyWebhook->save($c_entity);

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->message('Json error');
            return;
        }

        $event = $data['event'] ?? 'unknown';
        
        // Normalizar el acceso a payment_plan - puede estar directamente en data o dentro de payment
        $payment_plan = null;
        if (isset($data['data']['payment']['payment_plan'])) {
            // Formato para payment_succeeded y otros eventos de payment
            $payment_plan = $data['data']['payment']['payment_plan'];
        } elseif (isset($data['data']['payment_plan'])) {
            // Formato para plan_opened y otros eventos de payment_plan
            $payment_plan = $data['data']['payment_plan'];
        }
        
        if (empty($payment_plan)) {
            $this->message('Invalid webhook structure: payment_plan not found');
            return;
        }
        
        $metadata = $payment_plan['meta'] ?? [];
        $amount = $payment_plan['amount'] ?? 0;
        
        // Handle payment_failed event for deferred payments
        if ($event === 'payment_failed' && isset($metadata['deferred']) && $metadata['deferred'] === '1' && isset($metadata['payment_uid'])) {
            $this->loadModel('SpaLiveV1.DataDeferredPayments');
            $deferred_payment = $this->DataDeferredPayments->find()
                ->select([
                    'DataDeferredPayments.id',
                    'DataDeferredPayments.uid',
                    'DataDeferredPayments.user_id',
                    'DataDeferredPayments.amount',
                    'DataDeferredPayments.description',
                    'DataDeferredPayments.type',
                    'DataDeferredPayments.scheduled_date',
                    'User.id',
                    'User.name',
                    'User.lname',
                    'User.email'
                ])
                ->join([
                    'User' => [
                        'table' => 'sys_users',
                        'type' => 'INNER',
                        'conditions' => 'User.id = DataDeferredPayments.user_id'
                    ]
                ])
                ->where([
                    "DataDeferredPayments.uid" => $metadata['payment_uid'],
                    "DataDeferredPayments.status" => 'PENDING',
                    "DataDeferredPayments.deleted" => 0,
                ])
                ->first();

            if (empty($deferred_payment)) {
                $this->message('Invalid deferred payment');
                return;
            }

            // Get error message from payment data
            $payment_data = $data['data']['payment'] ?? [];
            $error_message = $payment_data['message'] ?? 'Payment failed';

            // Update deferred payment to FAILED status
            $deferred_payment->status = 'FAILED';
            $deferred_payment->executed_date = date('Y-m-d H:i:s');
            $deferred_payment->error_message = $error_message;
            $deferred_payment->payment_intent_id = $payment_data['id'] ?? null;
            $deferred_payment->charge_id = $payment_data['id'] ?? null;
            $this->DataDeferredPayments->save($deferred_payment);

            // Send error email
            $this->sendDeferredPaymentErrorEmail($deferred_payment, $error_message);

            // Notify Jenna about failed payment
            $this->sendFailedSMSToJenna($deferred_payment, $error_message);

            $this->success();
            return;
        }
        
        if (isset($metadata['payment_uid']) && (!isset($metadata['deferred']) || $event == 'payment_succeeded')) {
            // Obtener el payment_id - puede estar en diferentes lugares según el evento
            $partially_payment_id = $data['data']['payment']['id'] ?? $data['data']['payment_plan']['id'] ?? null;
            $payment_uid = $metadata['payment_uid'];
            $totalamount = $metadata['totalamount'];

            $this->loadModel('SpaLiveV1.DataPayment');
            // Para payment_succeeded con deferred, is_visible puede ser 1 porque ya se actualizó en plan_opened
            $is_deferred_payment_succeeded = isset($metadata['deferred']) && $event == 'payment_succeeded';
            $where_conditions = [
                "DataPayment.uid" => $payment_uid,
                "DataPayment.payment_platform" => "partially",
            ];
            if ($is_deferred_payment_succeeded) {
                $where_conditions["DataPayment.is_visible"] = 1;
            } else {
                // For plan_opened: find payment that hasn't been processed yet.
                // Use payment empty check instead of is_visible - in dev mode payments are
                // created with is_visible=1, so is_visible=0 would never match.
                $where_conditions['OR'] = [
                    ['DataPayment.payment IS' => null],
                    ['DataPayment.payment' => ''],
                ];
            }
            
            $ent_payment = $this->DataPayment->find()->where($where_conditions)->first();

            if (empty($ent_payment)) {
                $this->message('Invalid payment');
                return;
            }

            // if (floatval($ent_payment->total / 100) != floatval($amount)) {
            //     $this->message('Invalid amount');
            // }

            switch($event)  {
                case 'plan_opened':
                    $ent_payment->is_visible = 1;
                    $ent_payment->comission_payed = 1;
                    $ent_payment->payment = $partially_payment_id;
                    $ent_payment->receipt = $partially_payment_id;
                    $this->DataPayment->save($ent_payment);
                    $this->generateComissions($ent_payment->type,$ent_payment->uid, $totalamount,$ent_payment->id_from);
                
                    break;
                case 'payment_succeeded':
                    $this->loadModel('SpaLiveV1.DataDeferredPayments');
                    $deferred_payment = $this->DataDeferredPayments->find()->where([
                        "DataDeferredPayments.uid" => $metadata['payment_uid'],
                        "DataDeferredPayments.status IN" => array('PENDING','FAILED'),
                        "DataDeferredPayments.deleted" => 0,
                    ])->first();

                    if(empty($deferred_payment)){
                        $this->message('Invalid deferred payment');
                        return;
                    }

                    $deferred_payment->status = 'COMPLETED';
                    $deferred_payment->executed_date = date('Y-m-d H:i:s');
                    $payment_data = $data['data']['payment'] ?? [];
                    $deferred_payment->payment_intent_id = $payment_data['id'] ?? null;
                    $deferred_payment->charge_id = $payment_data['id'] ?? null;
                    $deferred_payment->receipt_url = $payment_data['receipt_url'] ?? null;
                    $deferred_payment->error_message = null;
                    $this->DataDeferredPayments->save($deferred_payment);

                    $payment_id = $payment_data['id'] ?? $partially_payment_id ?? null;
                    $receipt_url = $payment_data['receipt_url'] ?? $payment_id;
                    $ent_payment->comission_payed = 1;
                    $ent_payment->intent = $payment_id;
                    $ent_payment->payment = $payment_id;
                    $ent_payment->receipt = $receipt_url;
                    $ent_payment->total = $totalamount;
                    $ent_payment->created = date('Y-m-d H:i:s');
                    $this->DataPayment->save($ent_payment);
                    $this->generateComissions($ent_payment->type,$ent_payment->uid, $totalamount,$ent_payment->id_from);

                    $this->success();
                default:
            }   
        }else if(isset($metadata['payment_uid']) && isset($metadata['deferred'])){
            $payment_uid = $metadata['payment_uid'];
            $this->loadModel('SpaLiveV1.DataPayment');
            $ent_payment = $this->DataPayment->find()
            ->select([
                'DataPayment.id',
                'DataPayment.uid',
                'DataPayment.id_from',
                'DataPayment.type',
                'user_id'=>'User.id',
                'user_name'=>'User.name',
                'user_lname'=>'User.lname',
                'user_email'=>'User.email',
                'user_phone'=>'User.phone',
            ])
            ->join([
                'User' => [
                    'table' => 'sys_users',
                    'type' => 'LEFT',
                    'conditions' => 'User.id = DataPayment.id_from'
                ]
            ])
            ->where([
                "DataPayment.uid" => $payment_uid,
                "DataPayment.is_visible" => $this->is_dev ? 1 : 0,
            ])->first();

            if (empty($ent_payment)) {
                $this->message('Invalid payment');
                return;
            }

            // Usar el payment_plan ya normalizado
            if (empty($payment_plan)) {
                $this->message('Invalid webhook structure: payment_plan not found');
                return;
            }

            $customer_id = $payment_plan['customer']['id'] ?? null;
            $payment_method = $payment_plan['payment_method_id'] ?? null;
            if (!empty($payment_plan['payment_schedule']['installments']) && isset($payment_plan['payment_schedule']['installments'][0]['scheduled'])) {
                $scheduled_date = $payment_plan['payment_schedule']['installments'][0]['scheduled'];
            } else {
                $scheduled_date = $payment_plan['payment_schedule']['starts_date'] ?? date('Y-m-d');
            }

            // Parse date without timezone conversion to avoid day shift issues
            // strtotime() can cause timezone issues that shift the date by one day
            // If date is already in Y-m-d format, use it directly to avoid timezone conversion
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduled_date)) {
                // Date is already in Y-m-d format, use it directly without any conversion
                // This prevents timezone issues that strtotime() can cause
            } else {
                // Date is not in Y-m-d format, parse it and extract date part
                // Use DateTime to parse, but format to Y-m-d to get only date without time
                $dateTime = new \DateTime($scheduled_date);
                $scheduled_date = $dateTime->format('Y-m-d');
            }

            $values = [
                'BASIC COURSE' => 'LEVEL 1',
                'ADVANCED COURSE' => 'LEVEL 2',
                'ADVANCED TECHNIQUES MEDICAL' => 'LEVEL 3 MEDICAL',
                'FILLERS COURSE' => 'LEVEL 3 FILLERS',
                'LEVEL 1-1 NEUROTOXINS' => 'LEVEL 1-1 NEUROTOXINS',
            ];

            $payment_type = isset($values[$ent_payment->type]) ? $values[$ent_payment->type] : $ent_payment->type;

            $this->loadModel('SpaLiveV1.DataDeferredPayments');
            $array_save = [
                'uid' => $metadata['payment_uid'],
                'user_id' => $ent_payment->id_from,
                'customer_id' => $customer_id,
                'payment_method' => $payment_method,
                'amount' => 10000,
                'currency' => 'usd',
                'description' => $ent_payment->type, // valor de enum en data_payment
                'type' => $payment_type, // valor de enum en data_trainings
                'reference_id' => null,
                'reference_type' => $ent_payment->type,
                'scheduled_date' => $scheduled_date,
                'status' => 'PENDING',
                'metadata' => json_encode($data),
                'created' => date('Y-m-d H:i:s'),
                'created_by' => $ent_payment->id_from,
                'modified' => date('Y-m-d H:i:s'),
                'modified_by' => $ent_payment->id_from,
                'deleted' => 0,
                'source' => 'partially',
            ];

            $c_entity = $this->DataDeferredPayments->newEntity($array_save);
            $deferred_payment = $this->DataDeferredPayments->save($c_entity);

            $this->DataPayment->updateAll(
                ['deferred_payment_id' => $deferred_payment->id, 'is_visible' => 1],
                ['id' => $ent_payment->id]
            );

            if(!$this->is_dev){
                $Ghl = new GhlController();
                $array_ghl = array(
                    'email' => $ent_payment->user_email,
                    'name' => $ent_payment->user_name,
                    'lname' => $ent_payment->user_lname,
                    'phone' => $ent_payment->user_phone,
                    'costo' => 0,
                    'column' => 'Purchased basic',
                );
                $contactId = $Ghl->updateOpportunityTags($array_ghl);
                $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'deferred payment');
                $this->set('tag', $tag);
            }

            $this->success();

        }else if(isset($metadata['uid_msl']) && isset($metadata['uid_md'])){
            $partially_payment_id = $data['data']['payment']['id'];
            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
            $submsl = $this->DataSubscriptions->find()->where([
                "DataSubscriptions.uid" => $metadata['uid_msl'],
            ])->first();

            $submd = $this->DataSubscriptions->find()->where([
                "DataSubscriptions.uid" => $metadata['uid_md'],
            ])->first();

            if(empty($submsl) && empty($submd)){
                $this->message('Invalid subscription');
                return;
            }

            switch($event)  {
                case 'plan_opened':
                    $submsl->deleted = 0;
                    $submsl->status = 'ACTIVE';
                    $this->DataSubscriptions->save($submsl);
                    $submd->deleted = 0;
                    $submd->status = 'ACTIVE';
                    $this->DataSubscriptions->save($submd);
                    $this->generate_pays_subs($submsl, $submd, $partially_payment_id);
                    break;   
                default:
            }   
        }
        $this->success();
    }

    
    /**
     * Envía correo de error de pago diferido
     */
    private function sendDeferredPaymentErrorEmail($payment, $error_message) {
        $amount_formatted = number_format($payment->amount / 100, 2);
        // Handle scheduled_date - it can be a DateTime object or a string
        if (is_object($payment->scheduled_date)) {
            // If it's a DateTime/Time object, format it directly
            $payment_date = $payment->scheduled_date->format('F d, Y');
        } else {
            // If it's a string, use strtotime to convert it
            $payment_date = date('F d, Y', strtotime($payment->scheduled_date));
        }
        $course_type = $payment->description ? $payment->description : $payment->type;
        $user_name = $payment['User']['name'] . ' ' . $payment['User']['lname'];
        $account_url = 'https://app.myspalive.com/';
        
        $html = "
        <!doctype html>
        <html>
        <head>
            <meta name=\"viewport\" content=\"width=device-width\">
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">
            <title>Payment Failed - MySpaLive</title>
            <style>
                body { background-color: #f6f6f6; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.6; margin: 0; padding: 0; }
                .container { display: block; margin: 0 auto; max-width: 580px; padding: 10px; width: 580px; }
                .content { box-sizing: border-box; display: block; margin: 0 auto; max-width: 580px; padding: 10px; }
                .main { background: #ffffff; border-radius: 3px; width: 100%; }
                .wrapper { box-sizing: border-box; padding: 20px; }
                p { font-size: 14px; color: #333333; line-height: 1.6; margin: 10px 0; }
                a { color: #655489; text-decoration: underline; }
            </style>
        </head>
        <body>
            <div class=\"container\">
                <div class=\"content\">
                    <table class=\"main\" role=\"presentation\" border=\"0\" cellpadding=\"0\" cellspacing=\"0\">
                        <tr>
                            <td class=\"wrapper\">
                                <p>Hi {$user_name},</p>
                                
                                <p>We attempted to process your payment for <strong>{$course_type}</strong> on <strong>{$payment_date}</strong>, but the transaction was not successful.</p>
                                
                                <p>To keep your enrollment on track, please update your payment method or retry your payment as soon as possible: <a href=\"{$account_url}\">Go to the app</a></p>
                                
                                <p>If you believe this is an error or need assistance, our support team is here to help.</p>
                                
                                <p style=\"margin-top: 30px;\">Thank you,<br>MySpaLive</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $data = array(
            'from' => 'MySpaLive <noreply@mg.myspalive.com>',
            'to' => $payment['User']['email'],
            'subject' => "We couldn't process your payment for {$course_type}",
            'html' => $html,
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


    function w() { 
        $input = file_get_contents('php://input');
        $headers = $this->getRequestHeaders();
        $data = json_decode($input, true);
        $array_save = [
                'input' => $input,
                'headers' => json_encode($headers),
                'created' => date('Y-m-d H:i:s'),
            ];
        $this->loadModel('SpaLiveV1.PartiallyWebhook');
        $c_entity = $this->PartiallyWebhook->newEntity($array_save);
        $this->PartiallyWebhook->save($c_entity);

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->message('Json error');
            return;
        }

        $status = $data['data']['payment']['status'] ?? 'unknown';
        $customerPhone = $data['data']['payment']['payment_plan']['customer']['phone'] ?? 'unknown';
        $customerName = $data['data']['payment']['payment_plan']['customer']['first_name'] ?? 'Customer';
        $offerID = $data['data']['payment']['payment_plan']['offer_id'] ?? 'unknown';

        if ($status === 'failed'){
            //offers from production environment (partial.ly)
            //validation to send sms related to courses only
         
            // if($offerID === 'dd703e88-a3a3-4280-b41f-e0437d7b0731' || $offerID === 'b4d0e227-896b-408b-8cbe-fd9a7b85edab' || $offerID === 'b8ca798b-c33f-49d6-8850-c02c9c614ba2'){
            if($offerID === 'ad161b20-fe1e-4fc3-bf49-e457e940617f' || $offerID === '8613b979-ec91-4343-8946-bf25771f1a2b' || $offerID === '284e640a-267b-45e5-bc8e-57a1fd2fac00'){
                if($customerPhone != 'unknown' && $customerPhone != "" ){        
                    #if(!$isDev){
                        try {           
                            $sid    = env('TWILIO_ACCOUNT_SID'); 
                            $token  = env('TWILIO_AUTH_TOKEN'); 
                            $twilio = new Client($sid, $token); 
                                
                            $message = $twilio->messages 
                                        ->create( $customerPhone, // to 
                                                array(  
                                                    "messagingServiceSid" => "MG7264c7b10aa8abe2dbb513e2a9f43840",      
                                                    "body" => 'Hi ' . $customerName . ', your payment for your course purchase on MySpaLive has failed. Please update your payment method on Partial.ly to avoid service disruption'
                                                ) 
                                        ); 
                            } catch (TwilioException $e) {
                                $array_save = [
                                    'input' => $e,
                                    'headers' => json_encode($headers),
                                    'created' => date('Y-m-d H:i:s'),
                                ];
                            $c_entity = $this->PartiallyWebhook->newEntity($array_save);
                            $this->PartiallyWebhook->save($c_entity);
                            }
                    #}
                }
                $this->success();
            }


        }    
    }

    private function generateComissions($course, $payment_uid, $total_amount, $user_id) {

        $Main = new MainController();
        $type_string = $course;
        

        $temp_user = $this->SysUsers->find()->where(['SysUsers.id' => $user_id, 'SysUsers.deleted' => 0])->first();
        if($temp_user->steps != 'HOME' && $temp_user->steps != 'WAITINGSCHOOLAPPROVAL'){
            
            if($course == 'BASIC COURSE'){
                $step = 'SELECTBASICCOURSE';
            }else if($course == 'ADVANCED COURSE'){
                $step = $temp_user->steps;
            } else if($course == 'ADVANCED TECHNIQUES MEDICAL'){ 
                $step = $temp_user->steps;
            }else {
                $step = 'LICENCEOT';
            }
            
        }else{
            $step = $temp_user->steps;
        }

        if(!defined('USER_ID'))define('USER_ID', $temp_user->id);
        if(!defined('USER_UID'))define('USER_UID', $temp_user->uid);
        if(!defined('USER_NAME'))define('USER_NAME', $temp_user->name);
        if(!defined('USER_LNAME'))define('USER_LNAME', $temp_user->lname);
        if(!defined('USER_EMAIL'))define('USER_EMAIL', $temp_user->email);
        if(!defined('USER_PHONE'))define('USER_PHONE', $temp_user->phone);


        $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();
        $userEntity->steps = $step;
        $userEntity->login_status = 'READY';
        $userEntity->last_status_change = date('Y-m-d H:i:s');
        $userEntity->active = 1;

        #region Pay comission to sales representative
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        if($course == 'BASIC COURSE' || $course == 'ADVANCED COURSE' || $course == 'ADVANCED TECHNIQUES MEDICAL'){
            $Main->notify_devices('AFTER_BUY_BASIC_COURSE_EN',array(USER_ID),false,true);
        }

        if($course == 'BASIC COURSE'){
            $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
            ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
        } else{
            $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
            ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.deleted' => 0, 'DSR.team' => 'INSIDE'])->last();

            if(empty($assignedRep)){
                $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                    'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
            }
        }

        if (!empty($assignedRep)) {
            $amount_comission = 0;
            $description_comission = '';
            $pay = $this->DataPayment->find()
            ->where(['DataPayment.id_from' => USER_ID, 
                    'DataPayment.uid' => $payment_uid, 
                    'DataPayment.type' => $type_string])->first();

            $this->loadModel('SpaLiveV1.DataSalesRepresentative');
            $representative = $this->DataSalesRepresentative->find()->where([
                'DataSalesRepresentative.user_id' => $assignedRep['User']['id'],
                'DataSalesRepresentative.deleted' => 0,
            ])->first();

            if (!empty($representative) && !empty($pay)) {
            if($course == 'BASIC COURSE'){  
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the basic training purchase for $' . $total_amount / 100, $Main);
                $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the basic training purchase for $' . $total_amount / 100;
                $Main->send_email_after_register($assignedRep['User']['email'],'Basic training purchase',$msg);//($to, $subject, $body) 

                $this->email_to_jenna('Level 1', USER_NAME . ' ' . USER_LNAME);
                
                #region Pay Commision invitation injector
                $this->loadModel('SpaLiveV1.DataNetworkInvitations');

                $existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower(USER_EMAIL)])->first();

                if(!empty($existUser)){
                    $invite_user = $this->SysUsers->find()->where(['id' => $existUser->parent_id, 'deleted' => 0, 'active' => 1])->first();

                    if(!empty($invite_user)){
                        $parentRepRow = $this->DataSalesRepresentative->find()->where([
                            'DataSalesRepresentative.user_id' => $existUser->parent_id,
                            'DataSalesRepresentative.deleted' => 0,
                        ])->first();

                    if (!empty($parentRepRow)) {
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
                        $this->full_comission = 2500;
                        $service = 'Neurtoxins';
                        // $this->send_email_sales_team_member(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep);
                    }
                    }
                }
                #endregion
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
                
                $description_comission = 'SALES TEAM BASIC';
                $this->log(__LINE__ . ' ' . json_encode($representative->rank));
                if($representative->rank == 'JUNIOR' && !empty($existUser)){ // Si el representante es JUNIOR y hay invitacion entonces solo cambiamos el monto de la comision a $50
                    $amount_comission = $amount_comission == 0 ? 0 : 2500;
                }else if($representative->rank == 'JUNIOR' && empty($existUser)){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                    $amount_comission = $amount_comission == 0 ? 0 : 2500;
                    $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                    ])->where(['DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                    if(!empty($pay) && !empty($senior_rep)){
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
                        $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission, $senior_rep);
                    }
                } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                    $amount_comission_senior = $amount_comission == 0 ? 0 : 2500;
                    $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                    ])->where(['DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                    if(!empty($pay) && !empty($senior_rep)){
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
                        $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission_senior, $senior_rep);
                    }
                }

                if(!env('IS_DEV', false)){
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
                }

            }else if($course == 'ADVANCED COURSE'){$this->log(__LINE__ . ' ' . json_encode('ADVANCED COURSE'));
                $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the advanced training purchase for $' . $total_amount / 100;
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the advanced training purchase for $' . $total_amount / 100, $Main);
                $Main->send_email_after_register($assignedRep['User']['email'],'Advanced training purchase',$msg);

                $this->email_to_jenna('Level 2', USER_NAME . ' ' . USER_LNAME);

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

                if($representative->team == 'INSIDE'){ // Si el representante es INSIDE entonces pagamos con normalidad la comision y le generamos senior otra comision de $50
                    if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission = $amount_comission == 0 ? 0 : 2500;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay) && !empty($senior_rep)){
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
                            $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission, $senior_rep);
                        }
                    } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission_senior = $amount_comission == 0 ? 0 : 2500;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay) && !empty($senior_rep)){
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
                            $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission_senior, $senior_rep);
                        }
                    }
                } else if($representative->team == 'OUTSIDE'){// Si el representante es OUTSIDE entonces $25 de comision y nada al senior
                    $amount_comission = $amount_comission == 0 ? 0 : 5000;
                }
                
                if(!env('IS_DEV', false)){
                    $Ghl = new GhlController();
                    $array_ghl = array(
                        'email' => USER_EMAIL,
                        'name' => USER_NAME,
                        'lname' => USER_LNAME,
                        'phone' => USER_PHONE,
                        'costo' => 0,
                        'column' => 'Purchased advanced (no subscription)',
                    );
                    $contactId = $Ghl->updateOpportunityTags($array_ghl);
                    $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Purchased advanced');
                    $this->set('tag', $tag);
                }
                
            }else if($course == 'ADVANCED TECHNIQUES MEDICAL'){$this->log(__LINE__ . ' ' . json_encode('ADVANCED TECHNIQUES MEDICAL'));
                $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the advanced training purchase for $' . $total_amount / 100;
                $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the advanced training purchase for $' . $total_amount / 100, $Main);
                $Main->send_email_after_register($assignedRep['User']['email'],'Training purchase',$msg);

                $this->email_to_jenna('Level 3', USER_NAME . ' ' . USER_LNAME);

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
                if($representative->team == 'INSIDE'){ // Si el representante es INSIDE entonces pagamos con normalidad la comision y le generamos senior otra comision de $50
                    if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission = $amount_comission == 0 ? 0 : 2500;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.rank' => 'SENIOR'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay) && !empty($senior_rep)){
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
                            $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission, $senior_rep);
                        }
                    } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                        $amount_comission_senior = $amount_comission == 0 ? 0 : 2500;
                        $senior_rep = $this->DataSalesRepresentative->find()->select(['DataSalesRepresentative.user_id','User.id','User.email'])->join([
                            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                        ])->where(['DataSalesRepresentative.deleted' => 0, 'DataSalesRepresentative.rank' => 'SENIOR', 'DataSalesRepresentative.team' => 'OUTSIDE'])->first(); // Buscamos al representante SENIOR ya que solo habra uno en la tabla 

                        if(!empty($pay) && !empty($senior_rep)){
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
                            $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission_senior, $senior_rep);
                        }
                    }
                } else if($representative->team == 'OUTSIDE'){// Si el representante es OUTSIDE entonces $25 de comision y nada al senior
                    $amount_comission = $amount_comission == 0 ? 0 : 5000;
                }
            }else{
                $value_discount = $this->getParams('discount_amount', 0);
                if ($value_discount <= 30000){ // 795 - 300 = 495 // 795 - 201 = 594
                    // $amount_comission = $this->half_comission;
                    $amount_comission = $this->full_comission;
                } else if($value_discount >= 30100){ // 795 - 300 = 494
                    $amount_comission = 0;
                }

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
                    $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], $type_string);
                }
                
                $description_comission = 'SALES TEAM OTHER COURSE';
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

                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                $this->DataSalesRepresentativePayments->save($c_entity_comission);
                $service = 'Training';
                $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission, $assignedRep);
                //Assign inside sales rep
                if($course == 'BASIC COURSE') $this->assignRepInside();
            }
            }
        }

        #endregion

        
        $this->saveAdvancedReceipt(USER_EMAIL);

        $this->SysUsers->save($userEntity);
        $this->success();
        

    }

    private function saveAdvancedReceipt($str_email) {
        $Main = new MainController();

        $type = 'Invoice';
        $filename = $Main->rcpt_purchase(true ,USER_UID ,true , true);
        if(empty($filename)){
            return;
        }
        
        $subject = 'MySpaLive '.$type;
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $str_email,
            'subject' => $subject,
            'html'    => "You have received a {$type} from MySpaLive.",
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '.pdf'),
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
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);

        unlink($filename);
    }

    public function send_email_team_member_courses($injector_id, $service, $training, $amount, $salesRep) {
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $injector_id])->first();
        $full_name = $ent_user->name . ' ' . $ent_user->lname;
        $training = ucfirst($training);
        $date = date('m/d/Y');
        $amount = '$' . number_format($amount / 100,2);
        

        $subject = 'MySpaLive has paid you a sales commission';
        $html = '<!DOCTYPE html>
        <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>MySpaLive Receipt</title>
                <style>
                    body {
                        font-family: Arial, sans-serif;
                        margin: 20px;
                    }
                    .receipt {
                        max-width: 400px;
                        margin: 0 auto;
                        border: 1px solid #ccc;
                        padding: 20px;
                        border-radius: 10px;
                    }
                    .header {
                        text-align: center;
                        font-size: 24px;
                        margin-bottom: 20px;
                    }
                    .details {
                        font-size: 16px;
                        margin-bottom: 10px;
                    }
                </style>
            </head>
            <body>
                <div class="receipt">
                    <div class="details">
                        <p><strong>Injector:</strong> '.$full_name.'</p>
                        <p><strong>Service:</strong> '.$service.'</p>
                        <p><strong>Training:</strong> '.$training.'</p>
                        <p><strong>Date:</strong> '.$date.'</p>
                        <p><strong>Amount:</strong> '.$amount.'</p>
                    </div>
                </div>
            </body>
        </html>';

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $salesRep['User']['email'],
            'subject' => $subject,
            'html'    => $html,
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
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);
    }

    public function assignRepInside() {

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


        $this->loadModel('SpaLiveV1.DataAssignedJobs');
        $array_save = array(
            'user_id' => USER_ID,
            'representative_id' => 0,//$findRep->user_id,
            'cat_id' => 0, //$findRep->id,
            'date_assign' => date('Y-m-d H:i:s', strtotime('+ 24 hours')),
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
        );

        $entity = $this->DataAssignedJobs->newEntity($array_save);
        if(!$entity->hasErrors()){
            $this->DataAssignedJobs->save($entity);
        }

    }

    private function notificateSMS($user_id,$body,$Main) {
        $users_array = array( $user_id );
       $Main->notify_devices($body,$users_array,false,false, true, array(), '', array(), true, true);
    }

    private function formatPhoneNumber($str_phone) {
        //(469) 891 9026
        if (strlen($str_phone) != 10) return $str_phone;
        $restul = '(' . $str_phone[0] . $str_phone[1] . $str_phone[2] . ')' . ' ' . $str_phone[3] .  $str_phone[4] . $str_phone[5] . ' ' . $str_phone[6] .  $str_phone[7] .  $str_phone[8] .  $str_phone[9];
        return $str_phone;
    }

    private function getRequestHeaders() {
        $headers = array();
        foreach($_SERVER as $key => $value) {
            if (substr($key, 0, 5) <> 'HTTP_') {
                continue;
            }
            $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$header] = $value;
        }
        return $headers;
    }

    private function email_to_jenna($level, $name){
        $is_dev = env('IS_DEV', false);

        $subject = 'An Injector purchased a course through ZeroLoanPro';
        $html = '<!DOCTYPE html>
        <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body>
                <div>
                    <p><strong>Course Type: </strong> ' . $level . '</p>
                    <p><strong>Injector Name: </strong> ' . $name . '</p>
                    <p><strong>Date of Purchase: </strong> ' . date('m-d-Y') . '</p>
                    <p><strong>To find out if the course is pending booking or any other status, go to the "Injector Opportunities" tab on the MySpaLive administration panel and search by this injector name.</strong></p>
                </div>
            </body>
        </html>';

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'      => $is_dev ? 'carlos@advantedigital.com' : 'jennaleighbichler@gmail.com',
            'subject' => $subject,
            'html'    => $html,
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
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Content-Type:multipart/form-data"]);

        $result = curl_exec($curl);
        curl_close($curl);
    }

    private function generate_pays_subs($msl, $md, $pay){
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.SysUsers');

        $user = $this->SysUsers->find()->where(['id' => $md->user_id])->first();
        // Pay MSL
        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => $user->id,
            'subscription_id' => $msl->id,
            'total' => 0,
            'payment_id' => $pay,
            'charge_id' => $pay,
            'receipt_id' => $pay,
            'error' => '',
            'status' => 'DONE',
            'notes' => '',
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0,
            'payment_type' => 'FULL',
            'payment_description' => 'SUBSCRIPTIONMSL',
            'main_service' => 'NEUROTOXINS',
            'addons_services' => '',
            'payment_details' => json_encode(array('NEUROTOXINS' => 0)),
            'state' => $user->state,
            'md_id' => 0,
        );

        $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataSubscriptionPayments->save($c_entity);
        }
        // Pay MD
        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => $user->id,
            'subscription_id' => $md->id,
            'total' => 0,
            'payment_id' => $pay,
            'charge_id' => $pay,
            'receipt_id' => $pay,
            'error' => '',
            'status' => 'DONE',
            'notes' => '',
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0,
            'payment_type' => 'FULL',
            'payment_description' => 'SUBSCRIPTIONMD',
            'main_service' => 'NEUROTOXINS',
            'addons_services' => '',
            'payment_details' => json_encode(array('NEUROTOXINS' => 0)),
            'state' => $user->state,
            'md_id' => $user->md_id,
        );

        $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataSubscriptionPayments->save($c_entity);
        }

        // Purchase Tox Party
        $total_tox_party = 0;
        $purchase_uid = Text::uuid();
        $array_save = array(
            'uid' => $purchase_uid,
            'user_id' => $user->id,
            'status' => 'NEW',
            'name' => $user->name . ' ' . $user->lname,
            'address' => '',
            'suite' => '',
            'city' => '',
            'state' => '',
            'zip' => 0,
            'tracking' => '',
            'delivery_company' => '',
            'created' => date('Y-m-d H:i:s'),
            'shipping_date' => date('Y-m-d'),
            'shipping_cost' => 0,
            'amount' => $total_tox_party,
        );

        $this->loadModel('SpaLiveV1.DataPurchases');
        $c_entity = $this->DataPurchases->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPurchases->save($c_entity)) {
                $this->loadModel('SpaLiveV1.CatProducts');
                $product_id = 48;
                $ent_cprod = $this->CatProducts->find()->where(['CatProducts.id' => $product_id])->first();
                
                $a_sav = array(
                    'product_id' => $ent_cprod->id,
                    'price' => $ent_cprod->unit_price,
                    'qty' => 1,
                    'product_detail_question' => $ent_cprod->details_text,
                    'product_detail' => '',
                    'purchase_id' => $c_entity->id,
                );

                $this->loadModel('SpaLiveV1.DataPurchasesDetail');
                $csave_entity = $this->DataPurchasesDetail->newEntity($a_sav);
                if(!$csave_entity->hasErrors()) {
                    $this->DataPurchasesDetail->save($csave_entity);
                }
            }
        }

        // Pay of purchase
        $Pay = new PaymentsController();
        $Pay->createPaymentRegister('PURCHASE', $user->id, 0, $purchase_uid, $pay, $pay, $pay, $total_tox_party, $total_tox_party);

        $this->DataPurchases->updateAll(
            [
                'payment' => $pay,
                'payment_intent' => $pay,
                'receipt_url' => $pay,
            ],
            ['uid' => $purchase_uid]
        );

        $this->SysUsers->updateAll(
            ['steps' => 'SHIPPINGADDRESS'],
            ['id' => $user->id]
        );
    }

    function cp_record_course_ot(){
        if(!$this->validate_token()) return;

        $training_id = get('training_id', 0);
        $_deferred = get('deferred', 0);
        $total = 0;
        $subtotal = 0;
        $promo_code = '';
        $service_uid = "";
        $type_promo = '';

        $this->loadModel('SpaLiveV1.CatCoursesType');

        $training = $this->CatCoursesType->find()
            ->select([
                'course_id' => 'CatCoursesType.id',
                'name_key' => 'CatCoursesType.name_key',
                'price' => 'CatCoursesType.price',
                'image' => 'CatCoursesType.image',
                'course_name' => 'CatCoursesType.title',
                'description' => 'CatCoursesType.description',
                'offer_id' => 'CatCoursesType.offer_id',
                'deferred_offer_id' => 'CatCoursesType.deferred_offer_id',
            ])
            ->where(['CatCoursesType.id' => $training_id])
        ->first();

        if(empty($training)){
            $this->message('Training not found.');
            return;
        }

        $total = $training->price;
        $subtotal = $total;
        $course = $training->name_key;
        $course_name = $training->course_name;
        $offer_id = $training->offer_id;
        
        if ($_deferred == 1) {
            $offer_id = $training->deferred_offer_id;
        }

        if(empty($offer_id)){
            $this->message('This training does not have an offer.');
            return;
        }

        $this->set('code_valid', false);
        $Payments = new \SpaLiveV1\Controller\PaymentsController();

        $uid = Text::uuid();
        $array_save = [
            'id_from'           => USER_ID,
            'id_to'             => 0,
            'uid'               => $uid,
            'intent'            => $uid,
            'service_uid'       => $service_uid,
            'type'              => $course,
            'promo_discount'    => 0,
            'promo_code'        => $promo_code,
            'subtotal'          => 10000,//$total,
            'total'             => 10000,//$total,
            'prod'              => 1,
            'is_visible'        => $this->is_dev ? 1 : 0,
            'payment_platform'  => 'partially',
            'created'           => date('Y-m-d H:i:s'),
            'createdby'         => defined('USER_ID') ? USER_ID : 0,
            'state'          => defined('USER_STATE') ? USER_STATE : 0,
        ];        

        $this->loadModel('SpaLiveV1.DataPayment');
        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPayment->save($c_entity)) {
                $token = get('token', '');
                $url = env('URL_API', '');

                $metadata = [
                    'offer' => $offer_id,
                    'amount' => ($total / 100),
                    'meta[description]' => $course_name,
                    'meta[payment_uid]' => $uid, 
                    'meta[totalamount]' => $total,
                    'meta[training_id]' => $training_id,
                    'referral_source' => 'shared_link',
                ];

                if($_deferred == 1){
                    $metadata['meta[deferred]'] = 1;
                }

                $extra_string = '';
                foreach($metadata as $key => $value){
                    $concat_char = empty($extra_string) ? '?' : '&';

                    $extra_string .= $concat_char . $key . '=' . $value;
                }

                $checkout_url = $this->checkouturl . $extra_string;
                //https://partial.ly/checkout?offer=b4d0e227-896b-408b-8cbe-fd9a7b85edab&amount=700&meta[description]=pago&meta[user_id]=2424&referral_source=shared_link
            
                $this->set('uid', $uid);
                $this->set('checkout_url', $checkout_url);
                $this->success();
            }
        }
    }

    private function sendFailedSMSToJenna($payment, $error_message) {
        $default_rep_id = 6101; // Jenna's user ID
        $Main = new MainController();
        
        // Get course name from payment type or description
        $course_name = !empty($payment->type) ? $payment->type : (!empty($payment->description) ? $payment->description : 'Unknown Course');
        
        // Get injector's name and phone
        $injector_name = '';
        $injector_phone = '';
        
        if (!empty($payment['User'])) {
            $injector_name = trim(($payment['User']['name'] ?? '') . ' ' . ($payment['User']['lname'] ?? ''));
            $injector_phone = $payment['User']['phone'] ?? '';
            
            // Format phone number if available
            if (!empty($injector_phone)) {
                $injector_phone = $this->formatPhoneNumber($injector_phone);
            }
        }
        
        // Handle scheduled_date - it can be a DateTime object or a string
        if (is_object($payment->scheduled_date)) {
            $payment_date = $payment->scheduled_date->format('F d, Y');
        } else {
            $payment_date = date('F d, Y', strtotime($payment->scheduled_date));
        }
        
        // Build SMS message
        $sms_message = "Payment FAILED for {$course_name} scheduled on {$payment_date}, set by {$injector_name}";
        if (!empty($injector_phone)) {
            $sms_message .= " - {$injector_phone}";
        }
        $sms_message .= ". Error: {$error_message}";
        
        // Send SMS to Jenna
        try {
            $this->notificateSMS($default_rep_id, $sms_message, $Main);
        } catch (\Exception $e) {
            // Log error but don't break the flow
            $this->log(__LINE__ . ' Error sending SMS to Jenna: ' . $e->getMessage());
        }
    }

}