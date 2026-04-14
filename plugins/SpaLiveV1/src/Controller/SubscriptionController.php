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
use Cake\I18n\FrozenTime;
use SpaLiveV1\Controller\Data\ServicesHelper;
use DateTime;
use Twilio\TwiML\Voice\Start;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use SpaLiveV1\Controller\LoginController;
class SubscriptionController extends AppPluginController {

	private $total_subscriptionmsl = 3995;
	private $total_subscriptionmd = 17900;
    private $total_subscriptionmdBoth = 29999;
    private $total_subscriptionmslBoth = 2995;
    public $total_subscription_ot_main_msl = 3995;
    public $total_subscription_ot_main_md = 17900;
    public $total_subscription_ot_addon_msl = 2000;
    public $total_subscription_ot_addon_md = 8500;
    public $prices_msl = [3995, 5995, 6995, 7495, 7995, 7995, 7995, 7995, 7995, 7995, 7995, 7995];
    public $upgrades_msl = [3995, 2000, 1000, 500, 500, 0, 0, 0, 0, 0, 0, 0];

    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";

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
		$this->loadModel('SpaLiveV1.AppToken');
		$this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');
		$this->loadModel('SpaLiveV1.DataPayment');
		$this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatStates');

        $this->URL_API = env('URL_API', 'https://api.spalivemd.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.spalivemd.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.spalivemd.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.spalivemd.com/');
        $this->URL_SITE = env('URL_SITE', 'https://blog.myspalive.com/');

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
    }

    public function validateCode($code,$subtotal,$category) {

        $this->loadModel('SpaLiveV1.DataPromoCodes');

        $_where = ['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.code' => strtoupper($code)];
        $_where['OR'] = [['DataPromoCodes.category' => "ALL"], ['DataPromoCodes.category' => $category]];
        $ent_codes = $this->DataPromoCodes->find()->where($_where)->first();

        if (!empty($ent_codes)) {

            if ($ent_codes->category != 'ALL' && $ent_codes->category != $category) {
                $this->set('code_valid', false);
                return $subtotal;
            }

            $n_tot = $ent_codes->used + 1;
            $ent_codes->used = $n_tot;
            $this->DataPromoCodes->save($ent_codes);

            $this->set('code_valid', true);
            $this->set('discount_type', $ent_codes->type);
            if ($ent_codes->type == 'PERCENTAGE') {
                $this->set('discount', $ent_codes->discount);
                $this->set('discount_amount', $subtotal * ($ent_codes->discount / 100) );
                $this->set('discount_text', $ent_codes->discount . '% discount has been applied.');
                $total = $subtotal * (100 - $ent_codes->discount) / 100;
                if ($total < 100) $total = 100;
                return round($total);
            } else if ($ent_codes->type == 'AMOUNT') {
                $this->set('discount', round( ($ent_codes->discount/$subtotal) * 100));
                $this->set('discount_amount', $ent_codes->discount);
                $this->set('discount_text', '$' . ($ent_codes->discount / 100) . ' discount has been applied.');

                $total = $subtotal - $ent_codes->discount;
                if ($total < 100) $total = 100;
                return $total;
            }

        }

        $this->set('code_valid', false);
        return $subtotal;
    }

	public function setup_intent() {

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

        $subscription_type = get('type','');
        if (empty($subscription_type)) return;

        $agreement_id = get('agreement_id',0);
        if ($subscription_type > 0) return;

        $this->loadModel('SpaLiveV1.DataSubscriptions');

        /*
        $ent_subscription = $this->DataSubscriptions->find()->where([
            'DataSubscriptions.subscription_type' => $subscription_type,
            'DataSubscriptions.user_id' => USER_ID,
            'DataSubscriptions.status' => 'ACTIVE',
            'DataSubscriptions.deleted' => 0
        ])->first();

        if(!empty($ent_subscription)){
            $this->message('You already have an active subscription.');
            return;
        }*/

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

        $subscription_total = $this->total_subscriptionmsl;
        if ($subscription_type == "SUBSCRIPTIONMD") $subscription_total = $this->total_subscriptionmd;
        $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,$subscription_type);

		$intent = \Stripe\SetupIntent::create([
          'customer' => $customer['id'],
          'metadata' => ['type' => $subscription_type, 'email' => $stripe_user_email,'uid' => USER_UID, 'total' => $total_amount, 'subtotal' => $subscription_total, 'promo_code' => get('promo_code',''), 'agreement_id' => $agreement_id],
        ]);


        $client_secret = $intent->client_secret;
        // $promo_text= ($subscription_type == "SUBSCRIPTIONMD") ? "" : "Use our promo code 50OFFSPA that is available only for this month of September.";
        $promo_text = '';

        $this->set('secret', $client_secret);
        $this->set('total', $total_amount);
        $this->set('promo_text', $promo_text);

        $this->success();
	}

    public function setup_intent_check(){

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
        $stripe_user_name = get('sign','');
        $stripe_user_email = get('email', '');
        $userType = str_replace('+', '-', get('userType','injector'));
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

        $uid = Text::uuid();
        $this->loadModel('SpaLiveV1.DataAgreement');

        $array_save = array(
            'user_id' => $_userid,
            'uid' => $uid,
            'sign' => $str_sign,
            'agreement_uid' => $str_uid,
            'file_id' => $_file_id,
            'content' => $ent_agreement->content,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 1,
        );

        $entity = $this->DataAgreement->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->DataAgreement->save($entity)){
                \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));

                $subscription_type = get('type','');
                if (empty($subscription_type)) return;
                $agreement_id = $entity->id;
                /*$this->loadModel('SpaLiveV1.DataSubscriptions');

                $ent_subscription = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.subscription_type' => $subscription_type,
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.status' => 'ACTIVE',
                    'DataSubscriptions.deleted' => 0
                ])->first();

                if(!empty($ent_subscription)){
                    $this->message('You already have an active subscription.');
                    return;
                }*/

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

                $subscription_total = $this->total_subscriptionmsl;
                if ($subscription_type == "SUBSCRIPTIONMD") $subscription_total = $this->total_subscriptionmd;
                $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,$subscription_type);

                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'mode' => 'setup',
                    'customer' => $customer['id'],
                    'metadata' => ['type' => $subscription_type, 'email' => $stripe_user_email,'uid' => USER_UID, 'total' => $total_amount, 'subtotal' => $subscription_total, 'promo_code' => get('promo_code',''), 'agreement_uid' => $uid, 'agreement_id' => $agreement_id],
                    'success_url' => $this->URL_WEB . $userType . '/home',
                    'cancel_url' => $this->URL_WEB . $userType . '/home',
                ]);
                $this->set('url', $session->url);
                $this->set('id', $session->id);
                $this->set('total', $total_amount);
                $this->success();
            }
        }
    }

    public function get_subscription_data(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

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
        
        
        $decoration_subscription = $this->designated_subscription_type(USER_ID);

        $this->loadModel('SpaLiveV1.DataSubscriptions');    
        $check_msl = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.status IN' => array('ACTIVE'),
                'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL'.$decoration_subscription
            ])->first();
        
        $check_md = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.status IN' => array('ACTIVE'),
                'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD'. $decoration_subscription
            ])->first();

        if(!empty($check_msl) && !empty($check_md)){
            $this->set('has_both_subscriptions', true);
            $this->success();
            return;
        }

        $data = array();

        if(empty($check_msl)){
            // SEND DATA FOR MSL SUBSCRIPTION


        }else{
            // SEND DATA FOR MD SUBSCRIPTION


        }

        $this->set('data', $data);
        $this->set('has_both_subscriptions', false);
        $this->success();
    }

    /**
     * API: Manually create a subscription for any user.
     * Call with: action=Subscription____create_manual_subscription
     * Params: user_id (or uid), subscription_type; optional: amount, service, monthly, add_payment,
     * payment_notes, stripe_payment_id (or payment_id), charge_id, receipt_id (or receipt_url)
     */
    public function create_manual_subscription()
    {
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');

        $subscriptionTypes = [
            'SUBSCRIPTIONMSL', 'SUBSCRIPTIONMD', 'SUBSCRIPTIONMSLIVT', 'SUBSCRIPTIONMDIVT',
            'SUBSCRIPTIONMSLFILLERS', 'SUBSCRIPTIONMDFILLERS', 'TOXANDFILLERSUBSCRIPTIONPACKAGE', 'FILLERONLYSUBSCRIPTIONPACKAGE', 'CLASSROOMPACKAGENEUROTOXIN'
        ];
        $defaultAmounts = [
            'SUBSCRIPTIONMSL' => 9900,
            'SUBSCRIPTIONMD' => 17900,
            'SUBSCRIPTIONMSLIVT' => 3995,
            'SUBSCRIPTIONMDIVT' => 17900,
            'SUBSCRIPTIONMSLFILLERS' => 7500,
            'SUBSCRIPTIONMDFILLERS' => 7500,
            'TOXANDFILLERSUBSCRIPTIONPACKAGE' => 148000,
            'FILLERONLYSUBSCRIPTIONPACKAGE' => 95500,
            'CLASSROOMPACKAGENEUROTOXIN' => 79500
        ];

        $email = get('email', '');
        $name = get('name', '');
        $mname = get('mname', '');
        $lname = get('lname', '');
        $phone = get('phone', '');
        $state_id = get('state', 0);
        $city = get('city', '');
        $street = get('street', '');
        $suite = get('suite', '');
        $zip = get('zip', 0);
        $dob = get('dob', '2002-01-01');
        $user_type = get('user_type', 'injector'); // Default to injector
        $userInput = get('email', '');
        $subscriptionType = strtoupper(get('subscription_type', ''));
        $amountParam = get('amount', '');
        $serviceOption = get('service', '');
        $monthly = get('monthly', '1');
        $addPayment = get('add_payment', '1');
        $paymentNotes = get('payment_notes', '');
        if ($paymentNotes === false || $paymentNotes === '') {
            $paymentNotes = '';
        } else {
            $paymentNotes = (string)$paymentNotes;
        }
        $extPaymentId = get('stripe_payment_id', '') ?: get('payment_id', '');
        if ($extPaymentId === false) {
            $extPaymentId = '';
        } else {
            $extPaymentId = (string)$extPaymentId;
        }
        $extChargeId = get('charge_id', '');
        if ($extChargeId === false) {
            $extChargeId = '';
        } else {
            $extChargeId = (string)$extChargeId;
        }
        $extReceiptId = get('receipt_id', '') ?: get('receipt_url', '');
        if ($extReceiptId === false) {
            $extReceiptId = '';
        } else {
            $extReceiptId = (string)$extReceiptId;
        }

        if (empty($email)) {
            $this->message('Email is required.');
            $this->set('success', false);
            return;
        }

        if (empty($userInput)) {
            $this->message('email is required.');
            return;
        }

        if (empty($subscriptionType) || !in_array($subscriptionType, $subscriptionTypes, true)) {
            $this->message('subscription_type is required. Valid: ' . implode(', ', $subscriptionTypes));
            return;
        }

        if (!in_array((string)$monthly, ['1', '3', '12'], true)) {
            $this->message('monthly must be 1, 3, or 12.');
            return;
        }

        $user = null;
        $user = $this->SysUsers->find()
            ->where(['SysUsers.email LIKE' => strtolower(trim($email)), 'SysUsers.deleted' => 0])
            ->first();

        if (!$user) {
            $arr_dob = explode("-", $dob);
            $str_dob = "";
            
            if (count($arr_dob) == 3) {
                $str_dob = $arr_dob[0] . '-' . $arr_dob[1] . '-' . $arr_dob[2];
            } else {
                $str_dob = '2002-01-01';
            }

            // Generate short UID
            $shd = false;
            do {
                $num = substr(str_shuffle("0123456789"), 0, 4);
                $short_uid = $num . "" . strtoupper($this->generateRandomString(4));
                $existShort = $this->SysUsers->find()->where(['SysUsers.short_uid LIKE' => $short_uid])->first();
                if(empty($existShort))
                    $shd = true;
            } while (!$shd);

            $user_uid = Text::uuid();
            //$step = $state_id > 0 ? 'CODEVERIFICATION' : 'SELECTBASICCOURSE';
            // if($type_string == 'BASIC COURSE')
            // {
            //     $step = 'MATERIALS';
            // }
            // else
            // {
            //      $step = 'LICENCEOT';
            // }
            
            $step = '';

            $array_save = array(
                'uid' => $user_uid,
                'short_uid' => $short_uid,
                'name' => trim($name),
                'mname' => trim($mname),
                'lname' => trim($lname),
                'email' => trim(strtolower($email)),
                'phone' => $phone,
                'type' => $user_type,
                'state' => $state_id > 0 ? $state_id : 43, // Default state if not provided
                'city' => $city,
                'street' => $street,
                'suite' => $suite,
                'zip' => $zip,
                'dob' => $str_dob,
                'active' => 1,
                'login_status' => 'READY',
                'steps' => $step,
                'deleted' => 0,
                'createdby' => 0,
                'modifiedby' => 0,
                'photo_id' => 93, // Default photo
                'score' => 0,
                'enable_notifications' => 1,
                'last_status_change' => date('Y-m-d H:i:s'),
                'password' => hash_hmac('sha256', Text::uuid(), Security::getSalt()), // Random password
            );

            $userEntity = $this->SysUsers->newEntity($array_save);
            
            if (!$userEntity->hasErrors()) {
                $entUser = $this->SysUsers->save($userEntity);
                if ($entUser) {
                    $userId = $entUser->id;
                    $userState = $entUser->state ?? 0;
                    $userUId = $entUser->uid; 
                } else {
                    $this->message('Failed to create user.');
                    $this->set('success', false);
                    return;
                }
            } else {
                $this->message('User validation failed: ' . json_encode($userEntity->getErrors()));
                $this->set('success', false);
                return;
            }
        }
        else{
            $userId = $user->id;
            $userState = $user->state ?? 0;
            $userUId = $user->uid; 
        }        

        $mainService = 'NEUROTOXINS';
        if ($serviceOption) {
            $s = strtoupper(str_replace('_', ' ', $serviceOption));
            if ($s === 'IV THERAPY' || $s === 'IVTHERAPY') {
                $mainService = 'IV THERAPY';
            } elseif (in_array($s, ['NEUROTOXINS', 'FILLERS'], true)) {
                $mainService = $s;
            }
        } elseif (strpos($subscriptionType, 'FILLERS') !== false) {
            $mainService = 'FILLERS';
        } elseif (strpos($subscriptionType, 'IVT') !== false) {
            $mainService = 'IV THERAPY';
        }

        $amount = $amountParam !== '' && $amountParam !== false
            ? (int)$amountParam
            : ($defaultAmounts[$subscriptionType] ?? 17900);

        $paymentDetailsJson = json_encode([$mainService => $amount]);
        $now = date('Y-m-d H:i:s');

        $subEntity = $this->DataSubscriptions->newEntity([
            'uid' => $userUId,
            'event' => 'manual_subscription_api',
            'payload' => '',
            'user_id' => $userId,
            'request_id' => '',
            'data_object_id' => '',
            'customer_id' => '',
            'payment_method' => 'MANUAL',
            'subscription_type' => $subscriptionType,
            'promo_code' => '',
            'subtotal' => $amount,
            'total' => $amount,
            'status' => 'ACTIVE',
            'deleted' => 0,
            'created' => $now,
            'agreement_id' => 0,
            'comments' => '',
            'main_service' => $mainService,
            'addons_services' => '',
            'payment_details' => $paymentDetailsJson,
            'state' => $userState,
            'monthly' => (string)$monthly,
            'other_school' => 0,
        ]);

        if ($subEntity->hasErrors()) {
            $this->message('Validation error: ' . json_encode($subEntity->getErrors()));
            return;
        }

        $saved = $this->DataSubscriptions->save($subEntity);

        if (!$saved) {
            $this->message('Failed to save subscription.');
            return;
        }

        $this->set('subscription_id', $saved->id);

        if ($addPayment && $addPayment !== '0' && $addPayment !== false) {
            // Same as insert_subscription_by_values / save_subscription after Stripe: assign MD when missing
            if (stripos($subscriptionType, 'MD') !== false) {
                $this->SysUserAdmin->getAssignedDoctorInjector((int)$userId);
            }

            $userFresh = $this->SysUsers->get($userId);
            $mdIdPayment = (int)($userFresh->md_id ?? 0);

            // Mirrors save_subscription successful charge block (DataSubscriptionPayments row).
            // Optional params record external/Stripe or office payment references (check, wire ref, etc.).
            $payNotes = $paymentNotes;
            if ($payNotes === '' && ($extPaymentId !== '' || $extChargeId !== '' || $extReceiptId !== '')) {
                $payNotes = 'Manual API';
            }

            $payEntity = $this->DataSubscriptionPayments->newEntity([
                //'uid' => Text::uuid(),
                'uid' => $userUId,
                'user_id' => $userId,
                'subscription_id' => $saved->id,
                'total' => $amount,
                'payment_id' => $extPaymentId,
                'charge_id' => $extChargeId,
                'receipt_id' => $extReceiptId,
                'error' => '',
                'status' => 'DONE',
                'notes' => $payNotes,
                'created' => $now,
                'deleted' => 0,
                'payment_type' => 'FULL',
                'payment_description' => $subscriptionType,
                'main_service' => $mainService,
                'addons_services' => '',
                'payment_details' => $paymentDetailsJson,
                'state' => $userState,
                'md_id' => $mdIdPayment,
            ]);

            if ($payEntity->hasErrors()) {
                $this->message('Subscription saved but payment validation failed: ' . json_encode($payEntity->getErrors()));
                return;
            }

            $savedPayment = $this->DataSubscriptionPayments->save($payEntity);
            if (!$savedPayment) {
                $this->message('Subscription saved but payment row could not be saved.');
                return;
            }

            $this->set('payment_id', $savedPayment->id);
        }

        if (!empty($user->uid)) {
            shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . $user->uid . ' > /dev/null 2>&1 &');
        }

        if(!$user ){
            $user_id = $userId;
            $user_uid = $userUId;

            $key1 = Text::uuid();
            $key2 = md5(Text::uuid());

            // Store key1 and key2 in sys_intent_recover so WordPress recover.php can validate the reset link
            $this->loadModel('SpaLiveV1.SysIntentRecover');
            $array_save = array(
                'user_id' => $user_id,
                'key1' => $key1,
                'key2' => $key2,
                'active' => 1,
            );
            $c_entity = $this->SysIntentRecover->newEntity($array_save);
            if (!$c_entity->hasErrors()) {
                $this->SysIntentRecover->save($c_entity);
            }

            $emailUser = $this->SysUsers->find()
                ->where(['SysUsers.id' => $user_id])
                ->first();

            $resetLink = $this->URL_SITE . "recover.php?key1={$key1}&key2={$key2}";

            // Send email via Mailgun (CakePHP Mailer uses PHP mail() which often fails on servers)
            $mailgunKey = $this->getMailgunKey();

            if ($mailgunKey && $emailUser && !empty($emailUser->email)) {
                $emailBodyText = "Hello,\n\nClick the link below to create your password:\n\n" . $resetLink . "\n\nThank you.";
                $emailBodyHtml = $this->reset_password_email_template($resetLink);
                $data = [
                    'from' => 'MySpaLive <noreply@mg.myspalive.com>',
                    'to' => $emailUser->email,
                    'subject' => 'Create Password',
                    'text' => $emailBodyText,
                    'html' => $emailBodyHtml,
                ];
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
        }

        // Success response
        $this->set('success', true);
        $this->set('message', 'Subscription Created successfully.');

        $this->success();
    }
    
    public function save_subscription(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');

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

        $isDev = env('IS_DEV', false);

        $subscription_type = strtoupper(get('type',''));
        if (empty($subscription_type)){
            $this->message('Invalid type subscription.');
            return;
        }

        // Variable to save the main subscription
        $main_service = 'NEUROTOXINS';

        if(strpos($subscription_type, 'FILLERS') !== false){
            $main_service = 'FILLERS';
        }else if(strpos($subscription_type, 'IVT') !== false){
            $main_service = 'IV THERAPY';
        }
        $ent_subscriptions_md = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE','HOLD'), 'DataSubscriptions.subscription_type like' => '%MD%'])->all();
        $this->log(__LINE__ . ' ' . json_encode($ent_subscriptions_md));
        if (count($ent_subscriptions_md) == 0 ) {//no tiene subscripciones md
            if(strpos($subscription_type, 'MD') !== false && USER_STATE != 43){//el tipo de subscripcion es md, manda email a patientrelations@myspalive.com
                $this->send_email_after_sign_subscription(USER_ID, $main_service);
            }
        }

        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE','HOLD'), 'DataSubscriptions.subscription_type' => $subscription_type])->all();
        if (count($ent_subscriptions) > 0) {
            foreach ($ent_subscriptions as $key => $value) {
                if($value->status == 'HOLD'){
                    shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . USER_UID);

                    if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'SUBSCRIPTIONMSL'){
                        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL'])->first();
                        $ent_subscriptions2 = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD'])->first();
                    }else
                    if($subscription_type == 'SUBSCRIPTIONMDIVT' || $subscription_type == 'SUBSCRIPTIONMSLIVT'){
                        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSLIVT'])->first();
                        $ent_subscriptions2 = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMDIVT'])->first();
                    }else
                    if($subscription_type == 'SUBSCRIPTIONMD IVT' || $subscription_type == 'SUBSCRIPTIONMSL IVT' || $subscription_type == 'SUBSCRIPTIONMD+IVT' || $subscription_type == 'SUBSCRIPTIONMSL+IVT'){
                        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL+IVT'])->first();
                        $ent_subscriptions2 = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD+IVT'])->first();
                    }

                    if(!empty($ent_subscriptions) && !empty($ent_subscriptions2)){
                        $this->success();
                        return;
                    }else{
                        $this->message('Your payment method is not valid. Add a new payment method and try again.');
                        return;
                    }
                }
            }
            $this->set('update_agreement', false);
            $this->success();
            return;
        }

        $payment_method = get('payment_method','');

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

        $subscription_total = $this->total_subscriptionmsl;
        if($subscription_type == "SUBSCRIPTIONMSL+IVT" || $subscription_type == "SUBSCRIPTIONMSL IVT"){
            $subscription_total = $this->total_subscriptionmslBoth - $this->total_subscriptionmsl;
        }

        if ($subscription_type == "SUBSCRIPTIONMD"||$subscription_type == "SUBSCRIPTIONMDIVT"){
             $subscription_total = $this->total_subscriptionmd;
        }else if($subscription_type == "SUBSCRIPTIONMD+IVT" || $subscription_type == "SUBSCRIPTIONMD IVT"){
            $subscription_total = $this->total_subscriptionmdBoth - $this->total_subscriptionmd;
        }

        if($subscription_type == "SUBSCRIPTIONMSLFILLERS"){
            $subscription_total = $this->total_subscriptionmsl;
        }else if($subscription_type == "SUBSCRIPTIONMDFILLERS"){
            $subscription_total = $this->total_subscriptionmd;
        }

        $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,$subscription_type);

        if($subscription_type == "SUBSCRIPTIONMSLFILLERS" || $subscription_type == "SUBSCRIPTIONMDFILLERS"){
            $stripe_result = '';
            $error = '';
            
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => intval($total_amount),
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => $subscription_type,
                ]);
            } catch (\Stripe\Exception\ApiConnectionException $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';

            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
                $this->loadModel('SpaLiveV1.DataSubscriptions');
                $array_save = array(
                    'user_id' => USER_ID,
                    'uid' => Text::uuid(),
                    'event' => 'save_subscription',
                    'payload' => '',
                    'request_id' => '',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => $subscription_type,
                    'promo_code' =>  get('promo_code',''),
                    'subtotal' => $subscription_total,
                    'total' => $total_amount,
                    'status' => 'ACTIVE',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'agreement_id' => 0,
                    'comments' => '',
                    'main_service' => $main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($main_service => $total_amount)),
                    'state' => USER_STATE,
                );

                $c_entity = $this->DataSubscriptions->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $sub = $this->DataSubscriptions->save($c_entity);
                    $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                    $array_save = array(
                        'uid' => Text::uuid(),
                        'user_id' => USER_ID,
                        'subscription_id' => $sub->id,
                        'total' => $total_amount,
                        'payment_id' => $payment_id,
                        'charge_id' => $id_charge,
                        'receipt_id' => $receipt_url,
                        'error' => '',
                        'status' => 'DONE',
                        'notes' => '',
                        'created' => date('Y-m-d H:i:s'),
                        'deleted' => 0,
                        'payment_type' => 'FULL',
                        'payment_description' => $subscription_type,
                        'main_service' => $main_service,
                        'addons_services' => '',
                        'payment_details' => json_encode(array($main_service => $total_amount)),
                        'state' => USER_STATE,
                    );

                    $this->set('subscription_id', $sub->id);
                    $this->set('update_agreement', true);

                    $id_payment =  $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionPayments->save($c_entity);

                        #region send email to injector notify_devices
                        if ($subscription_type == 'SUBSCRIPTIONMDFILLERS') {
                             $this->log(__LINE__ . ' ' . json_encode('SUBSCRIPTIONMDFILLERS'));
                            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
                            $Main = new MainController();
                            $contains = array(
                                '[CP/InjName]' => trim($ent_user->name) . ' ' . trim($ent_user->lname),
                            );
                            switch ($main_service) {
                                case 'FILLERS':
                                    $message = 'AFTER_SUB_FILLERS';
                                        $Main->notify_devices($message,array(USER_ID),true,true,true,array(),'',$contains,true);
                                    
                                    break;
                            }

                            $this->loadModel('SpaLiveV1.DataAssignedToRegister');
                            $this->loadModel('SpaLiveV1.DataCourses');
                            $assignedRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
                                'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
                                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
                            ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.team' => 'INSIDE'])->first();
                            
                            if (empty($assignedRep)) {        
                                $assignedRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
                                    'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
                                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
                                ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.team' => 'OUTSIDE'])->first();
                            }
                            $this->log(__LINE__ . ' ' . json_encode($assignedRep));
                            if (!empty($assignedRep)) {
                                $school = $this->DataCourses->find()->where([
                                    'DataCourses.user_id' => USER_ID, 
                                    'DataCourses.deleted' => 0,
                                    'DataCourses.status'  => 'DONE',
                                ])->first();
    
                                if(!empty($school)){
                                    $Payments = new PaymentsController();
                                    $x = $Payments->pay_sales_rep_schools(USER_ID, $id_payment->id);$this->log(__LINE__ . ' ' . json_encode($x));
                                    $service = ucfirst(strtolower($main_service));$this->log(__LINE__ . ' ' . json_encode(array(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep)));
                                    $Payments->send_email_sales_team_member(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep);
                                    $Payments->email_injector_subscribe_os(USER_EMAIL);
                                    if(!$isDev){
                                        $user_state = '';
                                        $user_st = $this->CatStates->find()->where(['CatStates.id' => USER_STATE, 'CatStates.deleted' => 0])->first();
                                        if(!empty($ent_user)){
                                            $user_state = $user_st->name;
                                        }
                                        
                                        $phone_number = '+19729003944'; $this->log(__LINE__ . 'An injector from another school signed subscriptions: ' . $phone_number . ' ' . $user_state);           
                                        try {     
                                            $sid    = env('TWILIO_ACCOUNT_SID'); 
                                            $token  = env('TWILIO_AUTH_TOKEN'); 
                                            $twilio = new Client($sid, $token); 
                                                
                                            $twilio_message = $twilio->messages 
                                                ->create($phone_number, // to 
                                                    array(  
                                                        "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                                        "body" => 'An injector from another school signed subscriptions: ' . USER_NAME . ' ' . USER_LNAME . ' ' . $user_state . ' (' . USER_PHONE .')' . date('m-d-Y') 
                                                    ) 
                                            );                                                  
                        
                                        } catch (TwilioException $e) {
                                            $this->log(__LINE__ . " TwilioException ". $phone_number . " ".  json_encode($e->getCode()));
                                        }    
                                    }
                                }
                            }
                        }
                        #endregion
                    }
                }
            }else{
                $this->message($error);
                return;
            }            
        }else if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'SUBSCRIPTIONMSL'||$subscription_type == 'SUBSCRIPTIONMDIVT' || $subscription_type == 'SUBSCRIPTIONMSLIVT'){
            
            $ent_subscription = $this->DataSubscriptions->find()->where([
                'DataSubscriptions.subscription_type' => $subscription_type,
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status' => 'ACTIVE',
                'DataSubscriptions.deleted' => 0
            ])->first();

            if(!empty($ent_subscription)){
                // $this->message('You already have an active subscription.');
                $this->loadModel('SpaLiveV1.SysUsers');
                $settings = get('is_from_settings', false);
                if(!$settings){
                    $steps = "";

                    if($subscription_type == "SUBSCRIPTIONMSL"){
                        $steps = "MDSUBSCRIPTION";
                    }else 
                    if($subscription_type == "SUBSCRIPTIONMD"){
                        $steps = "W9";
                    }else
                    if($subscription_type == "SUBSCRIPTIONMSLIVT"){
                        $steps = "MDIVTSUBSCRIPTION";
                    }else 
                    if($subscription_type == "SUBSCRIPTIONMDIVT"){
                        $steps = "IVTHERAPYVIDEOWATCHED";
                    }else 
                    if($subscription_type == "SUBSCRIPTIONMSLFILLERS"){
                        $steps = "SUBSCRIPTIONMDFILLERS";
                    }else
                    if($subscription_type == "SUBSCRIPTIONMDFILLERS"){
                        $steps = "W9";
                    }

                    $this->SysUsers->updateAll(
                        ['steps' => $steps],
                        ['id' => USER_ID]
                    );
                }

                $this->set('update_agreement', false);
                $this->success();
                return;
            }

            $entity_sub = $this->DataSubscriptions->new_entity([
                'uid'		=> $this->DataSubscriptions->new_uid(),
                'event'		=> 'save_subscription',
                'payload'	=> '',
                'user_id'	=> USER_ID,
                'request_id'	=> '',
                'status' => 'ACTIVE',
                'data_object_id' => '',
                'customer_id' => $customer['id'],
                'payment_method' => $payment_method,
                'subscription_type' => $subscription_type,
                'total' =>$total_amount,
                'subtotal' => $subscription_total,
                'promo_code' => get('promo_code',''),
                'agreement_id' => get('agreement_id', 0),
                'main_service' => $main_service,
                'addons_services' => '',
                'payment_details' => json_encode(array($main_service => $total_amount)),
                'state' => USER_STATE,
            ]);

            $sub = $this->DataSubscriptions->save($entity_sub);

            $this->set('subscription_id', $sub->id);
            $this->set('update_agreement', true);

            if (stripos($subscription_type, 'MD') !== false || $subscription_type == 'SUBSCRIPTIONMSLIVT') {
                $this->loadModel('SpaLiveV1.SysUserAdmin');
                $this->SysUserAdmin->getAssignedDoctorInjector((int)USER_ID);

                if($subscription_type == 'SUBSCRIPTIONMD'){
                    if(!env('IS_DEV', false)){
                        $Ghl = new GhlController();
                        $array_ghl = array(
                            'email' => USER_EMAIL,
                            'name' => USER_NAME,
                            'lname' => USER_LNAME,
                            'phone' => USER_PHONE,
                            'costo' => 0,
                            'column' => 'Subscribed to basic neurotoxins',
                        );
                        $contactId = $Ghl->updateOpportunityTags($array_ghl);
                        $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'Subscribed to neurotoxins');
                    }
                }
                if($subscription_type == 'SUBSCRIPTIONMDIVT'){
                    $this->log(__LINE__ . ' ' . json_encode('SUBSCRIPTIONMDIVT'));
                    // add course pending to iv therapy
                    $Therapy = new  TherapyController();
                    $Therapy->request_therapist(USER_ID);
                    usleep(100000);
                    $Therapy->approved_ivtherapy(USER_ID);
                }                    

                #region send email to injector notify_devices
                $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
                $Main = new MainController();
                $contains = array(
                    '[CP/InjName]' => trim($ent_user->name) . ' ' . trim($ent_user->lname),
                );
                switch ($main_service) {
                    case 'IV THERAPY':
                        $message = 'AFTER_SUB_IV';
                        $Main->notify_devices($message,array(USER_ID),true,true,true,array(),'',$contains,true);
                        
                        break;
                }
                #endregion
                
            }

        }else
        if($subscription_type == 'SUBSCRIPTIONMD IVT'||$subscription_type == 'SUBSCRIPTIONMSL IVT'||$subscription_type == 'SUBSCRIPTIONMD+IVT'||$subscription_type == 'SUBSCRIPTIONMSL+IVT'){
            //actualizar la subscripción

            $where = ['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,
            'DataSubscriptions.status IN' => array('ACTIVE','HOLD')];

            $total_amount = 0;
            $subscription_total = 0;
            $s_type = "";

            if($subscription_type == 'SUBSCRIPTIONMD+IVT'||$subscription_type == 'SUBSCRIPTIONMD IVT'){
                $where['DataSubscriptions.subscription_type IN'] = array('SUBSCRIPTIONMD','SUBSCRIPTIONMDIVT');
                $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmdBoth,$subscription_type);
                $subscription_total = $this->total_subscriptionmdBoth;
                $s_type = "SUBSCRIPTIONMD+IVT";
            }else{
                $where['DataSubscriptions.subscription_type IN'] = array('SUBSCRIPTIONMSL','SUBSCRIPTIONMSLIVT');
                $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmslBoth,$subscription_type);
                $subscription_total = $this->total_subscriptionmslBoth;
                $s_type = "SUBSCRIPTIONMSL+IVT";
            }

            $ent_old_subscription = $this->DataSubscriptions->find()
                ->where($where)->first();
            
            if (!empty($ent_old_subscription)) {
                $ent_old_subscription->subscription_type = $s_type;
                
                $ent_old_subscription->subtotal = $subscription_total;
                $ent_old_subscription->total = $total_amount;

                $update_subscription = $this->DataSubscriptions->save($ent_old_subscription);

                if(!$update_subscription){
                    $this->message('Error in update subscription.');
                    return;
                }
            }
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $settings = get('is_from_settings', false);
        if(!$settings){

            $steps = "";

            if($subscription_type == "SUBSCRIPTIONMSL"){
                $steps = "MDSUBSCRIPTION";
            }else 
            if($subscription_type == "SUBSCRIPTIONMD"){
                $steps = "W9";
            }else
            if($subscription_type == "SUBSCRIPTIONMSLIVT"){
                $steps = "MDIVTSUBSCRIPTION";
            }else 
            if($subscription_type == "SUBSCRIPTIONMDIVT"){
                $steps = "IVTHERAPYVIDEOWATCHED";
            }else 
            if($subscription_type == "SUBSCRIPTIONMSL+IVT"||$subscription_type == "SUBSCRIPTIONMSL IVT"){
                $steps = "MD+IVTSUBSCRIPTION";
            }else 
            if($subscription_type == "SUBSCRIPTIONMD+IVT"||$subscription_type == "SUBSCRIPTIONMD IVT"){
                $steps = "IVTHERAPYVIDEOWATCHED";
            }else 
            if($subscription_type == "SUBSCRIPTIONMSLFILLERS"){
                $steps = "SUBSCRIPTIONMDFILLERS";
            }else
            if($subscription_type == "SUBSCRIPTIONMDFILLERS"){
                $steps = "W9";
            }

            $this->SysUsers->updateAll(
                ['steps' => $steps],
                ['id' => USER_ID]
            );
        }
       
        shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . USER_UID . ' > /dev/null 2>&1 &');

        $user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        
        if (!empty($user)){
            $array_data = array(
                'email' => $user['email'],
                'name' => $user['name'],
                'lname' => $user['lname'],
                'phone' => $user['phone'],
                'costo' => $total_amount / 100,
                'course' => 'Injectors With Subscriptions',
            );

            if(!env('IS_DEV', false))
            {   //print_r('seguimos --'); 
                $Ghl = new GhlController();
                $Ghl->updateOpportunity($array_data);
            }
        }

		$this->success();
    }

    public function save_subscription_new(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.SysUserAdmin');
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

        $isDev = env('IS_DEV', false);

        $subscription_type = strtoupper(get('type',''));
        $monthly_type = get('monthly_type',1);
        $other_school = get('is_from_school', 0);

        // Variable to save the main subscription
        $main_service = 'NEUROTOXINS';

        $payment_method = get('payment_method','');

        $ent_subscriptions_md = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('HOLD')])->all();

        if (count($ent_subscriptions_md) > 0 ) {
            $this->resubscription_monthly(USER_ID, $payment_method);
            return;
        }

        $ent_subscriptions_calcel = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('CANCELLED')])->all();

        if (count($ent_subscriptions_calcel) > 0 ) {
            $this->resubscription_monthly_cancel(USER_ID, $payment_method);
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

        $total_md = $this->validateCode(get('promo_code',''),$this->total_subscriptionmd,'SUBSCRIPTIONMD');

        $total_msl = $this->validateCode(get('promo_code',''),$this->total_subscriptionmsl,'SUBSCRIPTIONMD');

        if($monthly_type == 12){
            $total_md = 12900;
            $total_msl = 3995;
            $total_tox_party = 220000;
            $total_amount = $total_tox_party + $total_md + $total_msl;

            $stripe_result = '';
            $error = '';
            
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => intval($total_amount),
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => '12-Month Subscription',
                ]);
            } catch (\Stripe\Exception\ApiConnectionException $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';

            $md_id = $this->SysUserAdmin->getAssignedDoctorInjector(USER_ID);

            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
                $this->loadModel('SpaLiveV1.DataSubscriptions');
                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                $this->loadModel('SpaLiveV1.DataPurchases');
                // sub msl
                $array_save = array(
                    'user_id' => USER_ID,
                    'uid' => Text::uuid(),
                    'event' => 'save_subscription_new',
                    'payload' => '',
                    'request_id' => '',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => 'SUBSCRIPTIONMSL',
                    'promo_code' =>  get('promo_code',''),
                    'subtotal' => 3995,
                    'total' => $total_msl,
                    'status' => 'ACTIVE',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'agreement_id' => 0,
                    'comments' => '',
                    'main_service' => $main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($main_service => $total_msl)),
                    'state' => USER_STATE,
                    'monthly' => '12',
                    'other_school' => $other_school,
                );

                $c_entity = $this->DataSubscriptions->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $sub = $this->DataSubscriptions->save($c_entity);
                    $array_save = array(
                        'uid' => Text::uuid(),
                        'user_id' => USER_ID,
                        'subscription_id' => $sub->id,
                        'total' => $total_msl,
                        'payment_id' => $payment_id,
                        'charge_id' => $id_charge,
                        'receipt_id' => $receipt_url,
                        'error' => '',
                        'status' => 'DONE',
                        'notes' => '',
                        'created' => date('Y-m-d H:i:s'),
                        'deleted' => 0,
                        'payment_type' => 'FULL',
                        'payment_description' => 'SUBSCRIPTIONMSL',
                        'main_service' => $main_service,
                        'addons_services' => '',
                        'payment_details' => json_encode(array($main_service => $total_msl)),
                        'state' => USER_STATE,
                        'md_id' => 0,
                    );

                    $this->set('subscription_id', $sub->id);
                    $this->set('update_agreement', true);

                    $id_payment =  $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionPayments->save($c_entity);
                    }
                }

                // sub md
                $array_save = array(
                    'user_id' => USER_ID,
                    'uid' => Text::uuid(),
                    'event' => 'save_subscription_new',
                    'payload' => '',
                    'request_id' => '',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => 'SUBSCRIPTIONMD',
                    'promo_code' =>  get('promo_code',''),
                    'subtotal' => 17900,
                    'total' => $total_md,
                    'status' => 'ACTIVE',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'agreement_id' => 0,
                    'comments' => '',
                    'main_service' => $main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($main_service => $total_md)),
                    'state' => USER_STATE,
                    'monthly' => '12',
                    'other_school' => $other_school,
                );

                $c_entity = $this->DataSubscriptions->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $sub = $this->DataSubscriptions->save($c_entity);
                    $array_save = array(
                        'uid' => Text::uuid(),
                        'user_id' => USER_ID,
                        'subscription_id' => $sub->id,
                        'total' => $total_md,
                        'payment_id' => $payment_id,
                        'charge_id' => $id_charge,
                        'receipt_id' => $receipt_url,
                        'error' => '',
                        'status' => 'DONE',
                        'notes' => '',
                        'created' => date('Y-m-d H:i:s'),
                        'deleted' => 0,
                        'payment_type' => 'FULL',
                        'payment_description' => 'SUBSCRIPTIONMD',
                        'main_service' => $main_service,
                        'addons_services' => '',
                        'payment_details' => json_encode(array($main_service => $total_md)),
                        'state' => USER_STATE,
                        'md_id' => $md_id,
                    );

                    $this->set('subscription_id', $sub->id);
                    $this->set('update_agreement', true);

                    $id_payment =  $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionPayments->save($c_entity);
                    }
                }

                // Purchase Tox Party

                $purchase_uid = Text::uuid();
                $array_save = array(
                    'uid' => $purchase_uid,
                    'user_id' => USER_ID,
                    'status' => 'NEW',
                    'name' => USER_NAME . ' ' . USER_LNAME,
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
                        $this->set('purchase_uid', $purchase_uid);
                        $this->loadModel('SpaLiveV1.CatProducts');
                        $product_id = 59;
                        $ent_cprod = $this->CatProducts->find()->where(['CatProducts.id' => $product_id])->first();
                        
                        $a_sav = array(
                            'product_id' => $ent_cprod->id,
                            'price' => $total_tox_party,
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
                $Pay->createPaymentRegister('PURCHASE', USER_ID, 0, $purchase_uid, $paymen_intent, $id_charge, $receipt_url, $total_tox_party, $total_tox_party);

                $this->DataPurchases->updateAll(
                    [
                        'payment' => $id_charge,
                        'payment_intent' => $paymen_intent,
                        'receipt_url' => $receipt_url,
                    ],
                    ['uid' => $purchase_uid]
                );

                $this->SysUsers->updateAll(
                    ['steps' => 'SHIPPINGADDRESS'],
                    ['id' => USER_ID]
                );
                $this->success();
                return;

            }else{
                $this->message($error);
                return;
            }     
        } else if($monthly_type == 1){
            if($other_school == 1){ //other school
                $total_amount = $total_md + $total_msl;

                $stripe_result = '';
                $error = '';
                
                try {
                    $stripe_result = \Stripe\PaymentIntent::create([
                        'amount' => intval($total_amount),
                        'currency' => 'usd',
                        'customer' => $customer['id'],
                        'payment_method' => $payment_method,
                        'off_session' => true,
                        'confirm' => true,
                        'description' => '1-Month Subscription',
                    ]);
                } catch (\Stripe\Exception\ApiConnectionException $e) {
                    // Network communication with Stripe failed
                    $error = $e->getMessage();
                } catch(\Stripe\Exception\CardException $e) {
                    // Since it's a decline, \Stripe\Exception\CardException will be caught
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\RateLimitException $e) {
                    // Too many requests made to the API too quickly
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\AuthenticationException $e) {
                    // Authentication with Stripe's API failed
                    // (maybe you changed API keys recently)
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                }

                $receipt_url = '';
                $id_charge = '';
                $payment_id = '';

                $md_id = $this->SysUserAdmin->getAssignedDoctorInjector(USER_ID);

                if(isset($stripe_result->charges->data[0]->receipt_url)) {
                    $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                    $id_charge = $stripe_result->charges->data[0]->id;
                    $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                    $payment_id = $stripe_result->id;
                }

                if(empty($error) && $stripe_result->status == 'succeeded') {
                    $this->loadModel('SpaLiveV1.DataSubscriptions');
                    $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                    $Payments = new PaymentsController();
                    // sub msl
                    $array_save = array(
                        'user_id' => USER_ID,
                        'uid' => Text::uuid(),
                        'event' => 'save_subscription_new',
                        'payload' => '',
                        'request_id' => '',
                        'data_object_id' => '',
                        'customer_id' => $customer['id'],
                        'payment_method' => $payment_method,
                        'subscription_type' => 'SUBSCRIPTIONMSL',
                        'promo_code' =>  get('promo_code',''),
                        'subtotal' => 3995,
                        'total' => $total_msl,
                        'status' => 'ACTIVE',
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s'),
                        'agreement_id' => 0,
                        'comments' => '',
                        'main_service' => $main_service,
                        'addons_services' => '',
                        'payment_details' => json_encode(array($main_service => $total_msl)),
                        'state' => USER_STATE,
                        'other_school' => 1,
                    );

                    $c_entity = $this->DataSubscriptions->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        $sub = $this->DataSubscriptions->save($c_entity);
                        $array_save = array(
                            'uid' => Text::uuid(),
                            'user_id' => USER_ID,
                            'subscription_id' => $sub->id,
                            'total' => $total_msl,
                            'payment_id' => $payment_id,
                            'charge_id' => $id_charge,
                            'receipt_id' => $receipt_url,
                            'error' => '',
                            'status' => 'DONE',
                            'notes' => '',
                            'created' => date('Y-m-d H:i:s'),
                            'deleted' => 0,
                            'payment_type' => 'FULL',
                            'payment_description' => 'SUBSCRIPTIONMSL',
                            'main_service' => $main_service,
                            'addons_services' => '',
                            'payment_details' => json_encode(array($main_service => $total_msl)),
                            'state' => USER_STATE,
                            'md_id' => 0,
                        );

                        $this->set('subscription_id', $sub->id);
                        $this->set('update_agreement', true);

                        $id_payment =  $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                        if(!$c_entity->hasErrors()) {
                            $this->DataSubscriptionPayments->save($c_entity);
                        }
                    }

                    // sub md
                    $array_save = array(
                        'user_id' => USER_ID,
                        'uid' => Text::uuid(),
                        'event' => 'save_subscription_new',
                        'payload' => '',
                        'request_id' => '',
                        'data_object_id' => '',
                        'customer_id' => $customer['id'],
                        'payment_method' => $payment_method,
                        'subscription_type' => 'SUBSCRIPTIONMD',
                        'promo_code' =>  get('promo_code',''),
                        'subtotal' => 17900,
                        'total' => $total_md,
                        'status' => 'ACTIVE',
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s'),
                        'agreement_id' => 0,
                        'comments' => '',
                        'main_service' => $main_service,
                        'addons_services' => '',
                        'payment_details' => json_encode(array($main_service => $total_md)),
                        'state' => USER_STATE,
                        'other_school' => 1,
                    );

                    $c_entity = $this->DataSubscriptions->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        $sub = $this->DataSubscriptions->save($c_entity);
                        $array_save = array(
                            'uid' => Text::uuid(),
                            'user_id' => USER_ID,
                            'subscription_id' => $sub->id,
                            'total' => $total_md,
                            'payment_id' => $payment_id,
                            'charge_id' => $id_charge,
                            'receipt_id' => $receipt_url,
                            'error' => '',
                            'status' => 'DONE',
                            'notes' => '',
                            'created' => date('Y-m-d H:i:s'),
                            'deleted' => 0,
                            'payment_type' => 'FULL',
                            'payment_description' => 'SUBSCRIPTIONMD',
                            'main_service' => $main_service,
                            'addons_services' => '',
                            'payment_details' => json_encode(array($main_service => $total_md)),
                            'state' => USER_STATE,
                            'md_id' => $md_id,
                        );

                        $this->set('subscription_id', $sub->id);
                        $this->set('update_agreement', true);

                        $id_payment =  $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                        if(!$c_entity->hasErrors()) {
                            $this->DataSubscriptionPayments->save($c_entity);
                        }

                        $Payments->pay_sales_rep_schools(USER_ID, $id_payment->id);
                    }

                    $this->SysUsers->updateAll(
                        ['steps' => 'SCHOOLVIDEOWATCHED'],
                        ['id' => USER_ID]
                    );

                    $Payments->email_injector_subscribe_os(USER_EMAIL);
                    $this->success();
                    return;
                }
            }else{ //spalive school

                $this->loadModel('SpaLiveV1.DataSubscriptions');
                // sub msl
                $array_save = array(
                    'user_id' => USER_ID,
                    'uid' => Text::uuid(),
                    'event' => 'save_subscription_new',
                    'payload' => '',
                    'request_id' => '',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => 'SUBSCRIPTIONMSL',
                    'promo_code' =>  get('promo_code',''),
                    'subtotal' => 3995,
                    'total' => $total_msl,
                    'status' => 'ACTIVE',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'agreement_id' => 0,
                    'comments' => '',
                    'main_service' => $main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($main_service => $total_msl)),
                    'state' => USER_STATE,
                );

                $c_entity = $this->DataSubscriptions->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $sub = $this->DataSubscriptions->save($c_entity);
                }

                // sub md
                $array_save = array(
                    'user_id' => USER_ID,
                    'uid' => Text::uuid(),
                    'event' => 'save_subscription_new',
                    'payload' => '',
                    'request_id' => '',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => 'SUBSCRIPTIONMD',
                    'promo_code' =>  get('promo_code',''),
                    'subtotal' => 17900,
                    'total' => $total_md,
                    'status' => 'ACTIVE',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'agreement_id' => 0,
                    'comments' => '',
                    'main_service' => $main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($main_service => $total_md)),
                    'state' => USER_STATE,
                );

                $c_entity = $this->DataSubscriptions->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $sub = $this->DataSubscriptions->save($c_entity);

                    $this->set('subscription_id', $sub->id);
                    $this->set('update_agreement', true);
                }

                $this->SysUsers->updateAll(
                    ['steps' => 'W9'],
                    ['id' => USER_ID]
                );
                $this->success();
                return;
            }
        }
        $this->success();
        return;
    }

    public function save_subscription_ot(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        $this->loadModel('SpaLiveV1.SysUserAdmin');
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

        $data_training_id = get('data_training_id', 0);
        $course_id = get('data_course_id', 0);

      
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataCourses');

        $data_training = $this->DataTrainings->find()->select(['Cat.level'])
        ->join([
            'Cat' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Cat.id = DataTrainings.training_id']
        ])
        ->where(['DataTrainings.id' => $data_training_id])
        ->first();

        $main_training_level = "";
        if (!empty($data_training)) {
            $main_training_level = $data_training['Cat']['level'];
        }

        $is_other_schools = false;
        $is_iv_therapy = false;
        if(empty($main_training_level)){
            $this->loadModel('SpaLiveV1.DataCourses');
             $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type','SysTreatmentOT.name_key'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                'SchoolOption' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'SchoolOption.id = CatCourses.school_option_id'],
                'SysTreatmentOT' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'SysTreatmentOT.id = SchoolOption.sys_treatment_ot_id'],
            ])->where(['DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE','DataCourses.id' => $course_id])->first();
            if (!empty($user_course_basic)) {
                $is_other_schools = true;
                if (!empty($user_course_basic['CatCourses']['type']) && $user_course_basic['CatCourses']['type'] != 'OTHER TREATMENTS') {
                    $main_training_level = $user_course_basic['CatCourses']['type'];
                } else if (!empty($user_course_basic['SysTreatmentOT']['name_key'])) {
                    $main_training_level = $user_course_basic['SysTreatmentOT']['name_key'];
                }
            }
        }

        $ent_subscription_hold_check = [];

        if(empty($main_training_level)){
            $this->message('Invalid training.');
            return;
        }

        $levels = [
            'LEVEL 1',
            'LEVEL 3 FILLERS',
            'BOTH NEUROTOXINS',
            'NEUROTOXINS BASIC',
            'FILLERS',
            'LEVEL IV',
            'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE',
            'MYSPALIVES_HYBRID_TOX_FILLER_COURSE',
        ];

        if (in_array($main_training_level, $levels, true)) {

            $_total_md = 0;
            $_total_msl = 0;
            $total_msl_coverage = 0;
            $treatment_names = '';
            $main_service_msl = '';
            $main_service_md = '';
            $subs_msl = [];
            $subs_msl_addons = [];
            $subs_md = [];
            $subs_md_addons = [];
            
            $this->total_subscription_ot_main_md = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_main_md,'SUBSCRIPTIONMD'));
            $name_keys_to_check = [];
            
            switch($main_training_level){
                case 'LEVEL 1':
                case 'BOTH NEUROTOXINS':
                case 'NEUROTOXINS BASIC':
                    $name_keys_to_check[] = 'NEUROTOXINS';
                    $total_msl_coverage = 1;
                    $treatment_names = 'NEUROTOXINS';
                    $_total_md += $this->total_subscription_ot_main_md;
                    $subs_md['NEUROTOXINS'] = $this->total_subscription_ot_main_md;
                    $main_service_md = 'NEUROTOXINS';
                    break;
                case 'LEVEL 3 FILLERS':
                case 'FILLERS':
                    $name_keys_to_check[] = 'FILLERS';
                    $total_msl_coverage = 1;
                    $treatment_names = 'FILLERS';
                    $_total_md += $this->total_subscription_ot_main_md;
                    $subs_md['FILLERS'] = $this->total_subscription_ot_main_md;
                    $main_service_md = 'FILLERS';
                    break;
                case 'LEVEL IV':
                    $name_keys_to_check[] = 'IV THERAPY';
                    $total_msl_coverage = 1;
                    $treatment_names = 'IV THERAPY';
                    $_total_md += $this->total_subscription_ot_main_md;
                    $subs_md['IV THERAPY'] = $this->total_subscription_ot_main_md;
                    $main_service_md = 'IV THERAPY';
                    $is_iv_therapy = true;
                    break;
                case 'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE':
                case 'MYSPALIVES_HYBRID_TOX_FILLER_COURSE':
                    $name_keys_to_check[] = 'NEUROTOXINS';
                    $name_keys_to_check[] = 'FILLERS';
                    $total_msl_coverage = 2;
                    $treatment_names = 'NEUROTOXINS,FILLERS';
                    $_total_md += $this->total_subscription_ot_main_md;
                    $_total_md += $this->total_subscription_ot_addon_md;
                    $subs_md['NEUROTOXINS'] = $this->total_subscription_ot_main_md;
                    $subs_md['FILLERS'] = $this->total_subscription_ot_addon_md;
                    $main_service_md = 'NEUROTOXINS';
                    $subs_md_addons[] = 'FILLERS';
                    break;
                default:
                    $name_keys_to_check[] = $main_training_level;
                    $total_msl_coverage = 1;
                    $treatment_names = $main_training_level;
                    $_total_md += $this->total_subscription_ot_main_md;
                    $subs_md[$main_training_level] = $this->total_subscription_ot_main_md;
                    $main_service_md = $main_training_level;
                    break;
            }

            if($total_msl_coverage > 0){
                $_total_msl += $this->prices_msl[$total_msl_coverage - 1];
                $array_treatment_names = explode(',', $treatment_names);
                $main_service_msl = $array_treatment_names[0];
                if(count($array_treatment_names) > 1){
                    $subs_msl_addons = array_slice($array_treatment_names, 1);
                }
                $price_individual = $this->prices_msl[$total_msl_coverage - 1] / $total_msl_coverage;
                foreach($array_treatment_names as $treatment_name){
                    $subs_msl[$treatment_name] = $price_individual;
                }
            }


            // Verificar si el usuario tiene suscripciones canceladas para estos servicios
            $has_cancelled_subscription = false;
            if (!empty($name_keys_to_check)) {
                $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
                
                foreach($name_keys_to_check as $name_key) {
                    $cancelled_subscription = $this->DataSubscriptionCancelled->find()
                        ->join([
                            'DataSubscriptions' => [
                                'table' => 'data_subscriptions',
                                'type' => 'INNER',
                                'conditions' => 'DataSubscriptions.id = DataSubscriptionCancelled.subscription_id'
                            ]
                        ])
                        ->where([
                            'DataSubscriptions.user_id' => USER_ID,
                            'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $name_key . '%'
                        ])
                        ->first();
                    
                    if (!empty($cancelled_subscription)) {
                        $has_cancelled_subscription = true;
                        break;
                    }
                }
                
                // Verificar si el usuario tiene suscripciones en HOLD para estos servicios
                $ent_subscription_hold_check = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.deleted' => 0,
                    'DataSubscriptions.status IN' => array('HOLD')
                ])->all();
                
                if (!empty($ent_subscription_hold_check)) {
                    // Primero verificar si alguna suscripción HOLD tiene los servicios que va a firmar
                    foreach($ent_subscription_hold_check as $hold_sub) {
                        // Concatenar main_service y addons_services
                        $services_in_hold = $hold_sub->main_service;
                        if (!empty($hold_sub->addons_services)) {
                            $services_in_hold .= ',' . $hold_sub->addons_services;
                        }
                        
                        // Verificar si alguno de los name_keys está en los servicios HOLD
                        foreach($name_keys_to_check as $name_key) {
                            if (strpos($services_in_hold, $name_key) !== false) {
                                $has_cancelled_subscription = true;
                                break 2; // Salir de ambos foreach
                            }
                        }
                    }
                    
                }
            }




            $_total_msl = round($this->validateCode(get('promo_code',''),$_total_msl,'SUBSCRIPTIONMSL'));

            if(empty($subs_md) && empty($subs_msl)){
                $this->message('Treatment not found.');
                return;
            }
    
            $payment_method = get('payment_method','');

            // PAYMENT 

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
            
            
            $total_msl_total = $_total_msl;
            $total_md_total = $_total_md;

            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

            $addon_services = '';
            $addon_services = implode(',', $subs_msl_addons);


            if ($is_other_schools || $has_cancelled_subscription || $is_iv_therapy) {

                $error = '';
                try {
                    $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_msl_total + $total_md_total,
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => 'OT MySpaLive Subscription'
                    ]);
                } catch(Stripe_CardError $e) {
                    $error = $e->getMessage();
                } catch (Stripe_InvalidRequestError $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (Stripe_AuthenticationError $e) {
                    // Authentication with Stripe's API failed
                    $error = $e->getMessage();
                } catch (Stripe_ApiConnectionError $e) {
                    // Network communication with Stripe failed
                    $error = $e->getMessage();
                } catch (Stripe_Error $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                } catch (Exception $e) {
                    // Something else happened, completely unrelated to Stripe
                    $error = $e->getMessage();
                } catch(\Stripe\Exception\CardException $e) {
                    // Since it's a decline, \Stripe\Exception\CardException will be caught
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\RateLimitException $e) {
                    // Too many requests made to the API too quickly
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\AuthenticationException $e) {
                    // Authentication with Stripe's API failed
                    // (maybe you changed API keys recently)
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                }
    
                if(isset($stripe_result->charges->data[0]->receipt_url)) {
                    $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                    $id_charge = $stripe_result->charges->data[0]->id;
                    $payment_id = $stripe_result->id;
                }
    
                if(!empty($error)) {
                    $this->message('Your payment method is not valid. Add a new payment method and try again.');
                    return;
                }

            }

            $s_entity = $this->DataSubscriptions->newEntity([
                'uid'		=> $this->DataSubscriptions->new_uid(),
                'event'		=> 'save_subscription',
                'payload'	=> '',
                'user_id'	=> USER_ID,
                'request_id'	=> '',
                'status' => 'ACTIVE',
                'data_object_id' => '',
                'customer_id' => $customer['id'],
                'payment_method' => $payment_method,
                'subscription_type' => 'SUBSCRIPTIONMSL',
                'total' =>$_total_msl,
                'subtotal' => $total_msl_total,
                'promo_code' => get('promo_code',''),
                'agreement_id' => 0,
                'main_service' => $main_service_msl,
                'addons_services' => $addon_services,
                'payment_details' => json_encode($subs_msl),
                'state' => USER_STATE,
            ]);

            if(!$s_entity->hasErrors()) {
                $msl_id = $this->DataSubscriptions->save($s_entity);
                $this->set('msl_subscription_id', $msl_id->id);


                 // Si encontramos coincidencias, cancelar TODAS las suscripciones HOLD del usuario

                foreach($ent_subscription_hold_check as $hold_sub) {
                    $hold_sub->status = 'CANCELLED';
                    $this->DataSubscriptions->save($hold_sub);
                }
                

                if (count($subs_md) > 0) {

                    $addon_services = '';
                    $addon_services = implode(',', $subs_md_addons);

                    $m_entity = $this->DataSubscriptions->newEntity([
                        'uid'		=> $this->DataSubscriptions->new_uid(),
                        'event'		=> 'save_subscription',
                        'payload'	=> '',
                        'user_id'	=> USER_ID,
                        'request_id'	=> '',
                        'status' => 'ACTIVE',
                        'data_object_id' => '',
                        'customer_id' => $customer['id'],
                        'payment_method' => $payment_method,
                        'subscription_type' => 'SUBSCRIPTIONMD',
                        'total' =>$_total_md,
                        'subtotal' => $total_md_total,
                        'promo_code' => get('promo_code',''),
                        'agreement_id' => 0,
                        'main_service' => $main_service_md,
                        'addons_services' => $addon_services,
                        'payment_details' => json_encode($subs_md),
                        'state' => USER_STATE,
                    ]);

                    if(!$m_entity->hasErrors()) {

                        $md_id = $this->DataSubscriptions->save($m_entity);

                        $this->set('msl_subscription_id', $md_id->id);

                        $this->success();
                    }
                }
            }

            if ($is_other_schools || $has_cancelled_subscription || $is_iv_therapy) {

                if(empty($error) && $stripe_result->status == 'succeeded') {
                    $doctor_id = $this->SysUserAdmin->getAssignedDoctorInjector(USER_ID);

                    $c_entity = $this->DataSubscriptionPayments->newEntity([
                        'uid'   => Text::uuid(),
                        'subscription_id'  => $msl_id->id,
                        'user_id'  => USER_ID,
                        'total'  => $total_msl_total,
                        'payment_id'  => $payment_id,
                        'charge_id'  => $id_charge,
                        'receipt_id'  => $receipt_url,
                        'created'   => date('Y-m-d H:i:s'),
                        'error' => $error,
                        'status' => 'DONE',
                        'deleted' => 0,
                        'md_id' => $doctor_id,

                        'payment_type' => 'FULL',
                        'payment_description' => 'MSL Subscription',
                        'main_service' => $main_service_msl,
                        'addons_services' => $addon_services,
                        'payment_details' => json_encode($subs_msl),
                        'state' => USER_STATE,
                    ]);

                    $aux_payment = $this->DataSubscriptionPayments->save($c_entity);

                    $z_entity = $this->DataSubscriptionPayments->newEntity([
                        'uid'   => Text::uuid(),
                        'subscription_id'  => $md_id->id,
                        'user_id'  => USER_ID,
                        'total'  => $total_md_total,
                        'payment_id'  => $payment_id,
                        'charge_id'  => $id_charge,
                        'receipt_id'  => $receipt_url,
                        'created'   => date('Y-m-d H:i:s'),
                        'error' => $error,
                        'status' => 'DONE',
                        'deleted' => 0,
                        'md_id' => $doctor_id,

                        'payment_type' => 'FULL',
                        'payment_description' => 'MD Subscription',
                        'main_service' => $main_service_md,
                        'addons_services' => $addon_services,
                        'payment_details' => json_encode($subs_md),
                        'state' => USER_STATE,
                    ]);

                    $a_payment = $this->DataSubscriptionPayments->save($z_entity);

                    if($is_other_schools || $is_iv_therapy){
                        $Payments = new PaymentsController();
                        $x = $Payments->pay_sales_rep_schools(USER_ID, $a_payment->id);
                    }
                }
            }

            $user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
            if($user->steps != 'HOME'){
                $this->SysUsers->updateAll(['steps' => 'W9'], ['id' => USER_ID]); 
            }
            if ($is_other_schools) {
                $this->loadModel('SpaLiveV1.DataWN');
                $w9 = $this->DataWN->find()->where(['DataWN.user_id' => USER_ID])->first();
                if(empty($w9)){
                    $this->SysUsers->updateAll(
                        ['steps' => 'SCHOOLVIDEOWATCHED'], 
                        ['id' =>  USER_ID]
                    );
                }
            }

            $this->success();

        }else{

            if (!$is_other_schools) {

                $ent_data_trainings = $this->DataTrainings
                ->find()->select([
                    'name' => 'OtherTreatment.name_key',
                    'require_mdsub' => 'OtherTreatment.require_mdsub',
                    'msl'            => 'MAX(CatAgreementMSL.uid)',
                    'md'             => 'MAX(CatAgreementMD.uid)',
                    'msl_agreement'  => 'MAX(DataAgreementMSL.id)',
                    'md_agreement'   => 'MAX(DataAgreementMD.id)',
                    'total_coverage' => "(SELECT COUNT(Cover.id) FROM data_coverage_courses AS Cover WHERE Cover.course_type_id = CourseType.id)",
                    'treatment_names' => "(SELECT GROUP_CONCAT(DISTINCT OtherTreatment2.name_key SEPARATOR ',') FROM data_coverage_courses AS Coverage2 INNER JOIN sys_treatments_ot AS OtherTreatment2 ON OtherTreatment2.id = Coverage2.ot_id WHERE Coverage2.course_type_id = CourseType.id AND OtherTreatment2.deleted = 0)"
                ])
                ->join([
                    'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id'],
                    'CourseType' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CourseType.name_key = Training.level'],
                    'Coverage' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'Coverage.course_type_id = CourseType.id'],
                    'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = Coverage.ot_id AND OtherTreatment.deleted = 0'],
                    'CatAgreementMSL' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMSL.state_id = " . USER_STATE . "
                            AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                            AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                            AND CatAgreementMSL.deleted = 0
                            AND CatAgreementMSL.issue_type = 'MSL'"
                    ],
                    'CatAgreementMD' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMD.state_id = " . USER_STATE . "
                            AND (
                                    (CatAgreementMD.agreement_type = 'OTHER_TREATMENTS' AND CatAgreementMD.other_treatment_id = OtherTreatment.id AND CatAgreementMD.issue_type = 'MD')
                                    OR
                                    (CourseType.name_key IN ('MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE','MYSPALIVES_HYBRID_TOX_FILLER_COURSE') AND (CatAgreementMD.agreement_type = 'SUBSCRIPTIONMD' OR CatAgreementMD.agreement_type = 'SUBSCRIPTIONMDFILLERS'))
                                )
                            AND CatAgreementMD.deleted = 0"
                            
                    ],
                    'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.user_id = ' . USER_ID],
                    'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.user_id = ' . USER_ID],
                ])
                ->where([
                        'DataTrainings.id' => $data_training_id,
                        'OtherTreatment.deleted' => 0,
                        'OR' => [
                            'CatAgreementMSL.uid IS NOT' => null,
                            'CatAgreementMD.uid IS NOT'  => null,
                        ],
                    ])
                ->group(['OtherTreatment.id',
                    'OtherTreatment.name_key',
                    'OtherTreatment.require_mdsub',
                    'CourseType.id'])
                ->all();

            } else {

                $this->loadModel('SpaLiveV1.DataCourses');
            
                $ent_data_trainings = $this->DataCourses
                ->find()->select([
                    'name' => 'OtherTreatment.name_key',
                    'require_mdsub' => 'OtherTreatment.require_mdsub',
                    'msl'            => 'MAX(CatAgreementMSL.uid)',
                    'md'             => 'MAX(CatAgreementMD.uid)',
                    'msl_agreement'  => 'MAX(DataAgreementMSL.id)',
                    'md_agreement'   => 'MAX(DataAgreementMD.id)',
                    'total_coverage' => 1,
                    'treatment_names' => 'OtherTreatment.name_key',
                    // 'treatment_names' => "(SELECT GROUP_CONCAT(DISTINCT OtherTreatment2.name_key SEPARATOR ',') FROM data_coverage_courses AS Coverage2 INNER JOIN sys_treatments_ot AS OtherTreatment2 ON OtherTreatment2.id = Coverage2.ot_id WHERE Coverage2.course_type_id = CourseType.id AND OtherTreatment2.deleted = 0)"
                ])
                ->join([
                    'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                    'SchoolOption' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'SchoolOption.id = CatCourses.school_option_id'],
                    'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'OtherTreatment.id = SchoolOption.sys_treatment_ot_id'],
                    'CatAgreementMSL' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMSL.state_id = " . USER_STATE . "
                            AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                            AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                            AND CatAgreementMSL.deleted = 0
                            AND CatAgreementMSL.issue_type = 'MSL'"
                    ],
                    'CatAgreementMD' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMD.state_id = " . USER_STATE . "
                            AND (CatAgreementMD.agreement_type = 'OTHER_TREATMENTS' AND CatAgreementMD.other_treatment_id = OtherTreatment.id AND CatAgreementMD.issue_type = 'MD')
                            AND CatAgreementMD.deleted = 0"
                            
                    ],
                    'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.user_id = ' . USER_ID],
                    'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.user_id = ' . USER_ID],
                ])
                ->where([
                        'DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE','DataCourses.id' => $course_id,
                        'OR' => [
                            'CatAgreementMSL.uid IS NOT' => null,
                            'CatAgreementMD.uid IS NOT'  => null,
                        ],
                    ])
                ->group(['OtherTreatment.id',
                    'OtherTreatment.name_key',
                    'OtherTreatment.require_mdsub'
                    ])
                ->all();

            }

            // One row per other treatment (same as get_ot_subscription_info); avoids double MD/MSL charges.
            if (!empty($ent_data_trainings)) {
                $deduped_ot_rows = [];
                foreach ($ent_data_trainings as $row) {
                    $nk = $row->name ?? '';
                    if ($nk === '') {
                        continue;
                    }
                    if (!isset($deduped_ot_rows[$nk])) {
                        $deduped_ot_rows[$nk] = $row;
                    }
                }
                $ent_data_trainings = array_values($deduped_ot_rows);
            }

            $ent_subscription_msl_active = $this->DataSubscriptions->find()->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.status' => 'ACTIVE',
                'DataSubscriptions.subscription_type LIKE' => '%MSL%',
            ])->first();
            $previous_services = [];
            if (!empty($ent_subscription_msl_active)) {
                $ps = $ent_subscription_msl_active->main_service;
                if (!empty($ent_subscription_msl_active->addons_services)) {
                    $ps .= ',' . $ent_subscription_msl_active->addons_services;
                }
                $previous_services = array_values(array_filter(array_map('trim', explode(',', $ps)), function ($s) {
                    return $s !== '';
                }));
            }

            $_total_md = 0;
            $_total_msl = 0;
            $subs_md = [];
            $subs_msl = [];
            $subs_md_addons = [];
            $subs_msl_addons = [];
            $main_service_msl = '';
            $main_service_md = '';
            $total_msl_total = 0;
            $total_md_total = 0;
            $total_msl_coverage = 0;
            $treatment_names = '';

            $this->total_subscription_ot_main_msl = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_main_msl,'SUBSCRIPTIONOT'));
            $this->total_subscription_ot_addon_msl = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_addon_msl,'SUBSCRIPTIONOT'));
            $this->total_subscription_ot_main_md = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_main_md,'SUBSCRIPTIONOT'));
            $this->total_subscription_ot_addon_md = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_addon_md,'SUBSCRIPTIONOT'));

            // Obtener todos los name_key para verificar si hay suscripciones canceladas
            $name_keys_to_check = [];
            foreach($ent_data_trainings as $row) {
                $name_keys_to_check[] = $row->name;
            }

            $ent_subscription_hold_check = [];
            
            // Verificar si el usuario tiene suscripciones canceladas para estos servicios
            $has_cancelled_subscription = false;
            if (!empty($name_keys_to_check)) {
                $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
                
                foreach($name_keys_to_check as $name_key) {
                    $cancelled_subscription = $this->DataSubscriptionCancelled->find()
                        ->join([
                            'DataSubscriptions' => [
                                'table' => 'data_subscriptions',
                                'type' => 'INNER',
                                'conditions' => 'DataSubscriptions.id = DataSubscriptionCancelled.subscription_id'
                            ]
                        ])
                        ->where([
                            'DataSubscriptions.user_id' => USER_ID,
                            'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $name_key . '%'
                        ])
                        ->first();
                    
                    if (!empty($cancelled_subscription)) {
                        $has_cancelled_subscription = true;
                        break;
                    }
                }
                
                // Verificar si el usuario tiene suscripciones en HOLD para estos servicios
                $ent_subscription_hold_check = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.deleted' => 0,
                    'DataSubscriptions.status IN' => array('HOLD')
                ])->all();
                
                if (!empty($ent_subscription_hold_check)) {
                    // Primero verificar si alguna suscripción HOLD tiene los servicios que va a firmar
                    foreach($ent_subscription_hold_check as $hold_sub) {
                        // Concatenar main_service y addons_services
                        $services_in_hold = $hold_sub->main_service;
                        if (!empty($hold_sub->addons_services)) {
                            $services_in_hold .= ',' . $hold_sub->addons_services;
                        }
                        
                        // Verificar si alguno de los name_keys está en los servicios HOLD
                        foreach($name_keys_to_check as $name_key) {
                            if (strpos($services_in_hold, $name_key) !== false) {
                                $has_cancelled_subscription = true;
                                break 2; // Salir de ambos foreach
                            }
                        }
                    }
                }
            }
            
            foreach($ent_data_trainings as $row) {

                if (!empty($row->md_agreement) && !empty($row->msl_agreement) && (empty($row->md) || empty($row->msl)) ) continue;
                
                $tmp_total = 0;

                if (!empty($row->md) && !empty($row->md_agreement) && $row->require_mdsub == 1) {
                    if ($_total_md) {
                        $_total_md += $this->total_subscription_ot_addon_md;
                        $tmp_total = $this->total_subscription_ot_addon_md;
                    } else {
                        $_total_md += $this->total_subscription_ot_main_md;
                        $tmp_total = $this->total_subscription_ot_main_md;
                    }
                    if (empty($main_service_md)) {
                        $main_service_md = $row->name;
                    } else {
                        $subs_md_addons[] = $row->name;
                    }
                    $subs_md[$row->name] = $tmp_total;
                }
            }

            $total_msl_coverage = 0;
            $treatment_names = '';
            if (!empty($ent_data_trainings)) {
                if ($is_other_schools) {
                    $nameList = [];
                    foreach ($ent_data_trainings as $row) {
                        if (!empty($row->name)) {
                            $nameList[] = $row->name;
                        }
                    }
                    $treatment_names = implode(',', $nameList);
                    $total_msl_coverage = count($ent_data_trainings);
                } else {
                    foreach ($ent_data_trainings as $row) {
                        $total_msl_coverage = (int) $row->total_coverage;
                        if ($total_msl_coverage !== count($ent_data_trainings)) {
                            $total_msl_coverage = count($ent_data_trainings);
                        }
                        $treatment_names = $row->treatment_names;
                    }
                }
                $total_msl_coverage = $total_msl_coverage + count($previous_services);
            }

            if($total_msl_coverage > 0){
                $index = max(0, min($total_msl_coverage - 1, count($this->prices_msl) - 1));
                $_total_msl += $this->prices_msl[$index];
                $array_treatment_names = array_values(array_filter(array_map('trim', explode(',', $treatment_names)), function ($s) {
                    return $s !== '';
                }));
                $main_service_msl = $array_treatment_names[0] ?? '';
                if(count($array_treatment_names) > 1){
                    $subs_msl_addons = array_slice($array_treatment_names, 1);
                }
                $names_count = max(1, count($array_treatment_names));
                $price_individual = $this->prices_msl[$index] / $names_count;
                foreach($array_treatment_names as $treatment_name){
                    $subs_msl[$treatment_name] = $price_individual;
                }
            }

            if(empty($subs_md) && empty($subs_msl)){
                $this->message('Treatment not found.');
                return;
            }
    
            $payment_method = get('payment_method','');


            // PAYMENT 

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
            
            // $total_msl_total = $this->validateCode(get('promo_code',''),$_total_msl,'SUBSCRIPTIONOT');
            // if ($_total_md > 0) $total_md_total = $this->validateCode(get('promo_code',''),$_total_md,'SUBSCRIPTIONOT');
            $total_msl_total = $_total_msl;
            $total_md_total = $_total_md;

            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

            $addon_services = '';
            $addon_services = $this->normalize_addons_services(implode(',', $subs_msl_addons));
                    
            // Inicializar variables de pago
            $error = '';
            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';

            // Procesar pago si tiene suscripciones canceladas o HOLD
            if ($has_cancelled_subscription || $is_other_schools) {
                try {
                    $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_msl_total + $total_md_total,
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => 'OT MySpaLive Subscription'
                    ]);
                } catch(Stripe_CardError $e) {
                    $error = $e->getMessage();
                } catch (Stripe_InvalidRequestError $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (Stripe_AuthenticationError $e) {
                    // Authentication with Stripe's API failed
                    $error = $e->getMessage();
                } catch (Stripe_ApiConnectionError $e) {
                    // Network communication with Stripe failed
                    $error = $e->getMessage();
                } catch (Stripe_Error $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                } catch (Exception $e) {
                    // Something else happened, completely unrelated to Stripe
                    $error = $e->getMessage();
                } catch(\Stripe\Exception\CardException $e) {
                    // Since it's a decline, \Stripe\Exception\CardException will be caught
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\RateLimitException $e) {
                    // Too many requests made to the API too quickly
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\AuthenticationException $e) {
                    // Authentication with Stripe's API failed
                    // (maybe you changed API keys recently)
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    // Display a very generic error to the user, and maybe send
                    // yourself an email
                    $error = $e->getMessage();
                }
    
                if(isset($stripe_result->charges->data[0]->receipt_url)) {
                    $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                    $id_charge = $stripe_result->charges->data[0]->id;
                    $payment_id = $stripe_result->id;
                }
    
                if(!empty($error)) {
                    $this->message('Your payment method is not valid. Add a new payment method and try again.');
                    return;
                }

            }

            $s_entity = $this->DataSubscriptions->newEntity([
                'uid'		=> $this->DataSubscriptions->new_uid(),
                'event'		=> 'save_subscription',
                'payload'	=> '',
                'user_id'	=> USER_ID,
                'request_id'	=> '',
                'status' => 'ACTIVE',
                'data_object_id' => '',
                'customer_id' => $customer['id'],
                'payment_method' => $payment_method,
                'subscription_type' => 'SUBSCRIPTIONMSL',
                'total' =>$_total_msl,
                'subtotal' => $total_msl_total,
                'promo_code' => get('promo_code',''),
                'agreement_id' => 0,
                'main_service' => $main_service_msl,
                'addons_services' => $addon_services,
                'payment_details' => json_encode($subs_msl),
                'state' => USER_STATE,
            ]);

            if(!$s_entity->hasErrors()) {

                 // Si encontramos coincidencias, cancelar TODAS las suscripciones HOLD del usuario

                foreach($ent_subscription_hold_check as $hold_sub) {
                    $hold_sub->status = 'CANCELLED';
                    $this->DataSubscriptions->save($hold_sub);
                }

                $msl_id = $this->DataSubscriptions->save($s_entity);
                $this->set('msl_subscription_id', $msl_id->id);

                if (count($subs_md) > 0) {

                    $addon_services = '';
                    $addon_services = $this->normalize_addons_services(implode(',', $subs_md_addons));

                    $m_entity = $this->DataSubscriptions->newEntity([
                        'uid'		=> $this->DataSubscriptions->new_uid(),
                        'event'		=> 'save_subscription',
                        'payload'	=> '',
                        'user_id'	=> USER_ID,
                        'request_id'	=> '',
                        'status' => 'ACTIVE',
                        'data_object_id' => '',
                        'customer_id' => $customer['id'],
                        'payment_method' => $payment_method,
                        'subscription_type' => 'SUBSCRIPTIONMD',
                        'total' =>$_total_md,
                        'subtotal' => $total_md_total,
                        'promo_code' => get('promo_code',''),
                        'agreement_id' => 0,
                        'main_service' => $main_service_md,
                        'addons_services' => $addon_services,
                        'payment_details' => json_encode($subs_md),
                        'state' => USER_STATE,
                    ]);

                    if(!$m_entity->hasErrors()) {

                        $md_id = $this->DataSubscriptions->save($m_entity);

                        $this->set('msl_subscription_id', $md_id->id);

                    }
                }
            }

            // Guardar pagos si se procesó el cobro
            if ($has_cancelled_subscription || $is_other_schools) {

                if(empty($error) && $stripe_result->status == 'succeeded') {
                    $doctor_id = $this->SysUserAdmin->getAssignedDoctorInjector(USER_ID);

                    $c_entity = $this->DataSubscriptionPayments->newEntity([
                        'uid'   => Text::uuid(),
                        'subscription_id'  => $msl_id->id,
                        'user_id'  => USER_ID,
                        'total'  => $total_msl_total,
                        'payment_id'  => $payment_id,
                        'charge_id'  => $id_charge,
                        'receipt_id'  => $receipt_url,
                        'created'   => date('Y-m-d H:i:s'),
                        'error' => $error,
                        'status' => 'DONE',
                        'deleted' => 0,
                        'md_id' => $doctor_id,

                        'payment_type' => 'FULL',
                        'payment_description' => 'MSL Subscription',
                        'main_service' => $main_service_msl,
                        'addons_services' => $addon_services,
                        'payment_details' => json_encode($subs_msl),
                        'state' => USER_STATE,
                    ]);

                    $aux_payment = $this->DataSubscriptionPayments->save($c_entity);

                    if (count($subs_md) > 0) {
                        $addon_services_md = $this->normalize_addons_services(implode(',', $subs_md_addons));
                        
                        $z_entity = $this->DataSubscriptionPayments->newEntity([
                            'uid'   => Text::uuid(),
                            'subscription_id'  => $md_id->id,
                            'user_id'  => USER_ID,
                            'total'  => $total_md_total,
                            'payment_id'  => $payment_id,
                            'charge_id'  => $id_charge,
                            'receipt_id'  => $receipt_url,
                            'created'   => date('Y-m-d H:i:s'),
                            'error' => $error,
                            'status' => 'DONE',
                            'deleted' => 0,
                            'md_id' => $doctor_id,

                            'payment_type' => 'FULL',
                            'payment_description' => 'MD Subscription',
                            'main_service' => $main_service_md,
                            'addons_services' => $addon_services_md,
                            'payment_details' => json_encode($subs_md),
                            'state' => USER_STATE,
                        ]);

                        $a_payment = $this->DataSubscriptionPayments->save($z_entity);

                        if($is_other_schools){
                            $Payments = new PaymentsController();
                            $x = $Payments->pay_sales_rep_schools(USER_ID, $a_payment->id);
                        }
                    }
                }
            }

            $user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
            if($user->steps != 'HOME'){
                $this->SysUsers->updateAll(['steps' => 'W9'], ['id' => USER_ID]); 
            }
            

            $this->success();
        }
    }

    public function save_subscription_ot_schools(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        $this->loadModel('SpaLiveV1.SysUserAdmin');
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

        $course_id = get('course_id', 0);

        if($course_id <= 0){
            $this->message('Invalid data training ID.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysTreatmentsOt');

       

        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('CatCourses');
        $ent_data_trainings = $this->CatCourses
        ->find()->select([
            'name' => 'OtherTreatment.name_key',
            'require_mdsub' => 'OtherTreatment.require_mdsub',
            'msl' => 'CatAgreementMSL.uid',
            'md' => 'CatAgreementMD.uid',
            'md_agreement' => 'DataAgreementMD.id',
            'msl_agreement' => 'DataAgreementMSL.id',
        ])
        ->join([
            'CatSchoolOptionCert' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'CatSchoolOptionCert.id = CatCourses.school_option_id'],
            'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = CatSchoolOptionCert.sys_treatment_ot_id AND OtherTreatment.deleted = 0'],
            'CatAgreementMSL' => [
                'table'      => 'cat_agreements',
                'type'       => 'LEFT',
                'conditions' =>
                    "CatAgreementMSL.state_id = " . USER_STATE . "
                     AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                     AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                     AND CatAgreementMSL.deleted = 0
                     AND CatAgreementMSL.issue_type = 'MSL'"
            ],
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
            'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.deleted = 0 AND DataAgreementMSL.user_id = ' . USER_ID],
            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
        ])
        ->where([
                'CatCourses.id' => $course_id,
                'OtherTreatment.deleted' => 0,
                'OR' => [
                    'CatAgreementMSL.uid IS NOT' => null,
                    'CatAgreementMD.uid IS NOT'  => null,
                ],
            ])
        ->group(['CatAgreementMD.id','CatAgreementMSL.id'])
        ->all();



        $_total_md = 0;
        $_total_msl = 0;
        $subs_md = [];
        $subs_msl = [];
        $subs_md_addons = [];
        $subs_msl_addons = [];
        $main_service_msl = '';
        $main_service_md = '';
        $total_msl_total = 0;
        $total_md_total = 0;

        $this->total_subscription_ot_main_msl = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_main_msl,'SUBSCRIPTIONOT'));
        $this->total_subscription_ot_addon_msl = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_addon_msl,'SUBSCRIPTIONOT'));
        $this->total_subscription_ot_main_md = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_main_md,'SUBSCRIPTIONOT'));
        $this->total_subscription_ot_addon_md = round($this->validateCode(get('promo_code',''),$this->total_subscription_ot_addon_md,'SUBSCRIPTIONOT'));

        foreach($ent_data_trainings as $row) {
        
            if (!empty($row->md_agreement) && !empty($row->md_agreement) && (empty($row->md) || empty($row->msl)) ) continue;
            
            $tmp_total = 0;

            if (!empty($row->md) && !empty($row->md_agreement) && $row->require_mdsub == 1) {
                if ($_total_md == 0) {
                    $_total_md += $this->total_subscription_ot_main_md;
                    $tmp_total = $this->total_subscription_ot_main_md;
                } else {
                    $_total_md += $this->total_subscription_ot_addon_md;
                    $tmp_total = $this->total_subscription_ot_addon_md;
                }
                if (empty($main_service_md)) {
                    $main_service_md = $row->name;
                }else{
                    $subs_md_addons[] = $row->name;
                }
                $subs_md[$row->name] = $tmp_total;
            }


            if (!empty($row->msl) && !empty($row->msl_agreement)) {
                if ($_total_msl == 0) {
                    $_total_msl += $this->total_subscription_ot_main_msl;
                    $tmp_total = $this->total_subscription_ot_main_msl;
                } else {
                    $_total_msl += $this->total_subscription_ot_addon_msl;
                    $tmp_total = $this->total_subscription_ot_addon_msl;
                }
                if (empty($main_service_msl)) {
                    $main_service_msl = $row->name;
                }else{
                    $subs_msl_addons[] = $row->name;
                }
                $subs_msl[$row->name] = $tmp_total;
            }
                
        }

        if(empty($subs_md) && empty($subs_msl)){
            $this->message('Treatment not found.');
            return;
        }
  
        $payment_method = get('payment_method','');


        // PAYMENT 

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
        
        //$total_msl_total = $this->validateCode(get('promo_code',''),$_total_msl,'SUBSCRIPTIONOT');
        //if ($_total_md > 0) $total_md_total = $this->validateCode(get('promo_code',''),$total_md_total,'SUBSCRIPTIONOT');
        $total_msl_total = $_total_msl;
        $total_md_total = $_total_md;
        $total_amount = $total_msl_total + $total_md_total;

        $error = '';
        try {
                $stripe_result = \Stripe\PaymentIntent::create([
                  'amount' => $total_amount,
                  'currency' => 'usd',
                  'customer' => $customer['id'],
                  'payment_method' => $payment_method,
                  'off_session' => true,
                  'confirm' => true,
                  'description' => 'OT MySpaLive Subscription'
                ]);
            } catch(Stripe_CardError $e) {
                $error = $e->getMessage();
            } catch (Stripe_InvalidRequestError $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (Stripe_AuthenticationError $e) {
                // Authentication with Stripe's API failed
                $error = $e->getMessage();
            } catch (Stripe_ApiConnectionError $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch (Stripe_Error $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            } catch (Exception $e) {
                // Something else happened, completely unrelated to Stripe
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }
  
            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $payment_id = $stripe_result->id;
            }
  
            if(!empty($error)) {
                $this->message('Your payment method is not valid. Add a new payment method and try again.');
                return;
            }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        
        $addon_services = '';
        $addon_services = implode(',', $subs_msl_addons);

        $s_entity = $this->DataSubscriptions->newEntity([
            'uid'		=> $this->DataSubscriptions->new_uid(),
            'event'		=> 'save_subscription',
            'payload'	=> '',
            'user_id'	=> USER_ID,
            'request_id'	=> '',
            'status' => 'ACTIVE',
            'data_object_id' => '',
            'customer_id' => $customer['id'],
            'payment_method' => $payment_method,
            'subscription_type' => 'SUBSCRIPTIONMSL',
            'total' =>$_total_msl,
            'subtotal' => $total_msl_total,
            'promo_code' => get('promo_code',''),
            'agreement_id' => 0,
            'main_service' => $main_service_msl,
            'addons_services' => $addon_services,
            'other_school' => 1,
            'payment_details' => json_encode($subs_msl),
            'state' => USER_STATE,
        ]);

       
        if(!$s_entity->hasErrors()) {

            $msl_id = $this->DataSubscriptions->save($s_entity);
            $this->set('msl_subscription_id', $msl_id->id);



            $md_id = $this->SysUserAdmin->getAssignedDoctorInjector(USER_ID); 
            $c_entity = $this->DataSubscriptionPayments->newEntity([
                'uid'   => Text::uuid(),
                'subscription_id'  => $msl_id->id,
                'user_id'  => USER_ID,
                'total'  => $total_msl_total,
                'payment_id'  => $payment_id,
                'charge_id'  => $id_charge,
                'receipt_id'  => $receipt_url,
                'created'   => date('Y-m-d H:i:s'),
                'error' => $error,
                'status' => 'DONE',
                'deleted' => 0,
                'md_id' => $md_id,

                'payment_type' => 'FULL',
                'payment_description' => 'OT MSL Subscription',
                'main_service' => $main_service_msl,
                'addons_services' => $addon_services,
                'payment_details' => json_encode($subs_msl),
                'state' => USER_STATE,
            ]);

            $aux_payment = $this->DataSubscriptionPayments->save($c_entity);



            if (count($subs_md) > 0) {

                $addon_services = '';
                $addon_services = implode(',', $subs_md_addons);

                $m_entity = $this->DataSubscriptions->newEntity([
                    'uid'		=> $this->DataSubscriptions->new_uid(),
                    'event'		=> 'save_subscription',
                    'payload'	=> '',
                    'user_id'	=> USER_ID,
                    'request_id'	=> '',
                    'status' => 'ACTIVE',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => 'SUBSCRIPTIONMD',
                    'total' => $_total_md,
                    'subtotal' => $total_md_total,
                    'promo_code' => get('promo_code',''),
                    'agreement_id' => 0,
                    'other_school' => 1,
                    'main_service' => $main_service_md,
                    'addons_services' => $addon_services,
                    'payment_details' => json_encode($subs_md),
                    'state' => USER_STATE,
                ]);

                if(!$m_entity->hasErrors()) {

                    $md_sub_id = $this->DataSubscriptions->save($m_entity);

                    $this->set('md_subscription_id', $md_sub_id->id);

                      $z_entity = $this->DataSubscriptionPayments->newEntity([
                        'uid'   => Text::uuid(),
                        'subscription_id'  => $md_sub_id->id,
                        'user_id'  => USER_ID,
                        'total'  => $total_md_total,
                        'payment_id'  => $payment_id,
                        'charge_id'  => $id_charge,
                        'receipt_id'  => $receipt_url,
                        'created'   => date('Y-m-d H:i:s'),
                        'error' => $error,
                        'status' => 'DONE',
                        'deleted' => 0,
                        'md_id' => $md_id,

                        'payment_type' => 'FULL',
                        'payment_description' => 'OT MD Subscription',
                        'main_service' => $main_service_md,
                        'addons_services' => $addon_services,
                        'payment_details' => json_encode($subs_md),
                        'state' => USER_STATE,
                    ]);

                    $a_payment = $this->DataSubscriptionPayments->save($z_entity);

                    $user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                    if($user->steps != 'HOME'){
                        $this->SysUsers->updateAll(['steps' => 'W9'], ['id' => USER_ID]); 
                    }

                    $this->success();
                }
            } else {
                $user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                if($user->steps != 'HOME'){
                    $this->SysUsers->updateAll(['steps' => 'W9'], ['id' => USER_ID]); 
                }

                $this->success();
            }
            
        }
    }

    public function re_subscription(){
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

        $subscription_id = get('subscription_id', '');
        if (empty($subscription_id)){
            $this->message('Invalid subscription.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
        $entCancelled = $this->DataSubscriptionCancelled
                    ->find()
                    ->where([
                        'DataSubscriptionCancelled.subscription_id' => $subscription_id,
                        'DataSubscriptionCancelled.deleted' => 0
                    ])
                    ->order(['DataSubscriptionCancelled.id' => 'DESC'])
                    ->first();
        if (empty($entCancelled)){
            $this->message('The subscription hasn\'t been cancelled yet.');
            return;
        }

        $entCancelled->deleted = 1;
        $this->DataSubscriptionCancelled->save($entCancelled);
        $this->success();
    }

    public function set_trial_on_hold(){
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

        $allow_hold = $this->allow_hold_trial(USER_ID);

        if(!$allow_hold){
            $this->message('You can\'t put your trial on hold.');
            return; 
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');    
        $ent_subscriptions = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.status IN' => array('ACTIVE'),
                'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL'
            ])->first();
                
        $initial_date = $ent_subscriptions->created;
        $current_date = FrozenTime::now();    

        $interval  = $current_date->diff($initial_date);
        $days_used = $interval->format('%a');

        $total_date_subscription = 30;

        if($days_used >= $total_date_subscription){
            $this->message('You can\'t put your trial on hold. Your trial has expired.');   
            return; 
        }   

        $status = 'TRIALONHOLD';

        $this->DataSubscriptions->updateAll(
            [
                'status' => $status
            ],
            [
                'user_id' => USER_ID, 
                'deleted' => 0, 
                'status IN' => array('ACTIVE')
            ]
        );

        $days_left = $total_date_subscription - $days_used;

        $this->loadModel('SpaLiveV1.DataSubscriptionTrialHold');     
        $ent_trial_hold = $this->DataSubscriptionTrialHold->newEntity([
            'user_id' => USER_ID,
            'days_left' => $days_left,
            'created' => FrozenTime::now()
        ]);   

        $this->DataSubscriptionTrialHold->save($ent_trial_hold);    

        $this->success();   
    }

    public function resume_trial(){
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

        $this->loadModel('SpaLiveV1.DataSubscriptionTrialHold');  
        $ent_trial_hold = $this->DataSubscriptionTrialHold->find()
            ->where([
                'DataSubscriptionTrialHold.user_id' => USER_ID,
                'DataSubscriptionTrialHold.resumed' => 0
            ])
            ->first();  
        
        if(empty($ent_trial_hold)){ 
            $this->message('You haven\'t put your trial on hold.');   
            return; 
        }
        
        $total_month_days = 30;
        $days_spent = $total_month_days - $ent_trial_hold->days_left;

        $current_date = FrozenTime::now();    
        $payment_date = $current_date->modify('-'.$days_spent.' days');

        $status = 'ACTIVE';
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->DataSubscriptions->updateAll(
            [
                'status' => $status,
                'created' => $payment_date,
            ],
            [
                'user_id' => USER_ID, 
                'deleted' => 0, 
                'status IN' => array('TRIALONHOLD') 
            ]
        );

        $ent_trial_hold->resumed      = 1;   
        $ent_trial_hold->date_resumed = FrozenTime::now();  
        $this->DataSubscriptionTrialHold->save($ent_trial_hold);    
        $this->success();
    }

    // FUNCTIONS NOT ACTIONS 🥵🍟👈🏻

    public function designated_subscription_type(
        $user_id
    ){
        $options = $this->get_treatment_options($user_id);

        $has_neurotoxins = $options['NEUROTOXIN'];
        $has_therapy     = $options['THERAPY'];
        $has_fillers     = $options['FILLERS'];

        if(!$has_neurotoxins && !$has_therapy && !$has_fillers){
            return 'NOT AVAILABLE';
        }

        $variant_code = '';

        if($has_neurotoxins){
            $variant_code .= 'N';
        }
        if($has_therapy){
            $variant_code .= 'I';
        }
        if($has_fillers){
            $variant_code .= 'F';
        }
        
        $cases = array(
            "N"   => '',            // case 1️⃣
            "I"   => 'IVT',         // case 2️⃣
            "F"   => 'FILLERS',     // case 3️⃣
            "NI"  => '+IVT',        // case 4️⃣
            "NF"  => '+FILLERS',    // case 5️⃣
            "IF"  => 'IVTFILLERS',  // case 6️⃣
            "NIF" => '+IVT+FILLERS' // case 7️⃣
        );
        
        $decoration = $cases[$variant_code];

        return $decoration;
    }

    public function get_treatment_options(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.SysUsers'); 
    
        $filter_category_arr = array();
    
        // CHECK NEURROTOXINS
        $neurotoxins = 'IFNULL( (SELECT COUNT(*) FROM data_trainings DT JOIN cat_trainings CT ON DT.training_id = CT.id WHERE DT.user_id = SysUsers.id AND DT.deleted = 0 AND CT.level != \'LEVEL IV\') ,0)';
        $filter_category_arr[] = "($neurotoxins) AS neurotoxins";
    
        // CHECK NEURROTOXINS SCHOOLS
        $neurotoxins_schools = 'IFNULL( (SELECT COUNT(*) FROM data_courses DC JOIN cat_courses CC ON DC.course_id = CC.id WHERE DC.user_id = SysUsers.id AND DC.deleted = 0 AND CC.type IN (\'NEUROTOXINS BASIC\', \'NEUROTOXINS ADVANCED\', \'BOTH NEUROTOXINS\') AND DC.status = \'DONE\') ,0)';
        $filter_category_arr[] = "($neurotoxins_schools) AS neurotoxins_schools";        
    
        // CHECK IV THERAPY
        $therapy = 'IFNULL( (SELECT COUNT(*) FROM data_trainings DT JOIN cat_trainings CT ON DT.training_id = CT.id WHERE DT.user_id = SysUsers.id AND DT.deleted = 0 AND CT.level = \'LEVEL IV\') ,0)';
        $filter_category_arr[] = "($therapy) AS therapy";
        
    
        // CHECK FILLERS
        $fillers = 'IFNULL( (SELECT COUNT(*) FROM data_courses DC JOIN cat_courses CC ON DC.course_id = CC.id WHERE DC.user_id = SysUsers.id AND DC.deleted = 0 AND CC.type = \'FILLERS\' AND DC.status = \'DONE\') ,0)';
        $filter_category_arr[] = "($fillers) AS fillers";
        
        $filter_category = implode(', ', $filter_category_arr);
    
        $str_query_1 = "SELECT $filter_category 
                        FROM sys_users as SysUsers 
                        WHERE
                            SysUsers.active = 1 AND
                            SysUsers.deleted = 0 AND
                            SysUsers.id = $user_id";
    
        // pr($str_query_1); die; // TESTING PURPOSES ⚠️
    
        $str_query = $str_query_1 . " ORDER BY SysUsers.id DESC LIMIT 1";
    
        $providers_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
        if(!empty($providers_query)){
            $providers_query = $providers_query[0];
    
            if(empty($providers_query)){
                return array(
                    'NEUROTOXIN' => false,
                    'THERAPY'    => false,
                    'FILLERS'    => false,
                );
            }
        } else {
            return array(
                'NEUROTOXIN' => false,
                'THERAPY'    => false,
                'FILLERS'    => false,
            );
        }
        
    
        $has_neurotoxins = $providers_query['neurotoxins'] > 0 || $providers_query['neurotoxins_schools'] > 0;
        $has_therapy = $providers_query['therapy'] > 0;
        $has_fillers = $providers_query['fillers'] > 0;
    
        return array(
            'NEUROTOXIN' => $has_neurotoxins,
            'THERAPY'    => $has_therapy,
            'FILLERS'    => $has_fillers,
        );
    }

    public function allow_hold_trial($user_id){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $ent_subscriptions = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => $user_id,
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.status IN' => array('ACTIVE'),
                'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL'
            ])->first();
        $ent_subscriptions2 = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => $user_id,
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.status IN' => array('ACTIVE'),
                'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD'
            ])->first();
        if(empty($ent_subscriptions) || empty($ent_subscriptions2)){
            return false;
        }

        // pr($ent_subscriptions);
        // pr($ent_subscriptions2);
        // exit;

        $hasPaymentsMSL = $this->subscriptionHasPayments($ent_subscriptions->id, $user_id);
        $hasPaymentsMD = $this->subscriptionHasPayments($ent_subscriptions2->id, $user_id);        

        if($hasPaymentsMSL || $hasPaymentsMD){
            return false;
        }

        $hasPausedTrial = $this->subscriptionTrialsOnHold();
        return !$hasPausedTrial;
    }

    public function subscriptionHasPayments($subscription_id, $user_id){
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $ent_payments = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.user_id' => $user_id,
                'DataSubscriptionPayments.subscription_id' => $subscription_id,
                'DataSubscriptionPayments.deleted' => 0,
            ])->all();
        return count($ent_payments) > 0;
    }

    public function subscriptionTrialsOnHold(){
        $this->loadModel('SpaLiveV1.DataSubscriptionTrialHold');
        $ent_trial = $this->DataSubscriptionTrialHold->find()
            ->where([
                'DataSubscriptionTrialHold.user_id' => USER_ID,
            ])->first();
        return !empty($ent_trial);
    }

    public function canResumeTrial(){
        $this->loadModel('SpaLiveV1.DataSubscriptionTrialHold');
        $ent_trial = $this->DataSubscriptionTrialHold->find()
            ->where([
                'DataSubscriptionTrialHold.user_id' => USER_ID,
                'DataSubscriptionTrialHold.resumed' => 0
            ])->first();
        return !empty($ent_trial);
    }

    public function get_agreement(){
        $this->loadModel('SpaLiveV1.Agreement');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataCourses');

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
            'SUBSCRIPTIONMSLIVT' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMDIVT' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMSL+IVT' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMD+IVT' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMSL+FILLERS' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMD+FILLERS' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMSLIVTFILLERS' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMDIVTFILLERS' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMSL+IVT+FILLERS' => array(
                                    'injector' => true,
                                ),
            'SUBSCRIPTIONMD+IVT+FILLERS' => array(
                                    'injector' => true,
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
        }

        $str_user = get('user','');
        $int_state = get('state',0);
        $str_agreement_uid = get('agreement_uid','');

        if ($str_type == 'subscriptionmsl' || $str_type == 'subscriptionmd') {
            $int_state = 43;
        }

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
            $search_subscription = "";
            $total_subscription = 0;
            $total_diff = 0;
            $subTitle = "";
            if($subscription_type == 'subscriptionmsl'){

                //si va a firmar msl, debe de tener la de iv
                $search_subscription = 'SUBSCRIPTIONMSLIVT';
                $total_subscription = $this->total_subscriptionmslBoth;
                $total_diff = 1000;
                $this->set('title', "Congratulations on your certification!" );
                $subTitle = "Our platform subscription allows you to use our Software, manage the product inventory, and invest in marketing to get the patients to contact you. Please read it, sign it, and add a credit card to subscribe to it.";
                
            } else if ($subscription_type == 'subscriptionmd'){

                $search_subscription = 'SUBSCRIPTIONMDIVT';
                $total_subscription = $this->total_subscriptionmdBoth;
                $total_diff = 10000;

            } else if ($subscription_type == 'SUBSCRIPTIONMSLIVT'){
                $search_subscription = 'SUBSCRIPTIONMSL';

                $total_subscription = $this->total_subscriptionmslBoth;
                $total_diff = 1000;
                $this->set('title', "Congratulations on your approved as an IV Therapist!" );
                $subTitle = "Subscribing to our platform allows you to use our software, manage your product inventory and invest in marketing and invest in marketing to get patients to contact you. Please read it, sign it, and add a credit card to subscribe.";
                
            } else if ($subscription_type == 'SUBSCRIPTIONMDIVT'){

                $search_subscription = 'SUBSCRIPTIONMD';
                $total_subscription = $this->total_subscriptionmdBoth;
                $total_diff = 10000;

            }

            $_join = [
                'Subscription' => ['table' => 'data_subscriptions', 'type' => 'LEFT', 'conditions' => 'Subscription.id = DataSubscriptionPayments.subscription_id'],
            ];
    
            $subscription = $this->DataSubscriptionPayments->find()
            ->join($_join)
            ->where(
                ['Subscription.subscription_type' => $search_subscription, 'Subscription.status' => "ACTIVE", 'Subscription.deleted' => 0,
                 'DataSubscriptionPayments.status' => "DONE", 'DataSubscriptionPayments.deleted' => 0, 
                 'DataSubscriptionPayments.user_id' => $user["user_id"], 'Subscription.user_id' => $user["user_id"]])
            ->last();

            //$this->set('subscription', $subscription);

            if(!empty($subscription)){
                $today = date('Y-m-d');
                $subscription_date = date('Y-m-d', strtotime($subscription->created->format('Y-m-d')));

                $diff_days = strtotime($today) - strtotime($subscription_date);
                $diff_days = $diff_days / 60 / 60 / 24;
                
                $this->set('free_month', 0);

                $amount = round($total_diff - ($diff_days * $total_diff / 30),2);
                $next_payment = date('m-d-Y', strtotime($subscription->created->i18nFormat('dd-MM-yyyy')."+ 1 month"));

                $message = "You will pay a difference of $".round($amount/100,2). " to complete the actual month. And starting on ".$next_payment." you will pay $".round($total_subscription/100,2)." each month.";
                $this->set('description', $message);

                $this->set('total_subscription', round($amount,2));
                
            }else{
                //mes gratis

                $_join = [
                    'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                    'Licence' => ['table' => 'sys_licences','type' => 'INNER','conditions' => 'Licence.user_id = DataCourses.user_id'],
                ];

                $ent_courses = $this->DataCourses->find()->select(['DataCourses.course_id','DataCourses.front','DataCourses.back'])
                ->join($_join)
                ->where(
                    ['DataCourses.user_id' => $user["user_id"], 'DataCourses.status' => "DONE", 
                        'DataCourses.deleted' => 0, 'CatCourses.deleted' => 0, 'Licence.status' => "APPROVED"])
                ->first();
                
                //checar si se registro por escuela para no darle mes gratis
                //si es examiner no debe tener mes gratis

                if(!empty($ent_courses)||($user["user_role"] == "examiner"&&($subscription_type == 'SUBSCRIPTIONMDIVT')||$subscription_type == 'SUBSCRIPTIONMSLIVT')){
                    $this->set('free_month', 0);
                }else{
                    $this->set('free_month', 1);
                    $subTitle.= " The first month is free.";
                    $this->set('total_subscription', 0);
                }

                //calcular la diferencia, buscamos la suscripcion activa del mes gratis con la que se registro
                $subscription = $this->DataSubscriptions->find()
                ->where(
                    ['DataSubscriptions.subscription_type' => $search_subscription, 'DataSubscriptions.status' => "ACTIVE", 
                        'DataSubscriptions.deleted' => 0, 'DataSubscriptions.user_id' => $user["user_id"]])
                ->first();

                $message = "";

                if(!empty($subscription)){
                    $today = date('Y-m-d');
                    $subscription_date = date('Y-m-d', strtotime($subscription->created->format('Y-m-d')));

                    $diff_days = strtotime($today) - strtotime($subscription_date);
                    $diff_days = $diff_days / 60 / 60 / 24;

                    $amount = round($total_diff - ($diff_days * $total_diff / 30),2);
                    $next_payment = date('m-d-Y', strtotime($subscription->created->i18nFormat('dd-MM-yyyy')."+ 1 month"));

                    $message = "You will pay a difference of $".round($amount/100,2). " to complete the actual month. And starting on ".$next_payment." you will pay $".round($total_subscription/100,2)." each month.";
                    $this->set('total_subscription', $total_subscription);

                }else{
                    $this->set('total_subscription', $total_subscription - $total_diff);
                }
                                
                $this->set('description', $message);
            }

            $this->set('subTitle', $subTitle);

        }

        $this->success();
    }

    public function save_subscription_in_home(){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionsPaymentsError');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');

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

        $subscription_type = strtoupper(get('type',''));
        if (empty($subscription_type)){
            $this->message('Invalid type subscription.');
            return;
        }

        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE','HOLD'), 'DataSubscriptions.subscription_type' => $subscription_type])->all();
        if (count($ent_subscriptions) > 0) {
            foreach ($ent_subscriptions as $key => $value) {
                if($value->status == 'HOLD'){
                    shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . $user["user_id"]);

                    if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'SUBSCRIPTIONMSL'){
                        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL'])->first();
                        $ent_subscriptions2 = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD'])->first();
                    }else
                    if($subscription_type == 'SUBSCRIPTIONMDIVT' || $subscription_type == 'SUBSCRIPTIONMSLIVT'){
                        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSLIVT'])->first();
                        $ent_subscriptions2 = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMDIVT'])->first();
                    }else
                    if($subscription_type == 'SUBSCRIPTIONMD IVT' || $subscription_type == 'SUBSCRIPTIONMSL IVT' || $subscription_type == 'SUBSCRIPTIONMD+IVT' || $subscription_type == 'SUBSCRIPTIONMSL+IVT'){
                        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMSL+IVT'])->first();
                        $ent_subscriptions2 = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('ACTIVE'), 'DataSubscriptions.subscription_type' => 'SUBSCRIPTIONMD+IVT'])->first();
                    }

                    if(!empty($ent_subscriptions) && !empty($ent_subscriptions2)){
                        $this->set('subscription_id', $ent_subscriptions->id);
                        $this->success();
                        return;
                    }else{
                        $this->message('Your payment method is not valid. Add a new payment method and try again.');
                        return;
                    }
                }
            }
            $this->set('subscription_id', $ent_subscriptions->id);
            $this->success();
            return;
        }

        $payment_method = get('payment_method','');
        $freeMonth = intval(get('freeMonth', 0));

        if($user["user_role"] == "examiner"){
            //insertar suscripciones y ver si el usuario es examiner
            
            $subscription_total = 0;
            $total_amount = 0;

            $ent_subscriptions = $this->DataSubscriptions->find()
                ->where(['DataSubscriptions.user_id' => $user["user_id"], 'DataSubscriptions.deleted' => 0, 
                         'DataSubscriptions.status IN' => array('ACTIVE','HOLD'), 'DataSubscriptions.subscription_type' => $subscription_type])->first();

            $this->set("subscription", $subscription_type);

            if($subscription_type == "SUBSCRIPTIONMSL" || $subscription_type == "SUBSCRIPTIONMSLIVT"){
                $subscription_total = $this->total_subscriptionmsl;
                $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmsl,$subscription_type);
            }else if($subscription_type == "SUBSCRIPTIONMD" || $subscription_type == "SUBSCRIPTIONMDIVT"){
                $subscription_total = $this->total_subscriptionmd;
                $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmd,$subscription_type);
            }
            
            $response = $this->insert_subscription_by_values($user,$subscription_type,$freeMonth,$payment_method,$total_amount,get('promo_code',''),get('agreement_id',0),$subscription_total);
            
            if($response["response_flag"]){
                //si es md subscription, se le debe de actualizar el tipo a gfe+ci
                if($subscription_type == "SUBSCRIPTIONMDIVT" || $subscription_type == "SUBSCRIPTIONMD"){

                    $this->loadModel('SpaLiveV1.SysUsers');
                    $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"],'SysUsers.deleted' => 0])->first();
                    if(!empty($ent_user)){

                        $md_id = $this->SysUserAdmin->getAssignedDoctorInjector($user["user_id"]);  
                        
                        $ent_user->type = "gfe+ci";
                        $ent_user->md_id = $md_id;

                        if($this->SysUsers->save($ent_user)){

                            $Therapy = new TherapyController();

                            $training_iv = $Therapy->get_course_therapist(); 
                            
                            //send request gfe+ci
                            $this->loadModel('SpaLiveV1.DataRequestGfeCi');

                            $requestItem = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => $user["user_id"]])->first();
                            if(empty($requestItem)){
                    
                                 $request_save = [
                                    'user_id' => $user["user_id"],
                                    'created' => date('Y-m-d H:i:s'),
                                    'status' => 'INIT',
                                ];
                    
                                $entRequestSave = $this->DataRequestGfeCi->newEntity($request_save);
                                if(!$entRequestSave->hasErrors()){
                                    $this->success();
                                    if($this->DataRequestGfeCi->save($entRequestSave)){
                                    }
                                }
                    
                            } 
                            //
                            if($subscription_type == "SUBSCRIPTIONMDIVT"){
                                $array_save = array(
                                    'user_id' => $user["user_id"],
                                    'training_id' => $training_iv->id,        
                                );        

                                $this->loadModel('SpaLiveV1.DataTrainings');
                                $ent_training = $this->DataTrainings->newEntity($array_save);
                                if(!$this->DataTrainings->save($ent_training)){
                                    $this->message('Error when trying to process application for iv therapy.');
                                    return;
                                }
                            }

                        }else{
                            $this->message('Error in update user.');
                            return;
                        }
                    }
                }
                
                $this->success();
                return;
            }else{
                $this->message($response["response_message"]);
                return;
            }
            
        }//else if($subscription_type == 'SUBSCRIPTIONMD IVT'||$subscription_type == 'SUBSCRIPTIONMSL IVT'||$subscription_type == 'SUBSCRIPTIONMD+IVT'||$subscription_type == 'SUBSCRIPTIONMSL+IVT'){
        //     //actualizar la subscripción
        //     \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        //     $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));

        //     $stripe_user_email = $user['email'];
        //     $stripe_user_name = $user['name'];

        //     $oldCustomer = $stripe->customers->all([
        //         "email" => $stripe_user_email,
        //         "limit" => 1,
        //     ]);

        //     if (count($oldCustomer) == 0) {
        //         $customer = $stripe->customers->create([
        //             'description' => $stripe_user_name,
        //             'email' => $stripe_user_email,
        //         ]);
        //     } else $customer = $oldCustomer->data[0];

        //     $subscription_total = 0;
        //     $s_type = "";
        //     $total_amount = 0;

        //     $where = ['DataSubscriptions.user_id' => $user["user_id"],'DataSubscriptions.deleted' => 0,
        //     'DataSubscriptions.status IN' => array('ACTIVE','HOLD')];

        //     if($subscription_type == "SUBSCRIPTIONMSL+IVT" || $subscription_type == "SUBSCRIPTIONMSL IVT"){
        //         $subscription_total = $this->total_subscriptionmslBoth;
        //         $s_type = "SUBSCRIPTIONMSL+IVT";
        //         $where['DataSubscriptions.subscription_type IN'] = array('SUBSCRIPTIONMSL','SUBSCRIPTIONMSLIVT');
        //         $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmslBoth,$subscription_type);
        //     }else if($subscription_type == "SUBSCRIPTIONMD+IVT" || $subscription_type == "SUBSCRIPTIONMD IVT"){
        //         $subscription_total = $this->total_subscriptionmdBoth;
        //         $s_type = "SUBSCRIPTIONMD+IVT";
        //         $where['DataSubscriptions.subscription_type IN'] = array('SUBSCRIPTIONMD','SUBSCRIPTIONMDIVT');
        //         $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmdBoth,$subscription_type);
        //     }

        //     $ent_old_subscription = $this->DataSubscriptions->find()
        //         ->where($where)->first();
            
        //     if (!empty($ent_old_subscription)) {

        //         $ent_old_subscription->subscription_type = $s_type;
        //         $ent_old_subscription->subtotal = $subscription_total;
        //         $ent_old_subscription->total = $total_amount;

        //         $update_subscription = $this->DataSubscriptions->save($ent_old_subscription);

        //         if(!$update_subscription){
        //             $this->message('Error in update subscription.');
        //             return;
        //         }else{

        //             $totalSubscription = intval(get('totalSubscription', 0));

        //             if($freeMonth<1){

        //                 $stripe_result = '';
        //                 $error = '';
                        
        //                 try {
        //                     $stripe_result = \Stripe\PaymentIntent::create([
        //                         'amount' => $totalSubscription,
        //                         'currency' => 'usd',
        //                         'customer' => $customer['id'],
        //                         'payment_method' => $payment_method,
        //                         'off_session' => true,
        //                         'confirm' => true,
        //                         'description' => $s_type,
        //                     ]);
        //                 } /*catch(Stripe_CardError $e) {
        //                     $error = $e->getMessage();
        //                 } catch (Stripe_InvalidRequestError $e) {
        //                     // Invalid parameters were supplied to Stripe's API
        //                     $error = $e->getMessage();
        //                 }*/ /*catch (Stripe_AuthenticationError $e) {
        //                     // Authentication with Stripe's API failed
        //                     $error = $e->getMessage();
        //                 }*/ catch (\Stripe\Exception\ApiConnectionException $e) {
        //                     // Network communication with Stripe failed
        //                     $error = $e->getMessage();
        //                 } /*catch (Stripe_Error $e) {
        //                     // Display a very generic error to the user, and maybe send
        //                     // yourself an email
        //                     $error = $e->getMessage();
        //                 } catch (Exception $e) {
        //                     // Something else happened, completely unrelated to Stripe
        //                     $error = $e->getMessage();
        //                 }*/ catch(\Stripe\Exception\CardException $e) {
        //                 // Since it's a decline, \Stripe\Exception\CardException will be caught
        //                     $error = $e->getMessage();
        //                 } catch (\Stripe\Exception\RateLimitException $e) {
        //                     // Too many requests made to the API too quickly
        //                     $error = $e->getMessage();
        //                 } catch (\Stripe\Exception\InvalidRequestException $e) {
        //                     // Invalid parameters were supplied to Stripe's API
        //                     $error = $e->getMessage();
        //                 } catch (\Stripe\Exception\AuthenticationException $e) {
        //                     // Authentication with Stripe's API failed
        //                     // (maybe you changed API keys recently)
        //                     $error = $e->getMessage();
        //                 } catch (\Stripe\Exception\ApiErrorException $e) {
        //                     // Display a very generic error to the user, and maybe send
        //                     // yourself an email
        //                     $error = $e->getMessage();
        //                 }

        //                 $receipt_url = '';
        //                 $id_charge = '';
        //                 $payment_id = '';

        //                 if(isset($stripe_result->charges->data[0]->receipt_url)) {
        //                     $receipt_url = $stripe_result->charges->data[0]->receipt_url;
        //                     $id_charge = $stripe_result->charges->data[0]->id;
        //                     $payment_id = $stripe_result->id;
        //                 }

        //                 if(empty($error) && $stripe_result->status == 'succeeded') {
        //                     $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();

        //                     $c_entity = $this->DataSubscriptionPayments->newEntity([
        //                         'uid'   => Text::uuid(),
        //                         'subscription_id'  => $ent_old_subscription->id,
        //                         'user_id'  => $user["user_id"],
        //                         'total'  => $totalSubscription,
        //                         'payment_id'  => $payment_id,
        //                         'charge_id'  => $id_charge,
        //                         'receipt_id'  => $receipt_url,
        //                         'created'   => date('Y-m-d H:i:s'),
        //                         'error' => $error,
        //                         'status' => 'DONE',
        //                         'deleted' => 0,
        //                         'md_id' => $ent_user->md_id
        //                     ]);

        //                     $this->log(__LINE__ . ' ' . json_encode($c_entity));

        //                     if (empty($error)) {
        //                         if(!$c_entity->hasErrors()) {
        //                             $id_payment = $this->DataSubscriptionPayments->save($c_entity);
        //                         }
        //                     }else{
        //                         $c_entity = $this->DataSubscriptionsPaymentsError->newEntity([                                    
        //                             'subscription_id' => $ent_old_subscription->id,
        //                             'user_id' => $user["user_id"], 
        //                             'error' => json_encode($error), 
        //                             'date' => date('Y-m-d H:i:s') , 
        //                             'stripe_result' => json_encode($stripe_result), 
        //                             'customer_id' => $customer['id'],
        //                             'payment_method'=> $payment_method,                                                                 
        //                         ]);
        //                         if(!$c_entity->hasErrors()) {
        //                             $this->DataSubscriptionsPaymentsError->save($c_entity);
        //                             return;
        //                         }
        //                     }
        //                 }else{
        //                     $this->message($error);
        //                     return;
        //                 }
        //             }
        //         }

        //         /*shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . $user["user_id"] . ' > /dev/null 2>&1 &');

        //         $user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();
                
        //         if (!empty($user)){
        //             $array_data = array(
        //                 'email' => $user['email'],
        //                 'name' => $user['name'],
        //                 'lname' => $user['lname'],
        //                 'phone' => $user['phone'],
        //                 'costo' => $total_amount / 100,
        //                 'course' => 'Injectors With Subscriptions',
        //             );

        //             if(!env('IS_DEV', false))
        //             {   //print_r('seguimos --'); 
        //                 $Ghl = new GhlController();
        //                 $Ghl->updateOpportunity($array_data);
        //             }
        //         }*/
        //     }

        // }

        $this->success();    
    }

    public function insert_subscription_by_values($user, $subscription_type, $freeMonth, $payment_method, $total_amount,$promo_code,$agreement_id,$subscription_total){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionsPaymentsError');
        $this->loadModel('SpaLiveV1.SysUsers');
        $response_error = "";
        $response_flag = true;

        // Variable to save the main subscription
        $main_service = 'NEUROTOXINS';

        if(strpos($subscription_type, 'FILLERS') !== false){
            $main_service = 'FILLERS';
        }else if(strpos($subscription_type, 'IVT') !== false){
            $main_service = 'IV THERAPY';
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

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user["user_id"]])->first();

        $ent_subscription = $this->DataSubscriptions->find()->where([
            'DataSubscriptions.subscription_type' => $subscription_type,
            'DataSubscriptions.user_id' => $ent_user->id,
            'DataSubscriptions.status' => 'ACTIVE',
            'DataSubscriptions.deleted' => 0
        ])->first();

        if(!empty($ent_subscription)){
            $response = array(
                'response_error' => $response_error,
                'response_flag' => $response_flag
            );
    
            return $response;
        }

        $stripe_result = '';
        $error = '';
        $receipt_url = '';
        $id_charge = '';
        $payment_id = '';
        $id_payment = 0;
        
        if($freeMonth<1){
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                  'amount' => $total_amount,
                  'currency' => 'usd',
                  'customer' => $customer['id'],
                  'payment_method' => $payment_method,
                  'off_session' => true,
                  'confirm' => true,
                  'description' => $subscription_type
                ]);
            } catch(Stripe_CardError $e) {
                $error = $e->getMessage();
            } catch (Stripe_InvalidRequestError $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (Stripe_AuthenticationError $e) {
                // Authentication with Stripe's API failed
                $error = $e->getMessage();
            } catch (Stripe_ApiConnectionError $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch (Stripe_Error $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            } catch (Exception $e) {
                // Something else happened, completely unrelated to Stripe
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }
  
            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $payment_id = $stripe_result->id;
            }
  
            if(!empty($error)) {
                $response_flag = false;
                $response_error = 'Your payment method is not valid. Add a new payment method and try again. ' .$error;
            }
        }

        $s_entity = $this->DataSubscriptions->newEntity([
            'uid'		=> $this->DataSubscriptions->new_uid(),
            'event'		=> 'save_subscription_in_home',
            'payload'	=> json_encode($stripe_result),
            'user_id'	=> $ent_user->id,
            'request_id'	=> '',
            'status' => 'ACTIVE',
            'data_object_id' => '',
            'customer_id' => $customer['id'],
            'payment_method' => $payment_method,
            'subscription_type' => $subscription_type,
            'total' =>$total_amount,
            'subtotal' => $subscription_total,
            'promo_code' => $promo_code,
            'agreement_id' => $agreement_id,
            'main_service' => $main_service,
            'addons_services' => '',
            'payment_details' => json_encode(array($main_service => $total_amount)),
            'state' => USER_STATE,
        ]);

        if(!$s_entity->hasErrors()) {

            $id = $this->DataSubscriptions->save($s_entity);

            $this->set('subscription_id', $id->id);

            if($response_error!=""){
                $c_entity = $this->DataSubscriptionsPaymentsError->newEntity([                                    
                    'subscription_id' => $id->id,
                    'user_id' => $ent_user->id, 
                    'error' => json_encode($error), 
                    'date' => date('Y-m-d H:i:s') , 
                    'stripe_result' => json_encode($stripe_result), 
                    'customer_id' => $customer['id'],
                    'payment_method'=> $payment_method,                                                                 
                ]);

                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionsPaymentsError->save($c_entity);

                    $response = array(
                        'response_error' => $response_error,
                        'response_flag' => $response_flag
                    );
            
                    return $response;
                }
            }

            if($freeMonth<1){
                $c_entity = $this->DataSubscriptionPayments->newEntity([
                    'uid'   => Text::uuid(),
                    'subscription_id'  => $id->id,
                    'user_id'  => $ent_user->id,
                    'total'  => $total_amount,
                    'payment_id'  => $payment_id,
                    'charge_id'  => $id_charge,
                    'receipt_id'  => $receipt_url,
                    'created'   => date('Y-m-d H:i:s'),
                    'error' => $error,
                    'status' => 'DONE',
                    'deleted' => 0,
                    'md_id' => $ent_user->md_id,

                    'payment_type' => 'FULL',
                    'payment_description' => $subscription_type,
                    'main_service' => $main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($main_service => $total_amount)),
                    'state' => USER_STATE,
                ]);
    
                //$this->log(__LINE__ . ' ' . json_encode($c_entity));

                if(!$c_entity->hasErrors()) {
                    $aux_payment = $this->DataSubscriptionPayments->save($c_entity);
                    $id_payment = $aux_payment->id;
                }
            }

            if (stripos($subscription_type, 'MD') !== false) {
                $this->loadModel('SpaLiveV1.SysUserAdmin');
                $this->SysUserAdmin->getAssignedDoctorInjector((int)$ent_user->id);

                #region Pay comission to sales representative
                $this->loadModel('SpaLiveV1.DataAssignedToRegister');

                /* $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                ])->where(['DataAssignedToRegister.user_id' => $ent_user->id,'DataAssignedToRegister.deleted' => 0])->first();

                if (!empty($assignedRep)) {
                    
                    $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
    
                    if(!empty($pay)){
                        $array_save_comission = array(
                            'uid' => Text::uuid(),
                            'payment_id' => $id_payment,
                            'amount' => 7500,
                            'user_id' => $assignedRep['User']['id'],
                            'payment_uid' => '',
                            'description' => 'SALES TEAM MD SUB',
                            'payload' => '',
                            'deleted' => 1,
                            'created' => date('Y-m-d H:i:s'),
                            'createdby' => $ent_user->id,
                        );
                        
                        $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                        $this->DataSalesRepresentativePayments->save($c_entity_comission);
                    }else{
                        $this->loadModel('SpaLiveV1.DataCourses');

                        $school = $this->DataCourses->find()->where([
                            'DataCourses.user_id' => $ent_user->id, 
                            'DataCourses.deleted' => 0,
                            'DataCourses.status'  => 'DONE',
                        ])->first();

                        if(!empty($school)){
                            $Payments = new PaymentsController();
                            $Payments->pay_sales_rep_schools($ent_user->id, $id_payment);
                        }
                    }
                } */

                $isDev = env('IS_DEV', false);

                if (!$isDev) {
                    try {
                        $sid = env('TWILIO_ACCOUNT_SID');
                        $token = env('TWILIO_AUTH_TOKEN');
                        $twilio = new Client($sid, $token);

                        $twilio->messages->create('+1' . '9518168768', [
                            'messagingServiceSid' => 'MG65978a5932f4ba9dd465e05d7b22195e',
                            'body' => 'The examiner ' . $ent_user->name . ' ' . $ent_user->lname . ' (' . $ent_user->phone . ') has paid the subscription: ' . $subscription_type . '.',
                        ]);
                    } catch (TwilioException $e) {

                    }
                }

                #endregion
            }

        }else{
            $response_flag = false;
            $response_error = "An error occurred while trying to create the subscription: ".$error;
        }

        $response = array(
            'response_error' => $response_error,
            'response_flag' => $response_flag
        );

        return $response;

    }

    public function upgrade_subscription_info(){
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

        $subscription_type = get('type','');        // MSL, MD
        if (empty($subscription_type)){
            $this->message('Invalid type subscription.');
            return;
        }

        $subscription_upgrade = get('upgrade','');  // FILLERS, NEUROTOXINS, IV THERAPY, OTHER TREATMENTS
        if (empty($subscription_upgrade)){
            $this->message('Invalid type subscription.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');


        $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%' . $subscription_type . '%'
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();
        
        if(empty($ent_subscription)){
            $this->message('Invalid subscription.');
            return;
        }

        $name_key = get('name_key','');
        
        $subscription_upgrade_ = $subscription_upgrade != 'OTHER TREATMENTS' ? $subscription_upgrade : $name_key;
       
       
        // Si es null, lo convertimos en string vacío
        $addon_services = $ent_subscription->addons_services ?? '';

        // Convertimos en array (si está vacío, queda array con un elemento vacío)
        $addons = array_filter(array_map('trim', explode(',', $addon_services)));

        if (
            strcasecmp((string) $ent_subscription->main_service, $subscription_upgrade_) === 0 ||
            in_array($subscription_upgrade_, $addons, true)
        ) {
            $this->message('You already have this subscription.');
            return;
        } 

        $current_services_str = $ent_subscription->main_service . ( !empty($ent_subscription->addons_services) ? ',' . $ent_subscription->addons_services : '');
        $current_services = explode(',', $current_services_str);

        // $current_services = $this->get_services_subscription($subscription_type, $ent_subscription->subscription_type);

        // var_dump($current_services); exit;
        // $this->set('sub_type', $ent_subscription->subscription_type);
        // $this->set('current_services', $current_services);
        // $this->set('subscription_type', $subscription_type); 
        // return; for testing only 👀

        // $is_resubscribe = get('is_resubscribe', 0) == 1;
        // if(in_array($subscription_upgrade, $current_services) && !$is_resubscribe){
        //     $this->message('You already have this subscription.');
        //     return;
        // }
        
        $upgraded_subscription = $this->get_upgraded_subscription($subscription_type, $ent_subscription->subscription_type, $subscription_upgrade);

        if(empty($upgraded_subscription)){
            $this->message('Invalid subscription.');
            return;
        }

        $amount = $subscription_type == 'MSL'
            ? 2000
            : 8500;

        $ent_payments_subscription = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                'DataSubscriptionPayments.status'  => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->order(['DataSubscriptionPayments.created' => 'DESC'])
            ->first();

        $calculated_date = empty($ent_payments_subscription)
            ? $ent_subscription->created
            : $ent_payments_subscription->created;

        $now  = FrozenTime::now(); // Obtén la fecha y hora actual como un objeto FrozenTime
        $diff = $now->diff($calculated_date); // Calcula la diferencia entre las fechas
        $days = $diff->days; // Obtiene el número de días de la diferencia

        $amount = ((30 - $days) * $amount) / 30;
        if($amount < 100){
            $amount = 100;
        }

        $amount = intval($amount);

        // $this->set('amount', $amount);
        // $this->set('days', $days);
        // $this->set('now', $now);
        // $this->set('diff', $diff);
        // $this->set('calculated_date', $calculated_date);
        // $this->set('ent_payments_subscription', $ent_payments_subscription);
        // $this->set('ent_subscription', $ent_subscription);
        // return;
        
        if($subscription_type != 'MD'){ 
            if($subscription_upgrade == 'OTHER TREATMENTS') {
                $title = '';
            }else if($subscription_upgrade == 'NEUROTOXINS') {
                $title = 'Congratulations on your approval as a Neurotoxins Injector!';
            }else{
                $title = $subscription_upgrade == 'FILLERS'
                    ? 'Congratulations on your approval as a Fillers Injector!' 
                    : 'Congratulations on your approval as an IV Specialist!';
            }
        }else{
            $title = ''; // MD has no COngratulations :( They have the MD label instead :) 
        }        


        
        $description = $subscription_type == 'MSL'
            ? 'Subscribing to our platform allows you to use our software, manage your product inventory and invest in marketing to get patients to contact you. Please read it, sign it and add a credit card to subscribe.'
            : 'Now that you are certified, in order to purchase products and provide treatments you need to have a Medical Doctor supervising your work, this is required by law, and we provide you with one.';                

        $add_on_description = $subscription_type == 'MSL'
            ? 'MSL Subscription add on: +$20 / Month'
            : 'MD Subscription add on: +$85 / Month';

        $medical_director = 'Medical Director: ' . $this->get_medical_director(USER_ID);

        $level = count($current_services) + 1;
        
        $updated_total = $this->get_total_subscription($subscription_type, $level);

        $nextPay = '';
        $entPayment =  $ent_payments_subscription;
        
        $tmp_date = null;            
        if(!empty($entPayment)){
            $tmp_date = $entPayment->created->i18nFormat('dd-MM-yyyy');
        }else{
            $tmp_date = $ent_subscription->created->i18nFormat('dd-MM-yyyy');
        }

        $subs_day = $ent_subscription->created->i18nFormat('dd');
        $last_day = date('t', strtotime($tmp_date));
        $day_pay = date('d', strtotime($tmp_date));
        $month_pay = date('m', strtotime($tmp_date));
        $year = date('Y', strtotime($tmp_date));
        $month_next = $month_pay < 12 ? $month_pay + 1 : 1;
        $next_year = $month_next == 1 ? $year + 1 : $year;
        $month_next_last_day = date('t', strtotime($next_year . '-' . $month_next . '-01'));

        if($day_pay <= $month_next_last_day){
            $nextPay = $month_next . '/' . $day_pay . '/' . $next_year;
        }else{;
            $nextPay = $month_next . '/' . $month_next_last_day . '/' . $next_year;
        }

        if(!empty($entPayment) && $day_pay != $subs_day && $subs_day > 28 && $last_day == $day_pay){
            if($subs_day <= $month_next_last_day){
                $nextPay = $month_next . '/' . $subs_day . '/' . $next_year;
            }else{
                $nextPay = $month_next . '/' . $month_next_last_day . '/' . $next_year;
            }
        }

        $today = date('m/d/Y');
        $timestamp_next = strtotime($nextPay);
        $timestamp_today = strtotime($today);

        if($timestamp_today > $timestamp_next){
            $m = date('m', strtotime($today));
            $y = date('Y', strtotime($today));
            $month_next = $m < 12 ? $m + 1 : 1;
            $next_year = $month_next == 1 ? $y + 1 : $y;
            $nextPay = $month_next . '/' . $subs_day . '/' . $next_year;
        }

        $this->loadModel('SpaLiveV1.Agreement');
        $ent_agreement = $this->Agreement->find()->where(
            ['Agreement.state_id' => 43,
            'Agreement.user_type' => 'INJECTOR',
            'Agreement.agreement_type' => $upgraded_subscription,
            'Agreement.deleted' => 0]
        )->first();

        if ($subscription_upgrade == 'OTHER TREATMENTS') {
            $amount = 0;
        }

        $this->set('agreement_uid', $ent_agreement->uid);
        $this->set('title', $title);
        $this->set('description', $description);
        $this->set('add_on_description', $add_on_description);
        $this->set('medical_director', $medical_director);    
        $this->set('upgraded_subscription', $upgraded_subscription);
        $this->set('updated_total', $updated_total);
        $this->set('next_pay', $nextPay);
        $this->set('total', floatval($amount . ".00001"));
        
        $this->success();        
    }

    public function _upgrade_subscription_(){
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

        $subscription_type = get('type','');        // MSL, MD
        if (empty($subscription_type)){
            $this->message('Invalid type subscription.');
            return;
        }

        $subscription_upgrade = get('upgrade','');  // FILLERS, NEUROTOXINS, IV THERAPY
        if (empty($subscription_upgrade)){
            $this->message('Invalid type subscription.');
            return;
        }

        $name_key = get('name_key','');

        $subscription_upgrade_ = $subscription_upgrade != 'OTHER TREATMENTS' ? $subscription_upgrade : $name_key;

        $is_resubscribe = get('is_resubscribe', 0);
        if($is_resubscribe == 1){
            // REMOVE THE UNSUBSCRIPTION

            $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
            $ent_cancelled = $this->DataSubscriptionCancelled->find()
                ->join([
                    'DataSubscriptions' => [
                        'table' => 'data_subscriptions',
                        'type' => 'INNER',
                        'conditions' => 'DataSubscriptions.id = DataSubscriptionCancelled.subscription_id'
                    ]
                ])
                ->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.status'  => 'ACTIVE',
                    'DataSubscriptions.deleted' => 0,                    
                ])
                ->order(['DataSubscriptionCancelled.created' => 'DESC'])
                ->all();

            
            
            foreach($ent_cancelled as $cancel){
                $array_cancel = empty($cancel->services_unsubscribe) 
                    ? [] 
                    : explode(',', $cancel->services_unsubscribe);

                $key = array_search($subscription_upgrade_, $array_cancel);

                if($key !== false){
                    unset($array_cancel[$key]);
                }

                $cancel->services_unsubscribe = implode(',', $array_cancel);
                if(empty($cancel->services_unsubscribe)){
                    $cancel->deleted = 1;
                }

                $this->DataSubscriptionCancelled->save($cancel);
            }

            $this->success();
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%' . $subscription_type . '%'
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();
        
        if(empty($ent_subscription)){
            $this->message('Invalid subscription.');
            return;
        }

        $this->set('subscription_id', $ent_subscription->id);
        
        // $current_services = $this->get_services_subscription($subscription_type, $ent_subscription->subscription_type);
        $current_services_str = $ent_subscription->main_service . ( !empty($ent_subscription->addons_services) ? ',' . $ent_subscription->addons_services : '');
        $current_services = explode(',', $current_services_str);

        if(in_array($subscription_upgrade_, $current_services)){
            $this->message('You already have this subscription.');
            return;
        }
        
        // MAKE THE PAYMENT
        
        $amount_total = $subscription_type == 'MSL'
            ? $this->total_subscription_ot_addon_msl
            : $this->total_subscription_ot_addon_md;
        
        $payment_method = get('payment_method','');

        if (empty($payment_method)){
            $this->message('Invalid payment method.');
            return;
        }

        $ent_payments_subscription = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                'DataSubscriptionPayments.status'  => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->order(['DataSubscriptionPayments.created' => 'DESC'])
            ->first();
        
        $calculated_date = empty($ent_payments_subscription)
            ? $ent_subscription->created
            : $ent_payments_subscription->created;

        $now  = FrozenTime::now(); // Obtén la fecha y hora actual como un objeto FrozenTime
        $diff = $now->diff($calculated_date); // Calcula la diferencia entre las fechas
        $days = $diff->days; // Obtiene el número de días de la diferencia

        $amount = ((30 - $days) * $amount_total) / 30;
        if($amount < 100){
            $amount = 100;
        }
        
        $amount = intval($amount);
        
        // Check if this is OTHER TREATMENTS and user is NOT other_school
        $is_other_treatments_no_payment = ($subscription_upgrade == 'OTHER TREATMENTS' && $ent_subscription->other_school != 1);
        
        if($is_other_treatments_no_payment) {
            // For OTHER TREATMENTS without other_school, just update addons_services and total, no payment
            $upgraded_subscription = $this->get_upgraded_subscription($subscription_type, $ent_subscription->subscription_type, $subscription_upgrade_);            
            
            // For OTHER TREATMENTS, we only add the proportional amount to current total
            $updated_total = $ent_subscription->total + $amount;
            $subtotal = $ent_subscription->subtotal;
            
            $payment_details = json_decode($ent_subscription->payment_details, true);
            $arr_payment_details = array($subscription_upgrade_ => $amount); // Guardar solo el proporcional
            
            // Update addons_services with the name_key (comma separated)
            $current_addons = !empty($ent_subscription->addons_services) ? $ent_subscription->addons_services : '';
            $addons_services = !empty($current_addons) ? $current_addons . ',' . $name_key : $name_key;
            
            $ent_subscription->subscription_type = $upgraded_subscription;
            $ent_subscription->subtotal = $subtotal;
            $ent_subscription->total = $updated_total; // Ya incluye solo el proporcional
            $ent_subscription->addons_services = $addons_services;
            $ent_subscription->payment_details = json_encode(array_merge($payment_details, $arr_payment_details));
            
            $this->DataSubscriptions->save($ent_subscription);
            
            // Save pending payment for the remaining amount
            $this->loadModel('SpaLiveV1.DataSubscriptionPendingPayments');
            $amount_pending = $amount_total - $amount; // Total service amount - proportional amount already charged
            
            $pending_payment_data = array(
                'subscription_id' => $ent_subscription->id,
                'amount' => $amount_total,
                'amount_pending' => $amount_pending,
                'service' => $name_key,
                'created' => date('Y-m-d H:i:s'),
                'due_date' => date('Y-m-d H:i:s', strtotime('+1 month')),
                'deleted' => 0
            );
            
            $pending_entity = $this->DataSubscriptionPendingPayments->newEntity($pending_payment_data);
            if(!$pending_entity->hasErrors()) {
                $this->DataSubscriptionPendingPayments->save($pending_entity);
            }
            
            $this->success();
            return;
        }
        
        // For all other cases, proceed with payment
        $user_ent = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));

        $stripe_user_email = $user_ent['email'];
        $stripe_user_name = $user_ent['name'];

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

        try {
            $stripe_result = \Stripe\PaymentIntent::create([
              'amount' => $amount,
              'currency' => 'usd',
              'customer' => $customer['id'],
              'payment_method' => $payment_method,
              'off_session' => true,
              'confirm' => true,
              'description' => 'Upgrade Subscription - ' . $subscription_upgrade_
            ]);
          } catch(Stripe_CardError $e) {
              $error = $e->getMessage();
            } catch (Stripe_InvalidRequestError $e) {
              // Invalid parameters were supplied to Stripe's API
              $error = $e->getMessage();
            } catch (Stripe_AuthenticationError $e) {
              // Authentication with Stripe's API failed
              $error = $e->getMessage();
            } catch (Stripe_ApiConnectionError $e) {
              // Network communication with Stripe failed
              $error = $e->getMessage();
            } catch (Stripe_Error $e) {
              // Display a very generic error to the user, and maybe send
              // yourself an email
              $error = $e->getMessage();
            } catch (Exception $e) {
              // Something else happened, completely unrelated to Stripe
              $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
             // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
              // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
              // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
              // Authentication with Stripe's API failed
              // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
              // Display a very generic error to the user, and maybe send
              // yourself an email
                $error = $e->getMessage();
            }

          $receipt_url = '';
          $id_charge = '';
          $payment_id = '';
          if(isset($stripe_result->charges->data[0]->receipt_url)) {
              $receipt_url = $stripe_result->charges->data[0]->receipt_url;
              $id_charge = $stripe_result->charges->data[0]->id;
              $payment_id = $stripe_result->id;
          }

          if(empty($error) && $stripe_result->status == 'succeeded') {
            
            //UPDATE THE REGISTER OF THE SUBSCRIPTION

            //$promo_code = get('promo_code','');
            $agreement_id = get('agreement_id',0);

            $upgraded_subscription = $this->get_upgraded_subscription($subscription_type, $ent_subscription->subscription_type, $subscription_upgrade_);            
            
            $level = count($current_services) + 1;        
            
            $updated_total = $this->get_total_subscription($subscription_type, $level);
            $subtotal      = $updated_total;

            //$updated_total = $this->validateCode($promo_code,$updated_total,$upgraded_subscription);

            $payment_details = json_decode($ent_subscription->payment_details, true);

            $arr_payment_details = array($subscription_upgrade_ => $amount_total);

            $ent_subscription->agreement_id = $agreement_id;
            $ent_subscription->subscription_type = $upgraded_subscription;
            $ent_subscription->subtotal   = $subtotal;
            $ent_subscription->total      = $updated_total;
            $ent_subscription->addons_services = $this->set_addons_services($ent_subscription, $subscription_upgrade_);
            $ent_subscription->payment_details = json_encode(array_merge($payment_details, $arr_payment_details));

            $this->DataSubscriptions->save($ent_subscription);

            //$sub = $this->DataSubscriptions->save($c_entity);
            $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
            $array_save = array(
                'uid' => Text::uuid(),
                'user_id' => USER_ID,
                'subscription_id' => $ent_subscription->id,
                'total' => $amount,
                'payment_id' => $payment_id,
                'charge_id' => $id_charge,
                'receipt_id' => $receipt_url,
                'error' => '',
                'status' => 'DONE',
                'notes' => '',
                'created' => date('Y-m-d H:i:s'),
                'deleted' => 0,
                'payment_type' => 'PARTIAL',
                'payment_description' => $subscription_type,
                'main_service' => $ent_subscription->main_service,
                'addons_services' => $ent_subscription->addons_services,
                'payment_details' => json_encode(array($subscription_upgrade_ => $amount)),
                'state' => USER_STATE,
            );

            $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->DataSubscriptionPayments->save($c_entity);
            }

            if($subscription_type == 'MD'){
                $description = $subscription_upgrade_ . ' > ' . $subscription_type . ' > ADD-ON';
                $training_place = $ent_subscription->other_school == 1 ? 'other school' : '';
                $service = ucfirst(strtolower($subscription_upgrade_)) . $training_place;
                if ($subscription_upgrade != 'OTHER TREATMENTS') {
                    $this->pay_sales_rep_addon(USER_ID, $receipt_url, $description, $amount, $ent_subscription->id, $service);
                }
                if($subscription_upgrade_ == 'FILLERS'){
                    $Ghl = new GhlController();

                    $Ghl->addTag('', USER_EMAIL, USER_PHONE, 'Add on to fillers');
                } else if($subscription_upgrade_ == 'IV THERAPY'){
                    $Ghl = new GhlController();

                    $Ghl->addTag('', USER_EMAIL, USER_PHONE, 'Add on to IV');
                } else{
                    $Ghl = new GhlController();

                    $Ghl->addTag('', USER_EMAIL, USER_PHONE, 'Add on to neurotoxins');
                }
                
                #region send email to injector notify_devices
                $Main = new MainController();
                $contains = array(
                    '[CP/InjName]' => trim(USER_NAME) . ' ' . trim(USER_LNAME),
                );
                switch ($subscription_upgrade_) {
                    case 'IV THERAPY':
                        $message = 'AFTER_SUB_IV';
                        $Main->notify_devices($message,array(USER_ID),true,true,true,array(),'',$contains,true);

                        break;
                    case 'FILLERS':
                        $message = 'AFTER_SUB_FILLERS';
                        $Main->notify_devices($message,array(USER_ID),true,true,true,array(),'',$contains,true);
                        
                        break;
                }
                #endregion
            }

            $this->success();
          }else{
              $this->message('Your payment method is not valid. Add a new payment method and try again. ' .$error);
          }

    }

    public function upgrade_subscription(){
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

        $data_training_id = get('data_training_id', 0);
        $course_id = get('data_course_id', 0);
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $data_training = $this->DataTrainings->find()
        ->select(['level' => 'Cat.level'])
        ->join([
            'Cat' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Cat.id = DataTrainings.training_id']
        ])
        ->where(['DataTrainings.id' => $data_training_id, 'DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0])->first();
        
        $main_training_level = "";
        if(!empty($data_training)){
            $main_training_level = $data_training->level;
        }

        if(empty($main_training_level)){
            $this->loadModel('SpaLiveV1.DataCourses');
             $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
            ])->where(['DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE','DataCourses.id' => $course_id])->first();
            if (!empty($user_course_basic)) {
                if (!empty($user_course_basic['CatCourses']['type']) && $user_course_basic['CatCourses']['type'] != 'OTHER TREATMENTS') {
                    $main_training_level = $user_course_basic['CatCourses']['type'];
                }
            }
        }

        $payment_method = get('payment_method','');

        if (empty($payment_method)){
            $this->message('Invalid payment method.');
            return;
        }

        $levels = [
            'LEVEL 1',
            'LEVEL 3 MEDICAL',
            'LEVEL 2',
            'LEVEL 3 FILLERS',
            'LEVEL 1-1 NEUROTOXINS',
            //'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE',
            //'MYSPALIVES_HYBRID_TOX_FILLER_COURSE',
            'BOTH NEUROTOXINS',
            'NEUROTOXINS BASIC',
            'FILLERS',
            'LEVEL IV'
        ];

        if(in_array($main_training_level, $levels, true)){
            // Definir servicios específicos para cada nivel
            switch($main_training_level){
                case 'LEVEL 1':
                case 'BOTH NEUROTOXINS':
                case 'NEUROTOXINS BASIC':
                    $array_md = ['NEUROTOXINS'];
                    break;
                case 'LEVEL 3 FILLERS':
                case 'FILLERS':
                    $array_md = ['FILLERS'];
                    break;
                case 'LEVEL IV':
                    $array_md = ['IV THERAPY'];
                    break;
            }

            // Agregar servicios MSL como addons_services
            $array_msl = $array_md; // Los mismos servicios van a MSL también
            $_total_md = $this->total_subscription_ot_addon_md;
            $_total_msl = $this->total_subscription_ot_addon_msl;
            $prorrated_amount_msl = 0;
            $prorrated_amount_md = 0;
            $md_details = [];
            $msl_details = [];
            foreach($array_md as $service){
                $md_details[$service] = $this->total_subscription_ot_addon_md;
                $msl_details[$service] = $this->total_subscription_ot_addon_msl;
            }

            // Procesar suscripción MSL primero
            $ent_subscription_msl = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%MSL%'
            ])
            ->first();

            if(!empty($ent_subscription_msl)){
                $services_addons = $ent_subscription_msl->addons_services;
                $payment_details = json_decode($ent_subscription_msl->payment_details, true);

                $services_addons = $services_addons . ',' . implode(',', $array_msl);
                $payment_details = array_merge($payment_details, $msl_details);

                $ent_subscription_msl->addons_services = $this->normalize_addons_services($services_addons);
                $ent_subscription_msl->payment_details = json_encode($payment_details);

                $ent_payments_subscription = $this->DataSubscriptionPayments->find()
                ->where([
                    'DataSubscriptionPayments.subscription_id' => $ent_subscription_msl->id,
                    'DataSubscriptionPayments.status'  => 'DONE',
                    'DataSubscriptionPayments.deleted' => 0
                ])
                ->order(['DataSubscriptionPayments.created' => 'DESC'])
                ->first();
            
                $calculated_date = empty($ent_payments_subscription)
                    ? $ent_subscription_msl->created
                    : $ent_payments_subscription->created;

                $now  = FrozenTime::now();
                $diff = $now->diff($calculated_date);
                $days = $diff->days;

                $_total_msl = $this->prices_msl[count($payment_details) - 1];
                $_total_upgrade = $this->upgrades_msl[count($payment_details) - 1];
                $amount = ((30 - $days) * $_total_upgrade) / 30;
                if($amount < 100){
                    $amount = 100;
                }

                $amount = intval($amount);
                $prorrated_amount_msl = $amount;
                $ent_subscription_msl->total = $_total_msl;
                
            }

            // Buscar suscripción MD existente
            $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%MD%'
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();

            if(empty($ent_subscription)){
                // Crear nueva suscripción MD
                $main_service = $array_md[0];
                $addons_services = '';
                if(count($array_md) > 1){
                    array_shift($array_md); // Quita la posición 0
                    $addons_services = implode(',', $array_md);
                }

                $array_save = array(
                    'user_id' => USER_ID,
                    'uid' => Text::uuid(),
                    'event' => 'upgrade_subscription',
                    'payload' => '',
                    'request_id' => '',
                    'data_object_id' => '',
                    'customer_id' => $customer['id'],
                    'payment_method' => $payment_method,
                    'subscription_type' => 'SUBSCRIPTIONMD',
                    'promo_code' =>  get('promo_code',''),
                    'subtotal' => $_total_md,
                    'total' => $_total_md,
                    'status' => 'ACTIVE',
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s'),
                    'agreement_id' => 0,
                    'comments' => '',
                    'main_service' => $main_service,
                    'addons_services' => $this->normalize_addons_services($addons_services),
                    'payment_details' => json_encode($md_details),
                    'state' => USER_STATE,
                );

                $ent_subscription = $this->DataSubscriptions->newEntity($array_save);
                if(!$ent_subscription->hasErrors()) {
                    
                }
            }else{
                // Actualizar suscripción MD existente
                $services_addons = $ent_subscription->addons_services;
                $payment_details = json_decode($ent_subscription->payment_details, true);

                $services_addons = $services_addons . ',' . implode(',', $array_md);
                $payment_details = array_merge($payment_details, $md_details);

                $ent_subscription->addons_services = $this->normalize_addons_services($services_addons);
                $ent_subscription->payment_details = json_encode($payment_details);

                $ent_payments_subscription = $this->DataSubscriptionPayments->find()
                    ->where([
                        'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                        'DataSubscriptionPayments.status'  => 'DONE',
                        'DataSubscriptionPayments.deleted' => 0
                    ])
                    ->order(['DataSubscriptionPayments.created' => 'DESC'])
                    ->first();
                
                $calculated_date = empty($ent_payments_subscription)
                    ? $ent_subscription->created
                    : $ent_payments_subscription->created;

                $now  = FrozenTime::now();
                $diff = $now->diff($calculated_date);
                $days = $diff->days;

                $amount = ((30 - $days) * $_total_md) / 30;
                if($amount < 100){
                    $amount = 100;
                }

                $amount = intval($amount);
                $prorrated_amount_md = $amount;

                $ent_subscription->total = $_total_md + $ent_subscription->total;

                $this->success();
            }

            // For all other cases, proceed with payment
            $user_ent = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));

            $stripe_user_email = $user_ent['email'];
            $stripe_user_name = $user_ent['name'];

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

            try {
                    $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $prorrated_amount_msl + $prorrated_amount_md,
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => 'Upgrade Subscription'
                    ]);
                } catch(Stripe_CardError $e) {
                $error = $e->getMessage();
                } catch (Stripe_InvalidRequestError $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
                } catch (Stripe_AuthenticationError $e) {
                // Authentication with Stripe's API failed
                $error = $e->getMessage();
                } catch (Stripe_ApiConnectionError $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
                } catch (Stripe_Error $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
                } catch (Exception $e) {
                // Something else happened, completely unrelated to Stripe
                $error = $e->getMessage();
                } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                    $error = $e->getMessage();
                } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                    $error = $e->getMessage();
                }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';
            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
            
                $this->DataSubscriptions->save($ent_subscription_msl);
                $this->DataSubscriptions->save($ent_subscription);

                //$sub = $this->DataSubscriptions->save($c_entity);
                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => USER_ID,
                    'subscription_id' => $ent_subscription_msl->id,
                    'total' => $_total_msl,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'PARTIAL',
                    'payment_description' => 'SUBSCRIPTIONMSL',
                    'main_service' => $ent_subscription->main_service,
                    'addons_services' => $ent_subscription->addons_services,
                    'payment_details' => json_encode(array($ent_subscription->main_service => $_total_msl)),
                    'state' => USER_STATE,
                );

                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                    $array_save = array(
                        'uid' => Text::uuid(),
                        'user_id' => USER_ID,
                        'subscription_id' => $ent_subscription->id,
                        'total' => $_total_md,
                        'payment_id' => $payment_id,
                        'charge_id' => $id_charge,
                        'receipt_id' => $receipt_url,
                        'error' => '',
                        'status' => 'DONE',
                        'notes' => '',
                        'created' => date('Y-m-d H:i:s'),
                        'deleted' => 0,
                        'payment_type' => 'PARTIAL',    
                        'payment_description' => 'SUBSCRIPTIONMD',
                        'main_service' => $ent_subscription->main_service,
                        'addons_services' => $ent_subscription->addons_services,
                        'payment_details' => json_encode(array($ent_subscription->main_service => $_total_md)),
                        'state' => USER_STATE,
                    );
    
                    $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        $this->DataSubscriptionPayments->save($c_entity);
                    }
                }

                $this->success();
            }else{
                $this->message('Your payment method is not valid. Add a new payment method and try again. ' .$error);
            }
        }else{
            $course_type = $this->CatCoursesType->find()
            ->select([
                'name_key' => 'STOT.name_key',
                'name' => 'STOT.name',
                'require_mdsub' => 'STOT.require_mdsub',
            ])
            ->join([
                'DCC' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'DCC.course_type_id = CatCoursesType.id'],
                'STOT' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'STOT.id = DCC.ot_id AND STOT.deleted = 0']
            ])
            ->where(['CatCoursesType.name_key' => $data_training->level])->all();
            
            $array_msl = [];
            $array_md = [];
            $msl_details = [];
            $md_details = [];
            $_total_md = 0;
            $_total_msl = 0;


            foreach($course_type as $ct){
                if ($ct->name_key == 'ADVANCED_NEUROTOXINS' || $ct->name_key == 'LEVEL3_NEUROTOXINS') {
                    continue;
                }
                if($ct->require_mdsub == 1){
                    $array_md[] = $ct->name_key;
                    $_total_md += $this->total_subscription_ot_addon_md;
                    $md_details[$ct->name_key] = 0;
                }
                $array_msl[] = $ct->name_key;
                $msl_details[$ct->name_key] = 0;
            }


            $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%MSL%'
            ])
            ->first();

            if (empty($ent_subscription)) {
                // First purchase: summary already shows MSL+MD totals but no row exists yet — same flow as save_subscription_ot
                $this->save_subscription_ot();
                return;
            }

            $ent_payments_subscription = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                'DataSubscriptionPayments.status'  => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->order(['DataSubscriptionPayments.created' => 'DESC'])
            ->first();
        
            $calculated_date = empty($ent_payments_subscription)
                ? $ent_subscription->created
                : $ent_payments_subscription->created;

            $services_addons = $ent_subscription->addons_services;
            $payment_details = json_decode($ent_subscription->payment_details, true);
            if (!is_array($payment_details)) {
                $payment_details = [];
            }
            $preview_total_msl = $ent_subscription->total;

            $services_addons = empty($services_addons) ? implode(',', $array_msl) : $services_addons . ',' . implode(',', $array_msl);
            $payment_details = array_merge($payment_details, $msl_details);

            $ent_subscription->addons_services = $this->normalize_addons_services($services_addons);
            $ent_subscription->payment_details = json_encode($payment_details);

            $now  = FrozenTime::now(); // Obtén la fecha y hora actual como un objeto FrozenTime
            $diff = $now->diff($calculated_date); // Calcula la diferencia entre las fechas
            $days = $diff->days; // Obtiene el número de días de la diferencia
            
            $count_services = count($payment_details);
            $count_new_services = count($array_msl);
            $increment_services = $count_services - $count_new_services;
            foreach($array_msl as $service){
                $_total_upgrade = $this->upgrades_msl[$increment_services];
                $amount = intval(((30 - $days) * $_total_upgrade) / 30);
                if($amount < 100){
                    $amount = 100;
                }

                // Save pending payment for the remaining amount
                $this->loadModel('SpaLiveV1.DataSubscriptionPendingPayments');
                
                $pending_payment_data = array( 
                    'subscription_id' => $ent_subscription->id,
                    'amount' => $_total_upgrade,
                    'amount_pending' => $amount,
                    'service' => $service,
                    'created' => date('Y-m-d H:i:s'),
                    'due_date' => date('Y-m-d H:i:s', strtotime('+1 month')),
                    'deleted' => 0
                );
                
                $pending_entity = $this->DataSubscriptionPendingPayments->newEntity($pending_payment_data);
                if(!$pending_entity->hasErrors()) {
                    $this->DataSubscriptionPendingPayments->save($pending_entity);
                    $_total_msl += $amount;
                }

                $increment_services++;
            }

            $this->DataSubscriptions->save($ent_subscription);

            if(count($array_md) > 0){

                $ent_subscription = $this->DataSubscriptions->find()
                ->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.status'  => 'ACTIVE',
                    'DataSubscriptions.deleted' => 0,
                    'DataSubscriptions.subscription_type LIKE' => '%MD%'
                ])
                ->order(['DataSubscriptions.created' => 'DESC'])
                ->first();

                if(empty($ent_subscription)){
                    // create new subscription
                    $main_service = $array_md[0];
                    $addons_services = '';
                    if(count($array_md) > 1){
                        array_shift($array_md); // Quita la posición 0
                        $addons_services = implode(',', $array_md);
                    }

                    $array_save = array(
                        'user_id' => USER_ID,
                        'uid' => Text::uuid(),
                        'event' => 'upgrade_subscription',
                        'payload' => '',
                        'request_id' => '',
                        'data_object_id' => '',
                        'customer_id' => $customer['id'],
                        'payment_method' => $payment_method,
                        'subscription_type' => 'SUBSCRIPTIONMD',
                        'promo_code' =>  get('promo_code',''),
                        'subtotal' => $_total_md,
                        'total' => $_total_md,
                        'status' => 'ACTIVE',
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s'),
                        'agreement_id' => 0,
                        'comments' => '',
                        'main_service' => $main_service,
                        'addons_services' => $this->normalize_addons_services($addons_services),
                        'payment_details' => json_encode($md_details),
                        'state' => USER_STATE,
                    );
                }else{
                    $services_addons = $ent_subscription->addons_services;
                    $payment_details = json_decode($ent_subscription->payment_details, true);

                    $services_addons = empty($services_addons) ? implode(',', $array_md) : $services_addons . ',' . implode(',', $array_md);
                    $payment_details = array_merge($payment_details, $md_details);

                    $ent_subscription->addons_services = $this->normalize_addons_services($services_addons);
                    $ent_subscription->payment_details = json_encode($payment_details);
                   

                    $ent_payments_subscription = $this->DataSubscriptionPayments->find()
                        ->where([
                            'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                            'DataSubscriptionPayments.status'  => 'DONE',
                            'DataSubscriptionPayments.deleted' => 0
                        ])
                        ->order(['DataSubscriptionPayments.created' => 'DESC'])
                        ->first();
                    
                    $calculated_date = empty($ent_payments_subscription)
                        ? $ent_subscription->created
                        : $ent_payments_subscription->created;

                    $now  = FrozenTime::now(); // Obtén la fecha y hora actual como un objeto FrozenTime
                    $diff = $now->diff($calculated_date); // Calcula la diferencia entre las fechas
                    $days = $diff->days; // Obtiene el número de días de la diferencia

                    foreach($array_md as $service){
                        $amount = intval(((30 - $days) * $this->total_subscription_ot_addon_md) / 30);
                        if($amount < 100){
                            $amount = 100;
                        }

                        // Save pending payment for the remaining amount
                        $this->loadModel('SpaLiveV1.DataSubscriptionPendingPayments');
                        $amount_pending = $amount; // Total service amount - proportional amount already charged
                        
                        $pending_payment_data = array(
                            'subscription_id' => $ent_subscription->id,
                            'amount' => $this->total_subscription_ot_addon_md,
                            'amount_pending' => $amount_pending,
                            'service' => $service,
                            'created' => date('Y-m-d H:i:s'),
                            'due_date' => date('Y-m-d H:i:s', strtotime('+1 month')),
                            'deleted' => 0
                        );
                        
                        $pending_entity = $this->DataSubscriptionPendingPayments->newEntity($pending_payment_data);
                        if(!$pending_entity->hasErrors()) {
                            $this->DataSubscriptionPendingPayments->save($pending_entity);
                            $_total_md += $amount;
                        }
                    }

                    $this->DataSubscriptions->save($ent_subscription);
                }
            }

            $this->success();
            return;
        }
    }

    public function pay_sales_rep_addon($id_injector, $id_receipt, $description, $total_paid, $subscription_id, $service) {

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');

        $transfer = 5000;
        $total_to_transfer = 0;
        $rest_pay = 0;

        if($total_paid >= $transfer){
            $total_to_transfer = $transfer;
            $total_to_transfer = intval($total_to_transfer);
        } else{
            $total_to_transfer = ($total_paid * 0.97);
            $total_to_transfer = intval($total_to_transfer);
            $rest_pay = $transfer - $total_to_transfer;
            $rest_pay = intval($rest_pay);
        }

        $salesRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
            'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
        ])->where(['DataAssignedToRegister.user_id' => $id_injector,'DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.rank' => 'SENIOR', 'Rep.team' => 'OUTSIDE'])->last();
        
        if (empty($salesRep)) {        
            return "No sales rep"; ##testing purposes only
        }

        if ($salesRep['User']['stripe_account_confirm'] == 0) {            
            return 'Sales rep has no enabled stripe account.'; ##testing purposes only
        }

        if ($salesRep['Rep']['rank'] == 'JUNIOR') { // Si el sales rep es junior, se busca al senior
            $salesRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
                'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
            ])->where(['DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.rank' => 'SENIOR'])->last();
        }

        if (empty($salesRep)) {        
            return "No sales rep"; ##testing purposes only
        }

        if ($salesRep['User']['stripe_account_confirm'] == 0) {            
            return 'Sales rep has no enabled stripe account.'; ##testing purposes only
        }

        $this->loadModel('SpaLiveV1.DataPayment');


        $already_paid = $this->DataPayment->find()->where(
            [
                'DataPayment.id_from' => $id_injector,
                'DataPayment.type' => 'CI REGISTER',
                'DataPayment.comission_payed' => 1,
                'DataPayment.is_visible' => 0,
                'DataPayment.total' => 0
            ]        
        );

        /* if($already_paid->count() > 0){            
            return 'Sales rep already paid.'; ##testing purposes only
        } */

        $array_save = array(
            'id_from' => $id_injector,
            'id_to' => 0,
            'uid' => Text::uuid(),
            'type' => 'CI REGISTER', 
            'intent' => $id_receipt,
            'payment' => $id_receipt,
            'receipt' => $id_receipt,
            'discount_credits' => 0,        
            'promo_code' =>  '',
            'subtotal' => $total_paid,
            'total' => 0,
            'prod' => 1,
            'is_visible' => 0,
            'comission_payed' => 1,
            'comission_generated' => 0,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => $id_injector,
        );

        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $payment_ent_id = $this->DataPayment->save($c_entity); 
        }else{
            return 'Error saving payment'; ##testing purposes only
        }

        try {
            
            $transfer = \Stripe\Transfer::create([
                'amount' => $total_to_transfer,
                'currency' => 'USD',
                'description' => $description,
                'destination' => $salesRep['User']['stripe_account'],
            ]);

            if ($transfer) {
                $transfer_uid = Text::uuid();
                $array_save = array(
                    'uid' => $transfer_uid,
                    'payment_id' => $payment_ent_id->id,
                    'description' => $description,
                    'amount' => $total_to_transfer,
                    'user_id' => $salesRep['User']['id'],
                    'payment_uid' => $transfer->id,
                    'payload' => json_encode($transfer),
                    'created' => date('Y-m-d H:i:s'),
                    'createdby' => defined('USER_ID') ? USER_ID : 0
                );

                $c_entity = $this->DataSalesRepresentativePayments->newEntity($array_save);

                $PaymentsCtrl = new PaymentsController();

                $PaymentsCtrl->send_email_sales_team_member($id_injector, $service, 'MD', 'ADD-ON', $total_to_transfer, $salesRep);
                
                if(!$c_entity->hasErrors()) {
                    $saved = $this->DataSalesRepresentativePayments->save($c_entity);
                    
                    if ($rest_pay > 0) {
                        $array_save_rest = array(
                            'uid' => Text::uuid(),
                            'payment_id' => $payment_ent_id->id,
                            'description' => $description . ' PENDING PAYMENT',
                            'amount' => $rest_pay,
                            'user_id' => $salesRep['User']['id'],
                            'payment_uid' => '',
                            'payload' => '',
                            'deleted' => 1,
                            'created' => date('Y-m-d H:i:s'),
                            'createdby' => defined('USER_ID') ? USER_ID : 0,
                            'subscription_id' => $subscription_id,
                        );
                        $c_entity_rest = $this->DataSalesRepresentativePayments->newEntity($array_save_rest);
                        $saved2 = $this->DataSalesRepresentativePayments->save($c_entity_rest);
                    }

                    #return $saved; ##testing purposes only

                    $type = 'Receipt';
                    $filename = $this->receipt_sales_rep(true,$transfer_uid);

                    if(empty($filename)){
                        return 'Empty filename'; ##testing purposes only
                    }
                    
                    $subject = 'MySpaLive '.$type;
                    $data = array(
                        'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                        'to'    => $salesRep['User']['email'],
                        'subject' => $subject,
                        'html'    => "You have received a {$type} from MySpaLive.",
                        'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . ($c_entity->id+15000) . '.pdf'),
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
                }else{
                    return 'Error saving DataSalesRepresentativePayments '; ##testing purposes only
                }
            }

        }/* catch(Stripe_CardError $e) {
          $error = $e->getMessage();
        }*/  catch (\Stripe\Exception\ApiConnectionException $e) {
          // Network communication with Stripe failed
          $error = $e->getMessage();
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
          $error = $e->getMessage();
        } catch(\Stripe\Exception\CardException $e) {
         // Since it's a decline, \Stripe\Exception\CardException will be caught
            $error = $e->getMessage();
        } catch (\Stripe\Exception\RateLimitException $e) {
          // Too many requests made to the API too quickly
            $error = $e->getMessage();
        } catch (\Stripe\Exception\InvalidRequestException $e) {
          // Invalid parameters were supplied to Stripe's API
            $error = $e->getMessage();
        } catch (\Stripe\Exception\AuthenticationException $e) {
          // Authentication with Stripe's API failed
          // (maybe you changed API keys recently)
            $error = $e->getMessage();
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
          $error = $e->getMessage();
        }/*catch (Exception $e) {
            // Something else happened, completely unrelated to Stripe
            $error = $e->getMessage();
          }*/ 

        if(isset($error) && !empty($error)){
            return $error; ##testing purposes only
        }
    }

    public function receipt_sales_rep($return_path = false, $p_uid = ''){

        $l3n4p = get('l3n4p', '');
        if($l3n4p != '609s1d2482f7ce858.91ffd169218' && !$return_path) {
            $this->message('Not allowed');
            return;
        }   


        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');

        $payment_uid = get('uid',$p_uid);

        if (empty($payment_uid) && empty($p_uid)) { $this->message('Empty uid'); return; }

        $findPayment = $this->DataSalesRepresentativePayments->find()->select(['User.name','User.lname','DataSalesRepresentativePayments.id','DataSalesRepresentativePayments.payment_uid','DataSalesRepresentativePayments.amount','DataSalesRepresentativePayments.created'])->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentativePayments.user_id'],
        ])->where(['DataSalesRepresentativePayments.uid' => $payment_uid,'DataSalesRepresentativePayments.deleted' => 0])->first();

        if (empty($findPayment)) { $this->message('Source payment not found'); return; }


        $amount = number_format($findPayment->amount / 100,2);
        $user_name = $findPayment['User']['name'] . ' ' . $findPayment['User']['lname'];
        $provider  = 'MySpalive LLC';
        $transfer  = $findPayment->payment_uid;

        
        $date2 = $findPayment->created->i18nFormat('MM/dd/yyyy');
        $date = date('M d Y', strtotime($date2));

        // $url_panel = 'https://app.spalivemd.com/panel';
        $url_api = env('URL_API', 'https://api.myspalive.com/');
        $invoice = strval($findPayment->id + 2000);
        $len_inv = strlen($invoice);
        for ($i=$len_inv; $i < 6 ; $i++) { 
            $invoice = '0'.$invoice;
        }

        // $filename = 'transfer_' . ($findPayment->id+1500) . '.pdf';
        $filename = ($return_path == true ? TMP . 'reports' . DS : '') . 'transfer_' . ($findPayment->id+1500) . '.pdf';

        $html_content = "
            <div style=\"padding: 8px 1px 8px 1px; width: 100%\">
                <div style=\"width: 100%; display: inline-flex;\">
                    <img height=\"200\" src=\"{$url_api}/img/logo.png\">
                    <div style=\"margin-top: -130px; float: right; margin-left: 300px;\">
                        <p style=\"line-height:22px;\">
                            Date: {$date2}
                            <br>
                            Receipt: #{$invoice}
                            <br>
                        </p>
                    </div>
                </div>
                <div style=\"padding: 0px 16px 0px 16px; margin-top: 24px;\">
                    <p style=\"line-height:20px;\">
                        MySpaLive LLC
                        <br>
                        Address: 130 N Preston road. #329 Prosper, TX, 75078
                    </p>
                </div>
            </div> 
            <div style=\"margin-top:16px; border: 1px solid rgb(236, 236, 237); padding-left: 16px; width: 100%\">
                <h2>Receipt</h2>
            </div> 
            <div style=\"border: 1px solid rgb(236, 236, 237); padding: 0px 16px 16px 16px;\">
                <p>
                    {$provider} transferred \${$amount} USD to {$user_name}
                </p>
                <table width=\"100%\">
                    <thead>
                        <tr>
                            <th style=\"text-align: left; width: 450px;\">SENT ON</th>
                            <th style=\"text-align: left;\">STRIPE ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style=\"text-align: left; width: 350px;\">{$date}</td>
                            <td style=\"text-align: left;\">{$transfer}</td>
                        </tr>
                    </tbody>
                </table>
                <table width=\"100%\" style=\"margin-top:16px; border-collapse: collapse;\">
                    <tbody>
                        <tr>
                            <th colspan=\"2\" style=\"text-align: left;line-height:8px;\">SUMMARY<br>&nbsp;</th>
                        </tr>
                        <tr style=\"background-color: rgb(245, 247, 249); border-bottom: 1px solid rgb(236, 237, 241);\">
                            <td style=\"text-align: left; padding: 4px;\">Total amount sent by {$provider}</td>
                            <td style=\"text-align: right; padding: 4px;\">\${$amount} USD</td>
                        </tr>
                        <tr style=\"background-color: rgb(245, 247, 249);\">
                            <th colspan=\"2\" style=\"text-align: right;padding: 4px; width: 660px;\">Total amount sent to {$user_name} \${$amount} USD</th>
                        </tr>
                    </tbody>
                </table>
            </div> 
        ";

        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);


         if($return_path == true){
            $html2pdf->Output($filename, 'F'); //,'D'
            return $filename;
        }else $html2pdf->Output($filename, 'I'); //,'D'
        exit;

    }

    public function get_subscription_payments(){
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

        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        // Get detailed subscription info for the service
        $service_query = get('service_query', '');
        $subscription_info = $this->get_subscription_info_data($service_query);
        
        $this->set('policy', $subscription_info);

        $payments = array();

        $ent_subs_payment = $this->DataSubscriptionPayments->find()
            ->select([
                'DataSubscriptionPayments.id',
                'DataSubscriptionPayments.total',
                'DataSubscriptionPayments.created',
                'DataSubscriptions.subscription_type'
            ])
            ->join([
                'DataSubscriptions' => [
                    'table' => 'data_subscriptions',
                    'type' => 'LEFT',
                    'conditions' => 'DataSubscriptions.id = DataSubscriptionPayments.subscription_id'
                ]
            ])
            ->where([
                'DataSubscriptionPayments.user_id' => USER_ID,
                'DataSubscriptionPayments.status'  => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->order(['DataSubscriptionPayments.created' => 'DESC'])
            ->all();                

        foreach($ent_subs_payment as $ent_payment){

            $title = stripos($ent_payment['DataSubscriptions']['subscription_type'], 'MD') !== false 
                ? 'MD Subscription'
                : 'MSL Subscription';            

            $amount = $ent_payment->total;
            $date   = $ent_payment->created->i18nFormat('MM/dd/yyyy');

            $payments[] = array(
                "title"  =>     $title,
                "amount" =>     $amount,
                "date"   =>     $date
            );
        }

        $service_query = get('service_query', '');
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
        $ent_subscription =$this->get_subscription_msl(USER_ID);
        if(!empty($ent_subscription)){            
            $entCancelled = $this->DataSubscriptionCancelled
                ->find()
                ->where([
                    'DataSubscriptionCancelled.subscription_id' => $ent_subscription->id,
                    'DataSubscriptionCancelled.deleted' => 0
                ])
                ->order(['DataSubscriptionCancelled.id' => 'DESC'])
                ->first();
    
            $allow_unsubscribe = true;
            $allow_cancel = true;

            if($ent_subscription->monthly == '3' || $ent_subscription->monthly == '12'){
                $allow_cancel = false;
            }

            /*if(!empty($entCancelled)){
                $array_unsubscribe = $entCancelled->services_unsubscribe;
                if(!empty($array_unsubscribe)){
                    $array_unsubscribe = explode(',', $array_unsubscribe);
                    $allow_unsubscribe = !in_array($service_query, $array_unsubscribe);
                }
            }*/
            $show_unsubscribe_alert = false;

            if($service_query == 'NEUROTOXINS' && $ent_subscription->main_service == 'NEUROTOXINS'){
                $show_unsubscribe_alert = true;
            }
    
            $this->set('allow_unsubscribe', $allow_unsubscribe);
            $this->set('allow_cancel', $allow_cancel);
            $this->set('show_unsubscribe_alert', $show_unsubscribe_alert);
        }
        $this->set('payments', $payments);
        $this->success();
    }

    public function _get_summary_subscriptions_(){
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

        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
        $ent_subscriptions = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();
        
        if(empty($ent_subscriptions)){

            // I do that because a user in production, with subscription in HOLD status, in the application the screen appeared empty
            $ent_subscriptions_hold = $this->DataSubscriptions->find()
                ->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    //'DataSubscriptions.status'  => 'HOLD',
                    'DataSubscriptions.deleted' => 0
                ])
                ->order(['DataSubscriptions.created' => 'DESC'])
                ->first();

            if(empty($ent_subscriptions_hold)){
                $this->message('Subscription not found.');
                return;
            } else {
                $ent_subscriptions = $ent_subscriptions_hold;
            }
        }

        $ServicesHelper  = new ServicesHelper(USER_ID);
        $has_neurotoxins = $ServicesHelper->service_status('NEUROTOXINS') == "DONE";
        $has_iv_therapy  = $ServicesHelper->service_status('IV THERAPY')  == "DONE";
        $has_fillers     = $ServicesHelper->service_status('FILLERS')     == "DONE";

        $ot = $this->SysTreatmentsOt->find()
            ->where(['SysTreatmentsOt.deleted' => 0, 'SysTreatmentsOt.id NOT IN' => [1,2,3,999]])
        ->all();
        
        $services_completed = array();
        if($has_neurotoxins){
            $services_completed[] = 'NEUROTOXINS';
        }
        if($has_iv_therapy){
            $services_completed[] = 'IV THERAPY';
        }
        if($has_fillers){
            $services_completed[] = 'FILLERS';
        }

        if(count($ot) > 0){
            foreach($ot as $o){
                if($ServicesHelper->service_status($o->name_key) == "DONE"){
                    $services_completed[] = $o->name_key;
                }
            }
        }

        //MSL SUBSCRIPTION
        $msl_subscription     =  $this->get_subscription_msl(USER_ID);
        $current_services_msl =  $this->get_services_subscription('MSL', $msl_subscription->subscription_type);
        $ent_cancel_msl = $this->DataSubscriptionCancelled->find()
            ->where([
                'DataSubscriptionCancelled.subscription_id' => $msl_subscription->id,
                'DataSubscriptionCancelled.deleted' => 0
            ])->first();
        $ent_payments_msl = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.subscription_id' => $msl_subscription->id,
                'DataSubscriptionPayments.status' => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->count();
        $show_payments_msl = true;
        if($msl_subscription->monthly == '3'){
            $show_payments_msl = false;
        } else if($msl_subscription->monthly == '12' || $msl_subscription->monthly == '1'){
            $show_payments_msl = $ent_payments_msl >= 1;
        }
        
        //MD SUBSCRIPTION
        $md_subscription     =  $this->get_subscription_md(USER_ID);
        $current_services_md =  $this->get_services_subscription('MD',   $md_subscription->subscription_type);
        $ent_cancel_md = $this->DataSubscriptionCancelled->find()
            ->where([
                'DataSubscriptionCancelled.subscription_id' => $md_subscription->id,
                'DataSubscriptionCancelled.deleted' => 0
            ])->first();
        $ent_payments_md = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.subscription_id' => $md_subscription->id,
                'DataSubscriptionPayments.status' => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->count();
        $show_payments_md = true;
        if($md_subscription->monthly == '3'){
            $show_payments_md = false;
        } else if($md_subscription->monthly == '12' || $md_subscription->monthly == '1'){
            $show_payments_md = $ent_payments_md >= 1;
        }

        $services_subscription = array(); // AT LEAST HAVE MSL SUBSCRIPTION
        $pending_subscription  = array(); // DO NOT HAVE ANY SUBSCRIPTION

        $services_names = array(
            'NEUROTOXINS' => 'Neurotoxins',
            'FILLERS'     => 'Fillers',
            'IV THERAPY'  => 'IV Therapy'
        );

        foreach($current_services_msl as $service){
            $unsubscribe_mls = '';
            $unsubscribe_md = '';
            if(!empty($ent_cancel_msl)){
                $unsubscribe_mls = strpos($ent_cancel_msl->services_unsubscribe, $service) !== false ? 'Cancellation set for ' . $ent_cancel_msl->date_payment->i18nFormat('MM/dd/yyyy') : '';
            }

            if(!empty($ent_cancel_md)){
                $unsubscribe_md = strpos($ent_cancel_md->services_unsubscribe, $service) !== false ? 'Cancellation set for ' . $ent_cancel_md->date_payment->i18nFormat('MM/dd/yyyy') : '';
            }

            $services_subscription[] = array(
                "name" => $services_names[$service],
                "type" => $service,
                "edit_subscription_text" => array(
                    "text" => "Need to edit subscriptions? Click",
                    "action" => "here",
                ),
                "current_payments" => array(
                    array(
                        "name"   => "Membership + MSL Subscription ",
                        "status" => "Paid", // WE ALREADY KNOW THAT IS PAID
                        "type"   => "MSL",
                        "unsubscribe" => $unsubscribe_mls,
                        "sub_id" => $msl_subscription->id,
                        'monthly' => $msl_subscription->monthly,
                    ),
                    array(
                        "name"   => "Membership + MD Subscription ",
                        "status" => in_array($service, $current_services_md) ? "Paid" : "Pending",
                        "type"   => "MD",
                        "unsubscribe" => $unsubscribe_md,
                        "sub_id" => $md_subscription->id,
                        'monthly' => $md_subscription->monthly,
                    )
                )
            );
        }

        $services_pending = array();
        foreach($current_services_msl as $service){
            if(!in_array($service, $current_services_msl)){
                $services_pending[] = $service;
            }
        }

        foreach($services_pending as $service){
            $pending_subscription[] = array(
                "name" => $services_names[$service],
                "type" => $service,
            );
        }

        $this->set('services_subscription', $services_subscription);
        $this->set('pending_subscription', $pending_subscription);
        // $this->set('show_payments_msl', $show_payments_msl);
        // $this->set('show_payments_md', $show_payments_md);
        $this->set('show_payments_msl', false);
        $this->set('show_payments_md', false);
        $this->success();
    }

    public function get_summary_subscriptions(){
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

        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
        $ent_subscriptions = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();
        
        if(empty($ent_subscriptions)){

            // I do that because a user in production, with subscription in HOLD status, in the application the screen appeared empty
            $ent_subscriptions_hold = $this->DataSubscriptions->find()
                ->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    //'DataSubscriptions.status'  => 'HOLD',
                    'DataSubscriptions.deleted' => 0
                ])
                ->order(['DataSubscriptions.created' => 'DESC'])
                ->first();

            if(empty($ent_subscriptions_hold)){
                $this->message('Subscription not found.');
                return;
            } else {
                $ent_subscriptions = $ent_subscriptions_hold;
            }
        }

        //MSL SUBSCRIPTION
        $msl_subscription     =  $this->get_subscription_msl(USER_ID);

        if(empty($msl_subscription)){
            $this->message('MSL subscription not found.');
            return;
        }

        $services_msl = [];
        $service_msl = [];

        $service_msl[] = $msl_subscription->main_service;

        if(!empty($msl_subscription->addons_services)){
            $services_msl = explode(',', $msl_subscription->addons_services);
            foreach($services_msl as $service){
                $service_msl[] = $service;
            }
        }

        $ent_cancel_msl = $this->DataSubscriptionCancelled->find()
        ->where([
            'DataSubscriptionCancelled.subscription_id' => $msl_subscription->id,
            'DataSubscriptionCancelled.deleted' => 0
        ])->first();
        $ent_payments_msl = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.subscription_id' => $msl_subscription->id,
                'DataSubscriptionPayments.status' => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
        ->count();
        $show_payments_msl = true;
        if($msl_subscription->monthly == '3'){
            $show_payments_msl = false;
        } else if($msl_subscription->monthly == '12' || $msl_subscription->monthly == '1'){
            $show_payments_msl = $ent_payments_msl >= 1;
        }

        $services_subscription_msl = [];

        foreach($service_msl as $service){
            // Check if THIS specific service is cancelled
            $unsubscribe_mls = '';
            if(!empty($ent_cancel_msl)){
                $unsubscribe_mls = strpos($ent_cancel_msl->services_unsubscribe, $service) !== false 
                    ? 'Cancellation set for ' . $ent_cancel_msl->date_payment->i18nFormat('MM/dd/yyyy') 
                    : '';
            }
            
            $services_subscription_msl[] = array(
                "name" => 'Membership + MSL Subscription',
                "type" => 'MSL',
                "subtype" => $service,
                "unsubscribe" => $unsubscribe_mls,
                "sub_id" => $msl_subscription->id,
                'monthly' => $msl_subscription->monthly,
            );
        }
        
        //MD SUBSCRIPTION
        $md_subscription = $this->get_subscription_md(USER_ID);

        if(empty($md_subscription)){
            // Se puede dar elc aso de tener solo MSL sin MD, aqui hare lo que pasaria
        }

        $services_md = [];
        $service_md = [];

        if(!empty($md_subscription)){
            $service_md[] = $md_subscription->main_service;
        }

        if(!empty($md_subscription) && !empty($md_subscription->addons_services)){
            $addons_array = explode(',', $md_subscription->addons_services);
            foreach($addons_array as $service){
                $service_md[] = trim($service);
            }
        }
        $ent_cancel_md = null;
        $ent_payments_md = 0;
        
        if(!empty($md_subscription)){
            $ent_cancel_md = $this->DataSubscriptionCancelled->find()
                ->where([
                    'DataSubscriptionCancelled.subscription_id' => $md_subscription->id,
                    'DataSubscriptionCancelled.deleted' => 0
            ])->first();
            
            $ent_payments_md = $this->DataSubscriptionPayments->find()
                ->where([
                    'DataSubscriptionPayments.subscription_id' => $md_subscription->id,
                    'DataSubscriptionPayments.status' => 'DONE',
                    'DataSubscriptionPayments.deleted' => 0
                ])
            ->count();
        }

        $show_payments_md = true;
        if(!empty($md_subscription)){
            if($md_subscription->monthly == '3'){
                $show_payments_md = false;
            } else if($md_subscription->monthly == '12' || $md_subscription->monthly == '1'){
                $show_payments_md = $ent_payments_md >= 1;
            }
        }
        
        $services_subscription_md = [];

        foreach($service_md as $service){
            // Check if THIS specific service is cancelled
            $unsubscribe_md = '';
            if(!empty($ent_cancel_md)){
                $unsubscribe_md = strpos($ent_cancel_md->services_unsubscribe, $service) !== false 
                    ? 'Cancellation set for ' . $ent_cancel_md->date_payment->i18nFormat('MM/dd/yyyy') 
                    : '';
            }
            
            $services_subscription_md[] = array(
                "name" => 'Membership + MD Subscription',
                "type" => 'MD',
                "subtype" => $service,
                "unsubscribe" => $unsubscribe_md,
                "sub_id" => $md_subscription->id,
                'monthly' => $md_subscription->monthly,
            );
        }

        // Agrupar subscripciones por subtype
        $grouped_subscriptions = array();
        $all_subscriptions = array_merge($services_subscription_msl, $services_subscription_md);

        // Crear un array temporal para agrupar por subtype
        $subtypes_group = array();

        // Procesar subscripciones MSL y MD
        foreach($all_subscriptions as $subscription) {
            $subtype = $subscription['subtype'];
            
            if (!isset($subtypes_group[$subtype])) {
                $subtypes_group[$subtype] = array(
                    'name' => $this->getServiceDisplayName($subtype),
                    'type' => strtoupper($subtype),
                    'edit_subscription_text' => array(
                        'text' => 'Need to edit subscriptions? Click',
                        'action' => 'here'
                    ),
                    'current_payments' => array()
                );
            }
            
            // Agregar el payment al grupo correspondiente
            $payment_info = array(
                'name' => $subscription['name'],
                'status' => ($subscription['type'] == 'MSL' ? ($show_payments_msl ? 'Paid' : 'Pending') : ($show_payments_md ? 'Paid' : 'Pending')),
                'type' => $subscription['type'],
                'unsubscribe' => isset($subscription['unsubscribe']) ? $subscription['unsubscribe'] : '',
                'sub_id' => $subscription['sub_id'],
                'monthly' => $subscription['monthly']
            );
            
            $subtypes_group[$subtype]['current_payments'][] = $payment_info;
        }

        // Convertir el array asociativo a array indexado
        $final_subscriptions = array_values($subtypes_group);

        $this->set('services_subscription', $final_subscriptions);
        $this->set('pending_subscription', array());
        // $this->set('show_payments_msl', $show_payments_msl);
        // $this->set('show_payments_md', $show_payments_md);
        $this->set('show_payments_msl', false);
        $this->set('show_payments_md', false);
        $this->success();
    }

    /**
     * Get recent payments from all subscriptions (copied from get_subscription_payments)
     */
    public function get_recent_payments(){
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

        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        $limit = get('limit', 5); // Default 5, can be 100 for "see more"
        $limit = min($limit, 100); // Cap at 100

        $payments = array();

        // Same query as get_subscription_payments but without service_query filter
        $ent_subs_payment = $this->DataSubscriptionPayments->find()
            ->select([
                'DataSubscriptionPayments.id',
                'DataSubscriptionPayments.total',
                'DataSubscriptionPayments.created',
                'DataSubscriptions.subscription_type'
            ])
            ->join([
                'DataSubscriptions' => [
                    'table' => 'data_subscriptions',
                    'type' => 'LEFT',
                    'conditions' => 'DataSubscriptions.id = DataSubscriptionPayments.subscription_id'
                ]
            ])
            ->where([
                'DataSubscriptionPayments.user_id' => USER_ID,
                'DataSubscriptionPayments.status'  => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->order(['DataSubscriptionPayments.created' => 'DESC'])
            ->limit($limit)
            ->all();                


        //subscription name set manually
        foreach($ent_subs_payment as $ent_payment){

            $title = stripos($ent_payment['DataSubscriptions']['subscription_type'], 'MD') !== false 
                ? 'MD Subscription'
                : 'MSL Subscription';            

            $amount = $ent_payment->total;
            $date   = $ent_payment->created->i18nFormat('MM/dd/yyyy');

            $payments[] = array(
                "id"     => $ent_payment->id,
                "title"  => $title,
                "amount" => $amount,
                "amount_formatted" => '$' . number_format($amount / 100, 2),
                "date"   => $date,
                "status" => 'DONE'
            );
        }

        // Check if there are more payments available
        $total_payments = $this->DataSubscriptionPayments->find()
            ->where([
                'DataSubscriptionPayments.user_id' => USER_ID,
                'DataSubscriptionPayments.status'  => 'DONE',
                'DataSubscriptionPayments.deleted' => 0
            ])
            ->count();
        
        $this->set('show_edit_subscription', count($payments) > 0);
        $this->set('payments', $payments);
        $this->set('has_more', count($payments) < $total_payments);
        $this->success();
    }

    /**
     * Mapea los tipos de servicio a nombres legibles
     */
    private function getServiceDisplayName($subtype) {
        $serviceNames = [
            'NEUROTOXINS' => 'Neurotoxins',
            'IV THERAPY' => 'IV Therapy', 
            'FILLERS' => 'Fillers',
        ];
        
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $treatment = $this->SysTreatmentsOt->find()->where(['SysTreatmentsOt.deleted' => 0])->all();

        foreach($treatment as $t){
            $serviceNames[$t->name_key] = $t->name;
        }

        return isset($serviceNames[$subtype]) ? $serviceNames[$subtype] : ucwords(strtolower(str_replace('_', ' ', $subtype)));
    }

    public function get_subscription_info(){
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

        $type = get('type', '');
        if(empty($type)){
            $this->message('Invalid subscription type.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');

        // Get both MD and MSL subscriptions
        $md_subscription = $this->get_subscription_md(USER_ID);
        $msl_subscription = $this->get_subscription_msl(USER_ID);

        // Check which subscriptions contain the service to cancel
        $md_has_service = false;
        $msl_has_service = false;
        
        if(!empty($md_subscription)) {
            $md_services = array_merge([$md_subscription->main_service], 
                !empty($md_subscription->addons_services) ? explode(',', $md_subscription->addons_services) : []);
            $md_has_service = in_array($type, $md_services);
        }
        
        if(!empty($msl_subscription)) {
            $msl_services = array_merge([$msl_subscription->main_service], 
                !empty($msl_subscription->addons_services) ? explode(',', $msl_subscription->addons_services) : []);
            $msl_has_service = in_array($type, $msl_services);
        }
        
        // Check if service is in both subscriptions
        $is_in_both = $md_has_service && $msl_has_service;
        
        if(!$md_has_service && !$msl_has_service){
            $this->message('Service not found in any subscription.');
            return;
        }

        // Check for existing cancellations in both subscriptions
        $cancel_md = null;
        $cancel_msl = null;
        
        if($md_has_service && !empty($md_subscription)) {
            $cancel_md = $this->DataSubscriptionCancelled->find()
            ->where([
                'DataSubscriptionCancelled.subscription_id' => $md_subscription->id,
                'DataSubscriptionCancelled.deleted' => 0,
                'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $type . '%'
            ])
            ->first();
        }
        
        if($msl_has_service && !empty($msl_subscription)) {
            $cancel_msl = $this->DataSubscriptionCancelled->find()
            ->where([
                'DataSubscriptionCancelled.subscription_id' => $msl_subscription->id,
                'DataSubscriptionCancelled.deleted' => 0,
                'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $type . '%'
            ])
            ->first();
        }

        // Get service display name
        $service_name = $this->getServiceDisplayName($type);
        
        // Calculate current total payments for both subscriptions
        $current_total_md = $md_has_service ? $this->calculateCurrentTotal($md_subscription, 'MD') : 0;
        $current_total_msl = $msl_has_service ? $this->calculateCurrentTotal($msl_subscription, 'MSL') : 0;
        $current_total = $current_total_md + $current_total_msl;
        
        // Calculate subscription date for HTML description
        // Use the subscription with the most recent creation date
        $primary_subscription = null;
        if($md_has_service && $msl_has_service) {
            $primary_subscription = $md_subscription->created > $msl_subscription->created ? $md_subscription : $msl_subscription;
        } elseif($md_has_service) {
            $primary_subscription = $md_subscription;
        } else {
            $primary_subscription = $msl_subscription;
        }

        $subs_date = '';
        if($primary_subscription) {
            switch ($primary_subscription->monthly) {
                case '1':
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 1 month"));
                    break;
                case '3':
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 3 month"));
                    break;
                case '12':
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 12 month"));
                    break;
                default:
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 1 month"));
                    break;
            }
        }
        
        // Generate detailed HTML description based on cancellation scenarios
        $html_description = $this->generateCancellationDescription($type, $md_subscription, $msl_subscription, $md_has_service, $msl_has_service, $is_in_both, $cancel_md, $cancel_msl, $subs_date);

        // Determine if service is already cancelled in any subscription
        $is_cancelled = !empty($cancel_md) || !empty($cancel_msl);
        
        if(!$is_cancelled){
            $now = date('Y-m-d');
            $description = "";
            
            // Convert subs_date to Y-m-d format for date comparison
            $subs_date_ymd = date('Y-m-d', strtotime($subs_date));
    
            switch ($primary_subscription->monthly) {
                case '1':
                    $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date_ymd."- 15 days")) ? true : false;
                    $description = "Your subscription costs $" . number_format($current_total/100, 2) . " per month. If you cancel today, you'll be able to continue using the app until " . date('m-d-Y',strtotime($subs_date_ymd));
                    break;
                case '3':
                    $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date_ymd."- 15 days")) ? true : false;
                    $description = 'You are under contract with Port2Pay, please contact them to payments@port2pay.com if you want to cancel this subscription.';
                    break;
                case '12':
                    $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date_ymd."- 15 days")) ? true : false;
                    $description = 'To cancel, please text or call to the following number.';
                    break;
                default:
                    $allow_unsubscribe = false;
                    break;
            }
    
            $this->set('description', $description);
            $this->set('monthly', $primary_subscription->monthly);
            $this->set('title', 'CANCEL SUBSCRIPTION');
            $this->set('text_button', 'Unsubscribe');
        }else{
            // Service is already cancelled
            $cancel_info = !empty($cancel_md) ? $cancel_md : $cancel_msl;
            $cancelled_subscription = !empty($cancel_md) ? $md_subscription : $msl_subscription;
            
            if($type == $cancelled_subscription->main_service){
                switch ($cancelled_subscription->monthly) {
                    case '1':
                        $this->set('description', "Your subscription has been canceled. You can continue using the app until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))) . "\n\nIf you decide to undo this, click here:");
                        break;
                    case '3':
                        $this->set('description', "Your subscription has been canceled. You can continue using the app until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))));
                        break;
                    case '12':
                        $this->set('description', "Your subscription has been canceled. You can continue using the app until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))));
                        break;
                    default:
                        break;
                }
                $this->set('monthly', $cancelled_subscription->monthly);
            } else {
                $this->set('description', "Your subscription to this service has been cancelled you can continue offering this service until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))) . "\nIf you decide to undo this, click here:");
                $this->set('monthly', '1');
            }
            $this->set('unsubscription_date', "Your unsubscription date: " . date('m-d-Y',strtotime($cancel_info->date_cancelled->i18nFormat('yyyy-MM-dd'))));
            $this->set('title', 'SUBSCRIPTION CANCELED');
            $this->set('text_button', 'Resuscribe');
        }

        // Set new fields for enhanced UI
        $this->set('service_name', $service_name);
        $this->set('current_total', $current_total);
        $this->set('html_description', $html_description);
        
        // Set subscription type(s)
        if($is_in_both) {
            $this->set('subscription_type', 'BOTH');
            $this->set('subscription_types', ['MD', 'MSL']);
        } else {
            $this->set('subscription_type', $md_has_service ? 'MD' : 'MSL');
            $this->set('subscription_types', [$md_has_service ? 'MD' : 'MSL']);
        }

        $this->set('phone', '9729003944');
        $this->set('phone_label', '(972) 900-3944');
        $this->success();
    }

    /**
     * Get subscription info data for policy object
     */
    private function get_subscription_info_data($service_type) {
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');

        // Get both MD and MSL subscriptions
        $md_subscription = $this->get_subscription_md(USER_ID);
        $msl_subscription = $this->get_subscription_msl(USER_ID);

        // Check which subscriptions contain the service to cancel
        $md_has_service = false;
        $msl_has_service = false;
        
        if(!empty($md_subscription)) {
            $md_services = array_merge([$md_subscription->main_service], 
                !empty($md_subscription->addons_services) ? explode(',', $md_subscription->addons_services) : []);
            $md_has_service = in_array($service_type, $md_services);
        }
        
        if(!empty($msl_subscription)) {
            $msl_services = array_merge([$msl_subscription->main_service], 
                !empty($msl_subscription->addons_services) ? explode(',', $msl_subscription->addons_services) : []);
            $msl_has_service = in_array($service_type, $msl_services);
        }
        
        // Check if service is in both subscriptions
        $is_in_both = $md_has_service && $msl_has_service;
        
        if(!$md_has_service && !$msl_has_service){
            return array(
                'title' => 'Service not found',
                'message' => 'Service not found in any subscription.',
                'service_name' => $service_type,
                'service_price' => '$0.00',
                'top_text_html' => '',
                'bottom_text_html' => '',
                'phone' => '(972) 900-3944'
            );
        }

        // Check for existing cancellations in both subscriptions
        $cancel_md = null;
        $cancel_msl = null;
        
        if($md_has_service && !empty($md_subscription)) {
            $cancel_md = $this->DataSubscriptionCancelled->find()
            ->where([
                'DataSubscriptionCancelled.subscription_id' => $md_subscription->id,
                'DataSubscriptionCancelled.deleted' => 0,
                'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $service_type . '%'
            ])
            ->first();
        }
        
        if($msl_has_service && !empty($msl_subscription)) {
            $cancel_msl = $this->DataSubscriptionCancelled->find()
            ->where([
                'DataSubscriptionCancelled.subscription_id' => $msl_subscription->id,
                'DataSubscriptionCancelled.deleted' => 0,
                'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $service_type . '%'
            ])
            ->first();
        }

        // Get service display name
        $service_name = $this->getServiceDisplayName($service_type);
        
        // Calculate current total payments for both subscriptions
        $current_total_md = $md_has_service ? $this->calculateCurrentTotal($md_subscription, 'MD') : 0;
        $current_total_msl = $msl_has_service ? $this->calculateCurrentTotal($msl_subscription, 'MSL') : 0;
        $current_total = $current_total_md + $current_total_msl;
        
        // Calculate the price to display
        // For MSL, show the total MSL subscription price (software subscription)
        // For MD, show the specific service price
        $display_price = 0;
        if($msl_has_service) {
            // Show MSL total as "software subscription"
            $display_price = $current_total_msl;
        } elseif($md_has_service) {
            // Show specific service price for MD
            $display_price = $this->getServicePrice($md_subscription, $service_type, 'MD');
        }
        
        // Calculate the correct subscription date for HTML description
        $primary_subscription = null;
        if($md_has_service && $msl_has_service) {
            $primary_subscription = $md_subscription->created > $msl_subscription->created ? $md_subscription : $msl_subscription;
        } elseif($md_has_service) {
            $primary_subscription = $md_subscription;
        } else {
            $primary_subscription = $msl_subscription;
        }
        
        $subs_date = '';
        if($primary_subscription) {
            // Use the original logic: calculate based on subscription creation date
            switch ($primary_subscription->monthly) {
                case '1':
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd') . "+ 1 month"));
                    break;
                case '3':
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd') . "+ 3 month"));
                    break;
                case '12':
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd') . "+ 12 month"));
                    break;
                default:
                    // Default to 1 month if monthly value is not recognized
                    $subs_date = date('m/d/Y', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd') . "+ 1 month"));
                    break;
            }
        } else {
            // Fallback: use current date + 1 month
            $subs_date = date('m/d/Y', strtotime('+1 month'));
        }
     
        // Generate detailed HTML description
        $html_description = $this->generateCancellationDescription($service_type, $md_subscription, $msl_subscription, $md_has_service, $msl_has_service, $is_in_both, $cancel_md, $cancel_msl, $subs_date);

        // Determine if service is already cancelled
        $is_cancelled = !empty($cancel_md) || !empty($cancel_msl);
        
        if(!$is_cancelled){
            $now = date('Y-m-d');
            
            // Use the subscription with the most recent creation date for the main description
            $primary_subscription = null;
            if($md_has_service && $msl_has_service) {
                $primary_subscription = $md_subscription->created > $msl_subscription->created ? $md_subscription : $msl_subscription;
            } elseif($md_has_service) {
                $primary_subscription = $md_subscription;
            } else {
                $primary_subscription = $msl_subscription;
            }
    
            switch ($primary_subscription->monthly) {
                case '1':
                    $subs_date = date('Y-m-d', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 1 month"));
                    $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date."- 15 days")) ? true : false;
                    $description = "Your subscription costs $" . number_format($current_total/100, 2) . " per month. If you cancel today, you'll be able to continue using the app until " . date('m-d-Y',strtotime($subs_date));
                    break;
                case '3':
                    $subs_date = date('Y-m-d', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 3 month"));
                    $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date."- 15 days")) ? true : false;
                    $description = 'You are under contract with Port2Pay, please contact them to payments@port2pay.com if you want to cancel this subscription.';
                    break;
                case '12':
                    $subs_date = date('Y-m-d', strtotime($primary_subscription->created->i18nFormat('yyyy-MM-dd')."+ 12 month"));
                    $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date."- 15 days")) ? true : false;
                    $description = 'To cancel, please text or call to the following number.';
                    break;
                default:
                    $description = "";
                    break;
            }
            
            return array(
                'title' => 'CANCEL SUBSCRIPTION',
                'message' => $description,
                'service_name' => $service_name,
                'service_price' => '$' . number_format($display_price/100, 2),
                'top_text_html' => '',
                'bottom_text_html' => $html_description,
                'unsubscription_date' => !empty($subs_date) ? date('m-d-Y', strtotime($subs_date)) : '',
                'phone' => '(972) 900-3944'
            );
        }else{
            // Service is already cancelled
            $cancel_info = !empty($cancel_md) ? $cancel_md : $cancel_msl;
            $cancelled_subscription = !empty($cancel_md) ? $md_subscription : $msl_subscription;
            
            if($service_type == $cancelled_subscription->main_service){
                switch ($cancelled_subscription->monthly) {
                    case '1':
                        $description = "Your subscription has been canceled. You can continue using the app until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))) . "\n\nIf you decide to undo this, click here:";
                        break;
                    case '3':
                        $description = "Your subscription has been canceled. You can continue using the app until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd')));
                        break;
                    case '12':
                        $description = "Your subscription has been canceled. You can continue using the app until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd')));
                        break;
                    default:
                        $description = "";
                        break;
                }
            } else {
                $description = "Your subscription to this service has been cancelled you can continue offering this service until " . date('m-d-Y',strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))) . "\nIf you decide to undo this, click here:";
            }
            
            return array(
                'title' => 'SUBSCRIPTION CANCELED',
                'message' => $description,
                'service_name' => $service_name,
                'service_price' => '$' . number_format($current_total/100, 2),
                'top_text_html' => '',
                'bottom_text_html' => $html_description,
                'unsubscription_date' => "Your unsubscription date: " . date('m-d-Y',strtotime($cancel_info->date_cancelled->i18nFormat('yyyy-MM-dd'))),
                'phone' => '(972) 900-3944'
            );
        }
    }

    /**
     * Calculate current total payment for a subscription
     */
    private function calculateCurrentTotal($subscription, $subscription_type) {
        if($subscription_type == 'MSL') {
            // For MSL, use the prices_msl array based on number of services
            $all_services = array_merge([$subscription->main_service], 
                !empty($subscription->addons_services) ? explode(',', $subscription->addons_services) : []);
            $service_count = count($all_services);
            
            // Cap at 5 services maximum
            $service_count = min($service_count, 5);
            
            if($service_count > 0) {
                return $this->prices_msl[$service_count - 1];
            }
            return 0;
        } else {
            // For MD, use the total field or calculate from payment_details
            if(!empty($subscription->total)) {
                return $subscription->total;
            }
            
            // Fallback: calculate from payment_details JSON
            if(!empty($subscription->payment_details)) {
                $payment_details = json_decode($subscription->payment_details, true);
                if(is_array($payment_details)) {
                    return array_sum($payment_details);
                }
            }
            
            return 0;
        }
    }

    /**
     * Generate detailed HTML description for cancellation scenarios
     */
    private function generateCancellationDescription($service_to_cancel, $md_subscription, $msl_subscription, $md_has_service, $msl_has_service, $is_in_both, $cancel_md, $cancel_msl, $subs_date = '') {
        $html = '';
        
        // Check if service is already cancelled
        $is_cancelled = !empty($cancel_md) || !empty($cancel_msl);
        
        if(!$is_cancelled) {
            // Service is not yet cancelled
            if($is_in_both) {
                $html .= "<p>If you decide to cancel your subscription, you will be charged one last time on: " . $subs_date . ". After that, your subscription will be cancelled.</p>";
                
                // Analyze both subscriptions
                $md_is_main = $md_has_service && ($service_to_cancel == $md_subscription->main_service);
                $msl_is_main = $msl_has_service && ($service_to_cancel == $msl_subscription->main_service);
                
                if($md_is_main || $msl_is_main) {
                    // Service is main in at least one subscription
                    $html .= "<p>Your " . $this->getServiceDisplayName($service_to_cancel) . " subscription is the main one in ";
                    
                    $subscriptions_affected = [];
                    if($md_is_main) $subscriptions_affected[] = "MD";
                    if($msl_is_main) $subscriptions_affected[] = "MSL";
                    
                    $html .= implode(" and ", $subscriptions_affected) . " subscription(s). ";
                    
                    // Check for addon services that will become main
                    $addon_services = [];
                    if($md_has_service && !empty($md_subscription->addons_services)) {
                        $md_addons = explode(',', $md_subscription->addons_services);
                        $addon_services = array_merge($addon_services, $md_addons);
                    }
                    if($msl_has_service && !empty($msl_subscription->addons_services)) {
                        $msl_addons = explode(',', $msl_subscription->addons_services);
                        $addon_services = array_merge($addon_services, $msl_addons);
                    }
                    
                    if(!empty($addon_services)) {
                        $addon_services = array_unique($addon_services);
                        $addon_services = array_filter($addon_services, function($service) use ($service_to_cancel) {
                            return $service != $service_to_cancel;
                        });
                        
                        if(!empty($addon_services)) {
                            $new_main_service = reset($addon_services);
                            $new_main_name = $this->getServiceDisplayName($new_main_service);
                            
                            // Calculate price changes
                            $md_current_price = $md_has_service ? $this->getServicePrice($md_subscription, $new_main_service, 'MD') : 0;
                            $msl_current_price = $msl_has_service ? $this->getServicePrice($msl_subscription, $new_main_service, 'MSL') : 0;
                            
                            $md_new_price = $md_has_service ? $this->getNewServicePrice($md_subscription, 'MD', $new_main_service, true) : 0;
                            $msl_new_price = $msl_has_service ? $this->getNewServicePrice($msl_subscription, 'MSL', $new_main_service, true) : 0;
                            
                            $html .= "That characteristic will be transferred to " . $new_main_name . ", so that one will go from ";
                            
                            $price_changes = [];
                            if($md_has_service && $md_current_price > 0) {
                                $price_changes[] = "$" . number_format($md_current_price/100, 2) . " to $" . number_format($md_new_price/100, 2) . " (MD)";
                            }
                            if($msl_has_service && $msl_current_price > 0) {
                                $price_changes[] = "$" . number_format($msl_current_price/100, 2) . " to $" . number_format($msl_new_price/100, 2) . " (MSL)";
                            }
                            
                            $html .= implode(" and ", $price_changes) . " on " . $subs_date . ".</p>";
                        }
                    }
                    
                    // Show software subscription changes (MSL only)
                    if($msl_has_service) {
                        $msl_total = $this->calculateCurrentTotal($msl_subscription, 'MSL');
                        $new_msl_total = $this->calculateNewTotalAfterCancellation($msl_subscription, 'MSL', $service_to_cancel);
                        
                        if($new_msl_total != $msl_total) {
                            $html .= "<p>Your software subscription will be reduced from $" . number_format($msl_total/100, 2) . " to $" . number_format($new_msl_total/100, 2) . ".</p>";
                        }
                    }
                } else {
                    // Service is addon in both subscriptions
                    if($msl_has_service) {
                        // For MSL, show software subscription changes
                        $msl_total = $this->calculateCurrentTotal($msl_subscription, 'MSL');
                        $new_msl_total = $this->calculateNewTotalAfterCancellation($msl_subscription, 'MSL', $service_to_cancel);
                        
                        if($new_msl_total != $msl_total) {
                            // Price will change, show the reduction
                            $html .= "<p>* If you decide to cancel your subscription, you will be charged one last time on: " . $subs_date . ". After that, your software subscription will be reduced from $" . number_format($msl_total/100, 2) . " to $" . number_format($new_msl_total/100, 2) . ".</p>";
                        } else {
                            // Price won't change (e.g., 6 services to 5 services, both $79.95)
                            $html .= "<p>* Your software subscription will remain at $" . number_format($msl_total/100, 2) . ".</p>";
                        }
                    } elseif($md_has_service) {
                        // For MD only, show different message
                        $md_price = $this->getServicePrice($md_subscription, $service_to_cancel, 'MD');
                        $html .= "<p>* If you decide to cancel your subscription, you will be charged $: " . number_format($md_price/100, 2) . " on " . $subs_date . ". After that, your subscription will be cancelled.</p>";
                    }
                }
                
            } else {
                // Service is in only one subscription - use existing logic
                $target_subscription = $md_has_service ? $md_subscription : $msl_subscription;
                $subscription_type = $md_has_service ? 'MD' : 'MSL';
                
                $all_services = array_merge([$target_subscription->main_service], 
                    !empty($target_subscription->addons_services) ? explode(',', $target_subscription->addons_services) : []);
                
                $is_main_service = ($service_to_cancel == $target_subscription->main_service);
                $total_services = count($all_services);
                
                if($is_main_service) {
                    if($total_services == 1) {
                $html .= "<p>If you decide to cancel your subscription, you will have one more charge on " . $subs_date . ". After that, your subscription will be cancelled.</p>";
                    } else {
                        $remaining_services = array_filter($all_services, function($service) use ($service_to_cancel) {
                            return $service != $service_to_cancel;
                        });
                        $new_main_service = reset($remaining_services);
                        $new_main_name = $this->getServiceDisplayName($new_main_service);
                        
                        $html .= "<p>If you decide to cancel your subscription, you will have one more charge on " . $subs_date . ". After that, your subscription will be cancelled.</p>";
                        $html .= "<p>Your " . $this->getServiceDisplayName($service_to_cancel) . " subscription is the main one. That characteristic will be transferred to " . $new_main_name . ", so that one will go from $" . number_format($this->getServicePrice($target_subscription, $new_main_service, $subscription_type)/100, 2) . " to $" . number_format($this->getNewServicePrice($target_subscription, $subscription_type, $new_main_service, true)/100, 2) . " on " . $subs_date . ".</p>";
                    }
                } else {
                    // Service is addon - show price reduction scenario
                    $current_total = $this->calculateCurrentTotal($target_subscription, $subscription_type);
                    if($subscription_type == 'MSL') {
                        // For MSL, calculate the new total
                        $new_total = $this->calculateNewTotalAfterCancellation($target_subscription, $subscription_type, $service_to_cancel);
                        
                        if($new_total != $current_total) {
                            // Price will change, show the reduction
                            $html .= "<p>* If you decide to cancel your subscription, you will have one more charge on " . $subs_date . ". After that, your software subscription will be reduced from $" . number_format($current_total/100, 2) . " to $" . number_format($new_total/100, 2) . ".</p>";
                        } else {
                            // Price won't change (e.g., 6 services to 5 services, both $79.95)
                            $html .= "<p>* Your software subscription will remain at $" . number_format($current_total/100, 2) . ".</p>";
                        }
                    } else {
                        // For MD, use the specific service price
                        $service_price = $this->getServicePrice($target_subscription, $service_to_cancel, $subscription_type);
                        $new_total = $current_total - $service_price;
                        
                        $html .= "<p>* If you decide to cancel your subscription, you will be charged $:" . number_format($service_price/100, 2) . " on " . $subs_date . ". After that, your subscription will be reduced from $" . number_format($current_total/100, 2) . " to $" . number_format($new_total/100, 2) . ".</p>";
                    }
                }
            }
        } else {
            // Service is already cancelled
            $cancel_info = !empty($cancel_md) ? $cancel_md : $cancel_msl;
            
            $html .= "<p>Your subscription to " . $this->getServiceDisplayName($service_to_cancel) . " has been cancelled. You can continue using the app until " . date('m/d/Y', strtotime($cancel_info->date_payment->i18nFormat('yyyy-MM-dd'))) . ".</p>";
            $html .= "<p>If you decide to undo this, click here:</p>";
        }
        
        return $html;
    }

    /**
     * Get price for a specific service in a subscription
     */
    private function getServicePrice($subscription, $service, $subscription_type) {
        if($subscription_type == 'MSL') {
            // For MSL, divide total by number of services
            $all_services = array_merge([$subscription->main_service], 
                !empty($subscription->addons_services) ? explode(',', $subscription->addons_services) : []);
            $service_count = count($all_services);
            $service_count = min($service_count, 5);
            
            if($service_count > 0) {
                return $this->prices_msl[$service_count - 1] / $service_count;
            }
            return 0;
        } else {
            // For MD, use payment_details or calculate
            if(!empty($subscription->payment_details)) {
                $payment_details = json_decode($subscription->payment_details, true);
                if(is_array($payment_details) && isset($payment_details[$service])) {
                    return $payment_details[$service];
                }
            }
            
            // Fallback: main service costs more, addons cost less
            if($service == $subscription->main_service) {
                return $this->total_subscription_ot_main_md;
            } else {
                return $this->total_subscription_ot_addon_md;
            }
        }
    }

    /**
     * Calculate new total after cancelling a service
     */
    private function calculateNewTotalAfterCancellation($subscription, $subscription_type, $service_to_cancel) {
        if($subscription_type == 'MSL') {
            $all_services = array_merge([$subscription->main_service], 
                !empty($subscription->addons_services) ? explode(',', $subscription->addons_services) : []);
            $remaining_services = array_filter($all_services, function($service) use ($service_to_cancel) {
                return $service != $service_to_cancel;
            });
            
            $service_count = count($remaining_services);
            $service_count = min($service_count, 5);
            
            if($service_count > 0) {
                return $this->prices_msl[$service_count - 1];
            }
            return 0;
        } else {
            // For MD, subtract the service price
            $current_total = $this->calculateCurrentTotal($subscription, $subscription_type);
            $service_price = $this->getServicePrice($subscription, $service_to_cancel, $subscription_type);
            return $current_total - $service_price;
        }
    }

    /**
     * Get new price for a service after it becomes main service
     */
    private function getNewServicePrice($subscription, $subscription_type, $service, $is_new_main) {
        if($subscription_type == 'MSL') {
            // For MSL, if it becomes main, it gets the main service price
            if($is_new_main) {
                return $this->total_subscription_ot_main_msl;
            } else {
                return $this->total_subscription_ot_addon_msl;
            }
        } else {
            // For MD, similar logic
            if($is_new_main) {
                return $this->total_subscription_ot_main_md;
            } else {
                return $this->total_subscription_ot_addon_md;
            }
        }
    }

    public function unsubscribe_info(){
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

        $sub_id = get('subscription_id', '');

        if(empty($sub_id)){
            $this->message('Invalid subscription id.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $ent_subscription = $this->DataSubscriptions->find()
        ->where([
            'DataSubscriptions.id' => $sub_id,
            'DataSubscriptions.user_id' => USER_ID,
            'DataSubscriptions.deleted' => 0
        ])
        ->order(['DataSubscriptions.created' => 'DESC'])
        ->first();

        if(empty($ent_subscription)){
            $this->message('Subscription not found.');
            return;
        }

        $now = date('Y-m-d');

        switch ($ent_subscription->monthly) {
            case '1':
                $text = "Your subscription type is: Monthly\n\nYou may cancel your subscription on the displayed date by clicking the button below with a 15-day notice";
                $subs_date = date('Y-m-d', strtotime($ent_subscription->created->i18nFormat('yyyy-MM-dd')."+ 1 month"));
                $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date."- 15 days")) ? true : false;
                break;
            case '3':
                $text = "The discount package which included the product and service that you selected was for 3 months.\n\nYou may cancel your subscription on the displayed date by clicking the button below.\n\n*You cannot cancel prior to this date because you received the product as part of the package. ";
                $subs_date = date('Y-m-d', strtotime($ent_subscription->created->i18nFormat('yyyy-MM-dd')."+ 3 month"));
                $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date."- 15 days")) ? true : false;
                break;
            case '12':
                $text = "The discount package which included the product and service that you selected was for 12 months.\n\nYou may cancel your subscription on the displayed date by clicking the button below.\n\n*You cannot cancel prior to this date because you received the product as part of the package. ";
                $subs_date = date('Y-m-d', strtotime($ent_subscription->created->i18nFormat('yyyy-MM-dd')."+ 12 month"));
                $allow_unsubscribe = $now >= date('Y-m-d', strtotime($subs_date."- 15 days")) ? true : false;
                break;
            default:
                $text = "";
                $subs_date = "";
                $allow_unsubscribe = false;
                break;
        }

        $this->set('allow_unsubscribe', $allow_unsubscribe);
        $this->set('text', $text);
        $this->set('unsubscription_date', date('m-d-Y',strtotime($subs_date)));
        $this->success();
    }

    public function unsubscribe_from_service(){
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

        $subscribe_service = get('subscribe_service', '');
        if(empty($subscribe_service)){
            $this->message('Invalid subscribe type.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $ent_subscriptions = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->all();
        
        if(empty($ent_subscriptions)){
            $this->message('Subscription not found.');
            return;
        }

        $set_for_cancel = array();
        foreach($ent_subscriptions as $ent_subscription){

            if(stripos($ent_subscription->main_service, $subscribe_service) !== false || stripos($ent_subscription->addons_services, $subscribe_service) !== false){
                $set_for_cancel[] = $ent_subscription;
            }
            /*$current_subscription = $ent_subscription->subscription_type;
            $subscription_type    = stripos($current_subscription, 'MD') !== false 
                ? 'MD'
                : 'MSL';
            
            $current_services = $this->get_services_subscription($subscription_type, $current_subscription);
            if(in_array($subscribe_service, $current_services)){
                $set_for_cancel[] = $ent_subscription;
            }       */     
        }

        if(empty($set_for_cancel)){
            $this->message("Can't cancel this subscription add on.");
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        foreach($set_for_cancel as $ent_subscription){
            $entCancelled = $this->DataSubscriptionCancelled
                ->find()
                ->where([
                    'DataSubscriptionCancelled.subscription_id' => $ent_subscription->id,
                    'DataSubscriptionCancelled.deleted' => 0
                ])
                ->order(['DataSubscriptionCancelled.id' => 'DESC'])
                ->first();
            $sub_type = stripos($ent_subscription->subscription_type, 'MD') !== false 
                ? 'MD'
                : 'MSL';
            
            if(empty($entCancelled)){

                $nextPay = '';
                $entPayment =  $this->DataSubscriptionPayments->find()->where(['DataSubscriptionPayments.subscription_id' => $ent_subscription->id])->order(['DataSubscriptionPayments.id' => 'DESC'])->first();
                $today = date('Y-m-d H:i:s'); 
                if(!empty($entPayment)){
                    $nextPay = date('Y-m-d H:i:s', strtotime($entPayment->created->i18nFormat('dd-MM-yyyy')."+ 2 month"));                    
                }else{
                    $nextPay = date('Y-m-d H:i:s', strtotime($ent_subscription->created->i18nFormat('dd-MM-yyyy')."+ 2 month"));
                }

                $array_save = array(
                    'date_cancelled'        => date('Y-m-d H:i:s'),
                    'date_payment'          => $nextPay,
                    'subscription_id'       => $ent_subscription->id,
                    'reason'                => 'User cancelled subscription add on. ' . $subscribe_service,
                    'created'               => date('Y-m-d H:i:s'),
                    'modified'              => date('Y-m-d H:i:s'),
                    'services_unsubscribe'  => $subscribe_service,
                    'deleted'               => 0
                );
                $c_entity = $this->DataSubscriptionCancelled->newEntity($array_save);
                $this->DataSubscriptionCancelled->save($c_entity);
                $this->send_email_unsubscribe_to_suport($sub_type, $subscribe_service, $ent_subscription->created->i18nFormat('MM-dd-yyyy'), date('m-d-Y', strtotime($nextPay)));
            }else{
                $s = empty($entCancelled->services_unsubscribe)
                    ? ''
                    : $entCancelled->services_unsubscribe;

                $services_unsubscribe = explode(',', $s);
                if(!in_array($subscribe_service, $services_unsubscribe)){
                    $services_unsubscribe[] = $subscribe_service;
                }

                $entCancelled->services_unsubscribe = implode(',', $services_unsubscribe);
                $entCancelled->modified = date('Y-m-d H:i:s');
                
                $this->DataSubscriptionCancelled->save($entCancelled);
                $this->send_email_unsubscribe_to_suport($sub_type, $subscribe_service, $ent_subscription->created->i18nFormat('MM-dd-yyyy'), $entCancelled->date_payment->i18nFormat('MM-dd-yyyy'));
            }                        
        }        

        if(!empty($set_for_cancel)){            
            $Main = new MainController();
            $Main->sendUnsubscriptionEmail('EMAIL_SUBSCRIPTION_CANCELLATION_REQUEST', USER_EMAIL, 'Subscription Cancellation Request', $subscribe_service);
        }

        $this->success();
    }

    public function get_services_subscription($subscription_type,$current_subscription){   

       
        $ss = "SUBSCRIPTION". $subscription_type;
        $temporal_type = str_replace($ss, '', $current_subscription);

        $sf   = "FILLERS";
        $sn   = "NEUROTOXINS";
        $sivt = "IV THERAPY";

        switch ($temporal_type) {
            case '': 
                return [$sn];               // ONLY NEUROTOXINS
            case 'IVT':     
                return [$sivt];             // ONLY IV THERAPY
            case 'FILLERS': 
                return [$sf];               // ONLY FILLERS
            case '+IVT': 
                return [$sn, $sivt];        // NEUROTOXINS + IV THERAPY
            case '+FILLERS': 
                return [$sn, $sf];          // NEUROTOXINS + FILLERS
            case 'IVTFILLERS': 
                return [$sivt, $sf];        // IV THERAPY + FILLERS
            case '+IVT+FILLERS':
                return [$sn, $sivt, $sf];   // NEUROTOXINS + IV THERAPY + FILLERS
        }

        return false;
    }

    public function get_upgraded_subscription(
        $subscription_type,
        $current_subscription,
        $subscription_upgrade
    ){
        $services = $this->get_services_subscription($subscription_type, $current_subscription);
        $services[] = $subscription_upgrade;
        return $this->get_subscription_by_array($subscription_type, $services);
    }

    public function get_downgraded_subscription(
        $subscription_type,
        $current_subscription,
        $arr_subscription_downgrade
    ){
        $services = $this->get_services_subscription($subscription_type, $current_subscription);
        foreach($arr_subscription_downgrade as $subscription_downgrade){            
            $key = array_search($subscription_downgrade, $services);
            if($key !== false){
                unset($services[$key]);
            }
        }
        return $this->get_subscription_by_array($subscription_type, $services);
    }

    public function get_subscription_by_array(
        $subscription_type,
        $array_services
    ){
        $sf   = "FILLERS";
        $sn   = "NEUROTOXINS";
        $sivt = "IV THERAPY";

        $subscription = "SUBSCRIPTION" . $subscription_type;
        $has_neurotoxins = in_array($sn, $array_services);
        $has_fillers = in_array($sf, $array_services);
        $has_ivt = in_array($sivt, $array_services); 

        if($has_neurotoxins && $has_fillers && $has_ivt){
            return $subscription . "+IVT+FILLERS";
        }else if($has_neurotoxins && $has_fillers){
            return $subscription . "+FILLERS";
        }else if($has_neurotoxins && $has_ivt){
            return $subscription . "+IVT";
        }else if ($has_ivt && $has_fillers){
            return $subscription . "IVTFILLERS";
        }else{
            if($has_neurotoxins){
                return $subscription;
            }
            if($has_fillers){
                return $subscription . 'FILLERS';
            }
            if($has_ivt){
                return $subscription . 'IVT';
            }
            return false;
        }

        return $subscription;
    }

    /**
     * Normaliza el string de addons_services eliminando comas al inicio/final,
     * valores vacíos, duplicados y espacios en blanco
     * 
     * @param string|null $addons_services String de servicios separados por comas
     * @return string String limpio o vacío si no hay servicios válidos
     */
    private function normalize_addons_services($addons_services) {
        if (empty($addons_services)) {
            return '';
        }
        
        // Dividir por comas
        $services = explode(',', $addons_services);
        
        // Limpiar: eliminar espacios, valores vacíos y duplicados
        $services = array_map('trim', $services);
        $services = array_filter($services, function($service) {
            return !empty($service);
        });
        $services = array_unique($services);
        
        // Unir con comas y retornar
        return implode(',', $services);
    }

    public function set_addons_services(
        $subscription,
        $cat_service
    ) {
        if (empty($subscription->addons_services)) {
            return $this->normalize_addons_services($cat_service);
        } else {
            $combined = $subscription->addons_services . ',' . $cat_service;
            return $this->normalize_addons_services($combined);
        }
    }

    public function get_medical_director(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.SysUserAdmin');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user_id])->first();
        $md_id = (int)$ent_user->md_id;
        if ($md_id === 0) {
            $md_id = $this->SysUserAdmin->getAssignedDoctorInjector((int)$user_id);
        }

        $ent_medical_director = $this->SysUserAdmin->find()->where(['SysUserAdmin.id' => $md_id])->first();

        return $ent_medical_director !== null ? $ent_medical_director->name : '';
    }

    public function get_total_subscription(
        $subscription_type,
        $level,
        $subscription_base = 0
    ){

        if($subscription_base > 0){
            $base_price = $subscription_base;
        } else{
            $base_price = $subscription_type == 'MSL'
                ? 3995
                : 17900;
        }

        $add_on = $subscription_type == 'MSL'
            ? 2000
            : 8500;        

        $add_on = $add_on * ($level - 1);    

        $updated_total = $base_price + $add_on;
        
        return $updated_total;
    }

    public function has_service_subscription(
        $user_id,
        $service,
        $subscription_type        
    ){

        if($service=="BASIC NEUROTOXINS"){
            $service = "NEUROTOXINS";//parche para que aparezcan los servicios de neurotoxins
        }else if($service=="ADVANCED NEUROTOXINS"){
            $service = "NEUROTOXINS";
        }else if($service=="ADVANCED TECHNIQUES NEUROTOXINS"){
            $service = "NEUROTOXINS";
        }
        $this->loadModel('SpaLiveV1.DataSubscriptions');

      
        
        $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => $user_id,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%' . $subscription_type . '%'
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();

            

        if(empty($ent_subscription)){
            return false;
        }

       
       
        $new_services_review = [
            'FILLERS',
            'LEVEL IV',
            'IV THERAPY',
            'NEUROTOXINS',
            'FILLERS'
        ];
        

        if (in_array($service, $new_services_review, true) && strpos($ent_subscription->payment_details, $service) !== false) {
            return true;
        }

        return false;
        
        // var_dump($ent_subscription); exit;

        $current_services = $this->get_services_subscription($subscription_type, $ent_subscription->subscription_type);

        return in_array($service, $current_services);
    }

    public function get_subscription_msl(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        
        $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => $user_id,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%MSL%'
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();

        return $ent_subscription;
    }

    public function get_subscription_md(
        $user_id
    ){
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        
        $ent_subscription = $this->DataSubscriptions->find()
            ->where([
                'DataSubscriptions.user_id' => $user_id,
                'DataSubscriptions.status'  => 'ACTIVE',
                'DataSubscriptions.deleted' => 0,
                'DataSubscriptions.subscription_type LIKE' => '%MD%'
            ])
            ->order(['DataSubscriptions.created' => 'DESC'])
            ->first();

        return $ent_subscription;
    }
    
    public function send_email_unsubscribed_injector(){
        // Create a DateTime object for the current date and time
        $nowE = new DateTime();        
        // Set the day of the week to Friday (5)
        //$start_date = $nowS->modify('last Monday');
        $end_date = $nowE->modify('last Sunday');
        $end_date = $end_date->format('Y-m-d 23:59:59');
        $start_date = $nowE->modify('-6 day');
        $start_date = $start_date->format('Y-m-d 00:00:00');    

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $_where = ['DataSubscriptions.deleted' => 0, 'User.deleted' => 0, 'User.active' => 1,'DataSubscriptions.status'=>'CANCELLED'/*,'DSC.date_cancelled >=' => "$start_date"*/, 'DSC.date_cancelled <=' => "$end_date"];        
        $order = ['DSC.date_cancelled' => 'DESC'];        
        $fields = ['DataSubscriptions.id', 'DataSubscriptions.user_id','DataSubscriptions.status','DataSubscriptions.total','DataSubscriptions.promo_code', 'User.name', 'User.lname', 'User.email','DataSubscriptions.created', 'DataSubscriptions.type',
        'DSC.date_cancelled', 'DSC.date_payment', 'DSC.reason', 'DataSubscriptions.comments'/*, 'CC.name', 'User.md_id','Admin.name', 'School.id'*/];

        $having = [];
        $_group = [];                                                                   
        $ent_trainings = $this->DataSubscriptions->find()->select($fields)
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
            'DSC' => ['table' => 'data_subscription_cancelled', 'type' => 'LEFT', 'conditions' => 'DSC.subscription_id = DataSubscriptions.id'],            
        ])->where($_where)->group($_group)->having($having)->order($order)->toList();
        $users = [];
        $i=0;        
        // File path for the text file
        $filePath = TMP .'injector_unsubscribed.csv';        
        // Open the file for writing
        $file = fopen($filePath, 'w');      
       //start day report
        //$this->log(__LINE__ . ' ' . json_encode($ent_trainings[0]));
        if(isset($ent_trainings[0]['DSC']['date_cancelled']) ){                                                    
            $nowE = new DateTime($ent_trainings[0]['DSC']['date_cancelled']);
            $dayofweek = $nowE->format('D'); //$this->log(__LINE__ . ' ' . json_encode($nowE->format('D')));
            if($dayofweek != 'Sun'){                
                $end_date = $nowE->modify('next Sunday');;
                $end_date = $end_date->format('Y-m-d 23:59:59');
                $start_date = $nowE->modify('-6 day');
                $start_date = $start_date->format('Y-m-d 00:00:00');    
            }
            //$this->log(__LINE__ . 'date_cancelled ' . json_encode($ent_trainings[0]['DSC']['date_cancelled']));
            //$this->log(__LINE__ . 'end_date ' . json_encode($end_date));
            //$this->log(__LINE__ . 'start_date ' . json_encode($start_date));
        }

        $start = true;
        foreach($ent_trainings as $row) {
            //$this->log(__LINE__ . ' ' . json_encode($row));
            if(in_array($row->user_id, $users)){            
                continue;
            }
            if(empty($row['DSC']['date_cancelled'])){
                continue;
            }
            
            $users[] = $row->user_id;                                              
            if($start){
                $datee=date_create($end_date);
                $dates=date_create($start_date);
                fwrite($file, 'From: '.date_format($datee,"m/d/Y"). ' to:'. date_format($dates,"m/d/Y") . PHP_EOL);
                $start = false;    
            }
            $isDateInRange = $this->isDateInRange($row['DSC']['date_cancelled'], $start_date, $end_date);
            if ($isDateInRange) {             
                 fwrite($file, $row->user_id.','.$row['User']['name']." ".$row['User']['lname'].",".$row['User']['email'].",".$row['DSC']['date_cancelled'] . PHP_EOL);            
            }            
            
            if(!$isDateInRange && $row['DSC']['date_cancelled'] <= $start_date){
                //nuevo periodo                
                $nowE = new DateTime($row['DSC']['date_cancelled']);
                $dayofweek = $nowE->format('D'); //$this->log(__LINE__ . ' ' . json_encode($nowE->format('D')));
                if($dayofweek != 'Sun'){                
                    $end_date = $nowE->modify('next Sunday');;
                    $end_date = $end_date->format('Y-m-d 23:59:59');
                    $start_date = $nowE->modify('-6 day');
                    $start_date = $start_date->format('Y-m-d 00:00:00');
                    //$this->log(__LINE__ . 'date_cancelled ' . json_encode($row['DSC']['date_cancelled']));
                    //$this->log(__LINE__ . 'end_date ' . json_encode($end_date));
                    //$this->log(__LINE__ . 'start_date ' . json_encode($start_date));    
                }else{
                    $end_date = $nowE;
                    $end_date = $end_date->format('Y-m-d 23:59:59');
                    $start_date = $nowE->modify('-6 day');
                    $start_date = $start_date->format('Y-m-d 00:00:00');
                    //$this->log(__LINE__ . 'date_cancelled ' . json_encode($row['DSC']['date_cancelled']));
                    //$this->log(__LINE__ . 'end_date ' . json_encode($end_date));
                    //$this->log(__LINE__ . 'start_date ' . json_encode($start_date));
                }
                $datee=date_create($end_date);
                $dates=date_create($start_date);
                fwrite($file, 'From: '.date_format($datee,"m/d/Y"). ' to:'. date_format($dates,"m/d/Y") . PHP_EOL);                        
                
                $isDateInRange = $this->isDateInRange($row['DSC']['date_cancelled'], $start_date, $end_date);
                if ($isDateInRange) {                    
                    fwrite($file, $row->user_id.','.$row['User']['name']." ".$row['User']['lname'].",".$row['User']['email'].",".$row['DSC']['date_cancelled'] . PHP_EOL);            
               }
            }
            /*$i++;
            if($i==50){
                break;
            }*/
            
        }
        // Close the CSV file
        fclose($file);
        
        $subject = 'List injector unsubscribed' ;
        $isDev = env('IS_DEV', false);

        if(!$isDev){
            $to = 'francisco@advantedigital.com';
        }else{
            $to = 'francisco@advantedigital.com,cora@advantedigital.com';
        }
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $to,
            'subject' => $subject,
            'html'    => 'List injector unsubscribed.',            
            'attachment[1]' => curl_file_create(TMP .  "injector_unsubscribed.csv", 'text/csv', 'injector_unsubscribed.csv'),
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

        
        unlink(TMP . 'injector_unsubscribed.csv');
    }

    // Function to validate if a date is within a range
    function isDateInRange($dateToCheck, $startDate, $endDate) {
        $dateToCheck = new DateTime($dateToCheck);
        $startDate = new DateTime($startDate);
        $endDate = new DateTime($endDate);

        return $dateToCheck >= $startDate && $dateToCheck <= $endDate;
    }

    public function month_res($month, $year)
	{
		if ($month == 1) {
			$month = 12;
			$year = $year - 1;
		} else {
			$month = $month - 1;
		}
		return array($month, $year);
	}


    public function sales_month_v2($from, $to)
	{
		$this->set('from', $from);
		$this->set('to', $to);
		$html = array();
		$this->loadModel('DataPreRegister');
		$this->loadModel('DataPayment');
	
		$str_query_find = "SELECT 
					IFNULL(SUM(DP.total),0) amount, 
					COUNT(DP.id) total,
					'BASIC_COURSE_STRIPE' as types
				FROM data_payment DP 
					JOIN sys_users SU ON SU.id = DP.id_from 
					LEFT JOIN data_payment P ON P.uid = DP.uid 
					#AND P.total = DP.total 
					AND P.type = 'REFUND'
				WHERE
					#DP.payment_platform = 'stripe' AND 
					DP.payment <> '' AND
					DP.is_visible = 1 AND
					DP.promo_code <> 'DSCT99SPA' AND
					DP.promo_code != 'DSCT9934MYSPA' AND
					SU.name NOT LIKE '%Tester%' AND
					SU.name NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%Tester%' AND
					SU.lname NOT LIKE '%test%' AND
					DP.type IN ('CI REGISTER', 'BASIC COURSE') AND
					DP.payment <> '' AND
					DP.prod = 1 AND
					DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
					#AND P.type IS NULL
    
    	UNION

        SELECT 
			SUM(total) amount,
			count(total) total,
			'BASIC_COURSE_SEZZLE' types
				FROM data_payment DP 
				JOIN sys_users SU ON SU.id = DP.id_from 
				WHERE 
					DP.payment_platform = 'sezzle' AND
					DP.payment <> '' AND
					DP.is_visible = 1 AND
					DP.promo_code <> 'DSCT99SPA' AND
					DP.promo_code != 'DSCT9934MYSPA' AND
					SU.name NOT LIKE '%Tester%' AND
					SU.name NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%Tester%' AND
					SU.lname NOT LIKE '%test%' AND
					DP.type IN ('CI REGISTER', 'BASIC COURSE') AND
					DP.payment <> '' AND
					DP.prod = 1 AND
					DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		UNION

			SELECT 
				IFNULL(SUM(DP.total), 0) amount, 
				COUNT(DP.id) total,
				'BASIC_COURSE_AFFIRM' types
			FROM data_payment DP 
				JOIN sys_users SU ON SU.id = DP.id_from 
				LEFT JOIN data_payment P ON P.uid = DP.uid AND P.total = DP.total AND P.type = 'REFUND'
			WHERE 
				DP.payment_platform = 'affirm' AND
				DP.payment <> '' AND
				DP.is_visible = 1 AND
				DP.promo_code <> 'DSCT99SPA' AND
				DP.promo_code != 'DSCT9934MYSPA' AND
				SU.name NOT LIKE '%Tester%' AND
				SU.name NOT LIKE '%test%' AND
				SU.lname NOT LIKE '%Tester%' AND
				SU.lname NOT LIKE '%test%' AND
				DP.type IN ('CI REGISTER', 'BASIC COURSE') AND
				DP.payment <> '' AND
				DP.prod = 1 AND
				DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
				#AND P.type IS NULL
		
        UNION

			SELECT COUNT(SU.id) amount,
			COUNT(SU.id) total,
			'Subscribed_from_other_school' types	
			FROM sys_users SU
			JOIN data_courses DC ON DC.user_id = SU.id AND DC.deleted = 0 AND DC.status = 'DONE'
			JOIN cat_courses CC ON CC.id = DC.course_id AND CC.deleted = 0 AND CC.type IN ('NEUROTOXINS BASIC', 'BOTH NEUROTOXINS')
			JOIN data_subscriptions DS ON DS.user_id = SU.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type = 'SUBSCRIPTIONMD'
			WHERE SU.deleted = 0 AND SU.name NOT LIKE '%test%' AND SU.lname NOT LIKE '%test%' AND SU.is_test = 0 AND SU.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		
        UNION
        
        SELECT  COUNT(DPA.id) amount,
								SUM(DPA.total) total	,
           'Total_paid_from_the_advanced_courses' types	
		FROM data_payment DPA 
			JOIN sys_users SU ON SU.id = DPA.id_from
			LEFT JOIN data_payment P ON P.uid = DPA.uid AND P.total = DPA.total AND P.type = 'REFUND'
		WHERE
			SU.name NOT LIKE '%test%' AND
			SU.name NOT LIKE '%Tester%' AND
			SU.lname NOT LIKE '%test%' AND
			SU.lname NOT LIKE '%Tester%' AND
			SU.is_test = 0  AND
			DPA.promo_code != 'DSCT99SPA' AND
			DPA.promo_code != 'DSCT9934MYSPA' AND
			DPA.receipt <> '' AND
			DPA.type = 'ADVANCED COURSE' AND
			DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
			#AND P.type IS NULL
		
        UNION
        
        SELECT  SUM(DPA.total) amount	,
		SUM(DPA.total) total,
		'Total_paid_from_sezzle' as types	
		FROM data_payment DPA 
		JOIN sys_users SU ON SU.id = DPA.id_from
		WHERE
			SU.name NOT LIKE '%test%' AND
			SU.name NOT LIKE '%Tester%' AND
			SU.lname NOT LIKE '%test%' AND
			SU.lname NOT LIKE '%Tester%' AND
			SU.is_test = 0  AND
			DPA.payment_platform = 'sezzle' AND
			DPA.promo_code != 'DSCT99SPA' AND
			DPA.promo_code != 'DSCT9934MYSPA' AND
			DPA.receipt <> '' AND
			DPA.type = 'ADVANCED COURSE' AND
			DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		
        UNION
        
        SELECT 
		SUM(DPD.price * DPD.qty) - DPD.refunded_amount amount  ,
			count(*) total,
            'Total_paid_from_sezzle_2' types
			FROM data_purchases DP 
			JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id 
			JOIN sys_users SU ON SU.id = DP.user_id 
			JOIN cat_products CP ON CP.id = DPD.product_id 
			JOIN data_payment DPA ON DPA.uid = DP.uid 
			WHERE 
				SU.is_test = 0 AND 
				SU.name NOT LIKE '%test%' AND
				SU.name NOT LIKE '%Tester%' AND
				SU.lname NOT LIKE '%test%' AND
				SU.lname NOT LIKE '%Tester%' AND
				DPA.payment_platform = 'sezzle' AND
				DPA.is_visible = 1 AND 
				DPA.payment <> '' AND
				DPA.id_to = 0 AND
				DPA.promo_code <> 'DSCT99SPA' AND
				DPA.promo_code <> 'DSCT9934MYSPA' AND
				DPA.receipt <> '' AND
				CP.id = 44 AND 
				DP.deleted = 0 AND
				DP.payment <> '' AND
				DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		
        UNION
        
        SELECT  SUM(DPA.total) amount	,	
		COUNT(DPA.id) total,
		'Affirm_Number_of_advanced_training' as types 	
					FROM data_payment DPA 
					JOIN sys_users SU ON SU.id = DPA.id_from
					LEFT JOIN data_payment P ON P.uid = DPA.uid AND P.total = DPA.total AND P.type = 'REFUND'
					WHERE
					DPA.payment_platform = 'affirm' AND
					SU.name NOT LIKE '%test%' AND
					SU.name NOT LIKE '%Tester%' AND
					SU.lname NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%Tester%' AND
					SU.is_test = 0  AND
					DPA.promo_code != 'DSCT99SPA' AND
					DPA.promo_code != 'DSCT9934MYSPA' AND
					DPA.receipt <> '' AND
					DPA.type = 'ADVANCED COURSE' AND
					DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
					#AND P.type IS NULL
		
		UNION

			SELECT count(SU.id)  amount, 
			COUNT(SU.id) total, 
			'Subscribed_from_other_school_ADVANCED' as types 
			FROM sys_users SU
			JOIN data_courses DC ON DC.user_id = SU.id AND DC.deleted = 0 AND DC.status = 'DONE'
			JOIN cat_courses CC ON CC.id = DC.course_id AND CC.deleted = 0 AND CC.type IN ('NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS')
			JOIN data_subscriptions DS ON DS.user_id = SU.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type = 'SUBSCRIPTIONMD'
			WHERE SU.deleted = 0 AND SU.name NOT LIKE '%test%' AND SU.lname NOT LIKE '%test%' AND SU.is_test = 0 AND SU.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'

		UNION
        
        SELECT  COUNT(DPA.id) amount,
								SUM(DPA.total) total	,
           'Total_paid_from_the_advanced_techniques' types	
		FROM data_payment DPA 
			JOIN sys_users SU ON SU.id = DPA.id_from
			LEFT JOIN data_payment P ON P.uid = DPA.uid AND P.total = DPA.total AND P.type = 'REFUND'
		WHERE
			SU.name NOT LIKE '%test%' AND
			SU.name NOT LIKE '%Tester%' AND
			SU.lname NOT LIKE '%test%' AND
			SU.lname NOT LIKE '%Tester%' AND
			SU.is_test = 0  AND
			DPA.promo_code != 'DSCT99SPA' AND
			DPA.promo_code != 'DSCT9934MYSPA' AND
			DPA.receipt <> '' AND
			DPA.type = 'ADVANCED TECHNIQUES COURSE' AND
			DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
			#AND P.type IS NULL
		
        UNION
        
        SELECT  SUM(DPA.total) amount	,
		SUM(DPA.total) total,
		'Total_paid_from_sezzle_advanced_techniques' as types	
		FROM data_payment DPA 
		JOIN sys_users SU ON SU.id = DPA.id_from
		WHERE
			SU.name NOT LIKE '%test%' AND
			SU.name NOT LIKE '%Tester%' AND
			SU.lname NOT LIKE '%test%' AND
			SU.lname NOT LIKE '%Tester%' AND
			SU.is_test = 0  AND
			DPA.payment_platform = 'sezzle' AND
			DPA.promo_code != 'DSCT99SPA' AND
			DPA.promo_code != 'DSCT9934MYSPA' AND
			DPA.receipt <> '' AND
			DPA.type = 'ADVANCED TECHNIQUES COURSE' AND
			DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		
        UNION
        
        SELECT 
		SUM(DPD.price * DPD.qty) - DPD.refunded_amount amount  ,
			count(*) total,
            'Total_paid_from_sezzle_3' types
			FROM data_purchases DP 
			JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id 
			JOIN sys_users SU ON SU.id = DP.user_id 
			JOIN cat_products CP ON CP.id = DPD.product_id 
			JOIN data_payment DPA ON DPA.uid = DP.uid 
			WHERE 
				SU.is_test = 0 AND 
				SU.name NOT LIKE '%test%' AND
				SU.name NOT LIKE '%Tester%' AND
				SU.lname NOT LIKE '%test%' AND
				SU.lname NOT LIKE '%Tester%' AND
				DPA.payment_platform = 'sezzle' AND
				DPA.is_visible = 1 AND 
				DPA.payment <> '' AND
				DPA.id_to = 0 AND
				DPA.promo_code <> 'DSCT99SPA' AND
				DPA.promo_code <> 'DSCT9934MYSPA' AND
				DPA.receipt <> '' AND
				CP.id = 178 AND 
				DP.deleted = 0 AND
				DP.payment <> '' AND
				DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		
        UNION
        
        SELECT  SUM(DPA.total) amount	,	
		COUNT(DPA.id) total,
		'Affirm_Number_of_advanced_techniques_training' as types 	
					FROM data_payment DPA 
					JOIN sys_users SU ON SU.id = DPA.id_from
					LEFT JOIN data_payment P ON P.uid = DPA.uid AND P.total = DPA.total AND P.type = 'REFUND'
					WHERE
					DPA.payment_platform = 'affirm' AND
					SU.name NOT LIKE '%test%' AND
					SU.name NOT LIKE '%Tester%' AND
					SU.lname NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%Tester%' AND
					SU.is_test = 0  AND
					DPA.promo_code != 'DSCT99SPA' AND
					DPA.promo_code != 'DSCT9934MYSPA' AND
					DPA.receipt <> '' AND
					DPA.type = 'ADVANCED TECHNIQUES COURSE' AND
					DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
					#AND P.type IS NULL
		
		UNION

		SELECT 						
		IFNULL(SUM(DP.total), 0) amount, 																					
		COUNT(DP.id) total 		,
		'WEIGHT_LOSS_affirm' as types 		
		FROM data_payment DP 
		JOIN sys_users SU ON SU.id = DP.id_from
		LEFT JOIN data_payment P ON P.uid = DP.uid AND P.total = DP.total AND P.type = 'REFUND'
		WHERE DP.payment <> '' AND 
		DP.is_visible = 1 AND 
		DP.promo_code <> 'DSCT99SPA' AND 
		SU.name NOT LIKE '%test%' AND 
		SU.is_test = 0 AND 
		DP.receipt <> '' AND 
		DP.type = 'WEIGHT LOSS' AND 
		DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DP.payment_platform = 'affirm'
		#AND P.type IS NULL

        UNION

        
		SELECT 						
		IFNULL(SUM(DP.total), 0) amount, 																					
		COUNT(DP.id) total 	,
		'WEIGHT_LOSS_affirm_two' as types 			
		FROM data_payment DP 
		JOIN sys_users SU ON SU.id = DP.id_from
		LEFT JOIN data_payment P ON P.uid = DP.uid AND P.total = DP.total AND P.type = 'REFUND'
		WHERE DP.payment <> '' AND 
		DP.is_visible = 1 AND 
		DP.promo_code <> 'DSCT99SPA' AND 
		SU.name NOT LIKE '%test%' AND 
		SU.is_test = 0 AND 
		DP.receipt <> '' AND 
		DP.type = 'WEIGHT LOSS' AND 
		DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DP.payment_platform = 'affirm'
		AND P.type IS NOT NULL
		
		
		
		
		";
		$ent_total_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc'); 		
		
		$today = date("F", strtotime($from));

		//$html .= '<h1>' . strtoupper($today) . ' <br> <br> Purchases</h1>';
 
	
		
		$arr_totales = [];
		foreach ($ent_total_query as $key => $value) {
			$arr_totales[$value['types']] = $value;
		}
		$html['from']= $from;
        $html['to']= $to;
		$cant_refund = '';
		$amount_refund = '';	
		
		if(isset($arr_totales['BASIC_COURSE_STRIPE'])){
			$amount = $arr_totales['BASIC_COURSE_STRIPE']['amount'] / 100;
			$total = $arr_totales['BASIC_COURSE_STRIPE']['total'];
			
			//$html .= '<b>Basic Courses</b><br> $  $' . number_format($amount, 2) . ' ' . $amount_refund;
            $html['Total paid from the basic courses'] = number_format($amount, 2) . ' ' . $amount_refund;
            
			//$html .= '<br># Number of basic courses: ' . $total . ' ' . $cant_refund;
            $html['Number of basic courses'] = $total . ' ' . $cant_refund;
		}
		$str_query_find = "SELECT GROUP_CONCAT(DP.id) ids,
					IFNULL(SUM(DP.total),0) amount, 
					COUNT(DP.id) total,
					SUM(if(DP.payment_platform = 'stripe', 1, 0)) AS stripe,
					SUM(if(DP.payment_platform = 'stripe', DP.total, 0)) AS sum_stripe,
					SUM(if(DP.payment_platform = 'affirm', 1, 0)) AS affirm,
					SUM(if(DP.payment_platform = 'affirm', DP.total, 0)) AS sum_affirm,
					SUM(if(DP.payment_platform = 'sezzle', 1, 0)) AS sezzle
				FROM data_payment DP 
					JOIN sys_users SU ON SU.id = DP.id_from 
					LEFT JOIN data_payment P ON P.uid = DP.uid 
					#AND P.total = DP.total 
					AND P.type = 'REFUND'
				WHERE
					#DP.payment_platform = 'stripe' AND 
					DP.payment <> '' AND
					DP.is_visible = 1 AND
					DP.promo_code <> 'DSCT99SPA' AND
					DP.promo_code != 'DSCT9934MYSPA' AND
					DP.promo_code != '$1000$' AND
					DP.promo_code != '$10000$' AND
					SU.name NOT LIKE '%Tester%' AND
					SU.name NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%Tester%' AND
					SU.lname NOT LIKE '%test%' AND
					DP.type IN ('CI REGISTER', 'BASIC COURSE') AND
					DP.payment <> '' AND
					DP.prod = 1 AND
					DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
					#AND P.type IS NULL
		";
		
		$ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');

		$str_query_find_refund = " 		SELECT 
		IFNULL(SUM(amount),0) amount, 
		COUNT(id) total
		FROM data_stripe_transfer				
		WHERE				
		date BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
		description = 'REFUND FOR CHARGE (BASIC COURSE)' AND
		status = 'succeeded' 
		AND amount >= 700
		";				
		
		$ent_query_refund = $this->DataPreRegister->getConnection()->execute($str_query_find_refund)->fetchAll('assoc');
		$total_sold_bc =0;
		$fee_stripe_bc =0;
		$fee_affirm_bc =0;
		$refund_bc =0;
		$referral_pay =0;
		$sales_team =0;
		$cant_refund = '';
		$amount_refund = '';
		if($ent_query_refund && $ent_query_refund[0]['amount'] > 0){		
			$refund_bc =$ent_query_refund[0]['amount'];
		}
		
		if ($ent_query) {			
			//fee stripe 
			$total_sold_bc = $ent_query[0]['amount'] ;
			if($total_sold_bc > 0)			{
				$res = $ent_query[0]['sum_stripe'] * .029;
				$res += ($ent_query[0]['stripe'] *30);
				$fee_stripe_bc  = round($res, 0);
			}
			//fee affirm 9.99% +30c
			if($ent_query[0]['sum_affirm'] > 0){
				$fee_affirm_bc = ($ent_query[0]['sum_affirm'] * .0999) ;
				$fee_affirm_bc += ($ent_query[0]['affirm'] * 30) ;
			}
			$ids_payment = $ent_query[0]['ids'];
			if(!empty($ids_payment)){			
				$str_dsrp ="SELECT description, sum(amount) FROM data_sales_representative_payments where payment_id  in ($ids_payment) and deleted = 0  group by description";
				$dsrp = $this->DataPreRegister->getConnection()->execute($str_dsrp)->fetchAll('assoc');
				if(!empty($dsrp)){
					foreach ($dsrp as $key => $value) {						 
						 if($value["description"] == 'PAY INVITATION' && $value["sum(amount)"]> 0){
							$referral_pay = $value['sum(amount)'];
						 }else if($value["description"] == 'SALES TEAM' && $value["sum(amount)"]> 0){
							$sales_team =$value['sum(amount)'];
						 }
					}
				}
			}			
		}

		$str_query_affirm_refund = "
			SELECT 
				IFNULL(SUM(DP.total), 0) amount, 
				COUNT(DP.id) total 
			FROM data_payment DP 
				JOIN sys_users SU ON SU.id = DP.id_from 
				LEFT JOIN data_payment P ON P.uid = DP.uid AND P.total = DP.total AND P.type = 'REFUND'
			WHERE 
				DP.payment_platform = 'affirm' AND
				DP.payment <> '' AND
				DP.is_visible = 1 AND
				DP.promo_code <> 'DSCT99SPA' AND
				DP.promo_code != 'DSCT9934MYSPA' AND
				SU.name NOT LIKE '%Tester%' AND
				SU.name NOT LIKE '%test%' AND
				SU.lname NOT LIKE '%Tester%' AND
				SU.lname NOT LIKE '%test%' AND
				DP.type IN ('CI REGISTER', 'BASIC COURSE') AND
				DP.payment <> '' AND
				DP.prod = 1 AND
				DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
				AND P.type IS NOT NULL
		";

		$affirm_query_refund = $this->DataPayment->getConnection()->execute($str_query_affirm_refund)->fetchAll('assoc');
	
		
		$amount_refund = '0';
		if ($affirm_query_refund && $affirm_query_refund[0]['amount'] > 0){			
			$amount_refund = $affirm_query_refund[0]['amount'];
		}

 		$net_bc = ($total_sold_bc - $fee_stripe_bc - $fee_affirm_bc - $refund_bc - $referral_pay - $sales_team - $amount_refund)/100;
		/*$this->log(__LINE__ . 'total_sold_bc ' . json_encode($total_sold_bc));
		 $this->log(__LINE__ . 'fee_stripe_bc ' . json_encode($fee_stripe_bc));
		 $this->log(__LINE__ . 'fee_affirm_bc ' . json_encode($fee_affirm_bc));
		 $this->log(__LINE__ . 'refund_bc ' . json_encode($refund_bc));
		 $this->log(__LINE__ . 'referral_pay ' . json_encode($referral_pay));
		 $this->log(__LINE__ . 'sales_team ' . json_encode($sales_team));
		 $this->log(__LINE__ . 'cant_refund ' . json_encode($cant_refund));
		 $this->log(__LINE__ . 'amount_refund ' . json_encode($amount_refund));*/


		if (date('Y-m',strtotime($from)) < '2023-10') {
			/**************** SEZZLE******************* */		
			if(isset($arr_totales['BASIC_COURSE_SEZZLE'])){
				
				$amount = $arr_totales['BASIC_COURSE_SEZZLE']['amount'] / 100;
				//$html .= '<br> $ Total paid from sezzle: $' . number_format($amount, 2);
                $html['Total paid from sezzle'] = number_format($amount, 2);
			}
			
		}
	
		/*************************************************** */
			
		$cant_refund = '';
		$amount_refund = '';				
		
		if(isset($arr_totales['BASIC_COURSE_AFFIRM'])){
			$amount = $arr_totales['BASIC_COURSE_AFFIRM']['amount'] / 100;
			$total = $arr_totales['BASIC_COURSE_AFFIRM']['total'];

			//$html .= '<br> # Affirm - Number of basic training: ' . $total . ' ' . $cant_refund;
            $html['Affirm - Number of basic training'] = $total . ' ' . $cant_refund;
			//$html .= '<br> $ Total paid from Affirm: $' . number_format($amount, 2) . ' ' . $amount_refund;
            $html['Total paid from Affirm'] = number_format($amount, 2) . ' ' . $amount_refund;
		}
	
		/*if(isset($arr_totales['Subscribed_from_other_school'])){
			$total = $arr_totales['Subscribed_from_other_school']['total'];

			$html .= '<br> # Subscribed from other school: ' . $total;
		}*/	 
		//$html .= '<br>$ Net Income: $' . number_format($net_bc, 2) ;
        $html['Net_Income_bc'] = number_format($net_bc, 2);

		$cant_refund = '';
		$amount_refund = '';
		if(isset($arr_totales['Total_paid_from_the_advanced_courses'])){		
			$str_advanced_courses = $arr_totales['Total_paid_from_the_advanced_courses']['amount'];
			$total_advanced_course = $arr_totales['Total_paid_from_the_advanced_courses']['total'];				
			//$html .= '<br><br><b>Advanced Courses</b><br> $ Total paid from the advanced courses: $' . number_format($total_advanced_course / 100, 2) . ' ' . $amount_refund;
            $html['Total paid from the advanced courses'] = number_format($total_advanced_course / 100, 2) . ' ' . $amount_refund;
			//$html .= '<br># Number of advanced courses: ' . $str_advanced_courses . ' ' . $cant_refund;
            $html['Number of advanced courses'] = $str_advanced_courses . ' ' . $cant_refund;
		}

		
		if (date('Y-m',strtotime($from)) < '2023-10') {
		/****************SEZZLE***************** */
		
		$total_advanced_course_sezzle=0;
		if(isset($arr_totales['Total_paid_from_sezzle'])){	
			$total_advanced_course_sezzle +=  $arr_totales['Total_paid_from_sezzle']['amount'];
		}if(isset($arr_totales['Total_paid_from_sezzle_2'])){	
			$total_advanced_course_sezzle +=  $arr_totales['Total_paid_from_sezzle_2']['amount'];
		}
		
		//$html .= '<br> $ Total paid from sezzle : $' . number_format($total_advanced_course_sezzle / 100, 2);				 
        $html['Total paid from sezzle advanced_course'] = number_format($total_advanced_course_sezzle / 100, 2);
		}
	
		/*************************************** */
		
		$cant_refund = '';
		$amount_refund = '';	
		if(isset($arr_totales['Affirm_Number_of_advanced_training'])){	
		//if ($affirm_query) {
			$amount = $arr_totales['Affirm_Number_of_advanced_training']['amount'] / 100;
			$total = $arr_totales['Affirm_Number_of_advanced_training']['total'];

			//$html .= '<br> # Affirm - Number of advanced training: ' . $total. ' ' . $cant_refund;
            $html['Affirm - Number of advanced training'] = $total. ' ' . $cant_refund;
			//$html .= '<br> $ Total paid from Affirm: $' . number_format($amount, 2) . ' ' . $amount_refund;
            $html['Total paid from Affirm advanced training'] = number_format($amount, 2) . ' ' . $amount_refund;
		}
		

		/*if(isset($arr_totales['Subscribed_from_other_school_ADVANCED'])){		
			$total = $arr_totales['Subscribed_from_other_school_ADVANCED']['total'];
			$html .= '<br> # Subscribed from other school: ' . $total;
		}*/

		// --- net			
		$str_query_find = "SELECT  

		GROUP_CONCAT(DPA.id) ids,
							IFNULL(SUM(DPA.total),0) amount, 
							COUNT(DPA.id) total,
							SUM(if(DPA.payment_platform = 'stripe', 1, 0)) AS stripe,
							SUM(if(DPA.payment_platform = 'stripe', DPA.total, 0)) AS sum_stripe,
							SUM(if(DPA.payment_platform = 'affirm', 1, 0)) AS affirm,
							SUM(if(DPA.payment_platform = 'affirm', DPA.total, 0)) AS sum_affirm,
							SUM(if(DPA.payment_platform = 'sezzle', 1, 0)) AS sezzle,				
							SUM(DPA.total) total	
				   
				FROM data_payment DPA 
					JOIN sys_users SU ON SU.id = DPA.id_from
					LEFT JOIN data_payment P ON P.uid = DPA.uid AND P.total = DPA.total AND P.type = 'REFUND'
				WHERE
					SU.name NOT LIKE '%test%' AND
					SU.name NOT LIKE '%Tester%' AND
					SU.lname NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%Tester%' AND
					SU.is_test = 0  AND
					DPA.promo_code != 'DSCT99SPA' AND
					DPA.promo_code != 'DSCT9934MYSPA' AND
					DPA.receipt <> '' AND
					DPA.type = 'ADVANCED COURSE' AND
					#DPA.created BETWEEN '2024-01-01 00:00:00' AND '2024-01-31 23:59:59';
					DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
					
		";
		
		$ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');

		$str_query_find_refund = " 		SELECT 
		IFNULL(SUM(amount),0) amount, 
		COUNT(id) total
		FROM data_stripe_transfer				
		WHERE				
		date BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
		description = 'REFUND FOR CHARGE (ADVANCED COURSE)' AND
		status = 'succeeded' 
		AND amount >= 700
		";				
		
		$ent_query_refund = $this->DataPreRegister->getConnection()->execute($str_query_find_refund)->fetchAll('assoc');
		$total_sold_bc =0;
		$fee_stripe_bc =0;
		$fee_affirm_bc =0;
		$refund_bc =0;
		$referral_pay =0;
		$sales_team =0;
		$cant_refund = '';
		$amount_refund = '';
		if($ent_query_refund && $ent_query_refund[0]['amount'] > 0){		
			$refund_bc =$ent_query_refund[0]['amount'];
		}
		
		if ($ent_query) {			
			//fee stripe 
			$total_sold_bc = $ent_query[0]['amount'] ;			
			if($total_sold_bc > 0){
				$res = $ent_query[0]['sum_stripe'] * .029;
				$res += $ent_query[0]['stripe'] * 30;
				$fee_stripe_bc  = round($res, 0);
			}
			//fee affirm 9.99% +30c
			if($ent_query[0]['sum_affirm'] > 0){
				$fee_affirm_bc = ($ent_query[0]['sum_affirm'] * .0999) +30;
				$fee_affirm_bc += ($ent_query[0]['affirm'] ) *30;
			}
			$ids_payment = $ent_query[0]['ids'];
			if(!empty($ids_payment)){			
				$str_dsrp ="SELECT description, sum(amount) FROM data_sales_representative_payments where payment_id  in ($ids_payment) and deleted = 0  group by description";
				$dsrp = $this->DataPreRegister->getConnection()->execute($str_dsrp)->fetchAll('assoc');
				if(!empty($dsrp)){
					foreach ($dsrp as $key => $value) {						 
						 if($value["description"] == 'PAY INVITATION' && $value["sum(amount)"]> 0){
							$referral_pay = $value['sum(amount)'];
						 }else if($value["description"] == 'SALES TEAM' && $value["sum(amount)"]> 0){
							$sales_team =$value['sum(amount)'];
						 }
					}
				}
			}			
		}

		$str_query_affirm_refund = "
		SELECT  COUNT(DPA.id) total,
		SUM(DPA.total) amount		
			FROM data_payment DPA 
			JOIN sys_users SU ON SU.id = DPA.id_from
			LEFT JOIN data_payment P ON P.uid = DPA.uid AND P.total = DPA.total AND P.type = 'REFUND'
			WHERE
			DPA.payment_platform = 'affirm' AND
			SU.name NOT LIKE '%test%' AND
			SU.name NOT LIKE '%Tester%' AND
			SU.lname NOT LIKE '%test%' AND
			SU.lname NOT LIKE '%Tester%' AND
			SU.is_test = 0  AND
			DPA.promo_code != 'DSCT99SPA' AND
			DPA.promo_code != 'DSCT9934MYSPA' AND
			DPA.receipt <> '' AND
			DPA.type = 'ADVANCED COURSE' AND
			DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
			AND P.type IS NOT NULL
		";

		$affirm_query_refund = $this->DataPayment->getConnection()->execute($str_query_affirm_refund)->fetchAll('assoc');
	
		
		$amount_refund_af = '0';
		if ($affirm_query_refund && $affirm_query_refund[0]['amount'] > 0){			
			$amount_refund_af = $affirm_query_refund[0]['amount'];
		}

 		$net_bc = ($total_sold_bc - $fee_stripe_bc - $fee_affirm_bc - $refund_bc - $referral_pay - $sales_team - $amount_refund_af)/100;		 
		//$html .= '<br>$ Net Income: $' . number_format($net_bc, 2) ;
        $html['Net Income ADVANCED COURSE'] = number_format($net_bc, 2);
		// ---- net

		//! ADVANCED TECHNIQUES COURSE

		$cant_refund = '';
		$amount_refund = '';
		if(isset($arr_totales['Total_paid_from_the_advanced_techniques'])){		
			$str_advanced_courses = $arr_totales['Total_paid_from_the_advanced_techniques']['amount'];
			$total_advanced_course = $arr_totales['Total_paid_from_the_advanced_techniques']['total'];				
			//$html .= '<br><br><b>Advanced Techniques</b><br> $ Total paid from the advanced techniques: $' . number_format($total_advanced_course / 100, 2) . ' ' . $amount_refund;
			//$html .= '<br># Number of advanced techniques: ' . $str_advanced_courses . ' ' . $cant_refund;
		}

		
		if (date('Y-m',strtotime($from)) < '2023-10') {
			/****************SEZZLE***************** */
		
			$total_advanced_course_sezzle=0;
			if(isset($arr_totales['Total_paid_from_sezzle_advanced_techniques'])){	
				$total_advanced_course_sezzle +=  $arr_totales['Total_paid_from_sezzle_advanced_techniques']['amount'];
			}
			if(isset($arr_totales['Total_paid_from_sezzle_3'])){	
				$total_advanced_course_sezzle +=  $arr_totales['Total_paid_from_sezzle_3']['amount'];
			}
			
			//$html .= '<br> $ Total paid from sezzle: $' . number_format($total_advanced_course_sezzle / 100, 2);				 
            $html['Total paid from sezzle  advanced_techniques'] = number_format($total_advanced_course_sezzle / 100, 2);
		}
	
		/*************************************** */
		
		$cant_refund = '';
		$amount_refund = '';	
		if(isset($arr_totales['Affirm_Number_of_advanced_techniques_training'])){	
			//if ($affirm_query) {
			$amount = $arr_totales['Affirm_Number_of_advanced_techniques_training']['amount'] / 100;
			$total = $arr_totales['Affirm_Number_of_advanced_techniques_training']['total'];

			//$html .= '<br> # Affirm - Number of advanced techniques training: ' . $total. ' ' . $cant_refund;
            $html['Affirm - Number of advanced techniques training'] = $total. ' ' . $cant_refund;
			//$html .= '<br> $ Total paid from Affirm: $' . number_format($amount, 2) . ' ' . $amount_refund;
            $html['Total paid from Affirm advanced_techniques'] = number_format($amount, 2) . ' ' . $amount_refund;
		}
		
		
		//! WEIGHT LOSS
 
 $str_query_find_refund = "SELECT
				GROUP_CONCAT(DP.id) ids,
 				SUM(if(DP.payment_platform = 'stripe', 1, 0)) AS stripe,
				SUM(if(DP.payment_platform = 'stripe', DP.total, 0)) AS sum_stripe,
				SUM(if(DP.payment_platform = 'affirm', 1, 0)) AS affirm,
				SUM(if(DP.payment_platform = 'affirm', DP.total, 0)) AS sum_affirm,
				SUM(CASE WHEN DP.total < 80000 then DP.total ELSE 0 END) amount_month,
				SUM(CASE WHEN DP.total > 80000 then DP.total ELSE 0 END) amount_full,
				SUM(DP.total) amount, 
				
				COUNT(CASE WHEN DP.total < 80000 then 1 ELSE NULL END) total_month,
				COUNT(CASE WHEN DP.total > 80000 then 3 ELSE NULL END) total_full,
				COUNT(DP.id) total, 
				'WLTOTAL' tipos
				FROM data_payment DP 
				JOIN sys_users SU ON SU.id = DP.id_from
				LEFT JOIN data_payment P ON P.uid = DP.uid AND P.total = DP.total AND P.type = 'REFUND'
				WHERE 
						DP.payment <> '' AND 
					DP.is_visible = 1 AND 
					DP.promo_code <> 'DSCT99SPA' AND 
					SU.name NOT LIKE '%test%' AND 
					SU.is_test = 0 AND 
					DP.receipt <> '' AND 
					DP.type = 'WEIGHT LOSS' AND 
					DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
					#AND P.type IS NULL
					AND DP.total >= 700

		UNION

 SELECT 
		'0' ids,
 		SUM(if(DP.payment_platform = 'stripe', 1, 0)) AS stripe,
		SUM(if(DP.payment_platform = 'stripe', DP.total, 0)) AS sum_stripe,
		SUM(if(DP.payment_platform = 'affirm', 1, 0)) AS affirm,
		SUM(if(DP.payment_platform = 'affirm', DP.total, 0)) AS sum_affirm,
		SUM(CASE WHEN DP.total < 80000 then DP.total ELSE 0 END) amount_month,
		SUM(CASE WHEN DP.total > 80000 then DP.total ELSE 0 END) amount_full,
		SUM(DP.total) amount, 
		
		COUNT(CASE WHEN DP.total < 80000 then 1 ELSE NULL END) total_month,
		COUNT(CASE WHEN DP.total > 80000 then 3 ELSE NULL END) total_full,
		COUNT(DP.id) total ,
		'WL_REFUND' tipos
	   FROM data_payment DP 
	   JOIN sys_users SU ON SU.id = DP.id_from
	   LEFT JOIN data_payment P ON P.uid = DP.uid AND P.total = DP.total AND P.type = 'REFUND'
	   WHERE 
			   DP.payment <> '' AND 
			DP.is_visible = 1 AND 
			DP.promo_code <> 'DSCT99SPA' AND 
			SU.name NOT LIKE '%test%' AND 
			SU.is_test = 0 AND 
			DP.receipt <> '' AND 
			DP.type = 'WEIGHT LOSS' AND 
			DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
			AND P.type IS NOT NULL";

		$ent_query_wl = $this->DataPreRegister->getConnection()->execute($str_query_find_refund)->fetchAll('assoc');

		$cant_refund = '';
		$amount_refund = '';

		$cant_refund_full = '';
		$amount_refund_full = '';

		//net 
		$total_sold_bc =0;
		$fee_stripe_bc =0;
		$fee_affirm_bc =0;
		$refund_bc =0;
		$amount_refund_af = '0';
		$referral_pay =0;
		$sales_team =0;		
		
		if($ent_query_wl){
			if($ent_query_wl[1]['total_month'] > 0){
				$cant_refund = '( + ' . $ent_query_wl[1]['total_month'] . ' refunded)';
				$amount_refund = '( + $' . number_format($ent_query_wl[1]['amount_month'] / 100, 2) . ' refunded)';							
			}			
			if($ent_query_wl[1]['amount_full'] > 0){
				$refund_bc += $ent_query_wl[1]['amount_full'] ;
			}
		}

 		
		$amount_refund_affirm = '';
	
		
		if ($ent_query_wl) {
			$time=strtotime($from);
			$result = date("Y-m",$time);
			//$html .= '<br><br><b>Weight Loss</b>';		
			if($result < '2023-11'){
				//$html .= '<br> $ Paid (Month to Month): $' . number_format($ent_query_wl[0]['amount_month'] / 100, 2) . ' ' . $amount_refund;							
                $html['Paid (Month to Month) Weight Loss'] = number_format($ent_query_wl[0]['amount_month'] / 100, 2) . ' ' . $amount_refund;
				//$html .= '<br># Number of treatments (Month to Month): ' . $ent_query_wl[0]['total_month'] . ' ' . $cant_refund;				
                $html['Number of treatments (Month to Month) Weight Loss'] = $ent_query_wl[0]['total_month'] . ' ' . $cant_refund;
			}
			$total_sold_bc += $ent_query_wl[0]['amount'];
			//$html .= '<br>$ Paid (3 month): $' . number_format($ent_query_wl[0]['amount_full'] / 100, 2) . ' ' . $amount_refund_full;	
            $html['Paid (3 month) Weight Loss'] = number_format($ent_query_wl[0]['amount_full'] / 100, 2) . ' ' . $amount_refund_full;
			//$html .= '<br># Number of treatments (3 Months): ' . $ent_query_wl[0]['total_full'] . ' ' . $cant_refund_full;
            $html['Number of treatments (3 Months) Weight Loss'] = $ent_query_wl[0]['total_full'] . ' ' . $cant_refund_full;
			
			if(isset($arr_totales['WEIGHT_LOSS_affirm'])){
				//$html .= '<br>Paid from Affirm: $' . number_format($arr_totales['WEIGHT_LOSS_affirm']['amount'] / 100, 2) . ' ' . $amount_refund_affirm;
                $html['Paid from Affirm Weight Loss'] = number_format($arr_totales['WEIGHT_LOSS_affirm']['amount'] / 100, 2) . ' ' . $amount_refund_affirm;
			}

			//fee stripe 					
			if($ent_query_wl[0]['sum_stripe'] > 0){
				$res = $ent_query_wl[0]['sum_stripe'] * .029;
				$res = $res +30;
				$fee_stripe_bc  = round($res, 0);
			}
			//fee affirm 9.99% +30c
			if($ent_query_wl[0]['sum_affirm'] > 0){
				$fee_affirm_bc = ($ent_query_wl[0]['sum_affirm'] * .0999) +30;
			}
			// commission payment
			$ids_payment = $ent_query_wl[0]['ids'];
			if(!empty($ids_payment)){			
				$str_dsrp ="SELECT description, sum(amount) FROM data_sales_representative_payments where payment_id  in ($ids_payment) and deleted = 0  group by description";
				$dsrp = $this->DataPreRegister->getConnection()->execute($str_dsrp)->fetchAll('assoc');
				if(!empty($dsrp)){
					foreach ($dsrp as $key => $value) {						 
						 if($value["description"] == 'PAY INVITATION' && $value["sum(amount)"]> 0){
							$referral_pay = $value['sum(amount)'];
						 }else if($value["description"] == 'SALES TEAM' && $value["sum(amount)"]> 0){
							$sales_team =$value['sum(amount)'];
						 }else{
							$sales_team +=$value['sum(amount)'];
						 }
					}
				}
			}			 
		}
		
		$net_bc = ($total_sold_bc - $fee_stripe_bc - $fee_affirm_bc - $refund_bc - $referral_pay - $sales_team - $amount_refund_af)/100;		 
		//$html .= '<br>$ Net Income: $' . number_format($net_bc, 2) ;
        $html['Net Income Weight Loss'] = number_format($net_bc, 2) ;
 // net income
		$str_query_find = "SELECT 
								SUM(DPD.price * DPD.qty) - DPD.refunded_amount amount, 
								SUM(DPD.qty) total, 
								COUNT(DISTINCT DP.id) suma,
								CP.name,
								group_concat(DPA.id) ids
						   	FROM data_purchases DP
						   	JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id 
						   	JOIN sys_users SU ON SU.id = DP.user_id 
						   	JOIN data_payment DPA ON DPA.uid = DP.uid 
						   	JOIN cat_products CP ON DPD.product_id = CP.id AND (CP.category = 'NEUROTOXINS' or CP.category = 'NEUROTOXIN PACKAGES')
						   	WHERE 
						   		DP.payment <> '' AND 
								SU.is_test = 0 AND  
								DPA.is_visible = 1 AND  
								DPA.payment <> '' AND 
								DPA.id_to = 0 AND 
								DP.deleted = 0 AND 
								DPA.promo_code <> 'DSCT99SPA' AND
								SU.name NOT LIKE '%test%' AND
								SU.is_test = 0 AND
								DPA.receipt <> '' AND	
								#CP.id != 44	AND
								DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
								group by CP.name
		";

		$ent_query_to = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
 
					
		$toxin_total =0;
		//$html .= '<br><br><b>Products</b> ';
		$toxins = [];
		$tx_total = 0;
		$tx_total_c = 0;
		//net 
		$total_sold_bc =0;
		$fee_stripe_bc =0;
		$fee_affirm_bc =0;
		$refund_bc =0;
		$amount_refund_af = '0';
		$referral_pay =0;
		$sales_team =0;
		if ($ent_query_to) {			
			foreach ($ent_query_to as $key => $value) {	
				if (strpos($value['name'], 'Botox') !== false ){
					if( isset($toxins['Botox'])){
						$total = $toxins['Botox']['total']  + $value['total'];
						$amount = $toxins['Botox']['amount']+ $value['amount'];						

						$toxins['Botox']['total'] = $total;
						$toxins['Botox']['amount'] = $amount;												
					}else{
						$toxins['Botox']['total'] =  $value['total'];
						$toxins['Botox']['amount'] = $value['amount'];
						$toxins['Botox']['name'] = 'Botox';
					}
				}else if (strpos($value['name'], 'Xeomin') !== false  ){
					if( isset($toxins['Xeomin'])){
						$total = $toxins['Xeomin']['total']  + $value['total'];
						$amount = $toxins['Xeomin']['amount']+ $value['amount'];						

						$toxins['Xeomin']['total'] = $total;
						$toxins['Xeomin']['amount'] = $amount;												
					}else{
						$toxins['Xeomin']['total'] =  $value['total'];
						$toxins['Xeomin']['amount'] = $value['amount'];
						$toxins['Xeomin']['name'] = 'Xeomin';
					}
				}else if ( strpos($value['name'], 'Tox Party Package') !== false){
					if( isset($toxins['Xeomin'])){
						$total = $toxins['Xeomin']['total']  + ($value['total']*5);
						$amount = $toxins['Xeomin']['amount']+ $value['amount'];						

						$toxins['Xeomin']['total'] = $total;
						$toxins['Xeomin']['amount'] = $amount;												
					}else{
						$toxins['Xeomin']['total'] =  ($value['total']*5);
						$toxins['Xeomin']['amount'] = $value['amount'];
						$toxins['Xeomin']['name'] = 'Xeomin';
					}
				}else {
					if( isset($toxins['Other'])){
						$total = $toxins['Other']['total']  + $value['total'];
						$amount = $toxins['Other']['amount']+ $value['amount'];						

						$toxins['Other']['total'] = $total;
						$toxins['Other']['amount'] = $amount;												
					}else{
						$toxins['Other']['total'] =  $value['total'];
						$toxins['Other']['amount'] = $value['amount'];
						$toxins['Other']['name'] = $value['name'];
					}
				}
				
				//refund
				$ids_payment = $value['ids'];
				if(!empty($ids_payment)){
				$str_query_find_p = " 		
				select sum(DP.`total`) amount, count(*) count
					FROM data_payment DP 
						JOIN sys_users SU ON SU.id = DP.id_from
						LEFT JOIN data_payment P ON P.uid = DP.uid AND P.type = 'REFUND'
						WHERE 
								DP.payment <> '' AND 
								DP.is_visible = 1 AND 
								DP.promo_code <> 'DSCT99SPA' AND 
								SU.name NOT LIKE '%test%' AND 
								SU.is_test = 0 AND 
								DP.receipt <> '' AND 
								DP.type = 'PURCHASE' AND 
								DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
								P.type IS NOT NULL		AND
								DP.id in ($ids_payment)				
				";
				 
				$ent_query_refund_p = $this->DataPreRegister->getConnection()->execute($str_query_find_p)->fetchAll('assoc');		
				if($ent_query_refund_p && $ent_query_refund_p[0]['amount'] > 0){		
					$refund_bc +=$ent_query_refund_p[0]['amount'];
				}

				}
			}		
			foreach ($toxins as $key => $value) {
				//$html .= '<br> Toxins ('.$value['name'].'): $' . number_format($value['amount'] / 100, 2) . ' ('.$value['total'].')';
                $html['Toxins ('.$value['name'].')'] = number_format($value['amount'] / 100, 2) . ' ('.$value['total'].')';
				$toxin_total += $value['amount'];
				$tx_total_c += $value['total'];
			}
		}

		$str_query_find = "SELECT 
		SUM(DPD.price * DPD.qty) - DPD.refunded_amount amount, 
		SUM(DPD.qty) total, 
		COUNT(DISTINCT DP.id) suma,
		group_concat(DPA.id) ids
	   FROM data_purchases DP
	   JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id 
	   JOIN sys_users SU ON SU.id = DP.user_id 
	   JOIN data_payment DPA ON DPA.uid = DP.uid 
	   JOIN cat_products CP ON DPD.product_id = CP.id AND CP.category = 'IV VIALS'
	   WHERE 
		   DP.payment <> '' AND 
		SU.is_test = 0 AND  
		DPA.is_visible = 1 AND  
		DPA.payment <> '' AND 
		DPA.id_to = 0 AND 
		DP.deleted = 0 AND 
		DPA.promo_code <> 'DSCT99SPA' AND
		SU.name NOT LIKE '%test%' AND
		SU.is_test = 0 AND
		DPA.receipt <> '' AND	
		#CP.id != 44	AND
		DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		";

		$ent_query_iv = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
		$iv_total =0; $iv_total_c =0;
		if ($ent_query_iv) {
			//$html .= '<br>IV: $' . number_format($ent_query_iv[0]['amount'] / 100, 2) . ' ('.$ent_query_iv[0]['total'].')';			
            $html['IV'] = number_format($ent_query_iv[0]['amount'] / 100, 2) . ' ('.$ent_query_iv[0]['total'].')';
			$iv_total = $ent_query_iv[0]['amount'] ;  $iv_total_c = $ent_query_iv[0]['total'] ;

			//refund
			$ids_payment = $ent_query_iv[0]['ids'];
			if(!empty($ids_payment)){
			$str_query_find_p = " 		
			select sum(DP.`total`) amount, count(*) count
				FROM data_payment DP 
					JOIN sys_users SU ON SU.id = DP.id_from
					LEFT JOIN data_payment P ON P.uid = DP.uid AND P.type = 'REFUND'
					WHERE 
							DP.payment <> '' AND 
							DP.is_visible = 1 AND 
							DP.promo_code <> 'DSCT99SPA' AND 
							SU.name NOT LIKE '%test%' AND 
							SU.is_test = 0 AND 
							DP.receipt <> '' AND 
							DP.type = 'PURCHASE' AND 
							DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
							P.type IS NOT NULL		AND
							DP.id in ($ids_payment)				
			";
			 
			$ent_query_refund_p = $this->DataPreRegister->getConnection()->execute($str_query_find_p)->fetchAll('assoc');
			if($ent_query_refund_p && $ent_query_refund_p[0]['amount'] > 0){		
				$refund_bc +=$ent_query_refund_p[0]['amount'];
			}

			}
		}
 
		$str_query_find = "SELECT 
		SUM(DPD.price * DPD.qty) - DPD.refunded_amount amount, 
		SUM(DPD.qty) total, 
		COUNT(DISTINCT DP.id) suma,
		group_concat(DPA.id) ids
	   FROM data_purchases DP
	   JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id 
	   JOIN sys_users SU ON SU.id = DP.user_id 
	   JOIN data_payment DPA ON DPA.uid = DP.uid 
	   JOIN cat_products CP ON DPD.product_id = CP.id AND CP.category = 'FILLERS'
	   WHERE 
		   DP.payment <> '' AND 
		SU.is_test = 0 AND  
		DPA.is_visible = 1 AND  
		DPA.payment <> '' AND 
		DPA.id_to = 0 AND 
		DP.deleted = 0 AND 
		DPA.promo_code <> 'DSCT99SPA' AND
		SU.name NOT LIKE '%test%' AND
		SU.is_test = 0 AND
		DPA.receipt <> '' AND	
		#CP.id != 44	AND
		DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		";
		$fillers_total =0;$fillers_total_c =0;
		$ent_query_fi = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
		if ($ent_query_fi) {
			//$html .= '<br> Fillers: $' . number_format($ent_query_fi[0]['amount'] / 100, 2). ' ('.$ent_query_fi[0]['total'].')';										
            $html['Fillers'] = number_format($ent_query_fi[0]['amount'] / 100, 2). ' ('.$ent_query_fi[0]['total'].')';
			$fillers_total = $ent_query_fi[0]['amount'] ; $fillers_total_c = $ent_query_fi[0]['total'] ;
			//refund
			$ids_payment = $ent_query_fi[0]['ids'];
			if(!empty($ids_payment)){
			$str_query_find_p = " 		
			select sum(DP.`total`) amount, count(*) count
				FROM data_payment DP 
					JOIN sys_users SU ON SU.id = DP.id_from
					LEFT JOIN data_payment P ON P.uid = DP.uid AND P.type = 'REFUND'
					WHERE 
							DP.payment <> '' AND 
							DP.is_visible = 1 AND 
							DP.promo_code <> 'DSCT99SPA' AND 
							SU.name NOT LIKE '%test%' AND 
							SU.is_test = 0 AND 
							DP.receipt <> '' AND 
							DP.type = 'PURCHASE' AND 
							DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
							P.type IS NOT NULL		AND
							DP.id in ($ids_payment)				
			";
			 
			$ent_query_refund_p = $this->DataPreRegister->getConnection()->execute($str_query_find_p)->fetchAll('assoc');
			if($ent_query_refund_p && $ent_query_refund_p[0]['amount'] > 0){		
				$refund_bc +=$ent_query_refund_p[0]['amount'];
			}

			}
		}
		
		$str_query_find = "SELECT 
		SUM(DPD.price * DPD.qty) - DPD.refunded_amount amount, 
		SUM(DPD.qty) total, 
		COUNT(DISTINCT DP.id) suma,
		group_concat(DPA.id) ids
	   FROM data_purchases DP
	   JOIN data_purchases_detail DPD ON DPD.purchase_id = DP.id 
	   JOIN sys_users SU ON SU.id = DP.user_id 
	   JOIN data_payment DPA ON DPA.uid = DP.uid 
	   JOIN cat_products CP ON DPD.product_id = CP.id AND CP.category = 'MATERIALS'
	   WHERE 
		   DP.payment <> '' AND 
		SU.is_test = 0 AND  
		DPA.is_visible = 1 AND  
		DPA.payment <> '' AND 
		DPA.id_to = 0 AND 
		DP.deleted = 0 AND 
		DPA.promo_code <> 'DSCT99SPA' AND
		SU.name NOT LIKE '%test%' AND
		SU.is_test = 0 AND
		DPA.receipt <> '' AND	
		#CP.id != 44	AND
		DPA.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
		";

		$ent_query_ma = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
		$ma_total = 0;$ma_total_c = 0;
		if ($ent_query_ma) {			
			//$html .= '<br>Materials: $' . number_format($ent_query_ma[0]['amount'] / 100, 2). ' ('.$ent_query_ma[0]['total'].')';							
            $html['Materials'] = number_format($ent_query_ma[0]['amount'] / 100, 2). ' ('.$ent_query_ma[0]['total'].')';
			$ma_total = $ent_query_ma[0]['amount'] ;$ma_total_c = $ent_query_ma[0]['total'] ;
			//refund
			$ids_payment = $ent_query_ma[0]['ids'];
			if(!empty($ids_payment)){
			$str_query_find_p = " 		
			select sum(DP.`total`) amount, count(*) count
				FROM data_payment DP 
					JOIN sys_users SU ON SU.id = DP.id_from
					LEFT JOIN data_payment P ON P.uid = DP.uid AND P.type = 'REFUND'
					WHERE 
							DP.payment <> '' AND 
							DP.is_visible = 1 AND 
							DP.promo_code <> 'DSCT99SPA' AND 
							SU.name NOT LIKE '%test%' AND 
							SU.is_test = 0 AND 
							DP.receipt <> '' AND 
							DP.type = 'PURCHASE' AND 
							DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
							P.type IS NOT NULL		AND
							DP.id in ($ids_payment)				
			";
			 
			$ent_query_refund_p = $this->DataPreRegister->getConnection()->execute($str_query_find_p)->fetchAll('assoc');
			if($ent_query_refund_p && $ent_query_refund_p[0]['amount'] > 0){		
				$refund_bc +=$ent_query_refund_p[0]['amount'];
			}

			}
		}		
		$total_sold_bc = $toxin_total + $iv_total + $fillers_total + $ma_total;
		//fee stripe 					
		if($total_sold_bc > 0){
			$res = $total_sold_bc * .029;
			$res = $res +30;
			$fee_stripe_bc  = round($res, 0);
		}
		
		//$html .= '<br>Total: $' . number_format(($toxin_total + $iv_total + $fillers_total + $ma_total)/ 100, 2) . ' ('.($tx_total_c +$iv_total_c+$fillers_total_c+$ma_total_c).')';		 
        $html['Total products']           =  number_format(($toxin_total + $iv_total + $fillers_total + $ma_total)/ 100, 2) . ' ('.($tx_total_c +$iv_total_c+$fillers_total_c+$ma_total_c).')';
		$net_bc = ($total_sold_bc - $fee_stripe_bc - $fee_affirm_bc - $refund_bc - $referral_pay - $sales_team - $amount_refund_af)/100;		 
		//$html .= '<br>$ Net Income: $' . number_format($net_bc, 2) ;
        $html['Net Income products']      =  number_format($net_bc, 2) ;

		// gfe
		//net 
		$total_sold_bc =0;
		$fee_stripe_bc =0;
		$fee_affirm_bc =0;
		$refund_bc =0;
		$amount_refund_af = '0';
		$referral_pay =0;
		$sales_team =0;
		$str_query_find = "
		SELECT SUM(DP.total) amount, COUNT(DP.id) total, group_concat(QUOTE(DP.uid)) ids
						   FROM data_payment DP 
						   JOIN sys_users SU ON SU.id = DP.id_from
						   WHERE 
						   		DP.payment <> '' AND 
                                DP.is_visible = 1 AND 
                                promo_code <> 'DSCT99SPA' AND 
                                SU.name NOT LIKE '%test%' AND 
                                SU.is_test = 0 AND 
                                DP.receipt <> '' AND 
                                DP.type = 'GFE' AND 
								DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'
								";

		$ent_query_ma = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
		 
		
		if(!empty($ent_query_ma)){
			//$html .= '<br><br><b>Exams</b><br> $ Total paid from the exams: $' . number_format($ent_query_ma[0]['amount'] / 100, 2);
            $html['Total paid from the exams']                   = number_format($ent_query_ma[0]['amount'] / 100, 2);
			//$html .= '<br># Number of exams: ' . $ent_query_ma[0]['total'];
            $html['Number of exams']                   = $ent_query_ma[0]['total'];
			$total_sold_bc = $ent_query_ma[0]['amount'];
			//fee stripe 					
			if($total_sold_bc > 0){
				$res = $total_sold_bc * .029;
				$res = $res +30;
				$fee_stripe_bc  = round($res, 0);
			}
			$str_query_find_refund = " 		
			select sum(DP.`total`) amount, count(*) count
				FROM data_payment DP 
					JOIN sys_users SU ON SU.id = DP.id_from
					LEFT JOIN data_payment P ON P.uid = DP.uid AND P.type = 'REFUND'
					WHERE 
							DP.payment <> '' AND 
							DP.is_visible = 1 AND 
							DP.promo_code <> 'DSCT99SPA' AND 
							SU.name NOT LIKE '%test%' AND 
							SU.is_test = 0 AND 
							DP.receipt <> '' AND 
							DP.type = 'GFE' AND 
							DP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
							P.type IS NOT NULL						
			";				
			 
			$ent_query_refund = $this->DataPreRegister->getConnection()->execute($str_query_find_refund)->fetchAll('assoc');		
			if($ent_query_refund && $ent_query_refund[0]['amount'] > 0){		
				$refund_bc =$ent_query_refund[0]['amount'];
			}

			// commission payment
			$ids_payment = $ent_query_ma[0]['ids'];
			if(!empty($ids_payment)){			
				$str_dsrp ="SELECT type as description, sum(total) FROM data_payment where uid  in ($ids_payment) and is_visible = 1  AND type = 'GFE COMMISSION'";
				 
				$dsrp = $this->DataPreRegister->getConnection()->execute($str_dsrp)->fetchAll('assoc');
				if(!empty($dsrp)){					
							$sales_team +=$dsrp[0]['sum(total)'];						
				}
			}

			 
		}
		//net income
		$net_bc = ($total_sold_bc - $fee_stripe_bc - $fee_affirm_bc - $refund_bc - $referral_pay - $sales_team - $amount_refund_af)/100;		 
		//$html .= '<br>$ Net Income: $' . number_format($net_bc, 2) ;
        $html['Net Income exams']         =  number_format($net_bc, 2) ;

		//net
		############################ Subscriptions
		$str_query = "
		SELECT DS.subscription_type, sum(DS.total) s,  count(DSP.total)  c, 'SUBSCRIPTIONMD_ALL' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id   left join sys_users u on u.id = DS.user_id                    WHERE DSP.total >=100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMD%'  AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL'
		UNION
		SELECT DS.subscription_type, SUM(DSP.total) s, count(DSP.total) c, 'SUBSCRIPTIONMSL_ALL' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id   left join sys_users u on u.id = DS.user_id					WHERE DSP.total >=100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMSL%' AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL'
		UNION
		SELECT DS.subscription_type, sum(DS.total) s,  count(DSP.total)  c, 'SUBSCRIPTIONMD' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id   	  left join sys_users u on u.id = DS.user_id                    WHERE DSP.total >=100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMD%'      AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' AND DS.other_school = 0 and DS.main_service  = 'NEUROTOXINS'
		UNION
		SELECT DS.subscription_type, sum(DS.total) s,  count(DSP.total) c, 'SUBSCRIPTIONMSL' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id       left join sys_users u on u.id = DS.user_id                    WHERE DSP.total >=100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMSL%'     AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' AND DS.other_school = 0 and DS.main_service  = 'NEUROTOXINS'
		UNION
		SELECT DS.subscription_type, sum(DS.total) s,  count(DSP.total)  c , 'membership_md' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id       left join sys_users u on u.id = DS.user_id             	    WHERE DSP.total >=100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type = 'SUBSCRIPTIONMD' AND DSP.created 		BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' AND DS.other_school = 1 	and DS.main_service  = 'NEUROTOXINS'	
		UNION		
		SELECT DS.subscription_type, sum(DS.total) s,  count(DSP.total)  c,  'Membership_MSL' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id      left join sys_users u on u.id = DS.user_id                  	WHERE DSP.total >=100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type = 'SUBSCRIPTIONMSL' AND DSP.created 		BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' AND DS.other_school = 1     and DS.main_service  = 'NEUROTOXINS'
		UNION
		SELECT DS.subscription_type, SUM(DSP.total) s, count(DSP.total) c,'membership_md_fillers' tipo  FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id left join sys_users u on u.id = DS.user_id                  	WHERE DSP.total >= 100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND  DS.subscription_type like 'SUBSCRIPTIONMD%' AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' and DS.main_service  = 'FILLERS'
		UNION
		SELECT DS.subscription_type, SUM(DSP.total) s, count(DSP.total) c,'membership_msl_fillers' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id left join sys_users u on u.id = DS.user_id                  	WHERE DSP.total >= 100 AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMSL%' AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' and DS.main_service  = 'FILLERS'
		UNION
		SELECT DS.subscription_type, SUM(DSP.total) s, count(DSP.total) c,'membership_md_iv' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id       left join sys_users u on u.id = DS.user_id                  	WHERE DSP.total >= 100  AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMD%' AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' and DS.main_service  = 'IV THERAPY'
		UNION
		SELECT DS.subscription_type, SUM(DSP.total) s, count(DSP.total) c,'membership_msl_iv' tipo FROM data_subscription_payments DSP JOIN data_subscriptions DS ON DS.id = DSP.subscription_id      left join sys_users u on u.id = DS.user_id                  	WHERE DSP.total >= 100  AND DSP.deleted = 0 AND DSP.status = 'DONE' AND DS.subscription_type like 'SUBSCRIPTIONMSL%' AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DSP.payment_type = 'FULL' and DS.main_service  = 'IV THERAPY'



		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s,COUNT(DS.id) c,'Number_of_MD_Subscribers'    tipo FROM data_subscriptions DS left join sys_users u on u.id = DS.user_id WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND	DS.subscription_type like 'SUBSCRIPTIONMD%' AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DS.other_school = 0
		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s,COUNT(DS.id) c, 'Number_of_MSL_Subscribers'  tipo FROM data_subscriptions DS left join sys_users u on u.id = DS.user_id WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND	DS.subscription_type like 'SUBSCRIPTIONMSL%'  AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DS.other_school = 0
		UNION
		SELECT DS.subscription_type, COUNT(DISTINCT(DS.id)) s,COUNT(DISTINCT(DS.id)) c,'Number_of_MD_Subscribers_os'    tipo FROM sys_users SU 			JOIN data_courses DC ON DC.user_id = SU.id AND DC.deleted = 0 AND DC.status = 'DONE' 			JOIN cat_courses CC ON CC.id = DC.course_id AND CC.deleted = 0 AND CC.type IN ('NEUROTOXINS BASIC','NEUROTOXINS ADVANCED', 'BOTH NEUROTOXINS')		JOIN data_subscriptions DS ON DS.user_id = SU.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type LIKE 'SUBSCRIPTIONMD%' 		WHERE SU.deleted = 0 AND SU.name NOT LIKE '%test%' AND SU.lname NOT LIKE '%test%' AND SU.is_test = 0 AND DS.created BETWEEN  '{$from} 00:00:00' AND '{$to} 23:59:59'
		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s,COUNT(DS.id) c, 'Number_of_MSL_Subscribers_os'  tipo FROM data_subscriptions DS left join sys_users u on u.id = DS.user_id WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND	DS.subscription_type like 'SUBSCRIPTIONMSL%'  AND u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND u.lname NOT LIKE '%test%' AND DS.other_school = 1
		UNION
		SELECT 'subscription_type' subscription_type, SUM(US.c) s,SUM(US.c) c,'MD_Unsubscriptions' tipo FROM ((SELECT DS.subscription_type, COUNT(DS.id) c FROM data_subscriptions DS WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMD' 				AND DS.status = 'CANCELLED')  				UNION ALL (SELECT DS.subscription_type, COUNT(DS.id) c	FROM data_subscriptions DS INNER JOIN data_subscription_cancelled DSC ON DSC.subscription_id = DS.id AND DSC.deleted = 0 WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMD' AND DS.status = 'ACTIVE') ) AS US				
		UNION
		SELECT 'subscription_type' subscription_type ,SUM(US.c) s,SUM(US.c) c,'MSL_Unsubscriptions' tipo FROM ( ( SELECT DS.subscription_type, COUNT(DS.id) c FROM data_subscriptions DS WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMSL' AND DS.status = 'CANCELLED' ) UNION ALL (SELECT DS.subscription_type, COUNT(DS.id) c FROM data_subscriptions DS INNER JOIN data_subscription_cancelled DSC ON DSC.subscription_id = DS.id AND DSC.deleted = 0 WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMSL' AND DS.status = 'ACTIVE')) AS US				
		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s, COUNT(DS.id) c,'MD_on_Hold' tipo  FROM data_subscriptions DS WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMD' AND DS.status = 'HOLD'
		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s,COUNT(DS.id) c,'MSL_on_Hold' tipo  FROM data_subscriptions DS  WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMSL' AND DS.status = 'HOLD'
		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s,COUNT(DS.id) c,'MD_Trial_on_Hold' tipo FROM data_subscriptions DS WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMD' AND DS.status = 'TRIALONHOLD'
		UNION
		SELECT DS.subscription_type, COUNT(DS.id) s,COUNT(DS.id) c,'MSL_Trial_on_Hold' tipo FROM data_subscriptions DS WHERE DS.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND DS.subscription_type = 'SUBSCRIPTIONMSL' AND DS.status = 'TRIALONHOLD'
		";   
		 
		$this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $_where[] =
			[
				['DataSubscriptionPayments.deleted' => 0],
				#'DataSubscriptionPayments.status' => 'DONE',
				['DataSubscriptionPayments.charge_id <>' => ''],
				['DataSubscriptionPayments.receipt_id <>' => ''],
				['SU.name NOT LIKE' => '%test%'],
				['SU.mname NOT LIKE' => '%test%'],
				['SU.lname NOT LIKE' => '%test%'],
				['DataSubscriptionPayments.total >' => 0],
				["DataSubscriptionPayments.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'"		]
	];
		$_where['OR'] = [
			['DataSubscriptionPayments.status' => 'DONE'],
			['DataSubscriptionPayments.status' => 'REFUNDED']
		];
        $all_reg = $this->DataSubscriptionPayments
        ->find()
        ->select([
            'DataSubscriptionPayments.user_id','DataSubscriptionPayments.id', 'DataSubscriptionPayments.subscription_id', 'DataSubscriptionPayments.total',
            'DataSubscriptionPayments.payment_type', 'DataSubscriptionPayments.payment_description', 'DataSubscriptionPayments.main_service',
            'DataSubscriptionPayments.addons_services', 'DataSubscriptionPayments.payment_details', 'DataSubscriptionPayments.status',
            'sub_type' => 'DS.subscription_type', 'other_school' => 'DS.other_school',
        ])
        ->join([
            'DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DataSubscriptionPayments.subscription_id = DS.id'],
            'SU' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SU.id = DataSubscriptionPayments.user_id'],
        ])
        ->where($_where)->all();
        // FILLERS
        $MainMDFillers = 0;
        $MainMDFillersCount = 0;
        $MainMSLFillers = 0;
        $MainMSLFillersCount = 0;
        $AddOnMDFillers = 0;
        $AddOnMDFillersCount = 0;
        $AddOnMSLFillers = 0;
        $AddOnMSLFillersCount = 0;
        // IV 
        $MainMDIV = 0;
        $MainMDIVCount = 0;
        $MainMSLIV = 0;
        $MainMSLIVCount = 0;
        $AddOnMDIV = 0;
        $AddOnMDIVCount = 0;
        $AddOnMSLIV = 0;
        $AddOnMSLIVCount = 0;
        // NEUROTOXINS MY SPA LIVE
        $MainMDNeurotoxins = 0;
        $MainMDNeurotoxinsCount = 0;
        $MainMSLNeurotoxins = 0;
        $MainMSLNeurotoxinsCount = 0;
        $AddOnMDNeurotoxins = 0;
        $AddOnMDNeurotoxinsCount = 0;
        $AddOnMSLNeurotoxins = 0;
        $AddOnMSLNeurotoxinsCount = 0;
        // NEUROTOXINS OTHER SCHOOLS
        $MainMDNeurotoxinsOS = 0;
        $MainMDNeurotoxinsOSCount = 0;
        $MainMSLNeurotoxinsOS = 0;
        $MainMSLNeurotoxinsOSCount = 0;
        $AddOnMDNeurotoxinsOS = 0;
        $AddOnMDNeurotoxinsOSCount = 0;
        $AddOnMSLNeurotoxinsOS = 0;
        $AddOnMSLNeurotoxinsOSCount = 0;
        $fillers_id= array();
        foreach ($all_reg as $key => $value) {
			if(empty($value['payment_details'])) continue;
            $payment_details = json_decode($value['payment_details'], true);
 
            if(isset($payment_details['FILLERS'])){
                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'FILLERS'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDFillers += $payment_details['FILLERS'];
                        $MainMDFillersCount ++;
						$fillers_id[]= $value['user_id']; //$this->log(__LINE__ . ' ' . json_encode($value['user_id']));
                    }else{
                        // Es MSL y main 
                        $MainMSLFillers += $payment_details['FILLERS'];
                        $MainMSLFillersCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDFillers += $payment_details['FILLERS'];
                        $AddOnMDFillersCount ++;
						$fillers_id[]= $value['user_id']; //$this->log(__LINE__ . ' ' . json_encode($value['user_id']));
                    }else{
                        // Es MSL
                        $AddOnMSLFillers += $payment_details['FILLERS'];
                        $AddOnMSLFillersCount ++;
                    }
                }
            }

            if(isset($payment_details['IV THERAPY'])){
                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'IV THERAPY'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDIV += $payment_details['IV THERAPY'];
                        $MainMDIVCount ++;
                    }else{
                        // Es MSL y main 
                        $MainMSLIV += $payment_details['IV THERAPY'];
                        $MainMSLIVCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDIV += $payment_details['IV THERAPY'];
                        $AddOnMDIVCount ++;
                    }else{
                        // Es MSL
                        $AddOnMSLIV += $payment_details['IV THERAPY'];
                        $AddOnMSLIVCount ++;
                    }
                }
            }

            if(isset($payment_details['NEUROTOXINS']) && $value['other_school'] == 0){

                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'NEUROTOXINS'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDNeurotoxins += $payment_details['NEUROTOXINS'];
                        $MainMDNeurotoxinsCount ++;
                    }else{
                        // Es MSL y main 
                        $MainMSLNeurotoxins += $payment_details['NEUROTOXINS'];
                        $MainMSLNeurotoxinsCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDNeurotoxins += $payment_details['NEUROTOXINS'];
                        $AddOnMDNeurotoxinsCount ++;
                    }else{
                        // Es MSL
                        $AddOnMSLNeurotoxins += $payment_details['NEUROTOXINS'];
                        $AddOnMSLNeurotoxinsCount ++;
                    }
                }
            }

            if(isset($payment_details['NEUROTOXINS']) && $value['other_school'] == 1){

                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'NEUROTOXINS'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $MainMDNeurotoxinsOSCount ++;
                    }else{
                        // Es MSL y main 
                        $MainMSLNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $MainMSLNeurotoxinsOSCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $AddOnMDNeurotoxinsOSCount ++;
                    }else{
                        // Es MSL
                        $AddOnMSLNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $AddOnMSLNeurotoxinsOSCount ++;
                    }
                }
            }
        }
		$ent_query = $this->DataPreRegister->getConnection()->execute($str_query)->fetchAll('assoc');
		$arr_subscriptions = [];
		foreach ($ent_query as $key => $value) {
			$arr_subscriptions[$value['tipo']] = $value;
		}

		//$html .= '<br><br><b>Subscriptions</b> ';
		// ---- net income
		$total_sold_bc =0;
		$fee_stripe_bc =0;
		$fee_affirm_bc =0;
		$refund_bc =0;
		$amount_refund_af = 0;
		$referral_pay =0;
		$sales_team =0;

		//msl
		$total_sold_msl =0;
		$fee_stripe_msl =0;
		$fee_affirm_msl =0;
		$refund_msl =0;
		$amount_refund_msl = 0;
		$referral_pay_msl =0;
		$sales_team_msl =0;
		if (isset($arr_subscriptions['SUBSCRIPTIONMD_ALL'])) {
			$total_sold_bc = $MainMDFillers+$MainMDIV+$MainMDNeurotoxins+$MainMDNeurotoxinsOS+$AddOnMDNeurotoxins+$AddOnMDNeurotoxinsOS+$AddOnMDIV+$AddOnMDFillers;
			//$html .= '<br>Total MD: $' . number_format(($MainMDFillers+$MainMDIV+$MainMDNeurotoxins+$MainMDNeurotoxinsOS+$AddOnMDNeurotoxins+$AddOnMDNeurotoxinsOS+$AddOnMDIV+$AddOnMDFillers) / 100, 2) . ' ('.($MainMDFillersCount+$MainMDIVCount+$MainMDNeurotoxinsCount+$MainMDNeurotoxinsOSCount).')';
            $html['SUBSCRIPTIONMD_ALL']=   number_format(($MainMDFillers+$MainMDIV+$MainMDNeurotoxins+$MainMDNeurotoxinsOS+$AddOnMDNeurotoxins+$AddOnMDNeurotoxinsOS+$AddOnMDIV+$AddOnMDFillers) / 100, 2) . ' ('.($MainMDFillersCount+$MainMDIVCount+$MainMDNeurotoxinsCount+$MainMDNeurotoxinsOSCount).')';            
		}else{
			//$html .= '<br>Total MD: $0.00 (0)';
            $html['SUBSCRIPTIONMD_ALL']=   '0.00 (0)';            
		}		 

		if (isset($arr_subscriptions['SUBSCRIPTIONMSL_ALL'])) {
			$total_sold_msl = $MainMSLFillers+$MainMSLIV+$MainMSLNeurotoxins+$MainMSLNeurotoxinsOS+$AddOnMSLNeurotoxins+$AddOnMSLNeurotoxinsOS+$AddOnMSLIV+$AddOnMSLFillers;
			//$html .= '<br>Total MSL: $' . number_format(($MainMSLFillers+$MainMSLIV+$MainMSLNeurotoxins+$MainMSLNeurotoxinsOS+$AddOnMSLNeurotoxins+$AddOnMSLNeurotoxinsOS+$AddOnMSLIV+$AddOnMSLFillers) / 100, 2) . ' ('.($MainMSLFillersCount+$MainMSLIVCount+$MainMSLNeurotoxinsCount+$MainMSLNeurotoxinsOSCount).')';
            $html['SUBSCRIPTIONMSL_ALL']= number_format(($MainMSLFillers+$MainMSLIV+$MainMSLNeurotoxins+$MainMSLNeurotoxinsOS+$AddOnMSLNeurotoxins+$AddOnMSLNeurotoxinsOS+$AddOnMSLIV+$AddOnMSLFillers) / 100, 2) . ' ('.($MainMSLFillersCount+$MainMSLIVCount+$MainMSLNeurotoxinsCount+$MainMSLNeurotoxinsOSCount).')';
		} else{
			//$html .= '<br>Total MSL: $0.00 (0)';
            $html['SUBSCRIPTIONMSL_ALL']= '0.00 (0)';
		}

				
		//fee stripe 					
		if($total_sold_bc > 0){
			$res = $total_sold_bc * .029;
			$res = $res +30;
			$fee_stripe_bc  = round($res, 0);
		}
		$str_query_find_refund_md = " 		
		SELECT DS.subscription_type, sum(DSP.total) s,  count(DSP.total)  c, 'SUBSCRIPTIONMD_ALL' tipo FROM data_subscription_payments DSP
		 JOIN data_subscriptions DS ON DS.id = DSP.subscription_id   
		 left join sys_users u on u.id = DS.user_id                    
		 WHERE  DSP.deleted = 0 AND DSP.status = 'REFUNDED' AND DS.subscription_type like 'SUBSCRIPTIONMD%'  
		 AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  
		 u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	
		 u.lname NOT LIKE '%test%' 						
			";				
			 
		$ent_query_refund_md = $this->DataPreRegister->getConnection()->execute($str_query_find_refund_md)->fetchAll('assoc');		
		if($ent_query_refund_md && $ent_query_refund_md[0]['s'] > 0){		
			$refund_bc =$ent_query_refund_md[0]['s'];  
		}
		//md commission
		if($total_sold_bc > 0){
			$sales_team = $total_sold_bc * .21;
			
		}
		//sales team		
		$str_query_find_sales_md = " 		
		SELECT  sum(amount) s,  count(amount)  c, 'SUBSCRIPTIONMD_ALL' tipo 
		FROM data_sales_representative_payments WHERE (description LIKE 'SALES TEAM SCHOOLS'  OR description LIKE '%ADD-ON')
		AND created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59'

		 #WHERE  DSP.deleted = 0 AND DSP.status = 'REFUNDED' AND DS.subscription_type like 'SUBSCRIPTIONMSL%'  
		 #AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  
		 #u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	
		 #u.lname NOT LIKE '%test%' 						
			";				
			 
		$ent_query_sales_md = $this->DataPreRegister->getConnection()->execute($str_query_find_sales_md)->fetchAll('assoc');		
		if($ent_query_sales_md && $ent_query_sales_md[0]['s'] > 0){		
			$sales_team +=$ent_query_sales_md[0]['s'];		
	}

	
		$net_bc = ($total_sold_bc - $fee_stripe_bc - $fee_affirm_bc - $refund_bc - $referral_pay - $sales_team - $amount_refund_af)/100;		 
		//$html .= '<br>$ Net Income  MD: $' . number_format($net_bc, 2) ;
        $html['net_income_md']= number_format($net_bc, 2) ;
		//msl
		// fee stripe
		if($total_sold_msl > 0){
			$res = $total_sold_msl * .029;
			$res = $res +30;
			$fee_stripe_msl  = round($res, 0);
		}
		//refund
		$str_query_find_refund_msl = " 		
		SELECT DS.subscription_type, sum(DSP.total) s,  count(DSP.total)  c, 'SUBSCRIPTIONMD_ALL' tipo FROM data_subscription_payments DSP
		 JOIN data_subscriptions DS ON DS.id = DSP.subscription_id   
		 left join sys_users u on u.id = DS.user_id                    
		 WHERE  DSP.deleted = 0 AND DSP.status = 'REFUNDED' AND DS.subscription_type like 'SUBSCRIPTIONMSL%'  
		 AND DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND  
		 u.name NOT LIKE '%Tester%' AND u.name NOT LIKE '%test%' AND u.lname NOT LIKE '%Tester%' AND	
		 u.lname NOT LIKE '%test%' 						
			";				
			 
		$ent_query_refund_msl = $this->DataPreRegister->getConnection()->execute($str_query_find_refund_msl)->fetchAll('assoc');		
		if($ent_query_refund_msl && $ent_query_refund_msl[0]['s'] > 0){		
			$refund_msl =$ent_query_refund_msl[0]['s'];
		}
		$net_bc = ($total_sold_msl - $fee_stripe_msl - $fee_affirm_msl - $refund_msl - $referral_pay_msl - $sales_team_msl - $amount_refund_msl)/100;		 
		//$html .= '<br>$ Net Income  MSL: $' . number_format($net_bc, 2) ;
        $html['net_income_msl']= number_format($net_bc, 2) ;
		/*$this->log(__LINE__ . ' ' . json_encode(array(			
			'total_sold_bc' => $total_sold_msl ,
			'fee_stripe_msl' => $fee_stripe_msl ,
			'fee_affirm_msl' => $fee_affirm_msl ,
			'refund_msl' => $refund_msl ,
			'referral_pay_msl' => $referral_pay_msl ,
			'sales_team_msl' => $sales_team_msl ,
			'amount_refund_af_msl' => $amount_refund_msl,
		 )));*/
		## myspalive school
		//$html .= '<ol start="1"><li>MySpaLive School Neurotoxins';		
		if (isset($arr_subscriptions['SUBSCRIPTIONMD'])) {			
			//$html .= "<br>Membership MD: $".number_format($MainMDNeurotoxins/ 100, 2)." (".$MainMDNeurotoxinsCount.")";
            $html['SUBSCRIPTIONMD']=          number_format($MainMDNeurotoxins/ 100, 2).' ('.$MainMDNeurotoxinsCount.')';
		}else{
			//$html .= '<br>Membership MD: $199.00';
            $html['SUBSCRIPTIONMD']=          '0.00 (0)';
		}		
		
		if (isset($arr_subscriptions['SUBSCRIPTIONMSL'])) {
			//$html .= '<br>Membership MSL: $'. number_format($MainMSLNeurotoxins/ 100, 2).' (' . $MainMSLNeurotoxinsCount.')';
            $html['SUBSCRIPTIONMSL']=          number_format($MainMSLNeurotoxins/ 100, 2).' (' . $MainMSLNeurotoxinsCount.')';
		}else{
			//$html .= '<br>Membership MSL: $19.95 (0)';
            $html['SUBSCRIPTIONMSL']=          '0.00 (0)';
		}
				 		 		 	
		if (date('Y-m',strtotime($from)) > '2023-11') {		
			//$html .= '<br> Add-On MD: $'. (number_format(($AddOnMDNeurotoxins)/100 , 2)).' (' .$AddOnMDNeurotoxinsCount.')' ;	
            $html['SUBSCRIPTIONMD_ADDON']= number_format(($AddOnMDNeurotoxins)/100 , 2).' (' .$AddOnMDNeurotoxinsCount.')' ;
		}else{
			//$html .= '<br> Add-On MD: $0.00 (0)' ;	
            $html['SUBSCRIPTIONMD_ADDON']= '0.00 (0)' ;
		}
	

	if (date('Y-m',strtotime($from)) > '2023-11') {		
		//$html .= '<br> Add-On MSL: $'.((number_format(($AddOnMSLNeurotoxins)/100 , 2))) .' (' .$AddOnMSLNeurotoxinsCount.')</li></ol>'; 		
        $html['SUBSCRIPTIONMSL_ADDON']= number_format(($AddOnMSLNeurotoxins)/100 , 2).' (' .$AddOnMSLNeurotoxinsCount.')';
	}else{
		//$html .= '<br> Add-On MSL: $0.00 (0)</li></ol>'; 
        $html['SUBSCRIPTIONMSL_ADDON']= '0.00 (0)';
	}

########################################################################## OTHER SCHOOL ########################################################	
	//$html .= '<ol start="2"><li>Neurotoxins Other School';
// git
	$str_query = "SELECT DS.subscription_type, SUM(DSP.total) s, count(DSP.total) c 
					FROM data_subscription_payments DSP 
					JOIN data_subscriptions DS ON DS.id = DSP.subscription_id 
					JOIN sys_users SU ON SU.id = DS.user_id 
					WHERE 
					DSP.deleted = 0 AND
					SU.name NOT LIKE '%test%' AND
					SU.lname NOT LIKE '%test%' AND
					SU.is_test = 0 AND
					SU.deleted = 0 AND 
					SU.active = 1 AND
					DSP.status = 'DONE' AND
					DSP.created BETWEEN '{$from} 00:00:00' AND '{$to} 23:59:59' AND
					DS.subscription_type like 'SUBSCRIPTIONMD%' AND
					DS.promo_code <> 'DSCT99SPA' AND
					DS.promo_code <> '$1000$' AND
					DS.promo_code <> '$10000$' AND
					(SELECT (id) FROM data_courses School  WHERE School.user_id = DS.user_id AND School.status = 'DONE' AND School.deleted = 0 limit 1) is not null
					group by DSP.subscription_id
					";


if (isset($arr_subscriptions['membership_md'])) {	
	//$html .= '<br>Membership MD: $' . number_format($MainMDNeurotoxinsOS / 100, 2) . ' ('.$MainMDNeurotoxinsOSCount.')';
    $html['membership_md']= number_format($MainMDNeurotoxinsOS / 100, 2) . ' ('.$MainMDNeurotoxinsOSCount.')';
}else{
	//$html .= '<br>Membership MD: $0.00 (0)';
    $html['membership_md']= '0.00 (0)';
}	 		
	if (isset($arr_subscriptions['Membership_MSL'])) {	
		//$html .= '<br>Membership MSL: $' . number_format($MainMSLNeurotoxinsOS / 100, 2) . ' ('.$MainMSLNeurotoxinsOSCount.')';
        $html['Membership_MSL']= number_format($MainMSLNeurotoxinsOS / 100, 2) . ' ('.$MainMSLNeurotoxinsOSCount.')';
	} else{
		//$html .= '<br>Membership MSL: $0.00 (0)';
        $html['Membership_MSL']= '0.00 (0)';
	}		
	
## MD ADD ONN
		if (date('Y-m',strtotime($from)) > '2023-11') {		
			//$html .= '<br> Add-On MD: $'.(((number_format(($AddOnMDNeurotoxinsOS)/100 , 2)))) .' (' .$AddOnMDNeurotoxinsOSCount.')'; 
            $html['Add_On_MD_os']= number_format(($AddOnMDNeurotoxinsOS)/100 , 2).' (' .$AddOnMDNeurotoxinsOSCount.')';
			
		}else{
			//$html .= '<br> Add-On MD: $0.00 (0)'; 
            $html['Add_On_MD_os']= '0.00 (0)';
		}
	 
	
	if (date('Y-m',strtotime($from)) > '2023-11') {		
		//$html .= '<br> Add-On MSL: $'.((number_format(($AddOnMSLNeurotoxinsOS)/100 , 2))) .' (' .$AddOnMSLNeurotoxinsOSCount.')</li></ol>'; 
        $html['Add_On_MSL_os']= number_format(($AddOnMSLNeurotoxinsOS)/100 , 2).' (' .$AddOnMSLNeurotoxinsOSCount.')';
	}else{
		//$html .= '<br> Add-On MSL: $0.00 (0)</li></ol>'; 
        $html['Add_On_MSL_os']= '0.00 (0)';
	}

#####################################################################################

########################################################################## OTHER SCHOOL FILLERS ########################################################################	
//$html .= '<ol start="3"><li>Fillers Other School</b>';

if (isset($arr_subscriptions['membership_md_fillers'])) { 
	//$html .= '<br>Membership MD : $' . number_format($MainMDFillers / 100, 2) . ' ('.($MainMDFillersCount) .')';
    $html['membership_md_fillers']= number_format($MainMDFillers / 100, 2) . ' ('.($MainMDFillersCount) .')';
}

if (isset($arr_subscriptions['membership_msl_fillers'])) {
	//$html .= '<br>Membership MSL: $' . number_format($MainMSLFillers / 100, 2) . ' ('.($MainMSLFillersCount).')';
    $html['membership_msl_fillers']= number_format($MainMSLFillers / 100, 2) . ' ('.($MainMSLFillersCount).')';
}

## MD ADD ONN
	if (date('Y-m',strtotime($from)) > '2023-11') {	
		//$html .= '<br> Add-On MD: $' . (number_format(($AddOnMDFillers)/100 , 2)) . ' (' . $AddOnMDFillersCount .')';
        $html['Add_On_MD_fillers']= number_format(($AddOnMDFillers)/100 , 2).' (' .$AddOnMDFillersCount.')';
	}else{
		//$html .= '<br> Add-On MD: $0.00 (0)';
        $html['Add_On_MD_fillers']= '0.00 (0)';
	}

$add_iv =0;  
## MSL ADD ONN
	if (date('Y-m',strtotime($from)) > '2023-11') {
		//$html .= '<br> Add-On MSL: $' . (number_format(($AddOnMSLFillers)/100 , 2))  . ' (' . $AddOnMSLFillersCount .')</li></ol>';
        $html['Add_On_MSL_fillers']= number_format(($AddOnMSLFillers)/100 , 2).' (' .$AddOnMSLFillersCount.')';
	}else{
		//$html .= '<br> Add-On MSL: $0.00 (0)</li></ol>';
        $html['Add_On_MSL_fillers']= '0.00 (0)';
	}
/*}else{
	$html .= '<br> Add-On MSL: $0.00 (0)</li></ol>';
}*/
#####################################################################################

########################################################################## OTHER SCHOOL IV  ########################################################################	
//$html .= '<ol start="4"><li >IV';

if (isset($arr_subscriptions['membership_md_iv'])) {
	//$html .= '<br>Membership MD: $' . number_format($MainMDIV / 100, 2) . ' ('.($MainMDIVCount) .')'; 
    $html['membership_md_iv']= number_format($MainMDIV / 100, 2) . ' ('.($MainMDIVCount) .')';
}

if (isset($arr_subscriptions['membership_msl_iv'])) {
	//$html .= '<br>Membership MSL: $' . number_format($MainMSLIV / 100, 2) . ' ('.( $MainMSLIVCount).')';
    $html['membership_msl_iv']= number_format($MainMSLIV / 100, 2) . ' ('.( $MainMSLIVCount).')';
}

## MS ADD ONN
	if (date('Y-m',strtotime($from)) > '2023-11') {
	//$html .= '<br> Add-On MD: $' . (number_format(($AddOnMDIV)/100 , 2)) . ' (' . $AddOnMDIVCount .')';
        $html['Add_On_MD_iv']= number_format(($AddOnMDIV)/100 , 2).' (' .$AddOnMDIVCount.')';
	}else{
		//$html .= '<br> Add-On MD: $0.00 (0)';
        $html['Add_On_MD_iv']= '0.00 (0)';
	}

## MSL ADD ONN
	if (date('Y-m',strtotime($from)) > '2023-11') { 
		//$html .= '<br> Add-On MSL: $' . (number_format(($AddOnMSLIV)/100 , 2)) . ' (' .  $AddOnMSLIVCount .')</li></ol>';
        $html['Add_On_MSL_iv']= number_format(($AddOnMSLIV)/100 , 2).' (' .$AddOnMSLIVCount.')';
	}else{
		//$html .= '<br> Add-On MSL: $0 (0)</li></ol>';
        $html['Add_On_MSL_iv']= '0 (0)';
	}

#####################################################################################
	//$html .= '<br><br><b>New Subscribers</b>';

		if (isset($arr_subscriptions['Number_of_MD_Subscribers'])) {			
            $html['Number_of_MD_Subscribers']= $arr_subscriptions['Number_of_MD_Subscribers']['c'];  
		} 
		
		if (isset($arr_subscriptions['Number_of_MSL_Subscribers'])) {
			//$html .= '<br> # Number of our MSL Subscribers: ' . $arr_subscriptions['Number_of_MSL_Subscribers']['c'];
            $html['Number_of_MSL_Subscribers']= $arr_subscriptions['Number_of_MSL_Subscribers']['c'];
		} 
		
		if (isset($arr_subscriptions['Number_of_MD_Subscribers_os'])) {
			//$html .= '<br># Number of other school MD Subscribers: ' . $arr_subscriptions['Number_of_MD_Subscribers_os']['c'];
            $html['Number_of_MD_Subscribers_os']= $arr_subscriptions['Number_of_MD_Subscribers_os']['c'];
		} 
		
		if (isset($arr_subscriptions['Number_of_MSL_Subscribers_os'])) {
			//$html .= '<br> # Number of other school MSL Subscribers: ' . $arr_subscriptions['Number_of_MSL_Subscribers_os']['c'];
            $html['Number_of_MSL_Subscribers_os']= $arr_subscriptions['Number_of_MSL_Subscribers_os']['c'];
		} 
		
		if ($arr_subscriptions['MD_Unsubscriptions']) { 
			//$html .= '<br># MD Unsubscriptions: ' . $arr_subscriptions['MD_Unsubscriptions']['c'];
            $html['MD_Unsubscriptions']= $arr_subscriptions['MD_Unsubscriptions']['c'];
		} 
				
		if ($arr_subscriptions['MSL_Unsubscriptions']) { 
			//$html .= '<br># MSL Unsubscriptions: ' . $arr_subscriptions['MSL_Unsubscriptions']['c'];
            $html['MSL_Unsubscriptions']= $arr_subscriptions['MSL_Unsubscriptions']['c'];
		} 
		
		if ($arr_subscriptions['MD_on_Hold']) {
			//$html .= '<br># MD on Hold: ' . $arr_subscriptions['MD_on_Hold']['c'];
            $html['MD_on_Hold']= $arr_subscriptions['MD_on_Hold']['c'];
		} 
		
		if ($arr_subscriptions['MSL_on_Hold']) {
			//$html .= '<br># MSL on Hold: ' . $arr_subscriptions['MSL_on_Hold']['c'];
            $html['MSL_on_Hold']= $arr_subscriptions['MSL_on_Hold']['c'];
		} 
		
		if ($arr_subscriptions['MD_Trial_on_Hold']) {
			//$html .= '<br># MD Trial on Hold: ' . $arr_subscriptions['MD_Trial_on_Hold']['c'];
            $html['MD_Trial_on_Hold']= $arr_subscriptions['MD_Trial_on_Hold']['c'];
		} 
		
		if ($arr_subscriptions['MSL_Trial_on_Hold']) {
			//$html .= '<br># MSL Trial on Hold: ' . $arr_subscriptions['MSL_Trial_on_Hold']['c'];
            $html['MSL_Trial_on_Hold']= $arr_subscriptions['MSL_Trial_on_Hold']['c'];
		} 
 
		
		
		//$this->Response->set('data', $html);
		return $html;
	}

    public function sales_report(){       
		$prev_months = 13;
		$cols = array();
		$month = date('m');
		$year = date('Y');
		while ($prev_months >= 0) {
			$from = date('Y-m-01', strtotime($year . '-' . $month . '-01'));
			$to   = date('Y-m-t', strtotime($year . '-' . $month . '-01'));

			$cols[$from] = $this->sales_month_v2($from, $to);

			$numberDate = $this->month_res($month, $year);

			$month = $numberDate[0];
			$year = $numberDate[1];

			$prev_months--;
		}
        if(count($cols)>0){
            $this->sales_report_xls($cols);
        }
	

    }

    private function sales_report_xls($arr_description){

        $spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->getCell('A1')->setValue('MySpaLive Sales Report');
		$sheet->getStyle('A1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('right');
        //$legend =  "From: " . date('m-d-Y', strtotime($date_from)) . " to: " . date('m-d-Y', strtotime($date_to));
        //$sheet->getCell('A2')->setValue('Dates');
		//$sheet->getStyle('A2')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
         $this->log(__LINE__ . ' ' . json_encode($arr_description));

        $letters = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','AA','AB','AC'    ];
        
        $initIndex = 5;
        $i = 0;
		foreach ($arr_description as $item) {
            //get mount year from y-m-d;
            $date = $item['from'];
            $datetime = DateTime::createFromFormat('Y-m-d', $date);
            $my = $datetime->format('F Y');

            
            $sheet->getCell($letters[$i] . $initIndex)->setValue($my);
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Purchases');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;



            $sheet->getCell($letters[$i] . $initIndex)->setValue('Basic Courses');
            $initIndex = $initIndex + 1;
            if(isset($item['Total paid from the basic courses'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from the basic courses: '); 
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from the basic courses']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from the basic courses:');                
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Number of basic courses
            if(isset($item['Number of basic courses'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of basic courses: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number of basic courses']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of basic courses:');                
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0'); 
                $initIndex = $initIndex + 1;
            }//Total paid from sezzle
            if(isset($item['Total paid from sezzle'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from sezzle: ');                
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from sezzle']); 
                $initIndex = $initIndex + 1;
            }
            //Affirm - Number of basic training
            if(isset($item['Affirm - Number of basic training'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Affirm -Number of basic training: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Affirm - Number of basic training']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of basic training:');                
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0'); 
                $initIndex = $initIndex + 1;
            }//Total paid from Affirm
            if(isset($item['Total paid from Affirm'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from Affirm: ');                
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from Affirm']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from Affirm:');                
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Net_Income_bc
            if(isset($item['Net_Income_bc'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income: ' );
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Net_Income_bc']); 
                $initIndex = $initIndex + 1;                
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net_Income_bc:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }            
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Advanced Courses');
            $initIndex = $initIndex + 1;
            //Total paid from the advanced courses
            if(isset($item['Total paid from the advanced courses'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from the advanced courses: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from the advanced courses']); 
                $initIndex = $initIndex + 1;
            }else{  
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from the advanced courses:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Number of advanced courses
            if(isset($item['Number of advanced courses'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of advanced courses: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number of advanced courses']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of advanced courses:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0'); 
                $initIndex = $initIndex + 1;
            }//Total paid from sezzle advanced_course
            if(isset($item['Total paid from sezzle advanced_course'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from sezzle: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from sezzle advanced_course']); 
                $initIndex = $initIndex + 1;
            }
            //Affirm - Number of advanced training
            if(isset($item['Affirm - Number of advanced training'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Affirm - Number of advanced training: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Affirm - Number of advanced training']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of advanced training:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0'); 
                $initIndex = $initIndex + 1;
            }//Total paid from Affirm advanced training
            if(isset($item['Total paid from Affirm advanced training'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from Affirm advanced training: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from Affirm advanced training']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from Affirm advanced training:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Net Income ADVANCED COURSE
            if(isset($item['Net Income ADVANCED COURSE'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Net Income ADVANCED COURSE']); 
                $initIndex = $initIndex + 1;
            }else{  
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income ADVANCED COURSE:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Affirm - Number of advanced techniques training
            if(isset($item['Affirm - Number of advanced techniques training'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Affirm - Number of advanced techniques training: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Affirm - Number of advanced techniques training']); 
                $initIndex = $initIndex + 1;
            }else{  
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Affirm - Number of advanced techniques training:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0'); 
                $initIndex = $initIndex + 1;
            }//Total paid from Affirm advanced_techniques
            if(isset($item['Total paid from Affirm advanced_techniques'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from Affirm advanced_techniques: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from Affirm advanced_techniques']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from Affirm advanced_techniques:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Weight Loss');
            $initIndex = $initIndex + 1;
            //Paid (Month to Month) Weight Loss
            if(isset($item['Paid (Month to Month) Weight Loss'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Paid (Month to Month) Weight Loss: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Paid (Month to Month) Weight Loss']); 
                $initIndex = $initIndex + 1;
            }//Number of treatments (Month to Month) Weight Loss
            if(isset($item['Number of treatments (Month to Month) Weight Loss'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of treatments (Month to Month) Weight Loss: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number of treatments (Month to Month) Weight Loss']); 
                $initIndex = $initIndex + 1;
            }
            //Paid (3 month) Weight Loss
            if(isset($item['Paid (3 month) Weight Loss'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Paid (3 month) Weight Loss: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Paid (3 month) Weight Loss']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Paid (3 month) Weight Loss:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Number of treatments (3 Months) Weight Loss
            if(isset($item['Number of treatments (3 Months) Weight Loss'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of treatments (3 Months) Weight Loss: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number of treatments (3 Months) Weight Loss']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of treatments (3 Months) Weight Loss:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0'); 
                $initIndex = $initIndex + 1;
            }//Paid from Affirm Weight Loss
            if(isset($item['Paid from Affirm Weight Loss'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Paid from Affirm Weight Loss: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Paid from Affirm Weight Loss']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Paid from Affirm Weight Loss:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Net Income Weight Loss
            if(isset($item['Net Income Weight Loss'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Net Income Weight Loss']); 
                $initIndex = $initIndex + 1;
            }else{  
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income Weight Loss:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Products');
            $initIndex = $initIndex + 1;
            //Toxins (Botox)
            if(isset($item['Toxins (Botox)'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Toxins (Botox): ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Toxins (Botox)']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Toxins (Botox):');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            //Toxins (Jeuveau)
            if(isset($item['Toxins (Jeuveau)'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Toxins (Jeuveau): ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Toxins (Jeuveau)']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Toxins (Jeuveau):');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            //Toxins (Xeomin)
            if(isset($item['Toxins (Xeomin)'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Toxins (Xeomin): ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Toxins (Xeomin)']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Toxins (Xeomin):');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//IV
            if(isset($item['IV'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ IV: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['IV']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ IV:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Fillers
            if(isset($item['Fillers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Fillers: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Fillers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Fillers:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Materials
            if(isset($item['Materials'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Materials: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Materials']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Materials:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Total products
            if(isset($item['Total products'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total products: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total products']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total products:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Net Income products
            if(isset($item['Net Income products'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Net Income products']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Exams');
            $initIndex = $initIndex + 1;
            //Total paid from the exams
            if(isset($item['Total paid from the exams'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from the exams: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Total paid from the exams']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total paid from the exams:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            //Exams
            if(isset($item['Number of exams'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Exams: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number of exams']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Exams:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Net Income exams
            if(isset($item['Net Income exams'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Net Income exams']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Subscriptions');
            $initIndex = $initIndex + 1;
            //SUBSCRIPTIONMD_ALL
            if(isset($item['SUBSCRIPTIONMD_ALL'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['SUBSCRIPTIONMD_ALL']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//SUBSCRIPTIONMSL_ALL
            if(isset($item['SUBSCRIPTIONMSL_ALL'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['SUBSCRIPTIONMSL_ALL']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//net_income_md
            if(isset($item['net_income_md'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['net_income_md']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//net_income_msl
            if(isset($item['net_income_msl'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['net_income_msl']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Net Income MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('1 MySpaLive School Neurotoxins');
            $initIndex = $initIndex + 1;
            //SUBSCRIPTIONMD
            if(isset($item['SUBSCRIPTIONMD'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['SUBSCRIPTIONMD']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//SUBSCRIPTIONMSL
            if(isset($item['SUBSCRIPTIONMSL'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['SUBSCRIPTIONMSL']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//SUBSCRIPTIONMD_ADDON
            if(isset($item['SUBSCRIPTIONMD_ADDON'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['SUBSCRIPTIONMD_ADDON']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//SUBSCRIPTIONMSL_ADDON
            if(isset($item['SUBSCRIPTIONMSL_ADDON'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['SUBSCRIPTIONMSL_ADDON']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }            
            $sheet->getCell($letters[$i] . $initIndex)->setValue('2 Neurotoxins Other School');
            $initIndex = $initIndex + 1;
            //membership_md
            if(isset($item['membership_md'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['membership_md']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Membership_MSL
            if(isset($item['Membership_MSL'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Membership_MSL']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Add_On_MD_os
            if(isset($item['Add_On_MD_os'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Add_On_MD_os']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Add_On_MSL_os
            if(isset($item['Add_On_MSL_os'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Add_On_MSL_os']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('3 Fillers Other School');
            $initIndex = $initIndex + 1;
            //membership_md_fillers
            if(isset($item['membership_md_fillers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['membership_md_fillers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//membership_msl_fillers
            if(isset($item['membership_msl_fillers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['membership_msl_fillers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Add_On_MD_fillers
            if(isset($item['Add_On_MD_fillers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Add_On_MD_fillers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Add_On_MSL_fillers
            if(isset($item['Add_On_MSL_fillers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Add_On_MSL_fillers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('4 IV');
            $initIndex = $initIndex + 1;
            //membership_md_iv
            if(isset($item['membership_md_iv'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['membership_md_iv']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//membership_msl_iv
            if(isset($item['membership_msl_iv'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['membership_msl_iv']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Add_On_MD_iv
            if(isset($item['Add_On_MD_iv'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Add_On_MD_iv']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Add_On_MSL_iv
            if(isset($item['Add_On_MSL_iv'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Add_On_MSL_iv']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('New Subscribers');
            $initIndex = $initIndex + 1;
            //Number_of_MD_Subscribers
            if(isset($item['Number_of_MD_Subscribers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of our  MD Subscribers: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number_of_MD_Subscribers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of our  MD Subscribers:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Number_of_MSL_Subscribers
            if(isset($item['Number_of_MSL_Subscribers'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of our  MSL Subscribers: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number_of_MSL_Subscribers']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of our  MSL Subscribers:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//Number_of_MD_Subscribers_os
            if(isset($item['Number_of_MD_Subscribers_os'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of other school MD Subscribers: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['Number_of_MD_Subscribers_os']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# Number of MD Subscribers:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//MD_Unsubscriptions
            if(isset($item['MD_Unsubscriptions'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MD Unsubscriptions: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['MD_Unsubscriptions']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MD Unsubscriptions:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//MSL_Unsubscriptions
            if(isset($item['MSL_Unsubscriptions'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MSL Unsubscriptions: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['MSL_Unsubscriptions']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MSL Unsubscriptions:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//MD_on_Hold
            if(isset($item['MD_on_Hold'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MD on Hold: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['MD_on_Hold']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MD on Hold:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//MSL_on_Hold
            if(isset($item['MSL_on_Hold'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MSL on Hold: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['MSL_on_Hold']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MSL on Hold:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//MD_Trial_on_Hold
            if(isset($item['MD_Trial_on_Hold'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MD Trial on Hold: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['MD_Trial_on_Hold']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MD Trial on Hold:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }//MSL_Trial_on_Hold
            if(isset($item['MSL_Trial_on_Hold'])){
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MSL Trial on Hold: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue($item['MSL_Trial_on_Hold']); 
                $initIndex = $initIndex + 1;
            }else{
                $sheet->getCell($letters[$i] . $initIndex)->setValue('# MSL Trial on Hold:');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue('0.00'); 
                $initIndex = $initIndex + 1;
            }
            

			
            //$sheet->getStyle('B'. $initIndex)->getAlignment()->setHorizontal('right');
            $sheet->getStyle($letters[$i])->getAlignment()->setHorizontal('left');
            $sheet->getStyle($letters[$i+1])->getAlignment()->setHorizontal('left');
			$sheet->getColumnDimension($letters[$i])->setAutoSize(true);
            $sheet->getColumnDimension($letters[$i+1])->setAutoSize(true);
			$initIndex = $initIndex + 1;

            $initIndex=5;
            $i=$i+2;
		}

		$writer = new Xlsx($spreadsheet);
        $time = date('ymdhms');
		$writer->save(TMP . 'reports' . DS . "sales_report_".$time.".xls");

		//$this->Files->output_file(TMP . 'reports' . DS . "stripe_".$time.".xls");
        $fname = TMP . 'reports' . DS . "sales_report_".$time.".xls";    
        //dd($fname); return;
        if (file_exists($fname)) {
            $size = filesize($fname);                
            header('Content-type: application/vnd.ms-excel');
            header("Content-Disposition: inline; filename=sales_report_".$time.".xls");
            header("Content-Length: {$size}");
            header('content-Transfer-Encoding:binary');
            header('Accept-Ranges:bytes');
            @ readfile($fname);
		exit;
    }
    exit;
    }

    public function update_sub_agreement(){
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

        $subscription_id = get('subscription_id', 0);

        if($subscription_id == 0){
            $this->message('Invalid subscription id.');
            return;
        }

        $agreement_id = get('agreement_id', 0);

        if($agreement_id == 0){
            $this->message('Invalid agreement id.');
            return;
        }

        $this->loadModel('DataSubscriptions');

        $this->DataSubscriptions->updateAll(
            ['agreement_id' => $agreement_id],
            ['id' => $subscription_id]
        );

        $this->success();
    }

    public function future_subscription(){
    
        $prevision = $this->future_subscriptios_stripe();
        
        // Get the current date
        $currentDate = new DateTime();
        $this->loadModel('DataSubscriptionPayments');
        
        // Get the first day of the current month
        $firstDay = new DateTime('first day of ' . $currentDate->format('F Y'));

        // Get the last day of the current month
        $lastDay = new DateTime('last day of ' . $currentDate->format('F Y'));      

        $firstDay = $firstDay->format('Y-m-d');
        $lastDay  = $lastDay->format('Y-m-d');
        $state = get('state', '');

        $this->log(__LINE__ . ' ' . json_encode($firstDay));
        $this->log(__LINE__ . ' ' . json_encode($lastDay));
        $this->log(__LINE__ . ' ' . json_encode($state));
        //return;

        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        if(is_string($state) && $state !== ''){
            $_where[] =
			[
				['DataSubscriptionPayments.deleted' => 0],
				#'DataSubscriptionPayments.status' => 'DONE',
				['DataSubscriptionPayments.charge_id <>' => ''],
				['DataSubscriptionPayments.receipt_id <>' => ''],
				['SU.name NOT LIKE' => '%test%'],
				['SU.mname NOT LIKE' => '%test%'],
				['SU.lname NOT LIKE' => '%test%'],
				['DataSubscriptionPayments.total >' => 0],
				["DataSubscriptionPayments.created BETWEEN '{$firstDay} 00:00:00' AND '{$lastDay} 23:59:59'"		],
                ['DS.state IN ' =>  explode(',', $state)],
	        ];    						
		}else if($state !== ''){
            $_where[] =
			[
				['DataSubscriptionPayments.deleted' => 0],
				#'DataSubscriptionPayments.status' => 'DONE',
				['DataSubscriptionPayments.charge_id <>' => ''],
				['DataSubscriptionPayments.receipt_id <>' => ''],
				['SU.name NOT LIKE' => '%test%'],
				['SU.mname NOT LIKE' => '%test%'],
				['SU.lname NOT LIKE' => '%test%'],
				['DataSubscriptionPayments.total >' => 0],
				["DataSubscriptionPayments.created BETWEEN '{$firstDay} 00:00:00' AND '{$lastDay} 23:59:59'"		],
                ['DS.state IN ' =>  explode(',', $state)],
	        ]       ;			
		}else{
			$_where[] =
			[
				['DataSubscriptionPayments.deleted' => 0],
				#'DataSubscriptionPayments.status' => 'DONE',
				['DataSubscriptionPayments.charge_id <>' => ''],
				['DataSubscriptionPayments.receipt_id <>' => ''],
				['SU.name NOT LIKE' => '%test%'],
				['SU.mname NOT LIKE' => '%test%'],
				['SU.lname NOT LIKE' => '%test%'],
				['DataSubscriptionPayments.total >' => 0],
				["DataSubscriptionPayments.created BETWEEN '{$firstDay} 00:00:00' AND '{$lastDay} 23:59:59'"		]
	        ];
		}
		 $this->log(__LINE__ . ' ' . json_encode($_where));
		$_where['OR'] = [
			['DataSubscriptionPayments.status' => 'DONE'],
			['DataSubscriptionPayments.status' => 'REFUNDED']
		];
        $all_reg = $this->DataSubscriptionPayments
        ->find()
        ->select([
            'DataSubscriptionPayments.user_id','DataSubscriptionPayments.id', 'DataSubscriptionPayments.subscription_id', 'DataSubscriptionPayments.total',
            'DataSubscriptionPayments.payment_type', 'DataSubscriptionPayments.payment_description', 'DataSubscriptionPayments.main_service',
            'DataSubscriptionPayments.addons_services', 'DataSubscriptionPayments.payment_details', 'DataSubscriptionPayments.status',
            'sub_type' => 'DS.subscription_type', 'other_school' => 'DS.other_school',
        ])
        ->join([
            'DS' => ['table' => 'data_subscriptions', 'type' => 'INNER', 'conditions' => 'DataSubscriptionPayments.subscription_id = DS.id'],
            'SU' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SU.id = DataSubscriptionPayments.user_id'],
        ])
        ->where($_where)->all();
        // FILLERS
        $MainMDFillers = 0;
        $MainMDFillersCount = 0;
        $MainMSLFillers = 0;
        $MainMSLFillersCount = 0;
        $AddOnMDFillers = 0;
        $AddOnMDFillersCount = 0;
        $AddOnMSLFillers = 0;
        $AddOnMSLFillersCount = 0;
        // IV 
        $MainMDIV = 0;
        $MainMDIVCount = 0;
        $MainMSLIV = 0;
        $MainMSLIVCount = 0;
        $AddOnMDIV = 0;
        $AddOnMDIVCount = 0;
        $AddOnMSLIV = 0;
        $AddOnMSLIVCount = 0;
        // NEUROTOXINS MY SPA LIVE
        $MainMDNeurotoxins = 0;
        $MainMDNeurotoxinsCount = 0;
        $MainMSLNeurotoxins = 0;
        $MainMSLNeurotoxinsCount = 0;
        $AddOnMDNeurotoxins = 0;
        $AddOnMDNeurotoxinsCount = 0;
        $AddOnMSLNeurotoxins = 0;
        $AddOnMSLNeurotoxinsCount = 0;
        // NEUROTOXINS OTHER SCHOOLS
        $MainMDNeurotoxinsOS = 0;
        $MainMDNeurotoxinsOSCount = 0;
        $MainMSLNeurotoxinsOS = 0;
        $MainMSLNeurotoxinsOSCount = 0;
        $AddOnMDNeurotoxinsOS = 0;
        $AddOnMDNeurotoxinsOSCount = 0;
        $AddOnMSLNeurotoxinsOS = 0;
        $AddOnMSLNeurotoxinsOSCount = 0;
        $fillers_id= array(); 
        foreach ($all_reg as $key => $value) {
			if(empty($value['payment_details'])) continue;
            $payment_details = json_decode($value['payment_details'], true);
 
            if(isset($payment_details['FILLERS'])){
                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'FILLERS'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDFillers += $payment_details['FILLERS'];
                        $MainMDFillersCount ++;
						$fillers_id[]= $value['user_id']; //$this->log(__LINE__ . ' ' . json_encode($value['user_id']));
                    }else{
                        // Es MSL y main 
                        $MainMSLFillers += $payment_details['FILLERS'];
                        $MainMSLFillersCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDFillers += $payment_details['FILLERS'];
                        $AddOnMDFillersCount ++;
						$fillers_id[]= $value['user_id']; //$this->log(__LINE__ . ' ' . json_encode($value['user_id']));
                    }else{
                        // Es MSL
                        $AddOnMSLFillers += $payment_details['FILLERS'];
                        $AddOnMSLFillersCount ++;
                    }
                }
            }

            if(isset($payment_details['IV THERAPY'])){
                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'IV THERAPY'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDIV += $payment_details['IV THERAPY'];
                        $MainMDIVCount ++;
                    }else{
                        // Es MSL y main 
                        $MainMSLIV += $payment_details['IV THERAPY'];
                        $MainMSLIVCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDIV += $payment_details['IV THERAPY'];
                        $AddOnMDIVCount ++;
                    }else{
                        // Es MSL
                        $AddOnMSLIV += $payment_details['IV THERAPY'];
                        $AddOnMSLIVCount ++;
                    }
                }
            }

            if(isset($payment_details['NEUROTOXINS']) && $value['other_school'] == 0){

                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'NEUROTOXINS'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDNeurotoxins += $payment_details['NEUROTOXINS'];
                        $MainMDNeurotoxinsCount ++;
                    }else{
                        // Es MSL y main 
                        $MainMSLNeurotoxins += $payment_details['NEUROTOXINS'];
                        $MainMSLNeurotoxinsCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDNeurotoxins += $payment_details['NEUROTOXINS'];
                        $AddOnMDNeurotoxinsCount ++;
                    }else{
                        // Es MSL
                        $AddOnMSLNeurotoxins += $payment_details['NEUROTOXINS'];
                        $AddOnMSLNeurotoxinsCount ++;
                    }
                }
            }

            if(isset($payment_details['NEUROTOXINS']) && $value['other_school'] == 1){

                $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                if($value['main_service'] == 'NEUROTOXINS'){
                    if($type == 'MD'){
                        // Si es MD y main
                        $MainMDNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $MainMDNeurotoxinsOSCount ++;
                    }else{
                        // Es MSL y main 
                        $MainMSLNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $MainMSLNeurotoxinsOSCount ++;
                    }
                }else{
                    if($type == 'MD'){
                        // Si es MD y add on
                        $AddOnMDNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $AddOnMDNeurotoxinsOSCount ++;
                    }else{
                        // Es MSL
                        $AddOnMSLNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                        $AddOnMSLNeurotoxinsOSCount ++;
                    }
                }
            }
        }
		
        $spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->getCell('A1')->setValue('MySpaLive future subscription payments');
		$sheet->getStyle('A1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('right');
        
        $MainMDFillers +=                   $prevision['MainMDFillers'];
        $MainMDFillersCount +=              $prevision['MainMDFillersCount'];
        $MainMSLFillers +=                  $prevision['MainMSLFillers'];
        $MainMSLFillersCount +=             $prevision['MainMSLFillersCount'];
        $AddOnMDFillers +=                  $prevision['AddOnMDFillers'];
        $AddOnMDFillersCount +=             $prevision['AddOnMDFillersCount'];
        $AddOnMSLFillers +=                 $prevision['AddOnMSLFillers'];
        $AddOnMSLFillersCount +=            $prevision['AddOnMSLFillersCount'];
        // IV 
        $MainMDIV +=                        $prevision['MainMDIV'];
        $MainMDIVCount +=                   $prevision['MainMDIVCount'];
        $MainMSLIV +=                       $prevision['MainMSLIV'];
        $MainMSLIVCount +=                  $prevision['MainMSLIVCount'];
        $AddOnMDIV +=                       $prevision['AddOnMDIV'];
        $AddOnMDIVCount +=                  $prevision['AddOnMDIVCount'];
        $AddOnMSLIV +=                      $prevision['AddOnMSLIV'];
        $AddOnMSLIVCount +=                 $prevision['AddOnMSLIVCount'];
        // NEUROTOXINS MY SPA LIVE
        $MainMDNeurotoxins +=               $prevision['MainMDNeurotoxins'];
        $MainMDNeurotoxinsCount +=          $prevision['MainMDNeurotoxinsCount'];
        $MainMSLNeurotoxins +=              $prevision['MainMSLNeurotoxins'];
        $MainMSLNeurotoxinsCount +=         $prevision['MainMSLNeurotoxinsCount'];
        $AddOnMDNeurotoxins +=              $prevision['AddOnMDNeurotoxins'];
        $AddOnMDNeurotoxinsCount +=         $prevision['AddOnMDNeurotoxinsCount'];
        $AddOnMSLNeurotoxins +=             $prevision['AddOnMSLNeurotoxins'];
        $AddOnMSLNeurotoxinsCount +=        $prevision['AddOnMSLNeurotoxinsCount'];
        // NEUROTOXINS OTHER SCHOOLS
        $MainMDNeurotoxinsOS +=             $prevision['MainMDNeurotoxinsOS'];
        $MainMDNeurotoxinsOSCount +=        $prevision['MainMDNeurotoxinsOSCount'];
        $MainMSLNeurotoxinsOS +=            $prevision['MainMSLNeurotoxinsOS'];
        $MainMSLNeurotoxinsOSCount +=       $prevision['MainMSLNeurotoxinsOSCount'];
        $AddOnMDNeurotoxinsOS +=            $prevision['AddOnMDNeurotoxinsOS'];
        $AddOnMDNeurotoxinsOSCount +=       $prevision['AddOnMDNeurotoxinsOSCount'];
        $AddOnMSLNeurotoxinsOS +=           $prevision['AddOnMSLNeurotoxinsOS'];
        $AddOnMSLNeurotoxinsOSCount +=      $prevision['AddOnMSLNeurotoxinsOSCount'];
        

        $letters = ['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z','AA','AB','AC'    ];
        
        $initIndex = 5;
        $i = 0;
		//foreach ($arr_description as $item) {
            //get mount year from y-m-d;
            $date = $firstDay;
            $datetime = DateTime::createFromFormat('Y-m-d', $date);
            $my = $datetime->format('F Y');

            
            $sheet->getCell($letters[$i] . $initIndex)->setValue($my);
            $initIndex = $initIndex + 1;
            $initIndex = $initIndex + 1;

            $sheet->getCell($letters[$i] . $initIndex)->setValue('Total for this month:');
            //$initIndex = $initIndex + 1;
            
            $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMDFillers+$MainMDIV+$MainMDNeurotoxins+$MainMDNeurotoxinsOS+$AddOnMDNeurotoxins+$AddOnMDNeurotoxinsOS+$AddOnMDIV+$AddOnMDFillers+
            $MainMSLFillers+$MainMSLIV+$MainMSLNeurotoxins+$MainMSLNeurotoxinsOS+$AddOnMSLNeurotoxins+$AddOnMSLNeurotoxinsOS+$AddOnMSLIV+$AddOnMSLFillers)/100);
            $initIndex = $initIndex + 1;
            $initIndex = $initIndex + 1;
            //SUBSCRIPTIONMD_ALL
            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMDFillers+$MainMDIV+$MainMDNeurotoxins+$MainMDNeurotoxinsOS+$AddOnMDNeurotoxins+$AddOnMDNeurotoxinsOS+$AddOnMDIV+$AddOnMDFillers)/100); 
                $initIndex = $initIndex + 1;
            
            //SUBSCRIPTIONMSL_ALL

                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Total MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMSLFillers+$MainMSLIV+$MainMSLNeurotoxins+$MainMSLNeurotoxinsOS+$AddOnMSLNeurotoxins+$AddOnMSLNeurotoxinsOS+$AddOnMSLIV+$AddOnMSLFillers)/100); 
                $initIndex = $initIndex + 1;

            // ------------------------------- my spa ---------------------------
            $sheet->getCell($letters[$i] . $initIndex)->setValue('1 MySpaLive School Neurotoxins');
            $initIndex = $initIndex + 1;
            //SUBSCRIPTIONMD            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMDNeurotoxins/100)); 
                $initIndex = $initIndex + 1;
            
            //SUBSCRIPTIONMSL            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMSLNeurotoxins/100)); 
                $initIndex = $initIndex + 1;
            
            //SUBSCRIPTIONMD_ADDON            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMDNeurotoxins/100)); 
                $initIndex = $initIndex + 1;
            
            //SUBSCRIPTIONMSL_ADDON            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMSLNeurotoxins/100)); 
                $initIndex = $initIndex + 1;
            // ---------------------------------------------- other school -------------------------
            $sheet->getCell($letters[$i] . $initIndex)->setValue('2 Neurotoxins Other School');
            $initIndex = $initIndex + 1;
            //membership_md            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMDNeurotoxinsOS/100)); 
                $initIndex = $initIndex + 1;
            
            //Membership_MSL            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMSLNeurotoxinsOS/100)); 
                $initIndex = $initIndex + 1;
            
            //Add_On_MD_os            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMDNeurotoxinsOS/100)); 
                $initIndex = $initIndex + 1;
            
            //Add_On_MSL_os            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMSLNeurotoxinsOS/100)); 
                $initIndex = $initIndex + 1;
            //------------------------------------- fillers ---------------------------------------------------
            $sheet->getCell($letters[$i] . $initIndex)->setValue('3 Fillers Other School');
            $initIndex = $initIndex + 1;
            //membership_md_fillers            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMDFillers/100)); 
                $initIndex = $initIndex + 1;
            
            //membership_msl_fillers            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMSLFillers/100)); 
                $initIndex = $initIndex + 1;
            
            //Add_On_MD_fillers            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMDFillers/100)); 
                $initIndex = $initIndex + 1;
            
            //Add_On_MSL_fillers            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMSLFillers/100)); 
                $initIndex = $initIndex + 1;
            // ---------------------------------------------- i v  ------------------------------------------------------
            $sheet->getCell($letters[$i] . $initIndex)->setValue('4 IV');
            $initIndex = $initIndex + 1;
            //membership_md_iv            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMDIV/100)); 
                $initIndex = $initIndex + 1;
            
            //membership_msl_iv            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Membership MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($MainMSLIV/100)); 
                $initIndex = $initIndex + 1;
            
            //Add_On_MD_iv            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MD: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMDIV/100)); 
                $initIndex = $initIndex + 1;
            
            //Add_On_MSL_iv            
                $sheet->getCell($letters[$i] . $initIndex)->setValue('$ Add-on MSL: ');
                $sheet->getCell($letters[$i+1] . $initIndex)->setValue(($AddOnMSLIV/100)); 
                $initIndex = $initIndex + 1;
            
            $sheet->getCell($letters[$i] . $initIndex)->setValue('');
            $initIndex = $initIndex + 1;                                 			
            
            $sheet->getStyle($letters[$i])->getAlignment()->setHorizontal('left');
            $sheet->getStyle($letters[$i+1])->getAlignment()->setHorizontal('left');
			$sheet->getColumnDimension($letters[$i])->setAutoSize(true);
            $sheet->getColumnDimension($letters[$i+1])->setAutoSize(true);
			$initIndex = $initIndex + 1;

            $initIndex=5;
            $i=$i+2;
		//}

		$writer = new Xlsx($spreadsheet);
        $time = date('ymdhms');
		$writer->save(TMP . 'reports' . DS . "future_subscription_".$time.".xls");

		//$this->Files->output_file(TMP . 'reports' . DS . "stripe_".$time.".xls");
        $fname = TMP . 'reports' . DS . "future_subscription_".$time.".xls";    
        //dd($fname); return;
        if (file_exists($fname)) {
            $size = filesize($fname);                
            header('Content-type: application/vnd.ms-excel');
            header("Content-Disposition: inline; filename=future_subscription_".$time.".xls");
            header("Content-Length: {$size}");
            header('content-Transfer-Encoding:binary');
            header('Accept-Ranges:bytes');
            @ readfile($fname);
		exit;
    }
    exit;

    }

    private function future_subscriptios_stripe(){        
        // FILLERS
        $state = get('state', '');
        $MainMDFillers = 0; 
        $MainMDFillersCount = 0;
        $MainMSLFillers = 0;
        $MainMSLFillersCount = 0;
        $AddOnMDFillers = 0;
        $AddOnMDFillersCount = 0;
        $AddOnMSLFillers = 0;
        $AddOnMSLFillersCount = 0;
        // IV 
        $MainMDIV = 0;
        $MainMDIVCount = 0;
        $MainMSLIV = 0;
        $MainMSLIVCount = 0;
        $AddOnMDIV = 0;
        $AddOnMDIVCount = 0;
        $AddOnMSLIV = 0;
        $AddOnMSLIVCount = 0;
        // NEUROTOXINS MY SPA LIVE
        $MainMDNeurotoxins = 0;
        $MainMDNeurotoxinsCount = 0;
        $MainMSLNeurotoxins = 0;
        $MainMSLNeurotoxinsCount = 0;
        $AddOnMDNeurotoxins = 0;
        $AddOnMDNeurotoxinsCount = 0;
        $AddOnMSLNeurotoxins = 0;
        $AddOnMSLNeurotoxinsCount = 0;
        // NEUROTOXINS OTHER SCHOOLS
        $MainMDNeurotoxinsOS = 0;
        $MainMDNeurotoxinsOSCount = 0;
        $MainMSLNeurotoxinsOS = 0;
        $MainMSLNeurotoxinsOSCount = 0;
        $AddOnMDNeurotoxinsOS = 0;
        $AddOnMDNeurotoxinsOSCount = 0;
        $AddOnMSLNeurotoxinsOS = 0;
        $AddOnMSLNeurotoxinsOSCount = 0;
        // load DataSubscriptions
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $_fields = ['DataSubscriptions.customer_id','DataSubscriptions.total','DataSubscriptions.status','DataSubscriptions.payment_method','DataSubscriptions.subscription_type', 'DataSubscriptions.data_object_id','DataSubscriptions.id','DataSubscriptions.user_id','DataSubscriptions.created','User.name','User.lname','User.state','User.city','User.street','User.zip','User.suite','State.name', 'State.abv','DataSubscriptions.main_service','DataSubscriptions.addons_services', 'DataSubscriptions.payment_details', 'DataSubscriptions.other_school','sub_type' => 'DataSubscriptions.subscription_type','DataSubscriptions.monthly'];
        if(is_string($state) && $state !== ''){
            $_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1,'DataSubscriptions.state IN ' =>  explode(',', $state)];
		}else if($state !== ''){
            $_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1,'DataSubscriptions.state IN ' =>  explode(',', $state)];		
		}else{
			$_where = ['DataSubscriptions.status' => 'ACTIVE','DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1];
		}

        if (!empty($arr_arguments)) {
            $_where = ['DataSubscriptions.status IN' => array('ACTIVE','HOLD'),'DataSubscriptions.deleted' => 0,'User.deleted' => 0, 'User.active' => 1,'User.uid' => $arr_arguments[0]];
        }

        $_fields['last_payment'] = "(SELECT DATE_FORMAT(Payment.created, '%Y-%m-%d') created FROM data_subscription_payments Payment WHERE Payment.subscription_id = DataSubscriptions.id AND Payment.status IN ('DONE','REFUNDED') AND Payment.deleted = 0 AND Payment.payment_type = 'FULL'   ORDER BY Payment.id DESC LIMIT 1)";
        $arr_subscriptions = $this->DataSubscriptions->find()->select($_fields)->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSubscriptions.user_id'],
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = User.state']
        ])->where($_where)->all();         
        
        // Get tomorrow's date
        //$tomorrow = strtotime('tomorrow');
        //$tomorrow = strtotime(date('Y-m-d 00:00:00'));
        $now = strtotime('now');
        // Get the last day of the current month
        //$last_day_of_month = strtotime(date('Y-m-t'));
        $last_day_of_month = strtotime(date('Y-m-t 10:00:00'));
        // Loop through dates from tomorrow to the end of the month        
        $current_date = $now;  $this->log(__LINE__ . ' ' . json_encode($current_date));  $this->log(__LINE__ . ' ' . json_encode($last_day_of_month));
        while ($current_date <= $last_day_of_month) {
             date('Y-m-d', $current_date) . "\n";
             $date_modified = date('Y-m-d', $current_date);
            $current_date = strtotime('+1 day', $current_date);
             $this->log(__LINE__ . ' ' . json_encode($date_modified));
        //}
            foreach($arr_subscriptions as $row) {                        
                $forcepay = $this->resubscription([], $row->user_id ,$row->subscription_type);
                $should_pay = $this->validateSubscriptionPayment($date_modified, $row['created']->i18nFormat('yyyy-MM-dd'),$row['last_payment'],$row['status'],$forcepay);             
                if (!$should_pay){                
                    continue;
                }

                $value = $row;
                if(empty($value['payment_details'])) continue;
                $payment_details = json_decode($value['payment_details'], true);




                if($row['monthly'] == '3'){
                    $cancel = $this->cancel_new_subs($row['id'], $row['monthly']);
                    if(!$cancel){
                        if (isset($payment_details[$value['main_service']])) {
                            $payment_details[$value['main_service']] = 0; // Main subscription on 3 month
                        }
                    } else {
                        continue;
                    }
                } else if($row['monthly'] == '12'){
                    $cancel = $this->cancel_new_subs($row['id'], $row['monthly']);
                    if($cancel){
                        continue;
                    }
                }

                if(isset($payment_details['FILLERS'])){
                    $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                    if($value['main_service'] == 'FILLERS'){
                        if($type == 'MD'){
                            // Si es MD y main
                            $MainMDFillers += $payment_details['FILLERS'];
                            $MainMDFillersCount ++;
                            $fillers_id[]= $value['user_id']; //$this->log(__LINE__ . ' ' . json_encode($value['user_id']));
                        }else{
                            // Es MSL y main 
                            $MainMSLFillers += $payment_details['FILLERS'];
                            $MainMSLFillersCount ++;
                        }
                    }else{
                        if($type == 'MD'){
                            // Si es MD y add on
                            $AddOnMDFillers += $payment_details['FILLERS'];
                            $AddOnMDFillersCount ++;
                            $fillers_id[]= $value['user_id']; //$this->log(__LINE__ . ' ' . json_encode($value['user_id']));
                        }else{
                            // Es MSL
                            $AddOnMSLFillers += $payment_details['FILLERS'];
                            $AddOnMSLFillersCount ++;
                        }
                    }
                }

                if(isset($payment_details['IV THERAPY'])){
                    $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                    if($value['main_service'] == 'IV THERAPY'){
                        if($type == 'MD'){
                            // Si es MD y main
                            $MainMDIV += $payment_details['IV THERAPY'];
                            $MainMDIVCount ++;
                        }else{
                            // Es MSL y main 
                            $MainMSLIV += $payment_details['IV THERAPY'];
                            $MainMSLIVCount ++;
                        }
                    }else{
                        if($type == 'MD'){
                            // Si es MD y add on
                            $AddOnMDIV += $payment_details['IV THERAPY'];
                            $AddOnMDIVCount ++;
                        }else{
                            // Es MSL
                            $AddOnMSLIV += $payment_details['IV THERAPY'];
                            $AddOnMSLIVCount ++;
                        }
                    }
                }

                if(isset($payment_details['NEUROTOXINS']) && $value['other_school'] == 0){

                    $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                    if($value['main_service'] == 'NEUROTOXINS'){
                        if($type == 'MD'){
                            // Si es MD y main
                            $MainMDNeurotoxins += $payment_details['NEUROTOXINS'];
                            $MainMDNeurotoxinsCount ++;
                        }else{
                            // Es MSL y main 
                            $MainMSLNeurotoxins += $payment_details['NEUROTOXINS'];
                            $MainMSLNeurotoxinsCount ++;
                        }
                    }else{
                        if($type == 'MD'){
                            // Si es MD y add on
                            $AddOnMDNeurotoxins += $payment_details['NEUROTOXINS'];
                            $AddOnMDNeurotoxinsCount ++;
                        }else{
                            // Es MSL
                            $AddOnMSLNeurotoxins += $payment_details['NEUROTOXINS'];
                            $AddOnMSLNeurotoxinsCount ++;
                        }
                    }
                }

                if(isset($payment_details['NEUROTOXINS']) && $value['other_school'] == 1){

                    $type = strpos($value['sub_type'], 'MD') ? 'MD' : 'MSL';
                    if($value['main_service'] == 'NEUROTOXINS'){
                        if($type == 'MD'){
                            // Si es MD y main
                            $MainMDNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                            $MainMDNeurotoxinsOSCount ++;
                        }else{
                            // Es MSL y main 
                            $MainMSLNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                            $MainMSLNeurotoxinsOSCount ++;
                        }
                    }else{
                        if($type == 'MD'){
                            // Si es MD y add on
                            $AddOnMDNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                            $AddOnMDNeurotoxinsOSCount ++;
                        }else{
                            // Es MSL
                            $AddOnMSLNeurotoxinsOS += $payment_details['NEUROTOXINS'];
                            $AddOnMSLNeurotoxinsOSCount ++;
                        }
                    }
                }
            }
        }// end while

        
        return (
            [ 'MainMDFillers' => $MainMDFillers ,
            'MainMDFillersCount' => $MainMDFillersCount ,
            'MainMSLFillers' => $MainMSLFillers ,
            'MainMSLFillersCount' => $MainMSLFillersCount ,
            'AddOnMDFillers' => $AddOnMDFillers ,
            'AddOnMDFillersCount' => $AddOnMDFillersCount ,
            'AddOnMSLFillers' => $AddOnMSLFillers ,
            'AddOnMSLFillersCount' => $AddOnMSLFillersCount ,
            // IV 
            'MainMDIV' => $MainMDIV ,
            'MainMDIVCount' => $MainMDIVCount ,
            'MainMSLIV' => $MainMSLIV ,
            'MainMSLIVCount' => $MainMSLIVCount ,
            'AddOnMDIV' => $AddOnMDIV ,
            'AddOnMDIVCount' => $AddOnMDIVCount ,
            'AddOnMSLIV' => $AddOnMSLIV ,
            'AddOnMSLIVCount' => $AddOnMSLIVCount ,
            // NEUROTOXINS MY SPA LIVE
            'MainMDNeurotoxins' => $MainMDNeurotoxins ,
            'MainMDNeurotoxinsCount' => $MainMDNeurotoxinsCount ,
            'MainMSLNeurotoxins' => $MainMSLNeurotoxins ,
            'MainMSLNeurotoxinsCount' => $MainMSLNeurotoxinsCount ,
            'AddOnMDNeurotoxins' => $AddOnMDNeurotoxins ,
            'AddOnMDNeurotoxinsCount' => $AddOnMDNeurotoxinsCount ,
            'AddOnMSLNeurotoxins' => $AddOnMSLNeurotoxins ,
            'AddOnMSLNeurotoxinsCount' => $AddOnMSLNeurotoxinsCount ,
            // NEUROTOXINS OTHER SCHOOLS
            'MainMDNeurotoxinsOS' => $MainMDNeurotoxinsOS ,
            'MainMDNeurotoxinsOSCount' => $MainMDNeurotoxinsOSCount ,
            'MainMSLNeurotoxinsOS' => $MainMSLNeurotoxinsOS ,
            'MainMSLNeurotoxinsOSCount' => $MainMSLNeurotoxinsOSCount ,
            'AddOnMDNeurotoxinsOS' => $AddOnMDNeurotoxinsOS ,
            'AddOnMDNeurotoxinsOSCount' => $AddOnMDNeurotoxinsOSCount ,
            'AddOnMSLNeurotoxinsOS' => $AddOnMSLNeurotoxinsOS ,
            'AddOnMSLNeurotoxinsOSCount' => $AddOnMSLNeurotoxinsOSCount ,]
        );
    }

    private function resubscription($arr_arguments, $user_id ,$subscription_type){
        if(!empty($arr_arguments[0])){
            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $str_query = "SELECT * FROM data_subscriptions DS WHERE DS.user_id = $user_id AND DS.subscription_type = '$subscription_type' AND DS.deleted = 0 ORDER BY DS.id DESC LIMIT 2";
            $query_result = $this->DataSubscriptions->getConnection()->execute($str_query)->fetchAll('assoc');
            if(isset($query_result[0]['status']) && isset($query_result[1]['status'])){
                if($query_result[0]['status'] == 'ACTIVE' && $query_result[1]['status'] == 'CANCELLED'){
                    return true;
                }
            }
        }
        return false;
    }

    private function validateSubscriptionPayment($now , $str_subscription_date,$str_last_payment_date = '',$subscription_status = 'ACTIVE',$forcepay = false) {
        //$str_now = date('Y-m-d');
        $str_now = $now;
        $date_formatter = new \DateTime("-1 month");
        $monthago = $date_formatter->format("Y-m-d");
        
        //$forcepay = false;

        $currentDate = date('Y-m-d', strtotime($str_now));
        $currentDateDay = date('d', strtotime($str_now));
        $currentMonthLastDate = date('Y-m-t', strtotime($str_now));
        $isTodayLastDay = $currentDate == $currentMonthLastDate ? true : false;

        $subscriptionDate = date('Y-m-d', strtotime($str_subscription_date));
        $subscriptionDateDay = date('d', strtotime($str_subscription_date));
        $subscriptionLastDate = date('Y-m-t', strtotime($str_subscription_date));
        $isSubscriptionLastDay = $subscriptionDate == $subscriptionLastDate ? true : false;

        if (!empty($str_last_payment_date)) {    
            $lastPaymentDate = date('Y-m-d', strtotime($str_last_payment_date));
            $lastPaymentDateDay = date('d', strtotime($str_last_payment_date));
            $lastPaymentLastDate = date('Y-m-t', strtotime($str_last_payment_date));
            $isPaymentLastDay = $lastPaymentDate == $lastPaymentLastDate ? true : false;
        }

        if (empty($str_last_payment_date)) {
            $diff_days = strtotime($str_now) - strtotime($str_subscription_date); 
        } else {
            $diff_days = strtotime($str_now) - strtotime($str_last_payment_date); 
        }

        $diff_days = $diff_days / 60 / 60 / 24;
        if ($subscription_status == 'ACTIVE') {
            if (!empty($str_last_payment_date)) {   
                if ($lastPaymentDateDay == $subscriptionDateDay || ($isPaymentLastDay && $subscriptionDateDay > $lastPaymentDateDay)) {
                    if ($isTodayLastDay && ($subscriptionDateDay > $currentDateDay) && $diff_days >= 27) return true;
                    if ($subscriptionDateDay == $currentDateDay && $diff_days >= 27) return true;
                } else {
                    if ($isTodayLastDay && ($lastPaymentDateDay > $currentDateDay) && $diff_days >= 27) return true;
                    if ($lastPaymentDateDay == $currentDateDay && $diff_days >= 27) return true;
                }
            } else {
                if ($isTodayLastDay && ($subscriptionDateDay > $currentDateDay) && $diff_days >= 27) return true;
                if ($subscriptionDateDay == $currentDateDay && $diff_days >= 27) return true;
                if ($forcepay) return true;
            }
        }
        if ($subscription_status == 'HOLD') {
            if ($diff_days >= 27) return true;
        }

        return false;
    }

    private function cancel_new_subs($subscription_id, $monthly) {
        $this->loadModel('DataSubscriptionPayments');
        $payments = $this->DataSubscriptionPayments->find()->where(['subscription_id' => $subscription_id, 'status' => 'DONE', 'payment_type' => 'FULL'])->count();
        if($monthly == '3' && $payments >= 3) return true;
        else if($monthly == '12' && $payments >= 12) return true;
        return false;
    }

    public function send_email_after_sign_subscription($id=0, $type=''){
        $this->log(__LINE__ . ' ' . json_encode('send_email_after_sign_subscription'));
        $isDev = env('IS_DEV', false);
        $this->loadModel('SpaLiveV1.SysUsers');
        $userEntity = $this->SysUsers        
        ->find()
        ->select(['SysUsers.id','SysUsers.name','SysUsers.lname','SysUsers.email','SysUsers.phone', 'SysUsers.state',
        'SysUsers.street','SysUsers.suite','SysUsers.city','SysUsers.zip','states.name'])
        ->join(['states' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'states.id = SysUsers.state'],        ])
        //->join(['DAR' => ['table' => 'data_assigned_to_register', 'type' => 'LEFT', 'conditions' => 'DAR.user_id = SysUsers.id'],])
        //->join(['representative' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'DAR.representative_id = representative.id'],])
        ->where(['SysUsers.id' => $id])->first();
        $this->set('user', json_encode($userEntity));
        if(empty($userEntity)) {return;};

        $address = $userEntity->street . ' ' . $userEntity->suite . ', ' . $userEntity->city . ' ' . $userEntity['states']['name'] . ' ' . $userEntity->zip;
        $subject="A new injector has subscribed";
        $body= '<p  style="font-size: 20px;">An injector has signed an MD subscription..</p><br><br>'.
        '<p><span  style="font-weight: bold;">Name:</span> ' .$userEntity->name .' '. $userEntity->lname .'<br>'.
        '<span  style="font-weight: bold;">Email:</span> ' .$userEntity->email .'<br>'.                                
        '<span  style="font-weight: bold;">Phone:</span> ' .$userEntity->phone .'<br>'.
        '<span  style="font-weight: bold;">Address:</span> ' . $address .'<br></p>'.
        '<span  style="font-weight: bold;">Approved types of treatments:</span><br></p>'.
        '<ul>
            <li>' . $type .'</li>
        </ul>';

        if($userEntity['state'] == 43){
            return;
        }
        
        $this->set('body', json_encode($body));
        $login = new LoginController();
        if($isDev){
            $login->send_email_after_register("francisco@advantedigital.com", $subject, $body);
        }else{            
            $login->send_email_after_register('patientrelations@myspalive.com', $subject, $body);            
        }
        return;
    
    }

    public function send_email_unsubscribe_to_suport($type_sub, $treatment_sub, $sub_date, $date_cancel){
        $subject = 'Subscription canceled by ' . USER_NAME . ' ' . USER_LNAME;
        $isDev = env('IS_DEV', false);
        $body = '<p>The injector ' . USER_NAME . ' ' . USER_LNAME . ', ' . USER_EMAIL . ', ' . USER_PHONE . ' has cancelled the following subscriptions:</p><br>' . 
                '<p>Type ' . $type_sub . ' ' . $treatment_sub . ' subscription date: ' . $sub_date . ' expected end date: ' . $date_cancel . ' of the next cycle.';

        if(!$isDev){ 
            $to = 'francisco@advantedigital.com,support@myspalive.com,patientrelations@myspalive.com';
        }else{
            $to = 'francisco@advantedigital.com';
        }
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $to,
            'subject' => $subject,
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
        curl_setopt($curl, CURLOPT_HEADER, true);

        $result = curl_exec($curl);

        curl_close($curl);
    }

    public function undo_cancel_sub(){
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

        //$sub_id = get('sub_id', 0);

        $type = get('type', '');
        if(empty($type)){
            $this->message('Invalid type.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $subs = $this->DataSubscriptions->find()->where(['user_id' => USER_ID, 'deleted' => 0, 'status' => 'ACTIVE'])->all();

        if(count($subs) <= 0){
            $this->message('No subscriptions found.');
            return;
        }

        $ids_subs = array();
        foreach($subs as $sub){
            $ids_subs[] = $sub->id;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');

        $subscription = $this->DataSubscriptionCancelled->find()->where(['subscription_id IN' => $ids_subs, 'deleted' => 0, 'services_unsubscribe LIKE' => '%' . $type . '%'])->all();

        if(count($subscription) <= 0){
            $this->message('Subscription cancel not found.');
            return;
        }

        foreach($subscription as $sub){

            $arr_services = explode(',', $sub->services_unsubscribe);

            foreach($arr_services as $key => $value){
                if($value == $type){
                    unset($arr_services[$key]);
                }
            }

            if(count($arr_services) <= 0){
                $this->DataSubscriptionCancelled->updateAll(
                    ['deleted' => 1], 
                    ['id' => $sub->id]
                );
            }else{
                $this->DataSubscriptionCancelled->updateAll(
                    ['services_unsubscribe' => implode(',',$arr_services)], 
                    ['id' => $sub->id]
                );
            }
            
        }

        $this->success();
    }

    public function subscriptions_time(){
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
        $offer = "Free option, but product must be purchased before treating anyone.";
        $info_1 = 'Enjoy a 1-month free trial with this subscription, gain full access to the app, and once you purchase your first product, you\'ll be able to offer treatments and join our injector\'s database';
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $training = $this->DataTrainings->find()->select(['CT.id', 'DataTrainings.id'])
        ->join([
            'CT' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CT.id = DataTrainings.training_id']
        ])
        ->where(['DataTrainings.user_id' => USER_ID, 'DataTrainings.deleted' => 0, 'DataTrainings.attended' => 1, 'CT.level' => 'LEVEL 1'])->first();

        $count_subs = $this->DataSubscriptions->find()->where(['user_id' => USER_ID, 'deleted' => 0])->count();

        if(empty($training)){
            $this->loadModel('SpaLiveV1.DataCourses');
            $course = $this->DataCourses->find()->where(['user_id' => USER_ID, 'deleted' => 0, 'status' => 'DONE'])->first();
            if(!empty($course)){
                $offer = 'Product must be purchased before treating anyone.';
                $info_1 = "Gain full access to the app, and once you purchase your first product, you'll be able to offer treatments and join our injector's database.";
            }
        }else{
            if($count_subs > 0){
                $offer = 'Product must be purchased before treating anyone.';
                $info_1 = "Gain full access to the app, and once you purchase your first product, you'll be able to offer treatments and join our injector's database.";
            }
        }

        $this->set('text', 'Choose your subscription package. All of them include monthly Medical Director coverage, MySpaLive app, and admin features.');

        if($count_subs > 0){
            $subs = array(
                array(
                    'title' => 'Month-to-month',
                    'name' => '1 Month',
                    'offer' => $offer,
                    'info' => $info_1,
                )
            );
        }else{
            $subs = array(
                array(
                    'title' => 'Month-to-month',
                    'name' => '1 Month',
                    'offer' => $offer,
                    'info' => $info_1,
                ),
                /*array(
                    'title' => '12 Month',
                    'name' => '12 Month',
                    'offer' => 'Best long term discount',
                    'info' => 'Pay $2,368.95 upfront, followed by $168.95 per month for the remaining months. This plan includes a Tox Party.',
                ),*/
                array(
                    'title' => '3 Month',
                    'name' => '3 Month',
                    'offer' => 'Smartest offer',
                    'info' => 'Use our financing program and pay $295 each month and get a Xeomin vial. The first payment will be made once the subscription is signed.',
                )
            );
        }

        $this->set('types', $subs);

        $this->success();
    }

    public function subscription_disclaimer(){
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

        $this->set('title', 'SUBSCRIPTION DISCLAIMER');

        $text_html = '
            <p>Your subscription includes two components:</p>
            <ol>
                <li>Membership + MSL Software Usage</li>
                <li>Membership + MD Coverage</li>
            </ol>
            <hr>
            <h2>1. Membership + MSL Software Usage</h2>
            <p>This subscription allows you to access the MySpaLive software platform to manage your services, patients, and inventory.</p>
            <p>Pricing is tiered based on the number of services you offer:</p>
            <table>
                <thead>
                    <tr>
                        <th>Number of Services</th>
                        <th>Monthly Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1 service</td>
                        <td>$39.95</td>
                    </tr>
                    <tr>
                        <td>2 services</td>
                        <td>$59.95 (+$20)</td>
                    </tr>
                    <tr>
                        <td>3 services</td>
                        <td>$69.95 (+$10)</td>
                    </tr>
                    <tr>
                        <td>4 services</td>
                        <td>$74.95 (+$5)</td>
                    </tr>
                    <tr>
                        <td>5 services</td>
                        <td>$79.95 (+$5)</td>
                    </tr>
                    <tr>
                        <td>5+ services</td>
                        <td>$79.95</td>
                    </tr>
                </tbody>
            </table>
            <hr>
            <h2>2. Membership + MD Coverage</h2>
            <p>If you require medical director supervision, you can subscribe to MD Coverage:</p>
            <ul>
                <li>Base MD Coverage: $179.00 / month</li>
            </ul>
            <p>Includes the ability to manage and treat patients under physician supervision and to purchase products from our inventory.</p>
            <p>Additional MD Coverage (for extra treatments):</p>
            <ul>
                <li>Add-on: $85.00 / month per additional treatment</li>
            </ul>
            <p>If you cancel your main MD Coverage subscription but keep an add-on, the add-on will automatically convert to your new main MD Coverage subscription at its current price.</p>
        ';

        $this->set('text', $text_html);
        $this->success();
    }

    public function subscription_disclaimer_ot(){
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

        $this->set('title', 'SUBSCRIPTION DISCLAIMER');

        $text_html = '
            <p>Your subscription includes two components:</p>
            <ol>
                <li>Membership + MSL Software Usage</li>
                <li>Membership + MD Coverage</li>
            </ol>
            <hr>
            <h2>1. Membership + MSL Software Usage</h2>
            <p>This subscription allows you to access the MySpaLive software platform to manage your services, patients, and inventory.</p>
            <p>Pricing is tiered based on the number of services you offer:</p>
            <table>
                <thead>
                    <tr>
                        <th>Number of Services</th>
                        <th>Monthly Cost</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>1 service</td>
                        <td>$39.95</td>
                    </tr>
                    <tr>
                        <td>2 services</td>
                        <td>$59.95 (+$20)</td>
                    </tr>
                    <tr>
                        <td>3 services</td>
                        <td>$69.95 (+$10)</td>
                    </tr>
                    <tr>
                        <td>4 services</td>
                        <td>$74.95 (+$5)</td>
                    </tr>
                    <tr>
                        <td>5 services</td>
                        <td>$79.95 (+$5)</td>
                    </tr>
                    <tr>
                        <td>5+ services</td>
                        <td>$79.95</td>
                    </tr>
                </tbody>
            </table>
            <hr>
            <h2>2. Membership + MD Coverage</h2>
            <p>If you require medical director supervision, you can subscribe to MD Coverage:</p>
            <ul>
                <li>Base MD Coverage: $179.00 / month</li>
            </ul>
            <p>Includes the ability to manage and treat patients under physician supervision and to purchase products from our inventory.</p>
            <p>Additional MD Coverage (for extra treatments):</p>
            <ul>
                <li>Add-on: $85.00 / month per additional treatment</li>
            </ul>
            <p>If you cancel your main MD Coverage subscription but keep an add-on, the add-on will automatically convert to your new main MD Coverage subscription at its current price.</p>
        ';


        $this->set('text', $text_html);
        $this->success();
    }

    public function get_ot_subscription_info() {
        
        
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

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('SpaLiveV1.SysTreatmentsOt');
        $resubscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID, 'DataSubscriptions.deleted' => 0, 'DataSubscriptions.subscription_type LIKE' => '%MSL%'])->first();
        $ent_subscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID, 'DataSubscriptions.deleted' => 0, 'DataSubscriptions.status' => 'ACTIVE', 'DataSubscriptions.subscription_type LIKE' => '%MSL%'])->first();
        $ent_subscription_md = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID, 'DataSubscriptions.deleted' => 0, 'DataSubscriptions.status' => 'ACTIVE', 'DataSubscriptions.subscription_type LIKE' => '%MD%'])->first();
        $sub_active = false;
        $sub_active_md = false;
        $previous_services = [];

        if(!empty($resubscription)){
            $this->set('resubscribe', true);
        }else{
            $this->set('resubscribe', false);
        }

        if(!empty($ent_subscription)){
            $sub_active = true;
            $previous_services = $ent_subscription->main_service;
            if(!empty($ent_subscription->addons_services)){
                $previous_services .= ',' . $ent_subscription->addons_services;
            }
            $previous_services = explode(',', $previous_services);
        }else{
            $sub_active = false;
        }

        $previous_services_md = [];

        if(!empty($ent_subscription_md)){
            $sub_active_md = true;
            $previous_services_md = $ent_subscription_md->main_service;
            if(!empty($ent_subscription_md->addons_services)){
                $previous_services_md .= ',' . $ent_subscription_md->addons_services;
            }
            $previous_services_md = explode(',', $previous_services_md);
        }else{
            $sub_active_md = false;
        }

        $data_training_id = get('data_training_id',0);
        $course_id = get('data_course_id',0);
        // if ($data_training_id == 0) $this->message('Invalid course.');

        $training_level = $this->DataTrainings->find()->select(['level' => 'Training.level'])->join([
            'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id'],
        ])->where(['DataTrainings.id' => $data_training_id])->first();

        $main_training_level = "";
        if (!empty($training_level)) {
            $main_training_level = $training_level->level;
        }
        $is_other_schools = false;
        $is_iv_therapy = false;
        if(empty($main_training_level)){
            $this->loadModel('SpaLiveV1.DataCourses');
             $user_course_basic = $this->DataCourses->find()->select(['CatCourses.type','SysTreatmentOT.name_key'])->join([
                'CatCourses' => ['table' => 'cat_courses', 'type' => 'INNER', 'conditions' => 'CatCourses.id = DataCourses.course_id'],
                'SchoolOption' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'SchoolOption.id = CatCourses.school_option_id'],
                'SysTreatmentOT' => ['table' => 'sys_treatments_ot', 'type' => 'LEFT', 'conditions' => 'SysTreatmentOT.id = SchoolOption.sys_treatment_ot_id'],
            ])->where(['DataCourses.user_id' => USER_ID,'DataCourses.deleted' => 0,'DataCourses.status' => 'DONE','DataCourses.id' => $course_id])->first();

            if (!empty($user_course_basic)) {
                $is_other_schools = true;
                if (!empty($user_course_basic['CatCourses']['type']) && $user_course_basic['CatCourses']['type'] != 'OTHER TREATMENTS') {
                    $main_training_level = $user_course_basic['CatCourses']['type'];
                } else {
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
            
            $_total_md = 0;
            $_total_msl = 0;
            $total_msl_coverage = 0;
            
            // Obtener todos los name_key para verificar si hay suscripciones canceladas
            $name_keys_to_check = [];
            switch($main_training_level){
                case 'LEVEL 1':
                case 'BOTH NEUROTOXINS':
                case 'NEUROTOXINS BASIC':
                    $name_keys_to_check[] = 'NEUROTOXINS';

                    if(!empty($ent_subscription_md) && in_array('NEUROTOXINS', $previous_services_md, true)){
                        break;
                    }

                    $total_msl_coverage = 1;
                    // $agreement_md = $this->CatAgreements->find()
                    //     ->select([
                    //         'md' => 'CatAgreements.uid',
                    //         'md_agreement' => 'DataAgreementMD.id',
                    //         'content_md' => 'CatAgreements.content',
                    //     ])
                    //     ->join([
                    //         'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                    //     ])
                    //     ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMD', 'CatAgreements.deleted' => 0])
                    // ->first();

                    $_total_md = $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'Neurotoxins MD Subscription',
                        'description' => $sub_active == false ? '' : '(Additional subscription)',
                        'subtotal' => $_total_md
                    ];
                    break;
                case 'LEVEL 3 FILLERS':
                case 'FILLERS':
                    $name_keys_to_check[] = 'FILLERS';
                    if(!empty($ent_subscription_md) && in_array('FILLERS', $previous_services_md, true)){
                        break;
                    }
                    $total_msl_coverage = 1;
                    // $agreement_md = $this->CatAgreements->find()
                    //     ->select([
                    //         'md' => 'CatAgreements.uid',
                    //         'md_agreement' => 'DataAgreementMD.id',
                    //         'content_md' => 'CatAgreements.content',
                    //     ])
                    //     ->join([
                    //         'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreements.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                    //     ])
                    //     ->where(['CatAgreements.state_id' => USER_STATE, 'CatAgreements.agreement_type' => 'SUBSCRIPTIONMDFILLERS', 'CatAgreements.deleted' => 0])
                    // ->first();
                    $_total_md = $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'Fillers MD Subscription',
                        'description' => $sub_active == false ? '' : '(Additional subscription)',
                        'subtotal' => $_total_md
                    ];
                    break;
                case 'LEVEL IV':
                    $name_keys_to_check[] = 'IV THERAPY';
                    $is_iv_therapy = true; // para que no se muestre el mensaje de la primera mes gratis y cobre inmediato
                    $total_msl_coverage = 1;
                    $_total_md = $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'IV Therapy MD Subscription',
                        'description' => $sub_active == false ? '' : '(Additional subscription)',
                        'subtotal' => $_total_md
                    ];
                    break;
                case 'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE':
                case 'MYSPALIVES_HYBRID_TOX_FILLER_COURSE':
                    $name_keys_to_check[] = 'NEUROTOXINS';
                    $name_keys_to_check[] = 'FILLERS';
                    $total_msl_coverage = 0;
                    $total_tmp = 0;
                    if(empty($ent_subscription_md) || !in_array('NEUROTOXINS', $previous_services_md, true)){
                        $total_tmp += $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                        $sub_arr[] = [
                            'type' => 'Neurotoxins MD Subscription',
                            'description' => $sub_active == false ? '' : '(Additional subscription)',
                            'subtotal' => $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md
                        ];
                        $total_msl_coverage++;
                    }
                    if(empty($ent_subscription_md) || !in_array('FILLERS', $previous_services_md, true)){
                        $total_tmp += count($previous_services_md) > 0 || $total_msl_coverage > 0 ? $this->total_subscription_ot_addon_md : $this->total_subscription_ot_main_md;
                        $sub_arr[] = [
                            'type' => 'Fillers MD Subscription',
                            'description' => '(Additional subscription)',
                            'subtotal' => count($previous_services_md) > 0 || $total_msl_coverage > 0 ? $this->total_subscription_ot_addon_md : $this->total_subscription_ot_main_md
                        ];
                        $total_msl_coverage++;  
                    }
                    
                    $_total_md = $total_tmp;
                    break;
                default:
                    break;
            }
            
            // Verificar si el usuario tiene suscripciones canceladas para estos servicios
            $has_cancelled_subscription = false;
            if (!empty($name_keys_to_check)) {
                $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
                
                foreach($name_keys_to_check as $name_key) {
                    $cancelled_subscription = $this->DataSubscriptionCancelled->find()
                        ->join([
                            'DataSubscriptions' => [
                                'table' => 'data_subscriptions',
                                'type' => 'INNER',
                                'conditions' => 'DataSubscriptions.id = DataSubscriptionCancelled.subscription_id'
                            ]
                        ])
                        ->where([
                            'DataSubscriptions.user_id' => USER_ID,
                            'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $name_key . '%'
                        ])
                        ->first();
                    
                    if (!empty($cancelled_subscription)) {
                        $has_cancelled_subscription = true;
                        break;
                    }
                }
                
                // Verificar si el usuario tiene suscripciones en HOLD para estos servicios
                $ent_subscription_hold = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.deleted' => 0,
                    'DataSubscriptions.status IN' => array('HOLD')
                ])->all();
                
                if (!empty($ent_subscription_hold)) {
                    foreach($ent_subscription_hold as $hold_sub) {
                        // Concatenar main_service y addons_services
                        $services_in_hold = $hold_sub->main_service;
                        if (!empty($hold_sub->addons_services)) {
                            $services_in_hold .= ',' . $hold_sub->addons_services;
                        }
                        
                        // Verificar si alguno de los name_keys está en los servicios HOLD
                        foreach($name_keys_to_check as $name_key) {
                            if (strpos($services_in_hold, $name_key) !== false) {
                                $has_cancelled_subscription = true;
                                break 2; // Salir de ambos foreach
                            }
                        }
                    }
                }
            }
            
            $msl_description = '';
            $coverage_msl = $total_msl_coverage;
            $total_msl_coverage = $total_msl_coverage + count($previous_services);
            if($coverage_msl == 1){
                $msl_description = '(covers 1 type of treatment)';
            }else if($coverage_msl > 1){
                $msl_description = '(covers ' . $total_msl_coverage . ' types of treatments)';
            }
            // buscando al menos un agreement msl
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
                // Validar que el índice esté dentro del rango del array
                $index = max(0, min($total_msl_coverage - 1, count($this->prices_msl) - 1));
                $previos_total_msl = !empty($ent_subscription) ? $ent_subscription->total : 0;
                $sub_arr[] = [
                    'type' => 'Software subscription',
                    'description' => $msl_description,
                    'subtotal' => $this->prices_msl[$index] - $previos_total_msl
                    ];
                $_total_msl += $this->prices_msl[$index] - $previos_total_msl;
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
                    // Validar que el índice esté dentro del rango del array
                    $index = max(0, min($total_msl_coverage - 1, count($this->prices_msl) - 1));
                    $previos_total_msl = !empty($ent_subscription) ? $ent_subscription->total : 0;
                    $sub_arr[] = [
                        'type' => 'Software subscription',
                        'description' => $msl_description,
                        'subtotal' => $this->prices_msl[$index] - $previos_total_msl
                        ];
                    $_total_msl += $this->prices_msl[$index] - $previos_total_msl;
                }/*else{
                    $subs[] = [
                        'title' => $ent_agreements->agreement_title,
                        'uid' => $ent_agreements->uid,
                        'content' => $ent_agreements->content,
                        'require_mdsub' => false,
                        'signed' => false,
                    ];
                }*/
            }
            if($sub_active == false){
                if($has_cancelled_subscription || $is_other_schools || $is_iv_therapy){
                    $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today and recurring monthly: $' . ($_total_md + $_total_msl) / 100 . '</p>';
                }else{
                    $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today $0</p>';
                    $content .= '<p style="font-size: 28px; font-weight: bold; text-align: center;">The first month is free</p>';
                }
            }else{
                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                $ent_payments_subscription = $this->DataSubscriptionPayments->find()
                    ->where([
                        'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                        'DataSubscriptionPayments.status'  => 'DONE',
                        'DataSubscriptionPayments.user_id' => USER_ID,
                        'DataSubscriptionPayments.payment_type' => 'FULL',
                        'DataSubscriptionPayments.deleted' => 0
                    ])
                    ->order(['DataSubscriptionPayments.created' => 'DESC'])
                    ->first();
                
                $calculated_date = empty($ent_payments_subscription)
                    ? $ent_subscription->created
                    : $ent_payments_subscription->created;

                $now  = FrozenTime::now();
                $diff = $now->diff($calculated_date);
                $days = $diff->days;
                $expiration_date = $calculated_date->addMonth();
                $next_month_date = $now->addMonth();

                $total_anterior = $ent_subscription->total;
                if($sub_active_md){
                    $total_anterior += $ent_subscription_md->total;
                }
                $preview_total = $_total_md + $_total_msl;

                $next_month_total = $total_anterior + $preview_total;

                $amount = intval(((30 - $days) * $preview_total) / 30);

                if($amount < 100){
                    $amount = 100;
                }

                $prorrated_amount = $preview_total - $amount;

                if($has_cancelled_subscription || $is_other_schools || $is_iv_therapy){
                    $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today: $' . number_format($amount/100, 2) . '</p>';
                }else{
                    $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today $0</p>';
                    $content .= '<p style="font-size: 28px; font-weight: bold; text-align: center;">The first month is free</p>';
                }

                $content .= '<p style="font-size: 20px; font-weight: bold; text-align: center;">Total due on ' . date('m-d-Y', strtotime($next_month_date->i18nFormat('dd-MM-yyyy'))) . ': $' . number_format($prorrated_amount/100, 2) . '</p>';
                $content .= '<p style="font-size: 20px; font-weight: bold; text-align: center;">Total per month after that: $' . number_format($next_month_total/100, 2) . '</p>';
            }
        }else{

            if(!$is_other_schools){
                $ent_data_trainings = $this->DataTrainings
                ->find()->select([
                    'name_key' => 'OtherTreatment.name_key',
                    'name' => 'OtherTreatment.name',
                    'require_mdsub' => 'OtherTreatment.require_mdsub',
                    'msl'             => 'MAX(CatAgreementMSL.uid)',      // <-- antes: CatAgreementMSL.uid
                    'md'              => 'MAX(CatAgreementMD.uid)',       // <-- antes: CatAgreementMD.uid
                    'msl_agreement'   => 'MAX(DataAgreementMSL.id)',      // <-- antes: DataAgreementMSL.id
                    'md_agreement'    => 'MAX(DataAgreementMD.id)',       // <-- antes: DataAgreementMD.id
                    'total_coverage' => "(SELECT COUNT(Cover.id) FROM data_coverage_courses AS Cover WHERE Cover.course_type_id = CourseType.id)"
                ])
                ->join([
                    'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id'],
                    'CourseType' => ['table' => 'cat_courses_type', 'type' => 'INNER', 'conditions' => 'CourseType.name_key = Training.level'],
                    'Coverage' => ['table' => 'data_coverage_courses', 'type' => 'INNER', 'conditions' => 'Coverage.course_type_id = CourseType.id'],
                    'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = Coverage.ot_id AND OtherTreatment.deleted = 0'],
                    'CatAgreementMSL' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMSL.state_id = " . USER_STATE . "
                            AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                            AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                            AND CatAgreementMSL.deleted = 0
                            AND CatAgreementMSL.issue_type = 'MSL'"
                    ],
                    'CatAgreementMD' => [
                        'table'      => 'cat_agreements',
                        'type'       => 'LEFT',
                        'conditions' =>
                            "CatAgreementMD.state_id = " . USER_STATE . "
                            AND (
                                    (CatAgreementMD.agreement_type = 'OTHER_TREATMENTS' AND CatAgreementMD.other_treatment_id = OtherTreatment.id AND CatAgreementMD.issue_type = 'MD')
                                    OR
                                    (CourseType.name_key IN ('MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE','MYSPALIVES_HYBRID_TOX_FILLER_COURSE') AND (CatAgreementMD.agreement_type = 'SUBSCRIPTIONMD' OR CatAgreementMD.agreement_type = 'SUBSCRIPTIONMDFILLERS'))
                                )
                            AND CatAgreementMD.deleted = 0"
                            
                    ],
                    'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.deleted = 0 AND DataAgreementMSL.user_id = ' . USER_ID],
                    'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
                ])
                ->where([
                        'DataTrainings.id' => $data_training_id,
                        'OtherTreatment.deleted' => 0,
                        'OR' => [
                            'CatAgreementMSL.uid IS NOT' => null,
                            'CatAgreementMD.uid IS NOT'  => null,
                        ],
                    ])
                ->group([
                    'OtherTreatment.id',
                    'OtherTreatment.name_key',
                    'OtherTreatment.require_mdsub',
                    'CourseType.id'
                    ])
                ->all();
            }else{
                $ent_data_trainings = $this->SysTreatmentsOt
                ->find()->select([
                    'name_key' => 'SysTreatmentsOt.name_key',
                    'name' => 'SysTreatmentsOt.name',
                    'require_mdsub' => 'SysTreatmentsOt.require_mdsub',
                    'md' => 'CatAgreementMD.uid',
                    'md_agreement' => 'DataAgreementMD.id',
                    //'content_md' => 'CatAgreementMD.content',
                    'total_coverage' => 1,
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
                    'SysTreatmentsOt.deleted' => 0,
                    'OR' => [
                        'CatAgreementMD.uid IS NOT'  => null,
                    ],
                ])
                ->group(['CatAgreementMD.id'])
                ->all();
            }

            // One line per other treatment: queries can return duplicate rows per name_key (e.g. multiple MD agreements); only one main + distinct add-ons.
            if (!empty($ent_data_trainings)) {
                $deduped_ot_rows = [];
                foreach ($ent_data_trainings as $row) {
                    $nk = $row->name_key ?? '';
                    if ($nk === '') {
                        continue;
                    }
                    if (!isset($deduped_ot_rows[$nk])) {
                        $deduped_ot_rows[$nk] = $row;
                    }
                }
                $ent_data_trainings = array_values($deduped_ot_rows);
            }
            
            $total_msl_coverage = 0;
            $_total_md = 0;
            $_total_msl = 0;

            // Obtener todos los name_key para verificar si hay suscripciones canceladas
            $name_keys_to_check = [];
            foreach($ent_data_trainings as $row) {
                $name_keys_to_check[] = $row->name_key;
            }
            
            // Verificar si el usuario tiene suscripciones canceladas para estos servicios
            $has_cancelled_subscription = false;
            if (!empty($name_keys_to_check)) {
                $this->loadModel('SpaLiveV1.DataSubscriptionCancelled');
                
                foreach($name_keys_to_check as $name_key) {
                    $cancelled_subscription = $this->DataSubscriptionCancelled->find()
                        ->join([
                            'DataSubscriptions' => [
                                'table' => 'data_subscriptions',
                                'type' => 'INNER',
                                'conditions' => 'DataSubscriptions.id = DataSubscriptionCancelled.subscription_id'
                            ]
                        ])
                        ->where([
                            'DataSubscriptions.user_id' => USER_ID,
                            'DataSubscriptionCancelled.services_unsubscribe LIKE' => '%' . $name_key . '%'
                        ])
                        ->first();
                    
                    if (!empty($cancelled_subscription)) {
                        $has_cancelled_subscription = true;
                        break;
                    }
                }
                
                // Verificar si el usuario tiene suscripciones en HOLD para estos servicios
                $ent_subscription_hold = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.deleted' => 0,
                    'DataSubscriptions.status IN' => array('HOLD')
                ])->all();
                
                if (!empty($ent_subscription_hold)) {
                    foreach($ent_subscription_hold as $hold_sub) {
                        // Concatenar main_service y addons_services
                        $services_in_hold = $hold_sub->main_service;
                        if (!empty($hold_sub->addons_services)) {
                            $services_in_hold .= ',' . $hold_sub->addons_services;
                        }
                        
                        // Verificar si alguno de los name_keys está en los servicios HOLD
                        foreach($name_keys_to_check as $name_key) {
                            if (strpos($services_in_hold, $name_key) !== false) {
                                $has_cancelled_subscription = true;
                                break 2; // Salir de ambos foreach
                            }
                        }
                    }
                }
            }

            $sub_arr = [];

            foreach($ent_data_trainings as $row) {
                $total_msl_coverage = $row->total_coverage;
                if ($row->total_coverage != count($ent_data_trainings)) $total_msl_coverage = count($ent_data_trainings);
                if (!empty($row->md_agreement) && !empty($row->msl_agreement) && (empty($row->md) || empty($row->msl)) ) {
                    continue;
                }
                
                $tmp_total = 0;
                
                if (!empty($row->md) && !empty($row->md_agreement) && $row->require_mdsub == 1) {
                    $main_addon = 'Add on';
                    if ($_total_md == 0 && $sub_active == false) {
                        $_total_md += $this->total_subscription_ot_main_md;
                        $tmp_total = $this->total_subscription_ot_main_md;
                        $main_addon = 'Main';
                    } else {
                        $_total_md += $this->total_subscription_ot_addon_md;
                        $tmp_total = $this->total_subscription_ot_addon_md;
                    }
                    
                    $sub_arr[] = [
                    'type' => $row->name . ' MD Subscription',
                    'description' => $main_addon == 'Main' ? '' : '(Additional subscription)',
                    'subtotal' => $tmp_total
                    ];
                }
            }

            $msl_description = '';
            $total_msl_coverage = $total_msl_coverage + count($previous_services);
            if($total_msl_coverage == 1){
                $msl_description = '(covers 1 type of treatment)';
            }else if($total_msl_coverage > 1){
                $msl_description = '(covers ' . $total_msl_coverage . ' types of treatments)';
            }
            // buscando al menos un agreement msl
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
                // Validar que el índice esté dentro del rango del arra
                $index = max(0, min($total_msl_coverage - 1, count($this->prices_msl) - 1));
                $previos_total_msl = !empty($ent_subscription) ? $ent_subscription->total : 0;
                $sub_arr[] = [
                    'type' => 'Software subscription',
                    'description' => $msl_description,
                    'subtotal' => $this->prices_msl[$index] - $previos_total_msl
                    ];
                $_total_msl += $this->prices_msl[$index] - $previos_total_msl;
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
                    // Validar que el índice esté dentro del rango del array
                    $index = max(0, min($total_msl_coverage - 1, count($this->prices_msl) - 1));
                    $previos_total_msl = !empty($ent_subscription) ? $ent_subscription->total : 0;
                    $sub_arr[] = [
                        'type' => 'Software subscription',
                        'description' => $msl_description,
                        'subtotal' => $this->prices_msl[$index] - $previos_total_msl
                        ];
                    $_total_msl += $this->prices_msl[$index] - $previos_total_msl;
                }/*else{
                    $subs[] = [
                        'title' => $ent_agreements->agreement_title,
                        'uid' => $ent_agreements->uid,
                        'content' => $ent_agreements->content,
                        'require_mdsub' => false,
                        'signed' => false,
                    ];
                }*/
            }

            // Si el usuario tiene una suscripción cancelada para alguno de estos servicios, debe pagar
            if($has_cancelled_subscription || $is_other_schools){
                $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today and recurring monthly: $' . ($_total_md + $_total_msl) / 100 . '</p>';
            }else{
                $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today $0</p>';
                $content .= '<p style="font-size: 28px; font-weight: bold; text-align: center;">The first month is free</p>';
            }

            if($sub_active){
                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
                $ent_payments_subscription = $this->DataSubscriptionPayments->find()
                    ->where([
                        'DataSubscriptionPayments.subscription_id' => $ent_subscription->id,
                        'DataSubscriptionPayments.status'  => 'DONE',
                        'DataSubscriptionPayments.user_id' => USER_ID,
                        'DataSubscriptionPayments.payment_type' => 'FULL',
                        'DataSubscriptionPayments.deleted' => 0
                    ])
                    ->order(['DataSubscriptionPayments.created' => 'DESC'])
                    ->first();
                
                $calculated_date = empty($ent_payments_subscription)
                    ? $ent_subscription->created
                    : $ent_payments_subscription->created;

                $now  = FrozenTime::now();
                $diff = $now->diff($calculated_date);
                $days = $diff->days;
                $expiration_date = $calculated_date->addMonth();
                $next_month_date = $now->addMonth();

                $total_anterior = $ent_subscription->total;
                if($sub_active_md){
                    $total_anterior += $ent_subscription_md->total;
                }
                $preview_total = $_total_md + ($_total_msl - $ent_subscription->total);

                $next_month_total = $total_anterior + $preview_total;

                $amount = intval(((30 - $days) * $preview_total) / 30);

                if($amount < 100){
                    $amount = 100;
                }

                $content .= '<p style="font-size: 20px; font-weight: bold; text-align: center;">Total due on ' . date('m-d-Y', strtotime($next_month_date->i18nFormat('dd-MM-yyyy'))) . ': $' . number_format($amount/100, 2) . '</p>';
                $content .= '<p style="font-size: 20px; font-weight: bold; text-align: center;">Total per month after that: $' . number_format($next_month_total/100, 2) . '</p>';
            }

        }

        $this->set('content', $content);
        $this->set('total', $_total_md + $_total_msl);
        $this->set('data', $sub_arr);
        $this->success();
    }

    public function get_ot_subscription_info_schools() {
        

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


        $course_id = get('course_id',0);
        if ($course_id == 0) $this->message('Invalid course.');


        $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('CatCourses');
        $ent_data_trainings = $this->CatCourses
        ->find()->select([
            'name' => 'OtherTreatment.name_key',
            'require_mdsub' => 'OtherTreatment.require_mdsub',
            'msl' => 'CatAgreementMSL.uid',
            'md' => 'CatAgreementMD.uid',
            'md_agreement' => 'DataAgreementMD.id',
            'msl_agreement' => 'DataAgreementMSL.id',
        ])
        ->join([
            'CatSchoolOptionCert' => ['table' => 'cat_school_option_cert', 'type' => 'LEFT', 'conditions' => 'CatSchoolOptionCert.id = CatCourses.school_option_id'],
            'OtherTreatment' => ['table' => 'sys_treatments_ot', 'type' => 'INNER', 'conditions' => 'OtherTreatment.id = CatSchoolOptionCert.sys_treatment_ot_id AND OtherTreatment.deleted = 0'],
            'CatAgreementMSL' => [
                'table'      => 'cat_agreements',
                'type'       => 'LEFT',
                'conditions' =>
                    "CatAgreementMSL.state_id = " . USER_STATE . "
                     AND CatAgreementMSL.agreement_type = 'OTHER_TREATMENTS'
                     AND CatAgreementMSL.other_treatment_id = OtherTreatment.id
                     AND CatAgreementMSL.deleted = 0
                     AND CatAgreementMSL.issue_type = 'MSL'"
            ],
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
            'DataAgreementMSL' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMSL.agreement_uid = CatAgreementMSL.uid AND DataAgreementMSL.deleted = 0 AND DataAgreementMSL.user_id = ' . USER_ID],
            'DataAgreementMD' => ['table' => 'data_agreements', 'type' => 'LEFT', 'conditions' => 'DataAgreementMD.agreement_uid = CatAgreementMD.uid AND DataAgreementMD.deleted = 0 AND DataAgreementMD.user_id = ' . USER_ID],
        ])
        ->where([
                'CatCourses.id' => $course_id,
                'OtherTreatment.deleted' => 0,
                'OR' => [
                    'CatAgreementMSL.uid IS NOT' => null,
                    'CatAgreementMD.uid IS NOT'  => null,
                ],
            ])
        ->group(['CatAgreementMD.id','CatAgreementMSL.id'])
        ->all();

        // name_key is selected as "name"; avoid duplicate MD/MSL lines per treatment when multiple agreement rows exist
        if (!empty($ent_data_trainings)) {
            $deduped_school_ot = [];
            foreach ($ent_data_trainings as $row) {
                $nk = $row->name ?? '';
                if ($nk === '') {
                    continue;
                }
                if (!isset($deduped_school_ot[$nk])) {
                    $deduped_school_ot[$nk] = $row;
                }
            }
            $ent_data_trainings = array_values($deduped_school_ot);
        }

        $_total_md = 0;
        $_total_msl = 0;


       
        $sub_arr = [];
        foreach($ent_data_trainings as $row) {
        
            if (!empty($row->md_agreement) && !empty($row->msl_agreement) && (empty($row->md) || empty($row->msl)) ) {
                continue;
            }
            
            $tmp_total = 0;
            
            if (!empty($row->md) && !empty($row->md_agreement) && $row->require_mdsub == 1) {
                $main_addon = 'Add on';
                if ($_total_md == 0) {
                    $_total_md += $this->total_subscription_ot_main_md;
                    $tmp_total = $this->total_subscription_ot_main_md;
                    $main_addon = 'Main';
                } else {
                    $_total_md += $this->total_subscription_ot_addon_md;
                    $tmp_total = $this->total_subscription_ot_addon_md;
                }
                
                 $sub_arr[] = [
                    'type' => 'MD ' . $main_addon,
                    'description' => $row->name,
                    'subtotal' => $tmp_total
                 ];
            }


            if (!empty($row->msl) && !empty($row->msl_agreement)) {
                $main_addon = 'Add on';
                if ($_total_msl == 0) {
                    $_total_msl += $this->total_subscription_ot_main_msl;
                    $tmp_total = $this->total_subscription_ot_main_msl;
                    $main_addon = 'Main';
                } else {
                    $_total_msl += $this->total_subscription_ot_addon_msl;
                    $tmp_total = $this->total_subscription_ot_addon_msl;
                }
                
                 $sub_arr[] = [
                    'type' => 'MSL ' . $main_addon,
                    'description' => $row->name,
                    'subtotal' => $tmp_total
                 ];
            }
                
        }

        $this->set('total', $_total_md + $_total_msl);
        $this->set('data', $sub_arr);

    }

    public function resubscription_monthly($user_id, $payment_method){
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

        $this->loadModel('SpaLiveV1.DataSubscriptions');

        $ent_subscriptions_md = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user_id,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('HOLD'), 'DataSubscriptions.subscription_type LIKE' => '%MD%'])->last();

        $ent_subscriptions_msl = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => $user_id,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('HOLD'), 'DataSubscriptions.subscription_type LIKE' => '%MSL%'])->last();

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

        if(!empty($ent_subscriptions_md) && !empty($ent_subscriptions_msl)){
            $total_amount = $ent_subscriptions_md->total + $ent_subscriptions_msl->total;

            $stripe_result = '';
            $error = '';
            
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => intval($total_amount),
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => '1-Month Subscription',
                ]);
            } catch (\Stripe\Exception\ApiConnectionException $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';

            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
                $this->loadModel('SpaLiveV1.SysUserAdmin');
                $md_id = $this->SysUserAdmin->getAssignedDoctorInjector((int)$user_id);

                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => $user_id,
                    'subscription_id' => $ent_subscriptions_msl->id,
                    'total' => $ent_subscriptions_msl->total,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'FULL',
                    'payment_description' => 'SUBSCRIPTIONMSL',
                    'main_service' => $ent_subscriptions_msl->main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($ent_subscriptions_msl->main_service => $ent_subscriptions_msl->total)),
                    'state' => USER_STATE,
                    'md_id' => 0,
                );

                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }

                $this->DataSubscriptions->updateAll(
                    ['status' => 'ACTIVE'],
                    ['id' => $ent_subscriptions_msl->id]
                );

                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => $user_id,
                    'subscription_id' => $ent_subscriptions_md->id,
                    'total' => $ent_subscriptions_md->total,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'FULL',
                    'payment_description' => 'SUBSCRIPTIONMD',
                    'main_service' => $ent_subscriptions_md->main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($ent_subscriptions_md->main_service => $ent_subscriptions_md->total)),
                    'state' => USER_STATE,
                    'md_id' => $md_id,
                );

                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }

                $this->DataSubscriptions->updateAll(
                    ['status' => 'ACTIVE'],
                    ['id' => $ent_subscriptions_md->id]
                );

                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'],
                    ['id' => $user_id]
                );

                $this->success();

                return;
            } else {
                $this->message($error);
                return;
            }
        }

        if(!empty($ent_subscriptions_msl)){
            $total_amount = $ent_subscriptions_msl->total;

            $stripe_result = '';
            $error = '';
            
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => intval($total_amount),
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => '1-Month Subscription',
                ]);
            } catch (\Stripe\Exception\ApiConnectionException $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';

            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => $user_id,
                    'subscription_id' => $ent_subscriptions_msl->id,
                    'total' => $total_amount,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'FULL',
                    'payment_description' => 'SUBSCRIPTIONMSL',
                    'main_service' => $ent_subscriptions_msl->main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($ent_subscriptions_msl->main_service => $total_amount)),
                    'state' => USER_STATE,
                    'md_id' => 0,
                );

                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }

                $this->DataSubscriptions->updateAll(
                    ['status' => 'ACTIVE'],
                    ['id' => $ent_subscriptions_msl->id]
                );

                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'],
                    ['id' => $user_id]
                );

                $this->success();
            }else{
                $this->message($error);
                return;
            }
        }

        if(!empty($ent_subscriptions_md)){
            $total_amount = $ent_subscriptions_md->total;

            $stripe_result = '';
            $error = '';
            
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => intval($total_amount),
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => '1-Month Subscription',
                ]);
            } catch (\Stripe\Exception\ApiConnectionException $e) {
                // Network communication with Stripe failed
                $error = $e->getMessage();
            } catch(\Stripe\Exception\CardException $e) {
                // Since it's a decline, \Stripe\Exception\CardException will be caught
                $error = $e->getMessage();
            } catch (\Stripe\Exception\RateLimitException $e) {
                // Too many requests made to the API too quickly
                $error = $e->getMessage();
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                // Invalid parameters were supplied to Stripe's API
                $error = $e->getMessage();
            } catch (\Stripe\Exception\AuthenticationException $e) {
                // Authentication with Stripe's API failed
                // (maybe you changed API keys recently)
                $error = $e->getMessage();
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Display a very generic error to the user, and maybe send
                // yourself an email
                $error = $e->getMessage();
            }

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';

            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
                $this->loadModel('SpaLiveV1.SysUserAdmin');
                $md_id = $this->SysUserAdmin->getAssignedDoctorInjector((int)$user_id);

                $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => $user_id,
                    'subscription_id' => $ent_subscriptions_md->id,
                    'total' => $total_amount,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'FULL',
                    'payment_description' => 'SUBSCRIPTIONMD',
                    'main_service' => $ent_subscriptions_md->main_service,
                    'addons_services' => '',
                    'payment_details' => json_encode(array($ent_subscriptions_md->main_service => $total_amount)),
                    'state' => USER_STATE,
                    'md_id' => $md_id,
                );

                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }

                $this->DataSubscriptions->updateAll(
                    ['status' => 'ACTIVE'],
                    ['id' => $ent_subscriptions_md->id]
                );

                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'],
                    ['id' => $user_id]
                );

                $this->success();
            } else {
                $this->message($error);
                $this->success(false);
                return;
            }
        }
    }

    public function resubscription_monthly_cancel($user_id, $payment_method){
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

        $this->loadModel('SpaLiveV1.DataSubscriptions');

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

        $total_amount = $this->total_subscriptionmsl + $this->total_subscriptionmd;

        $stripe_result = '';
        $error = '';
        
        try {
            $stripe_result = \Stripe\PaymentIntent::create([
                'amount' => intval($total_amount),
                'currency' => 'usd',
                'customer' => $customer['id'],
                'payment_method' => $payment_method,
                'off_session' => true,
                'confirm' => true,
                'description' => '1-Month Subscription',
            ]);
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network communication with Stripe failed
            $error = $e->getMessage();
        } catch(\Stripe\Exception\CardException $e) {
            // Since it's a decline, \Stripe\Exception\CardException will be caught
            $error = $e->getMessage();
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Too many requests made to the API too quickly
            $error = $e->getMessage();
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters were supplied to Stripe's API
            $error = $e->getMessage();
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Authentication with Stripe's API failed
            // (maybe you changed API keys recently)
            $error = $e->getMessage();
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Display a very generic error to the user, and maybe send
            // yourself an email
            $error = $e->getMessage();
        }

        $receipt_url = '';
        $id_charge = '';
        $payment_id = '';

        if(isset($stripe_result->charges->data[0]->receipt_url)) {
            $receipt_url = $stripe_result->charges->data[0]->receipt_url;
            $id_charge = $stripe_result->charges->data[0]->id;
            $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
            $payment_id = $stripe_result->id;
        }

        if(empty($error) && $stripe_result->status == 'succeeded') {

            $this->loadModel('SpaLiveV1.SysUserAdmin');
            $md_id = $this->SysUserAdmin->getAssignedDoctorInjector((int)$user_id);

            $this->loadModel('SpaLiveV1.DataSubscriptions');
            $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
            $Payments = new PaymentsController();
            // sub msl
            $array_save = array(
                'user_id' => USER_ID,
                'uid' => Text::uuid(),
                'event' => 'resubscription_monthly_cancel',
                'payload' => '',
                'request_id' => '',
                'data_object_id' => '',
                'customer_id' => $customer['id'],
                'payment_method' => $payment_method,
                'subscription_type' => 'SUBSCRIPTIONMSL',
                'promo_code' =>  get('promo_code',''),
                'subtotal' => 3995,
                'total' => $this->total_subscriptionmsl,
                'status' => 'ACTIVE',
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s'),
                'agreement_id' => 0,
                'comments' => '',
                'main_service' => 'NEUROTOXINS',
                'addons_services' => '',
                'payment_details' => json_encode(array('NEUROTOXINS' => $this->total_subscriptionmsl)),
                'state' => USER_STATE,
            );

            $c_entity = $this->DataSubscriptions->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $sub = $this->DataSubscriptions->save($c_entity);

                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => $user_id,
                    'subscription_id' => $sub->id,
                    'total' => $this->total_subscriptionmsl,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'FULL',
                    'payment_description' => 'SUBSCRIPTIONMSL',
                    'main_service' => 'NEUROTOXINS',
                    'addons_services' => '',
                    'payment_details' => json_encode(array('NEUROTOXINS' => $this->total_subscriptionmsl)),
                    'state' => USER_STATE,
                    'md_id' => 0,
                );
    
                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }
            }

            $array_save = array(
                'user_id' => USER_ID,
                'uid' => Text::uuid(),
                'event' => 'resubscription_monthly_cancel',
                'payload' => '',
                'request_id' => '',
                'data_object_id' => '',
                'customer_id' => $customer['id'],
                'payment_method' => $payment_method,
                'subscription_type' => 'SUBSCRIPTIONMD',
                'promo_code' =>  get('promo_code',''),
                'subtotal' => 3995,
                'total' => $this->total_subscriptionmd,
                'status' => 'ACTIVE',
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s'),
                'agreement_id' => 0,
                'comments' => '',
                'main_service' => 'NEUROTOXINS',
                'addons_services' => '',
                'payment_details' => json_encode(array('NEUROTOXINS' => $this->total_subscriptionmd)),
                'state' => USER_STATE,
            );

            $c_entity = $this->DataSubscriptions->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $sub = $this->DataSubscriptions->save($c_entity);

                $array_save = array(
                    'uid' => Text::uuid(),
                    'user_id' => $user_id,
                    'subscription_id' => $sub->id,
                    'total' => $this->total_subscriptionmd,
                    'payment_id' => $payment_id,
                    'charge_id' => $id_charge,
                    'receipt_id' => $receipt_url,
                    'error' => '',
                    'status' => 'DONE',
                    'notes' => '',
                    'created' => date('Y-m-d H:i:s'),
                    'deleted' => 0,
                    'payment_type' => 'FULL',
                    'payment_description' => 'SUBSCRIPTIONMD',
                    'main_service' => 'NEUROTOXINS',
                    'addons_services' => '',
                    'payment_details' => json_encode(array('NEUROTOXINS' => $this->total_subscriptionmd)),
                    'state' => USER_STATE,
                    'md_id' => $md_id,
                );
    
                $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSubscriptionPayments->save($c_entity);
                }
            }

            $this->SysUsers->updateAll(
                ['steps' => 'HOME'],
                ['id' => $user_id]
            );

            $this->success();

            return;
        } else {
            $this->message($error);
            return;
        }
    }

    /**
     * Generate a random alpha-numeric string (used by multiple controllers).
     */
    protected function generateRandomString(int $length = 8): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }

        return $randomString;
    }


    public function reset_password_email_template($resetLink){
        $emailBodyHtml = '
            <div style="font-family:Arial,Helvetica,sans-serif;background:#f4f6f8;padding:30px">
            <div style="max-width:600px;margin:auto;background:#ffffff;border-radius:8px;padding:30px">

                <h2 style="color:#1D6782;margin-top:0;">Welcome to MySpaLive 👋</h2>

                <p>
                Congratulations on enrolling! We\'re excited to have you onboard.
                </p>

                <div style="background:#eef6f9;padding:15px;border-radius:6px;margin-top:20px">
                <strong>Next Step: Create Your Password</strong>
                <p style="margin:10px 0 0 0;">
                    To complete your onboarding process, please create your password using the link below.
                </p>
                </div>

                <h3 style="margin-top:25px;color:#333;">How to Get Started</h3>

                <ol style="line-height:1.6;color:#444">
                <li>
                    <strong>Create your MySpaLive password</strong><br>
                    Click the button below to create your password.
                </li>

                <li style="margin-top:10px">
                    <strong>Log in to your account</strong><br>
                    Use your email and new password to access your training materials.
                </li>
                </ol>

                <div style="text-align:center;margin:30px 0">
                <a href="' . htmlspecialchars($resetLink) . '" 
                    style="background:#1D6782;color:#ffffff;padding:14px 28px;
                    text-decoration:none;border-radius:5px;font-weight:bold;">
                    Create Your Password
                </a>
                </div>

                <p style="font-size:14px;color:#666;">
                If the button doesn’t work, copy and paste this link into your browser:
                </p>

                <p style="word-break:break-all;font-size:14px">
                <a href="' . htmlspecialchars($resetLink) . '">' . htmlspecialchars($resetLink) . '</a>
                </p>

                <hr style="margin:30px 0;border:none;border-top:1px solid #eee">

                <p style="font-size:13px;color:#777">
                Need help? Contact us at 
                <a href="mailto:support@port2pay.com">support@port2pay.com</a>
                </p>

            </div>
            </div>
        ';

        return $emailBodyHtml;
    }
}