<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Core\Configure;
use Cake\Utility\Security;
use Cake\Utility\Text;
use Cake\Utility\Hash;
use PHPUnit\Framework\Constraint\Count;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;
use \PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

require_once(ROOT . DS . 'vendor' . DS  . 'Twilio' . DS . 'autoload.php');
use Twilio\Rest\Client; 
use Twilio\Exceptions\TwilioException;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\SubscriptionController;
use Spipu\Html2Pdf\Debug\Debug;

class PaymentsController extends AppPluginController{

    public $total_subscription_ot_main_msl = 3995;
    public $total_subscription_ot_main_md = 17900;
    public $total_subscription_ot_addon_msl = 2000;
    public $total_subscription_ot_addon_md = 8500;
    public $prices_msl = [3995, 5995, 6995, 7495, 7995, 7995, 7995, 7995, 7995, 7995, 7995, 7995];

    private $total = 3900;
    private $paymente_gfe = 2500;
    private $register_total = 89500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 4000;
    private $shipping_cost_inj = 4000;
    private $shipping_cost_mat = 1000;
    private $shipping_cost_misc = 1000;
    private $shipping_cost_vial = 4000;
    private $ship_cost_mat = 1000;
    private $ship_cost_miscell = 1000;
    private $ship_cost_neuro = 4000;
    private $ship_cost_fill = 4000;
    private $ship_cost_vial = 4000;
    private $training_basic = 89500;
    private $training_advanced = 89500;
    private $level_3_fillers = 150000;//level 3 fillers
    private $level_3_medical = 99500;//level 3 medical
    private $level_1_to_1 = 19999;//level 1 to 1
    private $emergencyPhone = "9035301512";
    private $total_subscriptionmsl = 3995;
    private $total_subscriptionmd = 17900;
    private $total_subscriptionmslservice = 9900;
    private $total_services = 9900;
    private $URL_API = "";
    private $URL_WEB = "";
    private $URL_ASSETS = "";
    private $URL_PANEL = "";
    private $amount_cancel_treatment = 5000;
    private $weightloss_total = 178500;
    private $weightloss_monthly = 59500;
    private $weightloss_discount = 48600;
    private $full_comission = 10000;
    private $half_comission = 5000;

    protected $mailgunKey = null;

    /**
     * When any line in data_purchases_detail has this product_id, purchase promo codes
     * and Elite Club line discount are not applied. Override with env PURCHASE_PROMO_EXCLUDED_PRODUCT_ID.
     */
    protected $purchasePromoExcludedProductId = 24;

    protected function getMailgunKey(): ?string
    {
        if ($this->mailgunKey === null) {
            $this->mailgunKey = env('MAILGUN_API_KEY');
        }
        return $this->mailgunKey;
    }

    /**
     * @param array|\ArrayAccess[] $purchaseDetails Rows from DataPurchasesDetail (product_id on each row).
     */
    private function purchaseContainsPromoExcludedProduct($purchaseDetails): bool
    {
        if (empty($purchaseDetails)) {
            return false;
        }
        $excludedId = $this->purchasePromoExcludedProductId;
        foreach ($purchaseDetails as $row) {
            $pid = null;
            if (is_array($row)) {
                $pid = $row['product_id'] ?? null;
            } elseif (is_object($row) && isset($row->product_id)) {
                $pid = $row->product_id;
            }
            if ((int) $pid === $excludedId) {
                return true;
            }
        }
        return false;
    }

	public function initialize() : void{
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.myspalive.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.myspalive.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
        $this->purchasePromoExcludedProductId = (int) env('PURCHASE_PROMO_EXCLUDED_PRODUCT_ID', (string) $this->purchasePromoExcludedProductId);
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

        $product_fillers = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 178])->first();
        if(!empty($product_fillers)){
            $this->level_3_fillers = $product_fillers->unit_price > 0 ? $product_fillers->unit_price : $this->level_3_fillers;
        }

        $product_l3_medical = $this->CatProducts->find()->select(['CatProducts.unit_price'])->where(['CatProducts.id' => 184])->first();
        if(!empty($product_l3_medical)){
            $this->level_3_medical = $product_l3_medical->unit_price > 0 ? $product_l3_medical->unit_price : $this->level_3_medical;
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

        $payment_method = get('payment_method', '');

        if(empty($payment_method) || $payment_method === ''){
            $this->message('The payment method has failed or is empty.');
            return;
        }
 
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
        $new_card = $stripe->paymentMethods->retrieve(
            $payment_method,
            []
        );

        if(empty($new_card)) {
            $this->message('The payment method has failed.');
            return;
        }
 
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

        $payment_methods = $stripe->customers->allPaymentMethods(
            $customer->id,
            ['type' => 'card']
        );

        foreach($payment_methods as $row) {
            if($row->card->fingerprint == $new_card->card->fingerprint) {
                $this->message('The payment method already exists.');
                return;
            }
        }

        $error = '';

        try {
            $intent = \Stripe\SetupIntent::create([
                'confirm' => true,
                'customer' => $customer['id'],
                'payment_method' => $payment_method
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
            if ($e->getError()->code == 'card_declined') {
                $error = "Payment method failed. Your card was declined.";
            }else{
                $error = $e->getMessage();
            }
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

        if(!empty($error)){
            $this->message($error);
            return;
        }

        $client_secret = $intent->client_secret;
        
        $this->set('secret', $client_secret);

        if(USER_TYPE == 'patient'){
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.DataModelPatient');
            $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();
            $isModelPatient = $this->DataModelPatient->find()->where(['DataModelPatient.email LIKE' => strtolower($userEntity['email'])])->first();
            if (empty($isModelPatient)) {
                if($userEntity->steps == 'TREATMENTINFO' || $userEntity->steps == 'LONGFORMOFFCODE'){
                    
                }else{
                    $userEntity->steps = 'HOME';
                }
                // If are a patient model, does not update the step
            }
            $userEntity->last_status_change = date('Y-m-d H:i:s');
            $this->SysUsers->save($userEntity);
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $ent_subscriptions = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('HOLD'), 'DataSubscriptions.subscription_type IN' => array('SUBSCRIPTIONMSL', 'SUBSCRIPTIONMD')])->all();
        if(count($ent_subscriptions) > 0){
            // shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . USER_UID . ' > /dev/null 2>&1 &');
            shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . USER_UID);
        }
        
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
        $entMethod = $this->DataSubscriptionMethodPayments->find()->where(['DataSubscriptionMethodPayments.user_id' => USER_ID, 'DataSubscriptionMethodPayments.deleted' => 0])->first();
        if (!empty($entMethod)) {
            $entMethod->payment_id = $payment_method;
            $entMethod->error = 0;
            $entMethod->created = date('Y-m-d H:i:s');
            $entMethod->deleted = 0;
            $entMethod->preferred = 1;
            $this->DataSubscriptionMethodPayments->save($entMethod);

            $this->success();
        } else {
            $array_save = array(
                'user_id' => USER_ID,
                'payment_id' => $payment_method,
                'preferred' => 1,
                'error' => 0,
                'created' => date('Y-m-d H:i:s')
            );

            $c_entity = $this->DataSubscriptionMethodPayments->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataSubscriptionMethodPayments->save($c_entity)) {
                    $this->success();
                }
            }
        }

        $this->success();
    }

    public function payment_intent_basic_course() {

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

        $str_desc = "Basic trainning";

        $total_amount = $this->validateCode(get('promo_code',''),$this->register_total,'REGISTER');

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
                
        $oldCustomer = $stripe->customers->all([
            "email" => USER_EMAIL,
            "limit" => 1,
        ]);

        if (count($oldCustomer) > 0) {
            $customer = $oldCustomer->data[0];  
            $payment_methods = $stripe->customers->allPaymentMethods(
                $customer->id,
                ['type' => 'card']
            );
            
            $arr_payment_methods = array();
            foreach($payment_methods as $method) {
                $arr_payment_methods[] = $method->id;
            }   
            $paid = $this->execPayment(USER_ID,$total_amount,$customer->id,$arr_payment_methods,$str_desc);
            if($paid){
                $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                $userEntity->steps = 'SELECTREFERRED';
                $userEntity->last_status_change = date('Y-m-d H:i:s');
                $this->SysUsers->save($userEntity);
                $this->success();
            }
        }
    }

    public function apply_promo_purchase() {


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

       $purchase_uid = get('uid', '');
       if(empty($purchase_uid)){
           $this->message('uid empty.');
           return;
       }
       $this->loadModel('SpaLiveV1.DataPurchases');
       $this->loadModel('SpaLiveV1.DataPurchasesDetail');
       $ent_purchase = $this->DataPurchases->find()->where(['DataPurchases.uid' => $purchase_uid])->first();
       if(empty($ent_purchase)){
           $this->message('Treatment not found');
           return;
       }

       $type_purchase = 'PURCHASE';

       // VALIDATE IF IS TRAINING

       $level2_id = 44; // FIEX ID OF Advanced training

       $_ent_purchases =$this->DataPurchasesDetail->find()
        ->select([
            'DataPurchasesDetail.id',
            'DataPurchasesDetail.purchase_id',
            'DataPurchasesDetail.product_id',
            'DataPurchasesDetail.price',
            'DataPurchasesDetail.qty',
            'DataPurchasesDetail.shipped_qty',
            'DataPurchasesDetail.refunded',
            'DataPurchasesDetail.refunded_amount',
            'DataPurchasesDetail.product_number',
            'DataPurchasesDetail.serial_number',
            'DataPurchasesDetail.lot_number',
            'DataPurchasesDetail.expiration_date',
            'DataPurchasesDetail.product_detail_question',
            'DataPurchasesDetail.product_detail',
            'Product.category'
        ])
        ->join([
            'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id'],
        ])
        ->where(['DataPurchasesDetail.purchase_id' => $ent_purchase->id])->toArray();

        if (!empty($_ent_purchases)) {
            if (count($_ent_purchases) == 1) {
               if ($_ent_purchases[0]['product_id'] == 44 ){ //Advanced training
                    $type_purchase = 'TRAINING';
                } else if ($_ent_purchases[0]['product_id'] == 178) {//fillers
                    $type_purchase = 'FILLERS';
                } else if ($_ent_purchases[0]['product_id'] == 184) {//Level 3 Elite Techniques Course
                    $type_purchase = 'LEVEL3';
                } else if ($_ent_purchases[0]['product_id'] == 185) {//ToxTune-Up Sessions
                    $type_purchase = 'TOXTUNEUP';
                }
            }
        }

        $isSingleCoursePurchase = (count($_ent_purchases) === 1
            && in_array($type_purchase, ['TRAINING', 'LEVEL3', 'FILLERS', 'TOXTUNEUP'], true));

        $code = strtoupper(trim(get('promo_code', '')));
        $this->loadModel('SpaLiveV1.DataCodeEc');
        $ent_promo = $this->DataCodeEc->find()->last();

        $total_amount = 0;
        $skipPurchasePromo = $this->purchaseContainsPromoExcludedProduct($_ent_purchases);
        if ($skipPurchasePromo) {
            $total_amount = $this->validateCode('', ($ent_purchase->amount), $type_purchase) + $ent_purchase->shipping_cost;
        } elseif(!empty($ent_promo)){
            if($ent_promo->code == $code){
                $this->loadModel('SpaLiveV1.DataEliteClub');
                $elite = $this->DataEliteClub->find()->where(['user_id' => USER_ID, 'active' => 1, 'deleted' => 0])->first();
                if(!empty($elite)){
                    $discount_amount = 0;
                    foreach ($_ent_purchases as $value) {
                        if(($value['Product']['category'] == "NEUROTOXINS" || $value['Product']['category'] == 'NEUROTOXIN PACKAGES' || $value['Product']['category'] == 'FILLERS' || $value['Product']['category'] == 'IV VIALS') && ($value['product_id'] != 59 && $value['product_id'] != 1)){
                            $total_amount += ($value['price'] * $value['qty']) - (($value['price'] * $value['qty']) * ($ent_promo->discount / 100));
                            $discount_amount += ($value['price'] * $value['qty']) * ($ent_promo->discount / 100);
                        }else{
                            $total_amount += $value['price'] * $value['qty'];
                        }
                    }

                    if($discount_amount == 0){
                        if ($isSingleCoursePurchase) {
                            $stripe_fee = 0;
                            $this->set('code_valid', true);
                            $this->set('discount_type', '');
                            $this->set('discount', 0);
                            $this->set('discount_amount', $discount_amount);
                            $this->set('stripe_fee', 0);
                            $this->set('discount_text', '');
                            $total_amount = $total_amount + $ent_purchase->shipping_cost;
                        } else {
                            $stripe_fee = intval(($total_amount + $ent_purchase->shipping_cost) * 0.0315);
                            $this->set('code_valid', true);
                            $this->set('discount_type', '');
                            $this->set('discount', 0);
                            $this->set('discount_amount', $discount_amount);
                            $this->set('stripe_fee', $stripe_fee);
                            $this->set('discount_text', '');
                            $total_amount = $total_amount + $stripe_fee + $ent_purchase->shipping_cost;
                        }
                    }else{
                        if ($isSingleCoursePurchase) {
                            $stripe_fee = 0;
                            $this->set('code_valid', true);
                            $this->set('discount_type', $ent_promo->type);
                            $this->set('discount', $ent_promo->discount);
                            $this->set('discount_amount', $discount_amount);
                            $this->set('stripe_fee', 0);
                            $this->set('discount_text', '-' . $ent_promo->discount . '% Elite Club discount has been applied.');
                            $total_amount = $total_amount + $ent_purchase->shipping_cost;
                        } else {
                            $stripe_fee = intval(($total_amount + $ent_purchase->shipping_cost) * 0.0315);
                            $this->set('code_valid', true);
                            $this->set('discount_type', $ent_promo->type);
                            $this->set('discount', $ent_promo->discount);
                            $this->set('discount_amount', $discount_amount);
                            $this->set('stripe_fee', $stripe_fee);
                            $this->set('discount_text', '-' . $ent_promo->discount . '% Elite Club discount has been applied.');
                            $total_amount = $total_amount + $stripe_fee + $ent_purchase->shipping_cost;
                        }
                    }

                    
                }
                else{
                    $total_amount = $this->validateCode($code,($ent_purchase->amount),$type_purchase) + $ent_purchase->shipping_cost;
                }
            }else{
                $total_amount = $this->validateCode($code,($ent_purchase->amount),$type_purchase) + $ent_purchase->shipping_cost;
            }
        }else{
            $total_amount = $this->validateCode($code,($ent_purchase->amount),$type_purchase) + $ent_purchase->shipping_cost;
        }


       // $promo_code = get('promo_code','');
       // if (!empty($promo_code)) {
       //     if ($promo_code == 'dsct99') {
       //         $multiplier = 0.01;
       //         $total_amount = $ent_purchase->amount * $multiplier;
       //     }
       // }

       if ($total_amount < 100) $total_amount = 100;

       if (get('use_credits',0)) {
           $total_credits = $this->checkClinicCredits();
           if ($total_credits > 0) {
               if ($total_amount > 100) {
                   $this->set('use_credits', 1);
                   if ($total_amount == $total_credits) {
                       $total_amount = 100;
                       $this->set('discount_credits', $total_credits - 100);
                   } else if ($total_amount > $total_credits) {
                       $total_amount = $total_amount - $total_credits;
                       $this->set('discount_credits', $total_credits);
                       if ($total_amount < 100) {
                           $total_amount = 100;
                           $this->set('discount_credits', $total_credits + (100 - $total_amount));
                       }
                   } else {
                       $use_c = $total_credits - $total_amount + 100;
                       $this->set('discount_credits', $total_amount - 100);
                       $total_amount = 100;
                   }
               } else {
                   $this->set('use_credits', 0);
               }
           } else {
               $this->set('use_credits', 0);
           }
       }

       $this->set('total', $total_amount);
       $this->success();

    }

    private function execPayment($subtotal,$total,$customer_id,$payment_method,$str_desc) {
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        $stripe_result = '';
        $error = '';

        try {
            $stripe_result = \Stripe\PaymentIntent::create([
            'amount' => $total,
            'currency' => 'usd',
            'customer' => $customer_id,
            'payment_method' => $payment_method,
            'off_session' => true,
            'confirm' => true,
            'description' => $str_desc
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
        $paymen_intent = '';
        if (isset($stripe_result->charges->data[0]->receipt_url)) {
            $receipt_url = $stripe_result->charges->data[0]->receipt_url;
            $id_charge = $stripe_result->charges->data[0]->id;
            $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
            $payment_id = $stripe_result->id;
        }    

        if (empty($error)) {
            $this->createPaymentRegister($str_desc, USER_ID, 0, USER_UID, $paymen_intent, $id_charge, $receipt_url, $subtotal, $total);
            return true;
        }

        return false;
    }

    public function createPaymentRegister($type, $from, $to, $uid, $intent, $payment, $receipt, $subtotal, $total,$discount_credits = 0, $prepaid = 0, $payment_option = 0, $total_cash = 0) {

        $promo_code = strtoupper(get('promo_code',''));
        $promo_discount = $this->validateCodeMultiplier($promo_code,$type,$subtotal);

        if($promo_discount == 0) {
            $promo_code = '';
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $commision_payed = 1;
        if ($type == 'REFUND' || $type == 'GFE COMMISSION' || $type == 'CI COMMISSION' || $type == 'TIP COMMISSION') $commision_payed = 0;
         $array_save = array(
            'id_from' => $from,
            'id_to' => $to,
            'uid' => $uid,
            'type' => $type, //'CI REGISTER','PURCHASE','GFE','TREATMENT','COMISSION','REFUND'
            'intent' => $intent,
            'payment' => $payment,
            'receipt' => $receipt,
            'discount_credits' => isset($discount_credits) ? $discount_credits : 0,
            'promo_discount' => $promo_discount,
            'promo_code' =>  $promo_code,
            'subtotal' => $subtotal,
            'total' => $total,
            'prod' => 1,
            'is_visible' => 1,
            'comission_payed' => $commision_payed,
            'comission_generated' => 0,
            'prepaid' => $prepaid,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => defined('USER_ID') ? USER_ID : 0,
            'payment_option' => $payment_option,
            'state' => defined('USER_ID') ? USER_STATE : 43,
            'total_cash' => $total_cash,
        );
        $this->log(__LINE__ . ' ' . json_encode($array_save));
        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataPayment->save($c_entity); 
        } else {

        }

    }

    public function apply_promo_cot() {
        
        $promo_code = strtoupper(get('promo_code',''));

        $training_id = get('training_id', 0);

        if($training_id <= 0){
            $this->message('Invalid training ID.');
            return;
        }

        $this->loadModel('SpaLiveV1.CatCoursesType');
        $this->loadModel('SpaLiveV1.DataPromoCodes');

        $training = $this->CatCoursesType->find()
            ->select([
                'course_id' => 'CatCoursesType.id',
                'price' => 'CatCoursesType.price',
                'image' => 'CatCoursesType.image',
                'discount_id' => 'CatCoursesType.discount_id',
                'description' => 'CatCoursesType.description',
                'offer_id' => 'CatCoursesType.offer_id',
            ])
            ->where(['CatCoursesType.id' => $training_id])
        ->first();

        if(empty($training)){
            $this->message('Training not found.');
            return;
        }

        $total_training = $training->price;
        
        $total_amount = $this->validateCode($promo_code,$total_training,'OTCOURSE',[],$training->course_id);
        
        if ($training->discount_id != 0) {
            $ent_code = $this->DataPromoCodes->find()->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.id' => $training->discount_id])->first();
            if (!empty($ent_code)) {
            $default_discount = $ent_code->code;
                if($promo_code == $default_discount){
                    $this->set('text_discount', "Today's Discount: -$100 (valid for today only!)");
                }
            }
        }
        

        $this->set('total', intval($total_amount));

        $this->success();
    }

    public function request_register() {
        $default_discount = 'ELITE300B';
        $multiplier = 1.0;
        $total_amount = $this->register_total;
        $total_refund = $this->register_total - $this->register_refund;
        $promo_code = strtoupper(get('promo_code',''));
        
        $total_amount = $this->validateCode($promo_code,$this->register_total,'REGISTER');
        if ($total_amount < $this->register_total) {
            $total_refund = 0;
        }

        if($promo_code == $default_discount){
            $this->set('text_discount', "Today's Discount: -$300 (valid for today only!)");
        }

        $this->set('total', intval($total_amount));
        $this->set('refund', intval($total_refund));
        $this->success();
    }

    public function request_training_advanced() {

        $default_discount = 'ELITE300A';
        $promo_code = strtoupper(get('promo_code',''));
        $total_amount = $this->validateCode($promo_code,$this->training_advanced,'TRAINING');

        if($promo_code == $default_discount){
            $this->set('text_discount', "Today's Discount: -$300 (valid for today only!)");
        }

        $this->set('total', intval($total_amount));
        $this->success();
        
    }

    public function request_training_medical() {

        $default_discount = 'ELITE300';
        $promo_code = strtoupper(get('promo_code',''));
        $total_amount = $this->validateCode($promo_code,$this->level_3_medical,'LEVEL3');

        if($promo_code == $default_discount){
            $this->set('text_discount', "Today's Discount: -$300 (valid for today only!)");
        }

        $this->set('total', intval($total_amount));
        $this->success();
        
    }

    public function validateCodeOutside($code,$subtotal,$category,$treatment_array = array()){
        return $this->validateCode($code,$subtotal,$category,$treatment_array);
    }

    public function validateCode($code,$subtotal,$category,$treatment_array = array(),$course_id = 0) {

        $this->loadModel('SpaLiveV1.DataPromoCodes');

        // Course checkouts: do not pass Stripe processing fee to the customer (display or charge).
        $courseCategoriesNoStripePass = ['REGISTER', 'TRAINING', 'LEVEL3', 'OTCOURSE'];

        $ent_codes = $this->DataPromoCodes->find()
        ->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.code' => strtoupper($code)])->first();
        if (!empty($ent_codes)) {

            if ($ent_codes->category != 'ALL' && $ent_codes->category != $category) {
                $this->set('code_valid', false);
                $this->set('stripe_fee', in_array($category, $courseCategoriesNoStripePass, true) ? 0 : intval($subtotal * 0.0315));
                return $subtotal;
            }

            // OTHER COURSES VALIDATION START***
            if($category == 'OTCOURSE' && $ent_codes->course_type_id > 0 && $ent_codes->course_type_id != $course_id){
                $this->set('code_valid', false);
                $this->set('stripe_fee', 0);
                return $subtotal;
            }
            
            // OTHER COURSES VALIDATION END***

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
                if ($category == 'GFE') {
                    if ($ent_codes->category == 'GFE' && $ent_codes->discount == 99) $total = 100;
                    else if ($total <= $this->paymente_gfe) $total = $this->paymente_gfe; 
                }
                else if ($total < 100) $total = 100;

                if (in_array($category, $courseCategoriesNoStripePass, true)) {
                    $this->set('stripe_fee', 0);
                    return round($total);
                }
                $this->set('stripe_fee', intval($total * 0.0315));
                if($category == 'PURCHASE' || $category == 'TOXTUNEUP' || $category == 'FILLERS'){
                    $total = ($total * 0.0315) + $total;
                    $this->set('discount_text', ' -' . $ent_codes->discount . '% added');
                }
                return round($total);
            } else if ($ent_codes->type == 'AMOUNT') {
                $percent = round( ($ent_codes->discount/$subtotal) * 100);
                if ( $percent > 99) $percent = 99;
                $this->set('discount', $percent);
                $this->set('discount_amount', $ent_codes->discount);
                $this->set('discount_text', '$' . ($ent_codes->discount / 100) . ' discount has been applied.');
 
                $total = $subtotal - $ent_codes->discount;
                if ($category == 'GFE') {
                    if ($total <= $this->paymente_gfe) $total = $this->paymente_gfe;
                }
                else if ($total < 100) $total = 100;

                if (in_array($category, $courseCategoriesNoStripePass, true)) {
                    $this->set('stripe_fee', 0);
                    return round($total);
                }
                $this->set('stripe_fee', intval($total * 0.0315));
                if($category == 'PURCHASE' || $category == 'TOXTUNEUP' || $category == 'FILLERS'){
                    $total = ($total * 0.0315) + $total;
                    $this->set('discount_text', ' -$' . ($ent_codes->discount / 100) . ' added');
                }
                return round($total);
            }
        }

        if($category == 'TREATMENT' && !empty($treatment_array)){
            $assistance_id = USER_ID;
            if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
                $_where = [
                    'DataGiftCards.code' => $code,
                    'DataGiftCards.deleted' => 0,
                    'DataGiftCards.active' => 1,
                    'DataGiftCards.user_id' => USER_ID,
                    '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                    
                ];

                $_where['OR'] = [
                    ['DataGiftCards.receipt_id' => 0,],
                    ['DataGiftCards.receipt_id' => $treatment_array['patient_id']]
                ];

            } else if(USER_TYPE == 'patient'){
                $assistance_id = $treatment_array['assistance_id'];
                $_where = [
                    'DataGiftCards.code' => $code,
                    'DataGiftCards.deleted' => 0,
                    'DataGiftCards.active' => 1,
                    'DataGiftCards.user_id' => $treatment_array['assistance_id'],
                    'DataGiftCards.receipt_id' => USER_ID,
                    '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                ];
            }

            // pr($_where);exit;
            $this->loadModel('SpaLiveV1.DataGiftCards');
            $ent_gift = $this->DataGiftCards->find()->select(['DataGiftCards.id','DataGiftCards.discount','Treatment.id'])->join([
                'Treatment' => ['table' => 'data_treatment', 'type' => 'LEFT', 'conditions' => 'Treatment.promo_code = "' . $code . '" AND Treatment.assistance_id = ' . $assistance_id . ' AND Treatment.payment <> ""'],
                ])
            ->where($_where)
            ->first();
            // pr($ent_gift);exit;
            if(!empty($ent_gift) ){
                $percent = round( ($ent_gift->discount/$subtotal) * 100);
                if ( $percent > 99) $percent = 99;
                $this->set('discount', $percent);
                $this->set('discount_amount', $ent_gift->discount);
                $this->set('discount_text', '$' . ($ent_gift->discount / 100) . ' discount has been applied.');
                $_dis =  $subtotal - $ent_gift->discount;
                /*$this->DataGiftCards->updateAll(
                    ['discount' => ($_dis < 0 ? abs($_dis):0)],
                    ['id' => $ent_gift->id]
                );*/
                $total = $subtotal - $ent_gift->discount;
                if ($total < 100) $total = 100;
                $this->set('code_valid', true);
                $subtotal = round($total);
                return $subtotal;
            }
        }
        $this->set('code_valid', false);

        if (in_array($category, $courseCategoriesNoStripePass, true)) {
            $this->set('stripe_fee', 0);
            return round($subtotal);
        }
        $this->set('stripe_fee', intval($subtotal * 0.0315));
        if($category == 'TRAINING' || $category == 'REGISTER' || $category == 'PURCHASE'){
            $subtotal = ($subtotal * 0.0315) + $subtotal;
        }
        return round($subtotal);


    }

    private function validateCodeMultiplier() {
        if($this->getParams('code_valid')){
            return $this->getParams('discount');
        }
        return 0;

        /*$this->loadModel('SpaLiveV1.DataPromoCodes');

        $ent_codes = $this->DataPromoCodes->find()
        ->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.code' => strtoupper($code)])->first();
        if (!empty($ent_codes)) {

            if ($ent_codes->category != 'ALL' && $ent_codes->category != $category) {
                return 0;
            }

            if ($ent_codes->type == 'PERCENTAGE') {
                return $ent_codes->discount;
            } else if ($ent_codes->type == 'AMOUNT') {
                return round( ($ent_codes->discount/$subtotal) * 100);
            }
        }

        return 0;/**/
    }

    public function GetPaymentMethods(){

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

        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');

        $methods_array = array();
        $preferred_method = '';
        $entMethod = $this->DataSubscriptionMethodPayments->find()->where(['DataSubscriptionMethodPayments.user_id' => USER_ID, 'DataSubscriptionMethodPayments.deleted' => 0])->first();
        if (!empty($entMethod)) {
            $preferred_method = $entMethod->payment_id;
        }

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
        $oldCustomer = $stripe->customers->all([
            "email" => USER_EMAIL,
            "limit" => 1,
        ]);

        if (count($oldCustomer) > 0) {
           $customer = $oldCustomer->data[0];  

           $payment_methods = $stripe->customers->allPaymentMethods(
                $customer->id,
                ['type' => 'card']
            );
           
            if (empty($entMethod) && count($payment_methods) > 0) {
                $array_save = array(
                    'user_id' => USER_ID,
                    'payment_id' => $payment_methods->data[0]->id,
                    'preferred' => 1,
                    'error' => 0,
                    'created' => date('Y-m-d H:i:s')
                );
                $preferred_method = $payment_methods->data[0]->id;
                $c_entity = $this->DataSubscriptionMethodPayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) $this->DataSubscriptionMethodPayments->save($c_entity);
            }

            $preferred_ = false;
            foreach($payment_methods as $method) {
                $preferred = $method->id == $preferred_method ? 1 : 0;
                if($preferred == 1) $preferred_ = true;
                $methods_array[] = array(
                    'id' => $method->id,
                    'name' => strtoupper($method->card->brand) . ' ' . 'XXXX' . $method->card->last4,
                    'billing_details' => $method->billing_details->address,
                    'name_billing_address' => $method->billing_details->name,
                    'preferred' => $preferred,
                    'error' => $preferred == 1 && !empty($entMethod) ? $entMethod->error : 0
                ); 
            } 
            
            if (!$preferred_ && count($payment_methods) > 0) {

                $entMethod->payment_id = $payment_methods->data[0]->id;
                $entMethod->error = 0;
                $entMethod->created = date('Y-m-d H:i:s');
                $entMethod->deleted = 0;
                $entMethod->preferred = 1;
                $this->DataSubscriptionMethodPayments->save($entMethod);

                $methods_array[0]['preferred'] = 1;
            }

            if(!empty($entMethod) && count($payment_methods) <= 0){
                $this->loadModel('SpaLiveV1.DataSubscriptions');
                $ent_subscription = $this->DataSubscriptions->find()->where([
                    'DataSubscriptions.subscription_type IN' => array('SUBSCRIPTIONMSL','SUBSCRIPTIONMD'),
                    'DataSubscriptions.user_id' => USER_ID,
                    'DataSubscriptions.status' => 'ACTIVE',
                    'DataSubscriptions.deleted' => 0
                ])->all();
                /*if(count($ent_subscription) > 0){
                    foreach($ent_subscription as $subscription){
                        $this->DataSubscriptions->updateAll(
                            ['status' => 'HOLD'],
                            ['id' => $subscription->id]
                        );
                    }
                    $Main = new MainController();
                    $Main->sendEmalSubscriptionHold(USER_EMAIL);
                }*/
            }
        }

        array_multisort(array_column($methods_array,'preferred'), SORT_DESC, $methods_array);

        $this->set('methods', $methods_array);

        $this->success();
    }

    public function payment_intent_course() {

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatCourses');
        $this->loadModel('SpaLiveV1.DataPayment');
        
        $token = get('token', '');
        $Ghl = new GhlController();
        $Main = new MainController();
        
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

        $payment_method = get('payment_method', '');

        if(empty($payment_method) || $payment_method == ''){
            $this->message('Payment method empty.');
            return;
        }

        $course = get('type_course', '');

        if(empty($course) || $course == ''){
            $this->message('Empty course.');
            return;
        }

        $default_discount = get('default_discount', 0);

        $subtotal = 0;

        $payment_uid = '';

        if($course == 'NEUROTOXINS BASIC'){
            $where = ['DataPayment.uid' => USER_UID, 'DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1, 'DataPayment.type IN' => array('REGISTER', 'BASIC COURSE'), 'DataPayment.refund_id ' => 0];
            $twice_pay = $this->DataPayment->find()->where($where)->last();

            if (!empty($twice_pay)) {
                $twice_pay_refund = $this->DataPayment->find()
                ->where(['DataPayment.uid' => USER_UID, 'DataPayment.is_visible' => 1])->last();
                if($twice_pay_refund->type == 'REFUND' && $twice_pay_refund->total == $twice_pay->total){

                }else{
                    $this->message('You have already paid for the basic course.');
                    return;
                }
            }

            $total_amount = $this->validateCode(get('promo_code',''),$this->register_total,'REGISTER');

            // Default discount disabled
            // if($default_discount){
            //     $total_amount = $this->register_total - 30000;
            //     $total_amount = round(($total_amount * 0.0315) + $total_amount);
            // }

            $subtotal = $this->register_total;
            $payment_uid = USER_UID;
        }else if($course == 'NEUROTOXINS ADVANCED'){
            $twice_pay = $this->DataPayment->find()->where(['DataPayment.id_from' => USER_ID, 'DataPayment.is_visible' => 1, 'DataPayment.payment <>' => '', 'DataPayment.type' => 'ADVANCED COURSE', 'DataPayment.refund_id '=> 0])->first();
            if(!empty($twice_pay)){
                $this->message('You have already paid for the advanced course.');
                return;
            }
            $total_amount = $this->validateCode(get('promo_code',''),$this->training_advanced,'TRAINING');

            // Default discount disabled
            // if($default_discount){
            //     $total_amount = $this->training_advanced - 30000;
            //     $total_amount = round(($total_amount * 0.0315) + $total_amount);
            // }

            $subtotal = $this->training_advanced;
            $payment_uid = Text::uuid();
        } else if($course == 'ADVANCED TECHNIQUES MEDICAL'){
            $total_amount = $this->validateCode(get('promo_code',''),$this->level_3_medical,'TRAINING');

            // Default discount disabled
            // if($default_discount){
            //     $total_amount = $this->level_3_medical - 30000;
            //     $total_amount = round(($total_amount * 0.0315) + $total_amount);
            // }

            $subtotal = $this->level_3_medical;
            $payment_uid = Text::uuid();
        } else {
            $this->loadModel('SpaLiveV1.CatCoursesType');

            $ent_course = $this->CatCoursesType->find()
            ->where(['CatCoursesType.name_key' => $course])->first();

            if(empty($ent_course)){
                $this->message('Invalid course.');
                return;
            }

            $total_amount = $this->validateCode(get('promo_code',''),$ent_course->price,'OTCOURSE',[],$ent_course->id);

            $subtotal = $ent_course->price;
            $payment_uid = Text::uuid();
        }

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
                
        $oldCustomer = $stripe->customers->all([
            "email" => USER_EMAIL,
            "limit" => 1,
        ]);

        if (count($oldCustomer) > 0) {
            $customer = $oldCustomer->data[0];
            $type_string = '';
            $step = '';

            $temp_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.deleted' => 0])->first();
            if($temp_user->steps != 'HOME' && $temp_user->steps != 'WAITINGSCHOOLAPPROVAL'){
                
                if($course == 'NEUROTOXINS BASIC'){
                    $type_string = 'BASIC COURSE';
                    $step = 'SELECTBASICCOURSE';
                }else if($course == 'NEUROTOXINS ADVANCED'){
                    $type_string = 'ADVANCED COURSE';
                    $step = $temp_user->steps;
                } else if($course == 'ADVANCED TECHNIQUES MEDICAL'){
                    $step = $temp_user->steps;
                } else {
                    $type_string = $course;
                    $step = 'LICENCEOT';
                }
                
            }else{
                $step = $temp_user->steps;
            }
            
            switch ($course) {
                case 'NEUROTOXINS BASIC':
                    $type_string = 'BASIC COURSE';
                    break;
                case 'NEUROTOXINS ADVANCED':
                    $type_string = 'ADVANCED COURSE';
                    break;
                case 'ADVANCED TECHNIQUES MEDICAL':
                    $type_string = 'ADVANCED TECHNIQUES MEDICAL';
                    break;
                default:
                    $type_string = $course;
                    break;
            }

            $paid = false;

            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';

            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'usd',
                    'customer' => $customer->id,
                    'payment_method' => $payment_method,
                    'metadata' => ['state' => USER_STATE],
                    'off_session' => true,
                    'confirm' => true,
                    'description' => $type_string,
                    'error_on_requires_action' => true,
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
            $paymen_intent = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }    
            
            if (empty($error) && $stripe_result->status == 'succeeded') {
                $this->createPaymentRegister($type_string, USER_ID, 0, $payment_uid, $paymen_intent, $id_charge, $receipt_url, $subtotal, $total_amount);
                // create an agreement refund, charge 50 dls
                $this->loadModel('SpaLiveV1.SysUsers');
                $ent_user = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                $affirm = new AffirmController();
                $affirm->create_agrrement_refund($type_string, $ent_user->name ." ". $ent_user->lname, USER_ID);
                $paid = true;


            } else {
                $this->message('Payment failed. Declined card. Please try again.');
            }

            if($paid){

                $userEntity = $this->SysUsers->find()->where(['id' => USER_ID])->first();
                $userEntity->steps = $step;
                $userEntity->login_status = 'READY';
                $userEntity->last_status_change = date('Y-m-d H:i:s');
                $userEntity->active = 1;

                #region Pay comission to sales representative
                $this->loadModel('SpaLiveV1.DataAssignedToRegister');
                $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
                if($course == 'NEUROTOXINS BASIC' || $course == 'NEUROTOXINS ADVANCED' || $course == 'ADVANCED TECHNIQUES MEDICAL'){
                    $Main->notify_devices('AFTER_BUY_BASIC_COURSE_EN',array(USER_ID),false,true);
                } else{
                    $Main->notify_devices('PURCHASE_OT_'.$ent_course->id,array(USER_ID),false,true);
                }

                if($course == 'NEUROTOXINS BASIC'){
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
                    if($course == 'NEUROTOXINS BASIC'){  $this->log(__LINE__ . ' ' . json_encode('NEUROTOXINS BASIC'));
                        $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the basic training purchase for $' . $total_amount / 100, $Main);
                        $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the basic training purchase for $' . $total_amount / 100;
                        $Main->send_email_after_register($assignedRep['User']['email'],'Basic training purchase',$msg);//($to, $subject, $body) 
                        
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
                                $this->full_comission = 5000;
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
                            $amount_comission = $amount_comission == 0 ? 0 : 5000;
                        }else if($representative->rank == 'JUNIOR' && empty($existUser)){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                            $amount_comission = $amount_comission == 0 ? 0 : 5000;
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
                            $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
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

                    }else if($course == 'NEUROTOXINS ADVANCED'){$this->log(__LINE__ . ' ' . json_encode('NEUROTOXINS ADVANCED'));
                        $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the advanced training purchase for $' . $total_amount / 100;
                        $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the advanced training purchase for $' . $total_amount / 100, $Main);
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

                        if($representative->team == 'INSIDE'){ // Si el representante es INSIDE entonces pagamos con normalidad la comision y le generamos senior otra comision de $50
                            if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                                $amount_comission = $amount_comission == 0 ? 0 : 5000;
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
                                $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
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
                            $amount_comission = $amount_comission == 0 ? 0 : 10000;
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
                                $amount_comission = $amount_comission == 0 ? 0 : 5000;
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
                                $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
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
                            $amount_comission = $amount_comission == 0 ? 0 : 10000;
                        }
                    }else{
                        // Enviar SMS para otros cursos (OTCOURSE)
                        $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the ' . $type_string . ' training purchase for $' . $total_amount / 100, $Main);
                        $msg = 'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', has completed the ' . $type_string . ' training purchase for $' . $total_amount / 100;
                        $Main->send_email_after_register($assignedRep['User']['email'],'Training purchase',$msg);

                        $value_discount = $this->getParams('discount_amount', 0);

                        if ($value_discount <= 30000){
                            $amount_comission = $this->full_comission;
                        } else if($value_discount >= 30100){
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
                        $this->log(__LINE__ . ' ' . json_encode($array_save_comission));
                        $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                        $this->DataSalesRepresentativePayments->save($c_entity_comission);
                        $service = 'Training';
                        $this->send_email_team_member_courses(USER_ID, $service, $type_string, $amount_comission, $assignedRep);
                        //Assign inside sales rep
                        if($course == 'NEUROTOXINS BASIC') $this->assignRepInside();
                    }
                    }
                }

                #endregion

                
                $this->saveAdvancedReceipt(USER_EMAIL);

                // $array_data = array(
                //     'email' => USER_EMAIL,
                //     'name' => USER_NAME,
                //     'lname' => USER_LNAME,
                //     'phone' => USER_PHONE,
                //     'costo' => $total_amount / 100,
                //     'course' => $course == 'NEUROTOXINS BASIC' ? 'Basic' : 'Advanced',
                // );

                // if(!env('IS_DEV', false)){
                //     $contactId = $Ghl->updateOpportunity($array_data);
                //     if ($contactId)
                //         $tag = $Ghl->addTag($contactId, $array_data['email'], $array_data['phone'], $type_string);
                // }

                $this->SysUsers->save($userEntity);
                $this->success();
            }
        }
    }

    public function send_email_sales_team_member($injector_id, $service, $subscription_type, $subscription_sub, $amount, $salesRep){
        $this->loadModel('SpaLiveV1.SysUsers');
        
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $injector_id])->first();
        $full_name = $ent_user->name . ' ' . $ent_user->lname;
        //$service = strtolower($service);
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
                        <p><strong>Subscription type:</strong> '.$subscription_type.'</p>
                        <p><strong>Subscription subtype:</strong> '.$subscription_sub.'</p>
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

    public function send_email_team_member_courses($injector_id, $service, $training, $amount, $salesRep) {
        $this->loadModel('SpaLiveV1.SysUsers');
         $this->log(__LINE__ . ' ' . json_encode(array($injector_id, $service, $training, $amount, $salesRep)));
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
        curl_setopt($curl, CURLOPT_USERPWD,'api:' . $mailgunKey);
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

    public function payment_intent_reschedule_fee(){
        
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

        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');

        $stripe_user_email = USER_EMAIL;
        $stripe_user_name = USER_NAME . ' ' . USER_LNAME;

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
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

        $arr_payment_methods = array();

        $ent_payment_methods = $this->DataSubscriptionMethodPayments->find()->where(['user_id' => USER_ID, 'deleted' => 0])->order(['DataSubscriptionMethodPayments.preferred' => 'DESC','DataSubscriptionMethodPayments.id' => 'DESC'])->toArray();

        if (!empty($ent_payment_methods)) {
            foreach($ent_payment_methods as $rowp) {
                $arr_payment_methods[] = $rowp->payment_id;
            }
        }
        if(count($arr_payment_methods) == 0){
            $response = array(
                'res_flag' => false,
                'error' => 'There was an error charging cancellation fee. Add new payment method',
            );
            $this->set('response', $response);            
            return $response;
        }

        foreach($arr_payment_methods as $pm){
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';
            $res_flag = false;
            $total_amount = 5000;
            $transfer_uid = Text::uuid();
            $this->set('arr_payment_methods',$arr_payment_methods);
            try {
                $intent = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'USD',
                    'receipt_email' => $stripe_user_email,
                    'confirm' => true,
                    'description' => 'RESCHEDULE FEE',
                    'customer' => $customer['id'],
                    'payment_method' => $pm,
                    'metadata' => ['state' => USER_STATE],
                    'error_on_requires_action' => true,
                    'transfer_group' => $transfer_uid,
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
            if (isset($intent->charges->data[0]->receipt_url)) {
                $receipt_url = $intent->charges->data[0]->receipt_url;
                $id_charge = $intent->charges->data[0]->id;
                $payment_id = $intent->id;
            }
            
            if (empty($error) && $intent->status == 'succeeded') {
                $this->set('code_valid', false);
                $this->createPaymentRegister('RESCHEDULE FEE', USER_ID, 0, $transfer_uid, $payment_id, $id_charge, $receipt_url, $total_amount, $total_amount);
                $res_flag = true;
            }
        }

        $response = array(
            'res_flag' => $res_flag,
            'error' => $error,
        );
        $this->set('response', $response);
        //$this->message('Payment failed. Declined card. Please try again.');
        return $response;
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

    public function payment_intent_register() {

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

        $total_amount = $this->validateCode(get('promo_code',''),$this->register_total,'REGISTER');
        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        
        $oldCustomer = $stripe->customers->all([
            "email" => USER_EMAIL,
            "limit" => 1,
        ]);

        if (count($oldCustomer) == 0) {
            $customer = $stripe->customers->create([
                'description' => USER_NAME,
                'email' => USER_EMAIL,
            ]);
        } else $customer = $oldCustomer->data[0];
       
        if ($total_amount < 100) $total_amount = 100;

        $intent = \Stripe\PaymentIntent::create([
          'amount' => $total_amount,
          'currency' => 'USD',
          'metadata' => ['type' => 'register', 'uid' => USER_UID],
          'receipt_email' => USER_EMAIL,
          'customer' => $customer['id'],
          'transfer_group' => USER_UID,
          'description' => 'BASIC COURSE'
        ]);

        $client_secret = $intent->client_secret;
        $arr_pintnt = explode("_secret_", $client_secret);

        $this->createPaymentRegistern('BASIC COURSE', USER_ID, 0, USER_UID, $arr_pintnt[0], $this->register_total, $total_amount);

        $this->set('secret', $client_secret);
        $this->set('total', $total_amount);
        $this->success();
    }

    public function assignRepInside($user_id = 0) {
        if($user_id == 0){
            $user_id = defined('USER_ID') ? USER_ID : 0;
        } 
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativeRegister');
        $this->loadModel('SpaLiveV1.SysUsers');

        $entPatient = $this->SysUsers->find()->select(['SysUsers.name', 'SysUsers.lname', 'SysUsers.mname','SysUsers.phone','State.name'])
        ->join(['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SysUsers.state']])
        ->where(['SysUsers.id' => $user_id])->first();

        if (!empty($entPatient)) {
           if (strpos(strtolower($entPatient->name), 'test') !== false || strpos(strtolower($entPatient->lname), 'test') !== false || strpos(strtolower($entPatient->mname), 'test') !== false) {
                return;
            }
        }

        /*
        $assigned = $this->DataAssignedToRegister->find()->select(['DataAssignedToRegister.id','Rep.id'])->join([
            'Rep' => ['table' => 'data_sales_representative', 'type' => 'INNER', 'conditions' => 'Rep.id = DataAssignedToRegister.cat_id'],
            'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = Rep.admin_user_id'],
        ])->where(['Rep.deleted' => 0,'DataAssignedToRegister.manual' => 0, 'Rep.team' => 'INSIDE', 'SysUsersAdminStates.state_id' => USER_STATE])->order(['DataAssignedToRegister.id' => 'DESC'])->first();

        $findRep = $this->DataSalesRepresentative->find()->select(['User.uid','DataSalesRepresentative.user_id','DataSalesRepresentative.id'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = DataSalesRepresentative.admin_user_id'],
            ])->where(['DataSalesRepresentative.id >' => $assigned['Rep']['id'], 'DataSalesRepresentative.deleted' => 0,'User.deleted' => 0,'DataSalesRepresentative.sales_person' => 1, 'DataSalesRepresentative.team' => 'INSIDE', 'SysUsersAdminStates.state_id' => USER_STATE])
            ->order(['DataSalesRepresentative.id' => 'ASC'])->first();
                
        if (empty($findRep)) {
            $findRep = $this->DataSalesRepresentative->find()->select(['User.uid','DataSalesRepresentative.user_id','DataSalesRepresentative.id'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataSalesRepresentative.user_id'],
                'SysUsersAdminStates' => ['table' => 'sys_users_admin_states', 'type' => 'INNER', 'conditions' => 'SysUsersAdminStates.admin_user_id = DataSalesRepresentative.admin_user_id'],
            ])->where(['DataSalesRepresentative.deleted' => 0,'User.deleted' => 0, 'DataSalesRepresentative.sales_person' => 1, 'DataSalesRepresentative.team' => 'INSIDE', 'SysUsersAdminStates.state_id' => USER_STATE])
            ->order(['DataSalesRepresentative.id' => 'ASC'])->first();
        }*/

       //if (!empty($findRep)) {
            $this->loadModel('SpaLiveV1.DataAssignedJobs');
            $array_save = array(
                'user_id' => $user_id,
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
        //}
    }

    public function payment_intent_gfe() {

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

        $payment_method = get('payment_method', '');

        if(empty($payment_method) || $payment_method == ''){
            $this->message('Payment method empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => USER_ID, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();

        if (!empty($ent_payment)) {
            $this->message('You already have a credit, it is not necessary to pay.');
            return;
        }

        $transfer_group = Text::uuid();
        $prepaid = 1;
        $consultation_id = 0;
        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        
        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

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


        $date_now = date('Y-m-d');
        /*$pastCertificate = $this->DataCertificates->find()->join([
            'Consultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'Consultation.id = DataCertificates.consultation_id'],
            'Plan' => ['table' => 'data_consultation_plan', 'type' => 'INNER', 'conditions' => 'Plan.consultation_id = Consultation.id AND Plan.proceed = 1'],
        ])->where(['Consultation.patient_id' => $user_id,'DataCertificates.date_expiration >' => $date_now, 'DataCertificates.deleted' => 0])->first();

        if (!empty($pastCertificate)) {
            $this->success(false);
            $this->message("You already have a valid certificate.");
            return false;
        }*/
        
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

        $amount = $this->get_amount($consultation_id);
        $total_amount = 100;
        if(USER_TYPE == 'injector'){
            $total_amount = 2500;
            $this->set('code_valid', false);
        }else{
            $total_amount = $this->validateCode(get('promo_code',''),$amount,'GFE');
        }
        
        if ($total_amount < 100) $total_amount = 100;
        try{
            $intent = \Stripe\PaymentIntent::create([
              'customer' => $customer['id'],
              'amount' => $total_amount,
              'payment_method' => $payment_method,
              'confirm' => true,
              'currency' => 'USD',
              'metadata' => ['type' => 'exam', 'uid' => $transfer_group, 'state' => USER_STATE],
              //'receipt_email' => $user['email'],
               'transfer_group' => $transfer_group,
               'error_on_requires_action' => true,
               'description' => 'EXAM',
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
        $paymen_intent = '';
        if (isset($intent->charges->data[0]->receipt_url)) {
            $receipt_url = $intent->charges->data[0]->receipt_url;
            $id_charge = $intent->charges->data[0]->id;
            $paymen_intent = $intent->charges->data[0]->payment_intent;
            $payment_id = $intent->id;
        }

        if (empty($error) && $intent->status == 'succeeded') {
            $client_secret = $intent->client_secret;
            $arr_pintnt = explode("_secret_", $client_secret);
            $this->createPaymentRegister('GFE', $user_id, 0, $transfer_group, $paymen_intent, $id_charge, $receipt_url, $amount, $total_amount, 0, $prepaid);

            $this->loadModel('SpaLiveV1.DataPayment');

            $pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
            ])->where(['DataPayment.uid' => $transfer_group, 'DataPayment.type' => "GFE", 'DataPayment.id_to' => "0"])->first();

            $Main = new MainController();
            $Main->notify_devices('GFE_EXAM_PAYMENT',array($pay->id_from),true,false);

            $Main->send_receipt('GFE_EXAM_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid);

            $this->set('secret', $client_secret);
            $this->set('total', $total_amount);
            $this->set('uid', $transfer_group);
            $this->success();

            $ondemand_flow = get('ondemand_flow', 0);

            if($ondemand_flow == 1){
                $Main->notify_devices('PATIENT_ONDEMAND_FLOW',array($pay->id_from),false,true);
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->SysUsers->updateAll(
                    [  
                        'steps'     => 'PAIDGFE',
                    ], 
                    ['id' => $pay->id_from]
                );
            }
            
            $update_step = get('update_patient_step', 0);

            if($update_step == 1){
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'], 
                    ['id' => USER_ID]
                );

                $this->loadModel('SpaLiveV1.DataTreatment');
                $ent_draft = $this->DataTreatment->find()
                                ->select()
                                ->where(['DataTreatment.patient_id' => USER_ID, 'DataTreatment.status' => 'DRAFT', 'DataTreatment.deleted' => 0])
                                ->first();
                
                if(!empty($ent_draft)){
                    $is_uber = $ent_draft->type_uber; 
                    $status = $is_uber == 1 ? 'PETITION' : 'REQUEST';
                    $notes = $ent_draft->notes;
                    $array_words = array('free','model', 'test', 'f r e e', 'm o d e l', 't e s t');

                    foreach ($array_words as $key => $value) {
                        if (strpos(strtolower($notes), $value) !== false) {
                            $status = $status == 'PETITION' ? 'STOP' : $status;
                            break;
                        }
                    }

                    $ent_name_users = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.type' => 'injector', 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->all();

                    if(Count($ent_name_users) > 0){
                        foreach ($ent_name_users as $key => $value) {
                            $fullname = strtolower(trim($value['name']) . ' ' . trim($value['lname']));

                            if (strpos(strtolower($notes), $fullname) !== false) {
                                $status = $status == 'PETITION' ? 'STOP' : $status;
                                break;
                            }
                        }
                    }

                    if(date('Y-m-d H:i', strtotime(date('Y-m-d H:i').'+ 2 hour')) >= date('Y-m-d H:i', strtotime($ent_draft->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss')))){
                        $status = $status == 'PETITION' ? 'STOP' : $status;
                    }

                    $patients_names = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.id' => USER_ID, 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->toList();
                    if(Count($patients_names) > 0){
                        foreach ($array_words as $key => $value) {
                            $fullname = strtolower(trim($patients_names[0]['name']) . ' ' . trim($patients_names[0]['lname']));
                            if (strpos(strtolower($fullname), $value) !== false) {
                                $status = $status == 'PETITION' ? 'STOP' : $status;
                                break;
                            }
                        }
                    }$this->loadModel('SpaLiveV1.DataModelPatient');
                    $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => $user['email'], 'DataModelPatient.status' => 'assigned', 'DataModelPatient.registered_training_id >' => 0])->first();        
                    if (!empty($ent_patient)) {
                        $this->set('patient_model', true);
                        $status = $status == 'PETITION' ? 'STOP' : $status;
                    } else {
                        $this->set('patient_model', false);            
                    }
                    $assistance_id = $ent_draft->assistance_id;
                    $users_array = array();
                    $now = date('Y-m-d H:i:s');
                    $schedule_date = date('Y-m-d H:i:s', strtotime($ent_draft->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss')));
                    $this->DataTreatment->updateAll(
                        ['status' => $status], 
                        ['id' => $ent_draft->id]
                    );

                    if($status == 'REQUEST'){                    
                        if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < $schedule_date){
                            $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $ent_draft->patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                            $constants = [
                                '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                            ];
                            $Main->notify_devices('NEW_TREATMENT_PATIENT',array($assistance_id),true,true, true, array(), '',$constants,true);
                        }                           
                    }else{
                        // $fields = ['SysUsers.id', 'SysUsers.radius'];
                        // $fields['distance_in_mi'] = "(69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS(".$ent_draft->latitude."))
                        //     * COS(RADIANS(SysUsers.latitude))
                        //     * COS(RADIANS(".$ent_draft->longitude ." - SysUsers.longitude))
                        //     + SIN(RADIANS(".$ent_draft->latitude ."))
                        //     * SIN(RADIANS(SysUsers.latitude))))))";
                        // $fields['subscriptions'] = "(SELECT COUNT(DS.id) FROM data_subscriptions DS WHERE DS.user_id = SysUsers.id AND DS.deleted = 0 AND DS.status = 'ACTIVE' AND DS.subscription_type IN ('SUBSCRIPTIONMD', 'SUBSCRIPTIONMSL') )";                        
                        // $ent_user = $this->SysUsers->find()->select($fields)->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.type' => 'injector','SysUsers.active' => 1,'SysUsers.steps' => 'HOME'])->all();
                        // foreach ($ent_user as $row) {
                        //     // Validacion distancia
                        //     if(env('IS_DEV', false) == false){
                        //         if($row['distance_in_mi'] > $row['radius']) continue;
                        //     }
                        //     // Validacion subscriptions
                        //     if($row['subscriptions'] < 2) continue;
                            
                        //     // Validacion tratamientos avanzados
                        //     $user_training_advanced= $this->DataTrainings->find()->join([
                        //         'CatTrainigs' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainings.training_id'],
                        //         ])->where(['CatTrainigs.level' => 'LEVEL 2','DataTrainings.user_id' => $row['id'],'DataTrainings.deleted' => 0, '(DATE_FORMAT(CatTrainigs.scheduled, "%Y-%m-%d 16:00:00") < "'.$now.'")'])->first();
                        //     $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id' , 'CatCITreatments.std_price','CTC.id', 'CTC.name', 'CTC.type_uber'])
                        //     ->join([
                        //         'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id'],
                        //     ])->where(['CatCITreatments.deleted' => 0, 'FIND_IN_SET(CatCITreatments.id, "' . $ent_draft->treatments . '")'])->all();
                        //     foreach ($ent_treatments as $key => $value) {
                        //         if($value['CTC']['type_uber'] == 'NEUROTOXINS ADVANCED' && empty($user_training_advanced)){
                        //             continue 2;
                        //         }
                        //     }
                        //     $users_array[] = $row['id'];
                        // }
                        // if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < $schedule_date){                        
                        //     $Main->notify_devices('TREATMENT_AVAILABLE',$users_array,true,true, true, array(), '',array(),true);
                        // } 
                    }                                           
                }
            }
        }else {
            $this->message($error);
            return;
        }
    }

    public function payment_intent_gfe_ot() {

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

        $payment_method = get('payment_method', '');

        if(empty($payment_method) || $payment_method == ''){
            $this->message('Payment method empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => USER_ID, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE OT', 'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();

        if (!empty($ent_payment)) {
            $this->message('You already have a credit, it is not necessary to pay.');
            return;
        }

        $transfer_group = Text::uuid();
        $prepaid = 1;
        $consultation_id = 0;
        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        
        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

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


        $date_now = date('Y-m-d');
        
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

        $amount = $this->get_amount($consultation_id);
        $total_amount = 100;
        if(USER_TYPE == 'injector'){
            $total_amount = 2500;
            $this->set('code_valid', false);
        }else{
            $total_amount = $this->validateCode(get('promo_code',''),$amount,'GFE');
        }
        
        if ($total_amount < 100) $total_amount = 100;
        try{
            $intent = \Stripe\PaymentIntent::create([
                'customer' => $customer['id'],
                'amount' => $total_amount,
                'payment_method' => $payment_method,
                'confirm' => true,
                'currency' => 'USD',
                'metadata' => ['type' => 'exam', 'uid' => $transfer_group, 'state' => USER_STATE],
                //'receipt_email' => $user['email'],
                'transfer_group' => $transfer_group,
                'error_on_requires_action' => true,
                'description' => 'EXAM OT',
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
        $paymen_intent = '';
        if (isset($intent->charges->data[0]->receipt_url)) {
            $receipt_url = $intent->charges->data[0]->receipt_url;
            $id_charge = $intent->charges->data[0]->id;
            $paymen_intent = $intent->charges->data[0]->payment_intent;
            $payment_id = $intent->id;
        }

        if (empty($error) && $intent->status == 'succeeded') {
            $client_secret = $intent->client_secret;
            $arr_pintnt = explode("_secret_", $client_secret);
            $this->createPaymentRegister('GFE OT', $user_id, 0, $transfer_group, $paymen_intent, $id_charge, $receipt_url, $amount, $total_amount, 0, $prepaid);

            $this->loadModel('SpaLiveV1.DataPayment');

            $pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
            ])->where(['DataPayment.uid' => $transfer_group, 'DataPayment.type' => "GFE OT", 'DataPayment.id_to' => "0"])->first();
            
            $Main = new MainController();
            $Main->notify_devices('GFE_EXAM_PAYMENT',array($pay->id_from),true,false);

            $Main->send_receipt('GFE_EXAM_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid);

            $this->set('secret', $client_secret);
            $this->set('total', $total_amount);
            $this->set('uid', $transfer_group);
            $this->success();

            $ondemand_flow = get('ondemand_flow', 0);

            if($ondemand_flow == 1){
                $Main->notify_devices('PATIENT_ONDEMAND_FLOW',array($pay->id_from),false,true);
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->SysUsers->updateAll(
                    [  
                        'steps'     => 'PAIDGFE',
                    ], 
                    ['id' => $pay->id_from]
                );
            }
            
            $update_step = get('update_patient_step', 0);

            if($update_step == 1){
                $this->loadModel('SpaLiveV1.SysUsers');
                $this->SysUsers->updateAll(
                    ['steps' => 'HOME'], 
                    ['id' => USER_ID]
                );

                $this->loadModel('SpaLiveV1.DataTreatment');
                $ent_draft = $this->DataTreatment->find()
                                ->select()
                                ->where(['DataTreatment.patient_id' => USER_ID, 'DataTreatment.status' => 'DRAFT', 'DataTreatment.deleted' => 0])
                                ->first();
                
                if(!empty($ent_draft)){
                    $is_uber = $ent_draft->type_uber; 
                    $status = $is_uber == 1 ? 'PETITION' : 'REQUEST';
                    $notes = $ent_draft->notes;
                    $array_words = array('free','model', 'test', 'f r e e', 'm o d e l', 't e s t');

                    foreach ($array_words as $key => $value) {
                        if (strpos(strtolower($notes), $value) !== false) {
                            $status = $status == 'PETITION' ? 'STOP' : $status;
                            break;
                        }
                    }

                    $ent_name_users = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.type' => 'injector', 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->all();

                    if(Count($ent_name_users) > 0){
                        foreach ($ent_name_users as $key => $value) {
                            $fullname = strtolower(trim($value['name']) . ' ' . trim($value['lname']));

                            if (strpos(strtolower($notes), $fullname) !== false) {
                                $status = $status == 'PETITION' ? 'STOP' : $status;
                                break;
                            }
                        }
                    }

                    if(date('Y-m-d H:i', strtotime(date('Y-m-d H:i').'+ 2 hour')) >= date('Y-m-d H:i', strtotime($ent_draft->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss')))){
                        $status = $status == 'PETITION' ? 'STOP' : $status;
                    }

                    $patients_names = $this->SysUsers->find()->select(['SysUsers.name','SysUsers.lname'])->where(['SysUsers.id' => USER_ID, 'SysUsers.active' => 1, 'SysUsers.deleted' => 0])->toList();
                    if(Count($patients_names) > 0){
                        foreach ($array_words as $key => $value) {
                            $fullname = strtolower(trim($patients_names[0]['name']) . ' ' . trim($patients_names[0]['lname']));
                            if (strpos(strtolower($fullname), $value) !== false) {
                                $status = $status == 'PETITION' ? 'STOP' : $status;
                                break;
                            }
                        }
                    }$this->loadModel('SpaLiveV1.DataModelPatient');
                    $ent_patient = $this->DataModelPatient->find()->where(['DataModelPatient.email' => $user['email'], 'DataModelPatient.status' => 'assigned', 'DataModelPatient.registered_training_id >' => 0])->first();        
                    if (!empty($ent_patient)) {
                        $this->set('patient_model', true);
                        $status = $status == 'PETITION' ? 'STOP' : $status;
                    } else {
                        $this->set('patient_model', false);            
                    }
                    $assistance_id = $ent_draft->assistance_id;
                    $users_array = array();
                    $now = date('Y-m-d H:i:s');
                    $schedule_date = date('Y-m-d H:i:s', strtotime($ent_draft->schedule_date->i18nFormat('yyyy-MM-dd HH:mm:ss')));
                    $this->DataTreatment->updateAll(
                        ['status' => $status], 
                        ['id' => $ent_draft->id]
                    );

                    if($status == 'REQUEST'){                    
                        if(date('Y-m-d H:i:s', strtotime($now.'+ 1 hour')) < $schedule_date){
                            $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $ent_draft->patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                            $constants = [
                                '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                            ];
                            $Main->notify_devices('NEW_TREATMENT_PATIENT',array($assistance_id),true,true, true, array(), '',$constants,true);
                        }                           
                    }else{
                        
                    }                                           
                }
            }
        }else {
            $this->message($error);
            return;
        }
    }

    public function payment_intent_gfe_skip_patient_model() {

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
            $this->SysUsers->updateAll(
                ['steps' => 'HOME'], 
                ['id' => USER_ID]
            );
            $this->success();
            return;
           
    }

    public function get_amount($consultation_id = "") {
        if (empty($consultation_id)) return $this->total;

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.amount', 'DataConsultation.treatments'])
        ->where(['DataConsultation.id' => $consultation_id, 'DataConsultation.deleted' => 0])->first();

        if (!empty($ent_consultation)) {

            if ($ent_consultation->amount > 0) {
                return $ent_consultation->amount;
            }

            $arr_treatments = explode(",", $ent_consultation->treatments); 
            $tt = $this->total;
            if (count($arr_treatments) > 4) {
                $tt += $this->total;
            }


            $array_save = array(
                'id' => $consultation_id,
                'amount' => $tt,
            );

            $c_entity = $this->DataConsultation->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultation->save($c_entity)) {
                    return $tt;
                }
            }

            return $tt;
        }

    }

    public function request_payment_treatment_patient(){
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

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment not found');
            return;
        }

        if(!empty($ent_treatment->payment) && !empty($ent_treatment->receipt_url)){
            $this->set('error_code', 303);
            $this->message('This treatment has already been paid.');
            return;
        }

        /*$tip = get('tip', 0);
        $tip_type = get('tip_type','');*/
        $promo_code = get('promo_code','');

        if(empty($promo_code)&&$ent_treatment->promo_code != ''&&$ent_treatment->promo_code != null){
            $promo_code = $ent_treatment->promo_code;
        }

        if($ent_treatment->amount == 0) {
            $this->message('Try again.');
            return;
        }

        //check promo day
        $PromosDay = new PromosController();
        $promo_day_response = $PromosDay->get_discount_for_treatments($promo_code,$ent_treatment);
        
        if($promo_day_response['has_discount']){
            $total_amount = $ent_treatment->amount;
            $this->set('code_valid', true);
            $this->set('discount', $promo_day_response['discount']);
        }else{
        
            $total_amount = $this->validateCode($promo_code,$ent_treatment->amount,'TREATMENT', $ent_treatment);
        }
        
        // Asegurar que el total mínimo sea 100 centavos (1 dólar) en todos los casos
        $total_amount = max(100, $total_amount);
        /*
        if($tip > 0){
            if($tip_type == 'PERCENTAGE'){
                $tip = ($tip / 100) * $total_amount;
            }else if($tip_type == 'CUSTOM'){
                $tip = $tip * 100;
            }
        }*/

        $this->set('first_time_dsct', '');
        if($total_amount > 20000){
            $Treat = new TreatmentsController();
            $result = $Treat->get_promo_first_treatment($ent_treatment->id);
            if($result){
                $total_amount = $total_amount - $result['discount'];
                // Asegurar que después del descuento de primer tratamiento, el mínimo sea 100 centavos
                $total_amount = max(100, $total_amount);
                $this->set('first_time_dsct', $result['message']);
            }
        }

        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];
        $this->loadModel('SpaLiveV1.DataGiftCards');
        /*$assistance_id = USER_ID;
                if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
                    $_where = [
                        'DataGiftCards.code' => $promo_code,
                        'DataGiftCards.deleted' => 0,
                        'DataGiftCards.active' => 1,
                        'DataGiftCards.user_id' => USER_ID,
                        '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                        
                    ];
    
                    $_where['OR'] = [
                        ['DataGiftCards.receipt_id' => 0,],
                        ['DataGiftCards.receipt_id' => $ent_treatment['patient_id']]
                    ];
    
                } else if(USER_TYPE == 'patient'){
                    $assistance_id = $ent_treatment['assistance_id'];
                    $_where = [
                        'DataGiftCards.code' => $promo_code,
                        'DataGiftCards.deleted' => 0,
                        'DataGiftCards.active' => 1,
                        'DataGiftCards.user_id' => $ent_treatment['assistance_id'],
                        'DataGiftCards.receipt_id' => USER_ID,
                        '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                    ];
                }
    
                
                $this->loadModel('SpaLiveV1.DataGiftCards');
                $ent_gift = $this->DataGiftCards->find()->select(['DataGiftCards.id','DataGiftCards.discount','Treatment.id'])->join([
                    'Treatment' => ['table' => 'data_treatment', 'type' => 'LEFT', 'conditions' => 'Treatment.promo_code = "' . $promo_code . '" AND Treatment.assistance_id = ' . $assistance_id . ' AND Treatment.payment <> ""'],
                    ])
                ->where($_where)
                ->first();
                
                $this->set('DataGiftCards',$ent_gift); 
                return;*/
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
        if (!empty($ent_user)) {
            $stripe_user_email = $ent_user->email;
            $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
        }

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
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

        $arr_payment_methods = array();

        $ent_payment_methods = $this->DataSubscriptionMethodPayments->find()->where(['user_id' => $ent_user->id, 'deleted' => 0])->order(['DataSubscriptionMethodPayments.preferred' => 'DESC','DataSubscriptionMethodPayments.id' => 'DESC'])->toArray();

        if (!empty($ent_payment_methods)) {
            foreach($ent_payment_methods as $rowp) {
                $arr_payment_methods[] = $rowp->payment_id;
            }
        }

        foreach($arr_payment_methods as $pm){
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';
            $this->set('arr_payment_methods',$arr_payment_methods);
            try {
                $intent = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'USD',
                    'metadata' => ['type' => 'treatment', 'uid' => $treatment_uid, 'state' => USER_STATE],
                    'receipt_email' => $stripe_user_email,
                    'confirm' => true,
                    'description' => 'TREATMENT',
                    'customer' => $customer['id'],
                    'payment_method' => $pm,
                    'transfer_group' => $treatment_uid,
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
            if (isset($intent->charges->data[0]->receipt_url)) {
                $receipt_url = $intent->charges->data[0]->receipt_url;
                $id_charge = $intent->charges->data[0]->id;
                $payment_id = $intent->id;
            }
            
            if (empty($error) && $intent->status == 'succeeded') {
                $this->createPaymentRegister('TREATMENT', $ent_user->id, 0, $treatment_uid, $payment_id, $id_charge, $receipt_url, $ent_treatment->amount, $total_amount, 0, 0, 1, $ent_treatment->amount_cash);
                $this->set('first_time_dsct', '');
                if($total_amount > 20000){
                    $Treat = new TreatmentsController();
                    $result = $Treat->get_promo_first_treatment($ent_treatment->id, true);
                    if($result){
                        $total_amount = $total_amount - $result['discount'];
                        $this->set('first_time_dsct', $result['message']);
                    }
                }
                $this->loadModel('SpaLiveV1.DataPayment');
                $pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
                ])->where(['DataPayment.uid' => $treatment_uid, 'DataPayment.type' => "TREATMENT", 'DataPayment.id_to' => "0"])->first();
                
                if($promo_day_response['has_discount'] == false){
                    if($this->getParams('code_valid')){
                        
                        $assistance_id = USER_ID;
                        if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
                            $_where = [
                                'DataGiftCards.code' => $promo_code,
                                'DataGiftCards.deleted' => 0,
                                'DataGiftCards.active' => 1,
                                'DataGiftCards.user_id' => USER_ID,
                                '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                                
                            ];
            
                            $_where['OR'] = [
                                ['DataGiftCards.receipt_id' => 0,],
                                ['DataGiftCards.receipt_id' => $ent_treatment['patient_id']]
                            ];
            
                        } else if(USER_TYPE == 'patient'){
                            $assistance_id = $ent_treatment['assistance_id'];
                            $_where = [
                                'DataGiftCards.code' => $promo_code,
                                'DataGiftCards.deleted' => 0,
                                'DataGiftCards.active' => 1,
                                'DataGiftCards.user_id' => $ent_treatment['assistance_id'],
                                'DataGiftCards.receipt_id' => USER_ID,
                                '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                            ];
                        }
            
                        // pr($_where);exit;
                        $this->loadModel('SpaLiveV1.DataGiftCards');
                        $ent_gift = $this->DataGiftCards->find()->select(['DataGiftCards.id','DataGiftCards.discount','Treatment.id'])->join([
                            'Treatment' => ['table' => 'data_treatment', 'type' => 'LEFT', 'conditions' => 'Treatment.promo_code = "' . $promo_code . '" AND Treatment.assistance_id = ' . $assistance_id . ' AND Treatment.uid = "' . $treatment_uid. '"'],
                            ])
                        ->where($_where)
                        ->first();
                        if(!empty($ent_gift)){
                            $_dis =  $ent_treatment->amount - $ent_gift->discount;
                            $this->DataGiftCards->updateAll(
                                ['discount' => ($_dis < 0 ? abs($_dis):0),
                                'credits' => ($this->getParams('discount_amount')==!null)? $this->getParams('discount_amount') : 0],
                                ['id' => $ent_gift->id]
                            );
                        }
                    }
                }

                $html_msg_ = "
                Thank you, your treatment was paid.<br>

                Please find the invoice attached. We appreciate you working with us. <br><br>

                If you have any questions, please email us at info@myspalive.com
                ";

                $Main->send_receipt($html_msg_, $pay['User']['email'], $pay->id, $pay->uid);
                
                if($ent_treatment->patient_id == USER_ID){
                    $Main->notify_devices('The treatment has been paid by the patient. Go to payments section to see it.',array($ent_treatment->assistance_id),true,true,true,array(),'');
                }else{
                    $Main->notify_devices('TREATMENT_COMPLETE',array($ent_treatment->patient_id),true,true,true,array(),'');
                }
                $this->DataTreatment->updateAll(
                    [
                        'payment' => $id_charge,
                        'payment_intent' => $payment_id,
                        'receipt_url' => $receipt_url,
                    ], 
                    ['id' => $ent_treatment->id]
                );
                $Main->sendAfterCareEmail($ent_treatment->patient_id, $ent_treatment->id);
                $Main->payCIComissions($treatment_uid);
                $Main->sendTreatmentReview('EMAIL_AFTER_TREATMENT', $ent_treatment->patient_id, $ent_treatment->assistance_id, $treatment_uid, $ent_treatment->schedule_date);
                $this->remind_injector($treatment_uid);
                $this->success();
                //promoday save records
                if($promo_day_response['has_discount']){                                    
                    $this->loadModel('SpaLiveV1.DataPromoDayHistory');
                    $c_entity_pdh = $this->DataPromoDayHistory->newEntity([
                        'id_treatment' => $ent_treatment->id,
                        'id_promo_code' => $promo_day_response['id'],
                        'discount' => $promo_day_response['discount'],
                        'discount_type' => $promo_day_response['discount_type'],
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s')
                    ]);   
                    if(!$c_entity_pdh->hasErrors()) {              
                        $this->DataPromoDayHistory->save($c_entity_pdh);                 
                    }
                    
                }
                return;
            }
        }
        
        $arr_payment_methods = array();

        $payment_methods = $stripe->customers->allPaymentMethods(
            $customer->id,
            ['type' => 'card']
        );

        foreach($payment_methods as $method) {
            $arr_payment_methods[] = $method->id;
        }

        foreach($arr_payment_methods as $pm){
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';

            try {
                $intent = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'USD',
                    'metadata' => ['type' => 'treatment', 'uid' => $treatment_uid, 'state' => USER_STATE],
                    'receipt_email' => $stripe_user_email,
                    'confirm' => true,
                    'description' => 'TREATMENT',
                    'customer' => $customer['id'],
                    'payment_method' => $pm,
                    'transfer_group' => $treatment_uid,
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
            if (isset($intent->charges->data[0]->receipt_url)) {
                $receipt_url = $intent->charges->data[0]->receipt_url;
                $id_charge = $intent->charges->data[0]->id;
                $payment_id = $intent->id;
            }

            if (empty($error) && $intent->status == 'succeeded') {
                $this->createPaymentRegister('TREATMENT', $ent_user->id, 0, $treatment_uid, $payment_id, $id_charge, $receipt_url, $ent_treatment->amount, $total_amount, 0, 0, 1, $ent_treatment->amount_cash);
                if($ent_treatment->patient_id == USER_ID){
                    $Main->notify_devices('The treatment has been paid by the patient. Go to payments section to see it.',array($ent_treatment->assistance_id),true,true,true,array(),'');
                }else{
                    $Main->notify_devices('TREATMENT_COMPLETE',array($ent_treatment->patient_id),true,true,true,array(),'');
                }
                $this->DataTreatment->updateAll(
                    [
                        'payment' => $id_charge,
                        'payment_intent' => $payment_id,
                        'receipt_url' => $receipt_url,
                    ], 
                    ['id' => $ent_treatment->id]
                );
                $Main->sendAfterCareEmail($ent_treatment->patient_id, $ent_treatment->id);
                $Main->payCIComissions($treatment_uid);
                $Main->sendTreatmentReview('EMAIL_AFTER_TREATMENT', $ent_treatment->patient_id, $ent_treatment->assistance_id, $treatment_uid, $ent_treatment->schedule_date);
                $this->remind_injector($treatment_uid);
                if($promo_day_response['has_discount'] == false){
                    if($this->getParams('code_valid')){                    
                        $assistance_id = USER_ID;
                        if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
                            $_where = [
                                'DataGiftCards.code' => $promo_code,
                                'DataGiftCards.deleted' => 0,
                                'DataGiftCards.active' => 1,
                                'DataGiftCards.user_id' => USER_ID,
                                '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                                
                            ];
            
                            $_where['OR'] = [
                                ['DataGiftCards.receipt_id' => 0,],
                                ['DataGiftCards.receipt_id' => $ent_treatment['patient_id']]
                            ];
            
                        } else if(USER_TYPE == 'patient'){
                            $assistance_id = $ent_treatment['assistance_id'];
                            $_where = [
                                'DataGiftCards.code' => $promo_code,
                                'DataGiftCards.deleted' => 0,
                                'DataGiftCards.active' => 1,
                                'DataGiftCards.user_id' => $ent_treatment['assistance_id'],
                                'DataGiftCards.receipt_id' => USER_ID,
                                '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                            ];
                        }
            
                        // pr($_where);exit;
                        $this->loadModel('SpaLiveV1.DataGiftCards');
                        $ent_gift = $this->DataGiftCards->find()->select(['DataGiftCards.id','DataGiftCards.discount','Treatment.id'])->join([
                            'Treatment' => ['table' => 'data_treatment', 'type' => 'LEFT', 'conditions' => 'Treatment.promo_code = "' . $promo_code . '" AND Treatment.assistance_id = ' . $assistance_id . ' AND Treatment.payment <> ""'],
                            ])
                        ->where($_where)
                        ->first();
                        if(!empty($ent_gift)){
                            $_dis =  $ent_treatment->amount - $ent_gift->discount;
                            $this->DataGiftCards->updateAll(
                                ['discount' => ($_dis < 0 ? abs($_dis):0),
                                'credits' => ($this->getParams('discount_amount')==!null)? $this->getParams('discount_amount') : 0],
                                ['id' => $ent_gift->id]
                            );
                        }
                    }
                }
                if($promo_day_response['has_discount']){                
                    //DataPromoDayHistory save fileds id_treatment id_promo_code discount discount_type deleted created
                    $this->loadModel('SpaLiveV1.DataPromoDayHistory');
                    $c_entity_pdh = $this->DataPromoDayHistory->newEntity([
                        'id_treatment' => $ent_treatment->id,
                        'id_promo_code' => $promo_day_response['id'],
                        'discount' => $promo_day_response['discount'],
                        'discount_type' => $promo_day_response['discount_type'],
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s')
                    ]);   
                    if(!$c_entity_pdh->hasErrors()) {              
                        $this->DataPromoDayHistory->h->save($c_entity_pdh);                 
                    }
                    
                }
                $this->success();
                return;
            }
        }

        
        $this->message('There was a problem with the payment, please ask the patient to pay directly on your Certified Provider App.');
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments'); 
        $this->DataSubscriptionMethodPayments->updateAll(
            ['error' => 1],
            ['user_id' => $ent_treatment->patient_id]
        );  
        $Main->notify_devices('NOTIFI_PAYMENT_TREATMENT',array($ent_treatment->patient_id),true);
        $this->success(false);
    }

    private function remind_injector($treatment_uidt){

        $this->loadModel('SpaLiveV1.SysUserAdmin');
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatCITreatments');
        $ent_treatment = $this->DataTreatment->find()
        ->select([
            'injector_name' => '(SELECT CONCAT(SU.name, " ", SU.lname) FROM sys_users SU WHERE SU.id = DataTreatment.assistance_id)',
            'patient_name' => '(SELECT CONCAT(SU.name, " ", SU.lname) FROM sys_users SU WHERE SU.id = DataTreatment.patient_id)',
            'DataTreatment.schedule_date',
            'DataTreatment.treatments',
            'DataTreatment.assigned_doctor'
        ])
        ->where(['DataTreatment.uid' => $treatment_uidt])->first();
        $doc = $this->SysUserAdmin->find()->where(['SysUserAdmin.id' => $ent_treatment['assigned_doctor']])->first();

        if(empty($doc)){
            return;
        }

        $list_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.name','CatCITreatments.details', 'CatCITreatments.std_price', 'Cat.name', 'CatCITreatments.category_treatment_id'])
            ->join([
                'Cat' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'Cat.id = CatCITreatments.category_treatment_id'],
            ])
            ->where(['CatCITreatments.id IN' => explode(',', $ent_treatment->treatments)]);
            
        $array_list = array();
        foreach($list_treatments as $row){
            if($row->name == 'Let my provider choose' || $row->name == 'Let my provider help me decide' || $row->name == 'No preference'){
                if($row->id == 999){$row['Cat']['name'] = 'Basic Neurotoxins'; $row->category_treatment_id = 1;}
                $ent_treatments2 = $this->CatCITreatments->find()->select(['CatCITreatments.id', 'CatCITreatments.std_price', 'CatCITreatments.name', 'CatCITreatments.category_treatment_id'])->join([
                    'CTC' => ['table' => 'cat_treatments_category', 'type' => 'INNER', 'conditions' => 'CTC.id = CatCITreatments.category_treatment_id AND CatCITreatments.name <> CTC.name'],
                ])->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.name NOT IN' => array('Let my provider help me decide', 'Let my provider choose', 'No preference') ,'CatCITreatments.category_treatment_id' => $row->category_treatment_id])->all();
                $array_prices = array();
                foreach ($ent_treatments2 as $key => $trea) {
                    $array_prices[] = $trea['name'];
                }
                $array_list[] = $row['Cat']['name'];
            }else{
                $array_list[] = $row->name == $row['Cat']['name'] ? $row->name : $row['Cat']['name'] . ' ('. $row->name .')';
            }
        }

        $string_treatments = implode(', ', $array_list);
        $body = '
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body>
            <h3>A new treatment has been completed by ' . $ent_treatment['injector_name'] .' and requires your review.</h3>
            <h3>Treatment Details:</h3>
            <p><strong>Patient:</strong> ' . $ent_treatment['patient_name'] . '</p>
            <p><strong>Date of Treatment:</strong> ' . $ent_treatment['schedule_date'] . '</p>
            <p><strong>Treatment Type:</strong> ' . $string_treatments . '</p>
            <p>Log in to your account at <a href="http://md.myspalive.com">md.myspalive.com</a> to review the treatment and determine whether to approve or deny it.</p>
        </body>
        </html>
        ';
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'      => $doc->username,
            'bbc'     => 'carlos@advantedigital.com',
            'subject' => 'A new treatment has been completed by ' . $ent_treatment['injector_name'] . ' and requires your review.',
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
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($curl);

        curl_close($curl);
    }

    public function payment_intent_purchase() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $purchase_uid = get('uid', '');
        if(empty($purchase_uid)){
            $this->message('uid empty.');
            return;
        }
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
        $ent_purchase = $this->DataPurchases->find()->where(['DataPurchases.uid' => $purchase_uid, 'DataPurchases.deleted' => 0])->first();
        if(empty($ent_purchase)){
            $this->message('Purchase not found');
            return;
        }

        $payment_method = get('payment_method', '');

        if(empty($payment_method) || $payment_method == ''){
            $this->message('Payment method empty.');
            return;
        }
       
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        
        $oldCustomer = $stripe->customers->all([
            "email" => $user['email'],
            "limit" => 1,
        ]);

        if (count($oldCustomer) == 0) {
            $customer = $stripe->customers->create([
                'description' => $user['name'].' '.$user['mname'].' '.$user['lname'],
                'email' => $user['email'],
            ]);
        } else $customer = $oldCustomer->data[0];

        $type_purchase = 'PURCHASE';

        // VALIDATE IF IS TRAINING

        $_ent_purchases =$this->DataPurchasesDetail->find()
        ->select([
            'DataPurchasesDetail.id',
            'DataPurchasesDetail.purchase_id',
            'DataPurchasesDetail.product_id',
            'DataPurchasesDetail.price',
            'DataPurchasesDetail.qty',
            'DataPurchasesDetail.shipped_qty',
            'DataPurchasesDetail.refunded',
            'DataPurchasesDetail.refunded_amount',
            'DataPurchasesDetail.product_number',
            'DataPurchasesDetail.serial_number',
            'DataPurchasesDetail.lot_number',
            'DataPurchasesDetail.expiration_date',
            'DataPurchasesDetail.product_detail_question',
            'DataPurchasesDetail.product_detail',
            'Product.category'
        ])
        ->join([
            'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id'],
        ])
        ->where(['DataPurchasesDetail.purchase_id' => $ent_purchase->id])->toArray();
        $Main = new MainController();
        if (!empty($_ent_purchases)&&count($_ent_purchases) == 1) {

            $basic_id = 103;

            $is_dev = env('IS_DEV', false);
            
            if(!$is_dev){
                $basic_id = 45;
            }

            $course_amount = 0;
            $label = "";

            if($_ent_purchases[0]->product_id == $basic_id){
                //comprar Basico iv therapy
                $course_amount = $this->training_basic;
                $label = "BASIC COURSE";
                $discount_category = 'REGISTER';
            }else if ($_ent_purchases[0]->product_id == 44){
                $course_amount = $this->training_advanced;
                $label = "ADVANCED COURSE";
                $discount_category = 'TRAINING';
            }else if ($_ent_purchases[0]->product_id == 178){
                $course_amount = $this->level_3_fillers;
                $label = "FILLERS COURSE";
                $discount_category = 'FILLERS';
            }else if ($_ent_purchases[0]->product_id == 185){
                $course_amount = $this->level_1_to_1;
                $label = "LEVEL 1-1 NEUROTOXINS";
                $discount_category = 'TOXTUNEUP';
            }else if ($_ent_purchases[0]->product_id == 184){
                $course_amount = $this->level_3_medical;
                $label = "ADVANCED TECHNIQUES MEDICAL";
                $discount_category = 'LEVEL3';
            }

            if($label!=""&&$course_amount>0){

                $promoForCourse = get('promo_code', '');
                if ($this->purchaseContainsPromoExcludedProduct($_ent_purchases)) {
                    $promoForCourse = '';
                }
                $total_amount = $this->validateCode($promoForCourse, $course_amount, $discount_category);
                try {
                    $stripe_result = \Stripe\PaymentIntent::create([
                        'amount' => $total_amount,
                        'currency' => 'usd',
                        'customer' => $customer->id,
                        'payment_method' => $payment_method,
                        'metadata' => ['state' => USER_STATE],
                        'off_session' => true,
                        'confirm' => true,
                        'error_on_requires_action' => true,
                        'description' => $label,
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
                $paymen_intent = '';
                if (isset($stripe_result->charges->data[0]->receipt_url)) {
                    $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                    $id_charge = $stripe_result->charges->data[0]->id;
                    $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                    $payment_id = $stripe_result->id;
                } 
                    
                if (empty($error) && $stripe_result->status == 'succeeded') {
                    $uid = $label == "BASIC COURSE" ? USER_UID : Text::uuid();
                    $this->createPaymentRegister($label, USER_ID, 0, $uid, $paymen_intent, $id_charge, $receipt_url, $course_amount, $total_amount);
                    $this->success();

                    if($label == "LEVEL 1-1 NEUROTOXINS"){
                        $Main->notify_devices('BUY_TOXTUNEUP_COURSE',array(USER_ID),true,true, true, array(), '');
                    }

                    if($label == "FILLERS COURSE"){
                        if(USER_STEP != 'HOME'){
                            $this->SysUsers->updateAll(
                                ['steps' => 'SELECTFILLERS'], 
                                ['id' =>  USER_ID]
                            );
                        }
                    }

                    if($label == "ADVANCED COURSE" || $label == "BASIC COURSE" || $label == "FILLERS COURSE" || $label == "ADVANCED TECHNIQUES MEDICAL"){
                        if($label == "ADVANCED COURSE" || $label == "BASIC COURSE" || $label == "ADVANCED TECHNIQUES MEDICAL"){
                            $Main->notify_devices('AFTER_BUY_BASIC_COURSE_EN',array(USER_ID),false,true);
                        }

                        $this->loadModel('SpaLiveV1.DataAssignedToRegister');

                        if($label == 'BASIC COURSE'){
                            $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                                'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                            ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.deleted' => 0, 'DSR.team' => 'OUTSIDE'])->last();
        
                            if(empty($assignedRep)){
                                $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id','User.email'])->join([
                                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                                    'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                                ])->where(['DataAssignedToRegister.user_id' => USER_ID,'DataAssignedToRegister.deleted' => 0, 'DSR.deleted' => 0, 'DSR.team' => 'INSIDE'])->last();
                            }
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

                            $this->loadModel('SpaLiveV1.DataPayment');
                            $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
                            $this->loadModel('SpaLiveV1.DataSalesRepresentative');

                            $pay = $this->DataPayment->find()
                            ->where(['DataPayment.id_from' => USER_ID,
                                    'DataPayment.uid' => $uid,
                                    'DataPayment.type' => $label])->first();

                            $representative = $this->DataSalesRepresentative->find()->where([
                                'DataSalesRepresentative.user_id' => $assignedRep['User']['id'],
                                'DataSalesRepresentative.deleted' => 0,
                            ])->first();

                            if (!empty($pay) && !empty($representative)) {

                            $text_for_sms = "advanced";

                            $pay_description = 'SALES TEAM ADVANCED';

                            if($label == "BASIC COURSE"){
                                $text_for_sms = "basic";
                                $pay_description = 'SALES TEAM BASIC'; 
                            }else if($label == "FILLERS COURSE"){
                                $text_for_sms = "Fillers Foundation";
                                $pay_description = 'SALES TEAM FILLERS';
                                $constants = [
                                    '[CP/NAME]' => trim(USER_NAME) . ' ' . trim(USER_LNAME),
                                ];
                                $Main->notify_devices('BUY_FILLERS_COURSE',array(USER_ID),true,true, true, array(), '', $constants);
                            } else if($label == "ADVANCED TECHNIQUES MEDICAL"){
                                $text_for_sms = "Neurotoxins level 3";
                                $pay_description = 'SALES TEAM LEVEL 3';  
                                /* $constants = [
                                    '[CP/NAME]' => trim(USER_NAME) . ' ' . trim(USER_LNAME),
                                ];
                                $Main->notify_devices('BUY_FILLERS_COURSE',array(USER_ID),true,true, true, array(), '', $constants); */
                                $Main->notify_devices('BUY_LEVEL3_COURSE',array(USER_ID),true,true, true, array(), '');                                                                     
                            }

                            $this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . USER_NAME . ' ' . USER_LNAME . ', ' . $this->formatPhoneNumber(USER_PHONE) . ', has completed the '.$text_for_sms.' training purchase for $' . $total_amount / 100, $Main);
                            #region Pay comission to sales representative

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

                                if($representative->team == 'INSIDE'){
                                    if($representative->rank == 'JUNIOR' ){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                                        $amount_comission = $amount_comission == 0 ? 0 : 5000;
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
                                                'description' => $pay_description,
                                                'payload' => '',
                                                'deleted' => 1,
                                                'created' => date('Y-m-d H:i:s'),
                                                'createdby' => defined('USER_ID') ? USER_ID : 0,
                                            );
                            
                                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                                            $service = 'Training';
                                            $this->send_email_team_member_courses(USER_ID, $service, '', $amount_comission, $senior_rep);
                                        }
                                    } else if($representative->rank == 'JUNIOR+'){ // Si el representante es JUNIOR y no hay invitacion entonces se cambia el monto de la comision a $50 y se agrega el pago para el SENIOR de otros $50
                                        $amount_comission_senior = $amount_comission == 0 ? 0 : 5000;
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
                                                'description' => $pay_description,
                                                'payload' => '',
                                                'deleted' => 1,
                                                'created' => date('Y-m-d H:i:s'),
                                                'createdby' => defined('USER_ID') ? USER_ID : 0,
                                            );
                            
                                            $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                            $this->DataSalesRepresentativePayments->save($c_entity_comission);
                                            $service = 'Training';
                                            $this->send_email_team_member_courses(USER_ID, $service, '', $amount_comission_senior, $senior_rep);
                                        }
                                    }
                                } else if($representative->team == 'OUTSIDE'){
                                    $amount_comission = $amount_comission == 0 ? 0 : 10000;
                                }

                                $array_save_comission = array( 
                                    'uid' => Text::uuid(),
                                    'payment_id' => $pay->id,
                                    'amount' => $amount_comission,
                                    'user_id' => $assignedRep['User']['id'],
                                    'payment_uid' => '',
                                    'description' => $pay_description,
                                    'payload' => '',
                                    'deleted' => 1,
                                    'created' => date('Y-m-d H:i:s'),
                                    'createdby' => defined('USER_ID') ? USER_ID : 0,
                                );
                
                                $c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
                                $this->DataSalesRepresentativePayments->save($c_entity_comission);

                            #endregion
                            }
                        }
                    }

                    return;
                } else {
                    $this->message('Payment failed. Declined card. Please try again.');
                    return;
                }
            }
        }
       
        $code = strtoupper(trim(get('promo_code', '')));
        $this->loadModel('SpaLiveV1.DataCodeEc');
        $ent_promo = $this->DataCodeEc->find()->last();

        $total_amount = 0;
        $skipPurchasePromo = $this->purchaseContainsPromoExcludedProduct($_ent_purchases);
        if ($skipPurchasePromo) {
            $total_amount = $this->validateCode('', ($ent_purchase->amount), $type_purchase) + $ent_purchase->shipping_cost;
        } elseif(!empty($ent_promo)){
            if($ent_promo->code == $code){
                $this->loadModel('SpaLiveV1.DataEliteClub');
                $elite = $this->DataEliteClub->find()->where(['user_id' => USER_ID, 'active' => 1, 'deleted' => 0])->first();
                if(!empty($elite)){
                    foreach ($_ent_purchases as $value) {
                        if(($value['Product']['category'] == "NEUROTOXINS" || $value['Product']['category'] == 'NEUROTOXIN PACKAGES' || $value['Product']['category'] == 'FILLERS' || $value['Product']['category'] == 'IV VIALS') && ($value['product_id'] != 59 && $value['product_id'] != 1)){
                            $total_amount += ($value['price'] * $value['qty']) - (($value['price'] * $value['qty']) * ($ent_promo->discount / 100));
                        }else{
                            $total_amount += $value['price'] * $value['qty'];
                        }
                    }

                    $stripe_fee = intval(($total_amount + $ent_purchase->shipping_cost) * 0.0315);

                    $total_amount = intval($total_amount + $ent_purchase->shipping_cost + $stripe_fee);
                }
                else{
                    $total_amount = $this->validateCode($code,($ent_purchase->amount),$type_purchase) + $ent_purchase->shipping_cost;
                }
            }else{
                $total_amount = $this->validateCode($code,($ent_purchase->amount),$type_purchase) + $ent_purchase->shipping_cost;
            }
        }else{
            $total_amount = $this->validateCode($code,($ent_purchase->amount),$type_purchase) + $ent_purchase->shipping_cost;
        }

        if ($total_amount < 100) $total_amount = 100;

        $save_discount_credits = 0;

        if (get('use_credits',0)) {
            $total_credits = $this->checkClinicCredits();
            if ($total_credits > 0) {
                if ($total_amount > 100) {
                    $this->set('use_credits', 1);
                    if ($total_amount == $total_credits) {
                        $total_amount = 100;
                        $save_discount_credits = $total_credits - 100;
                        $this->set('discount_credits', $total_credits - 100);
                    } else if ($total_amount > $total_credits) {
                        $total_amount = $total_amount - $total_credits;
                        $save_discount_credits = $total_credits;
                        $this->set('discount_credits', $total_credits);
                        if ($total_amount < 100) {
                            $total_amount = 100;
                            $save_discount_credits = $total_credits + (100 - $total_amount);
                            $this->set('discount_credits', $save_discount_credits);
                        }
                    } else {
                        $use_c = $total_credits - $total_amount + 100;
                        $save_discount_credits = $total_amount - 100;
                        $this->set('discount_credits', $save_discount_credits);
                        $total_amount = 100;
                    }
                } else {
                    $this->set('use_credits', 0);
                }
            } else {
                $this->set('use_credits', 0);
            }
        }

        $error = '';

            try {
                $intent = \Stripe\PaymentIntent::create([
                    'customer' => $customer['id'],
                    'amount' => $total_amount,
                    'payment_method' => $payment_method,
                    'confirm' => true,
                    'error_on_requires_action' => true,
                    'currency' => 'USD',
                    'metadata' => ['type' => 'purchase', 'uid' => $purchase_uid, 'state' => USER_STATE],
                    'receipt_email' => $user['email'],
                    'transfer_group' => $purchase_uid,
                    'description' => 'PURCHASE',
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
            $paymen_intent = '';
            if (isset($intent->charges->data[0]->receipt_url)) {
                $receipt_url = $intent->charges->data[0]->receipt_url;
                $id_charge = $intent->charges->data[0]->id;
                $paymen_intent = $intent->charges->data[0]->payment_intent;
                $payment_id = $intent->id;
            }    

        if(empty($error) && $intent->status == 'succeeded'){
                $client_secret = $intent->client_secret;
                $arr_pintnt = explode("_secret_", $client_secret);
        
                $this->createPaymentRegister('PURCHASE', USER_ID, 0, $purchase_uid, $paymen_intent, $id_charge, $receipt_url, ($ent_purchase->amount + $ent_purchase->shipping_cost), $total_amount, $save_discount_credits);
                
                $this->loadModel('SpaLiveV1.DataPayment');
                
                $pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
                ])->where(['DataPayment.uid' => $purchase_uid, 'DataPayment.type' => "PURCHASE", 'DataPayment.id_to' => "0"])->first();
                
                $Main = new MainController();
                
                $Main->send_receipt('NEW_PURCHASE', $pay['User']['email'], $pay->id, $pay->uid);
                
                // Check if purchase contains skin products and send email to pharmacy
                $skin_products_found = false;
                foreach ($_ent_purchases as $_row) {
                    if (in_array($_row['Product']['category'], ['ACNE PRODUCTS', 'BRIGHTENING PRODUCTS', 'ANTI-AGING PRODUCTS', 'BACKBAR PRODUCTS','SKIN PRODUCTS']) && $_row['product_id'] != 382) {
                        $skin_products_found = true;
                    }
                }
                
                
                if ($skin_products_found) {
                    // Call pharmacy email function
                    $result = $this->send_pharmacy_email_skin_products($ent_purchase->id);
                    $this->set('PharmacyEmailSent', $result);
                }

                $this->set('total', $total_amount);
                
                $this->success();
        
                if (count($arr_pintnt)) {
                    $array_save = array(
                        'id' => $ent_purchase->id,
                        'payment_intent' => $arr_pintnt[0],
                        'use_credits' => get('use_credits',0),
                    );
        
                    $c_entity = $this->DataPurchases->newEntity($array_save);
                    if(!$c_entity->hasErrors()) {
                        if ($this->DataPurchases->save($c_entity)) {

                            $Therapy = new TherapyController();
                            $iv_products = $Therapy->bought_iv_therapy_products($ent_purchase->id);
                            if(count($iv_products)>0){
                                $iv_form = $Therapy->get_iv_form_info(USER_ID);
                                if(!$iv_form){
                                    $this->DataPurchases->updateAll(
                                        ['status' => 'PHARMACY FORM PENDING'],
                                        ['id' => $ent_purchase->id]
                                    );
                                }else{
                                    //compro productos de iv therapy (vials)
                                    $this->loadModel('SpaLiveV1.SysUsers');

                                    $ent_user = $this->SysUsers->find()->where(['id' => $user["user_id"]])->first();
                                    
                                    $Therapy->send_email_to_pharmacy($ent_user,$iv_products,$ent_purchase->id);   
                                }
                            }

                            $this->success();
                        }
                    }
                }
        }else {
            $this->success(false);
            $this->message('There was a problem with the payment. Try again later');
        }
    }

    private function checkClinicCredits() {

        $this->loadModel('SpaLiveV1.DataCredits');
        $str_query_find = "
                SELECT SUM(amount) total FROM data_credits WHERE user_id = " . USER_ID;

        $ent_query = $this->DataCredits->getConnection()->execute($str_query_find)->fetchAll('assoc');

        $total_credits = 0;
        if (!empty($ent_query)) {
            $total_credits = $ent_query[0]['total'];
        }
        
        $this->set('total_available_credits', intval($total_credits));

        return $total_credits;

    }

    public function payment_treatment_from_provider(){
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

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment not found');
            return;
        }

        if(!empty($ent_treatment->payment) && !empty($ent_treatment->receipt_url)){
            $this->set('error_code', 303);
            $this->message('This treatment has already been paid by the patient.');
            return;
        }

        $tip = get('tip', 0);
        $tip_type = get('tip_type','');
        $promo_code = get('promo_code','');

        if($ent_treatment->amount == 0) {
            $this->message('Try again.');
            return;
        }

        //check promo day
        $PromosDay = new PromosController();
        $promo_day_response = $PromosDay->get_discount_for_treatments($promo_code,$ent_treatment);
        
        if($promo_day_response['has_discount']){
            $total_amount = $ent_treatment->amount;
            $this->set('code_valid', true);
            $this->set('discount', $promo_day_response['discount']);
        }else{
        
            $total_amount = $this->validateCode($promo_code,$ent_treatment->amount,'TREATMENT', $ent_treatment);
        }
        
        // Asegurar que el total mínimo sea 100 centavos (1 dólar) en todos los casos
        $total_amount = max(100, $total_amount);

        $this->set('first_time_dsct', '');
        if($total_amount > 20000){
            $Treat = new TreatmentsController();
            $result = $Treat->get_promo_first_treatment($ent_treatment->id);
            if($result){
                $total_amount = $total_amount - $result['discount'];
                // Asegurar que después del descuento de primer tratamiento, el mínimo sea 100 centavos
                $total_amount = max(100, $total_amount);
                $this->set('first_time_dsct', $result['message']);
            }
        }

        if($tip > 0){
            if($tip_type == 'PERCENTAGE'){
                $tip = ($tip / 100) * $total_amount;
            }else if($tip_type == 'CUSTOM'){
                $tip = $tip * 100;
            }
        }

        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
        if (!empty($ent_user)) {
            $stripe_user_email = $ent_user->email;
            $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
        }
         
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
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

        $payment_method = get('payment_method', '');

        if(empty($payment_method)){
            $this->message('Payment method empty.');
            return;
        }

        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        $stripe_result = '';
        $error = '';

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $total_amount + $tip,
                'currency' => 'USD',
                'metadata' => ['state' => USER_STATE],
                'receipt_email' => $stripe_user_email,
                'confirm' => true,
                'description' => 'TREATMENT',
                'customer' => $customer['id'],
                'payment_method' => $payment_method,
                'transfer_group' => $treatment_uid,
                'error_on_requires_action' => true,
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
        if (isset($intent->charges->data[0]->receipt_url)) {
            $receipt_url = $intent->charges->data[0]->receipt_url;
            $id_charge = $intent->charges->data[0]->id;
            $payment_id = $intent->id;
        }    

        if (empty($error) && $intent->status == 'succeeded') {
            $this->createPaymentRegister('TREATMENT', $ent_user->id, 0, $treatment_uid, $payment_id, $id_charge, $receipt_url, $total_amount, $total_amount + $tip, 0, 0, 2);
            $this->set('first_time_dsct', '');
            if($total_amount > 20000){
                $Treat = new TreatmentsController();
                $result = $Treat->get_promo_first_treatment($ent_treatment->id, true);
                if($result){
                    $total_amount = $total_amount - $result['discount'];
                    $this->set('first_time_dsct', $result['message']);
                }
            }
            $this->loadModel('SpaLiveV1.DataPayment');
                $pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
                ])->where(['DataPayment.uid' => $treatment_uid, 'DataPayment.type' => "TREATMENT", 'DataPayment.id_to' => "0"])->first();

                $html_msg_ = "
                Thank you, your treatment was paid.<br>

                Please find the invoice attached. We appreciate you working with us. <br><br>

                If you have any questions, please email us at info@myspalive.com
                ";

            $Main->send_receipt($html_msg_, $pay['User']['email'], $pay->id, $pay->uid);
            if($ent_treatment->patient_id == USER_ID){
                $Main->notify_devices('The treatment has been paid by the patient. Go to payments section to see it.',array($ent_treatment->assistance_id),true,true,true,array(),'');
            }else{
                $Main->notify_devices('TREATMENT_COMPLETE',array($ent_treatment->patient_id),true,true,true,array(),'');
            }
            $this->DataTreatment->updateAll(
                [
                    'payment' => $id_charge,
                    'payment_intent' => $payment_id,
                    'receipt_url' => $receipt_url,
                    'status' => 'DONE',
                ], 
                ['id' => $ent_treatment->id]
            );
            $Main->sendAfterCareEmail($ent_treatment->patient_id, $ent_treatment->id);
            $Main->payCIComissions($treatment_uid);
            $Main->sendTreatmentReview('EMAIL_AFTER_TREATMENT', $ent_treatment->patient_id, $ent_treatment->assistance_id, $treatment_uid, $ent_treatment->schedule_date);
            $this->remind_injector($treatment_uid);
            $this->success();

            if($promo_day_response['has_discount'] == false){
                if($this->getParams('code_valid')){
                    $assistance_id = USER_ID;
                    if(USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci'){
                        $_where = [
                            'DataGiftCards.code' => $promo_code,
                            'DataGiftCards.deleted' => 0,
                            'DataGiftCards.active' => 1,
                            'DataGiftCards.user_id' => USER_ID,
                            '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                        ];
        
                        $_where['OR'] = [
                            ['DataGiftCards.receipt_id' => 0,],
                            ['DataGiftCards.receipt_id' => $ent_treatment['patient_id']]
                        ];
        
                    } else if(USER_TYPE == 'patient'){
                        $assistance_id = $ent_treatment['assistance_id'];
                        $_where = [
                            'DataGiftCards.code' => $promo_code,
                            'DataGiftCards.deleted' => 0,
                            'DataGiftCards.active' => 1,
                            'DataGiftCards.user_id' => $ent_treatment['assistance_id'],
                            'DataGiftCards.receipt_id' => USER_ID,
                            '(DATE_FORMAT(DataGiftCards.expiration, "%Y-%m-%d") > "' . date('Y-m-d') . '")'
                        ];
                    }
        
                    // pr($_where);exit;
                    $this->loadModel('SpaLiveV1.DataGiftCards');
                    $ent_gift = $this->DataGiftCards->find()->select(['DataGiftCards.id','DataGiftCards.discount','Treatment.id'])->join([
                        'Treatment' => ['table' => 'data_treatment', 'type' => 'LEFT', 'conditions' => 'Treatment.promo_code = "' . $promo_code . '" AND Treatment.assistance_id = ' . $assistance_id . ' AND Treatment.payment <> ""'],
                        ])
                    ->where($_where)
                    ->first();
                    if(!empty($ent_gift)){
                        $_dis =  $ent_treatment->amount - $ent_gift->discount;
                        $this->DataGiftCards->updateAll(
                            ['discount' => ($_dis < 0 ? abs($_dis):0),
                            'credits' => ($this->getParams('discount_amount')==!null)? $this->getParams('discount_amount') : 0],
                            ['id' => $ent_gift->id]
                        );
                    }
                }
            }
            if($promo_day_response['has_discount']){                                
                $this->loadModel('SpaLiveV1.DataPromoDayHistory');
                $c_entity_pdh = $this->DataPromoDayHistory->newEntity([
                    'id_treatment' => $ent_treatment->id,
                    'id_promo_code' => $promo_day_response['id'],
                    'discount' => $promo_day_response['discount'],
                    'discount_type' => $promo_day_response['discount_type'],
                    'deleted' => 0,
                    'created' => date('Y-m-d H:i:s')
                ]);   
                if(!$c_entity_pdh->hasErrors()) {              
                    $this->DataPromoDayHistory->save($c_entity_pdh);                 
                }
                
            }
        } else {
            $this->success(false);
            $this->message('There was a problem with the payment, please ask the patient to pay directly on your Certified Provider App.');
            $this->DataTreatment->updateAll(
                [
                    'request_payment' => 1,
                    'tip' => $tip,
                    'promo_code' => $promo_code,
                ],
                ['id' => $ent_treatment->id]
            );
            
            $Main->notify_devices('NOTIFI_PAYMENT_TREATMENT',array($ent_treatment->patient_id),true);
        }
    }

    public function payment_tip_treatment(){
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

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_payment = $this->DataPayment->find()->where(['uid' => $treatment_uid, 'type' => 'TREATMENT'])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment not found');
            return;
        }

        $tip = get('tip', 0);
        $tip_type = get('tip_type','');

        if($tip > 0){
            if($tip_type == 'PERCENTAGE'){
                $tip = ($tip / 100) * $ent_payment->total;
            }else if($tip_type == 'CUSTOM'){
                $tip = $tip * 100;
            }
        }

        if($tip < 100){
            $this->message('The tip must be greater than $1.00');
            return;
        }

        // $total_amount = $this->validateCode(get('promo_code',''),$ent_treatment->amount,'TREATMENT', $ent_treatment);

        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
        if (!empty($ent_user)) {
            $stripe_user_email = $ent_user->email;
            $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
        }
         
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
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

        $arr_payment_methods = array();

        $ent_payment_methods = $this->DataSubscriptionMethodPayments->find()->where(['user_id' => $ent_user->id, 'deleted' => 0])->order(['DataSubscriptionMethodPayments.preferred' => 'DESC','DataSubscriptionMethodPayments.id' => 'DESC'])->toArray();

        if (!empty($ent_payment_methods)) {
            foreach($ent_payment_methods as $rowp) {
                $arr_payment_methods[] = $rowp->method_id;
            }
        }

        foreach($arr_payment_methods as $pm){
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';

            try {
                $intent = \Stripe\PaymentIntent::create([
                    'amount' => round($tip),
                    'currency' => 'USD',
                    'metadata' => ['type' => 'tip', 'uid' => $treatment_uid, 'state' => USER_STATE],
                    'receipt_email' => $stripe_user_email,
                    'confirm' => true,
                    'description' => 'TIP',
                    'customer' => $customer['id'],
                    'payment_method' => $pm,
                    'transfer_group' => $treatment_uid,
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
            if (isset($intent->charges->data[0]->receipt_url)) {
                $receipt_url = $intent->charges->data[0]->receipt_url;
                $id_charge = $intent->charges->data[0]->id;
                $payment_id = $intent->id;
            }

            if (empty($error) && $intent->status == 'succeeded') {
                $this->createPaymentRegister('TIP', $ent_user->id, 0, $treatment_uid, $payment_id, $id_charge, $receipt_url, $tip, $tip);
                $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                $constants = [
                    '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                ];
                $Main->notify_devices('TREATMENT_TIP',array($ent_treatment->assistance_id),true,true,true,array(),'', $constants, true);
                $this->payCIComissionsOnTip($treatment_uid);
                $this->DataTreatment->updateAll(
                    ['tip' => $tip,],
                    ['id' => $ent_treatment->id]
                );
                $this->success();
                return;
            }
        }

        $arr_payment_methods = array();

        $payment_methods = $stripe->customers->allPaymentMethods(
            $customer->id,
            ['type' => 'card']
        );

        foreach($payment_methods as $method) {
            $arr_payment_methods[] = $method->id;
        }

        foreach($arr_payment_methods as $pm){
            \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
            $stripe_result = '';
            $error = '';

            try {
                $intent = \Stripe\PaymentIntent::create([
                    'amount' => round($tip),
                    'currency' => 'USD',
                    'metadata' => ['type' => 'tip', 'uid' => $treatment_uid, 'state' => USER_STATE],
                    'receipt_email' => $stripe_user_email,
                    'confirm' => true,
                    'description' => 'TIP',
                    'customer' => $customer['id'],
                    'payment_method' => $pm,
                    'transfer_group' => $treatment_uid,
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
            if (isset($intent->charges->data[0]->receipt_url)) {
                $receipt_url = $intent->charges->data[0]->receipt_url;
                $id_charge = $intent->charges->data[0]->id;
                $payment_id = $intent->id;
            }

            if (empty($error) && $intent->status == 'succeeded') {
                $this->createPaymentRegister('TIP', $ent_user->id, 0, $treatment_uid, $payment_id, $id_charge, $receipt_url, $tip, $tip);
                $this->payCIComissionsOnTip($treatment_uid);
                $this->DataTreatment->updateAll(
                    ['tip' => $tip,],
                    ['id' => $ent_treatment->id]
                );
                $this->success();
                $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id, 'SysUsers.is_test' => 0, 'SysUsers.deleted' => 0])->first();
                $constants = [
                    '[CNT/PName]' => trim($ent_patient->name) . ' ' . trim($ent_patient->lname),
                ];
                $Main->notify_devices('TREATMENT_TIP',array($ent_treatment->assistance_id),true,true,true,array(),'', $constants, true);
                return;
            }
        }

        $this->success(false);
        $this->message('There was a problem with the payment, please ask the patient to pay directly on your Certified Provider App.');
        $Main->notify_devices('NOTIFI_PAYMENT_TREATMENT',array($ent_treatment->patient_id),true);
    }

    public function payment_tip_treatment_custom(){
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

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if(empty($ent_treatment)){
            $this->message('Treatment not found');
            return;
        }

        $tip = get('tip', 0);
        $tip_type = get('tip_type','');

        if($tip > 0){
            if($tip_type == 'PERCENTAGE'){
                $tip = ($tip / 100) * $ent_treatment->amount;
            }else if($tip_type == 'CUSTOM'){
                $tip = $tip * 100;
            }
        }

        $total_amount = $this->validateCode(get('promo_code',''),$ent_treatment->amount,'TREATMENT', $ent_treatment);

        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataSubscriptionMethodPayments');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
        if (!empty($ent_user)) {
            $stripe_user_email = $ent_user->email;
            $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
        }
         
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
        $stripe_result = '';
        $error = '';

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $tip,
                'currency' => 'USD',
                'metadata' => ['type' => 'tip', 'uid' => $treatment_uid, 'state' => USER_STATE],
                'receipt_email' => $stripe_user_email,
                'confirm' => true,
                'description' => 'TIP',
                'payment_method' => get('payment_method_id', ''),
                'transfer_group' => $treatment_uid,
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
        if (isset($intent->charges->data[0]->receipt_url)) {
            $receipt_url = $intent->charges->data[0]->receipt_url;
            $id_charge = $intent->charges->data[0]->id;
            $payment_id = $intent->id;
        }

        if (empty($error) && $intent->status == 'succeeded') {
            $this->createPaymentRegister('TIP', $ent_user->id, 0, $treatment_uid, $payment_id, $id_charge, $receipt_url, $tip, $tip);
            $this->payCIComissionsOnTip($treatment_uid);
            $this->DataTreatment->updateAll(
                ['tip' => $tip,],
                ['id' => $ent_treatment->id]
            );
            $this->success();
            return;
        }        

        $this->success(false);
        $this->message('There was a problem with the payment, please ask the patient to pay directly on the patient\'s app.');
        $Main->notify_devices('NOTIFI_PAYMENT_TREATMENT',array($ent_treatment->patient_id),true);
    }

    private function createPaymentRegistern($type, $from, $to, $uid, $intent, $subtotal, $total,$discount_credits = 0, $prepaid = 0) {

        $promo_code = strtoupper(get('promo_code',''));
        $promo_discount = $this->validateCodeMultiplier($promo_code,$type,$subtotal);

        if($promo_discount != 0) {
            $promo_code = strtoupper(get('promo_code',''));
        } else {
            $promo_code = '';
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $is_vis = 0;
        if ($type == 'REFUND' || $type == 'GFE COMMISSION' || $type == 'CI COMMISSION') $is_vis = 1;
         $array_save = array(
            'id_from' => $from,
            'id_to' => $to,
            'uid' => $uid,
            'type' => $type, //'CI REGISTER','PURCHASE','GFE','TREATMENT','COMISSION','REFUND'
            'intent' => $intent,
            'payment' => '',
            'receipt' => '',
            'discount_credits' => isset($discount_credits) ? $discount_credits : 0,
            'promo_discount' => $promo_discount,
            'promo_code' =>  $promo_code,
            'subtotal' => $subtotal,
            'total' => $total,
            'prod' => 1,
            'is_visible' => $is_vis,
            'comission_payed' => 0,
            'comission_generated' => 0,
            'prepaid' => $prepaid,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => defined('USER_ID') ? USER_ID : 0,
        );

        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataPayment->save($c_entity); 
        } else {

        }

    }

    public function payCIComissionsOnTip($treatment_uid) {

        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentDetail');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if (empty($ent_treatment)) return;
        $ent_pay = $this->DataPayment->find()
        ->where(['DataPayment.uid' => $treatment_uid, 'DataPayment.comission_generated' => 0,'DataPayment.is_visible' => 1, 'DataPayment.type' => 'TIP'])->first();
       
        if (empty($ent_pay)) {
            return;
        }

        // CI COMISSION CALC

        $super_total_ci_comission = 0;
        $discount_multiplier = ( 100 - intval($ent_pay->promo_discount) ) / 100;
        if ($discount_multiplier > 1 || $discount_multiplier <= 0) $discount_multiplier = 1;

        $ci_comission = 0.95;

        $total_comission = $ent_pay->total * $ci_comission * $discount_multiplier;
        
        $super_total_ci_comission = $total_comission;

        if ($super_total_ci_comission > 0) {

            $reg_id = $this->createPaymentCommissionRegister('TIP COMMISSION', 0, $ent_treatment->assistance_id, $treatment_uid, $ent_pay->intent, $ent_pay->payment, '', $super_total_ci_comission);
        }

        // ************* CI PAYMENT COMISSION

        $ent_pay->comission_generated = 1;
        $this->DataPayment->save($ent_pay);

        //SET PAYMENT comission_generated
    }

    private function createPaymentCommissionRegister($type, $from, $to, $uid, $intent, $payment, $receipt, $total, $service_uid = '') {
       
        $this->loadModel('SpaLiveV1.DataPayment');
        
         $array_save = array(
            'id_from' => $from,
            'id_to' => $to,
            'uid' => $uid,
            'service_uid' => $service_uid,
            'type' => $type, //'CI REGISTER','PURCHASE','GFE','TREATMENT','COMISSION','REFUND'
            'intent' => $intent,
            'payment' => $payment,
            'receipt' => $receipt,
            'discount_credits' => 0,
            'promo_discount' => 0,
            'promo_code' =>  '',
            'subtotal' => $total,
            'total' => $total,
            'prod' => 1,
            'is_visible' => 1,
            'comission_payed' => 0,
            'comission_generated' => 0,
            'created' => date('Y-m-d H:i:s'),
        );

        $c_entity = $this->DataPayment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $ent_saved = $this->DataPayment->save($c_entity);
            if ($ent_saved) {
                return $ent_saved->id;
            }

            return 0;
        } 

    }

    public function calculate_tip(){
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

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        //$ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        $ent_treatment = $this->DataPayment->find()->where(['uid' => $treatment_uid, 'type' => 'TREATMENT'])->first();
                //$total_amount = $ent_payment->total;

        if(empty($ent_treatment)){
            $this->message('Treatment not found');
            return;
        }

        $tip = get('tip', 0);
        $tip_type = get('tip_type','');

        if($tip > 0){
            if($tip_type == 'PERCENTAGE'){
                $tip = ($tip / 100) * $ent_treatment->total;
            }else if($tip_type == 'CUSTOM'){
                $tip = $tip * 100;
            }
        }

        $this->set('tip', round($tip));
        $this->success();
    }

    public function promo_code_subscription() {

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

        $subscription_total = $this->total_subscriptionmsl;

        if ($subscription_type == "SUBSCRIPTIONMD"||$subscription_type == "SUBSCRIPTIONMDFILLERS"||$subscription_type == "SUBSCRIPTIONMDIVT"||$subscription_type == "SUBSCRIPTIONMDIVTFILLERS"){
            $subscription_total = $this->total_subscriptionmd;
        }

        // Validar que el codigo de descuento sea iv para cambiar la categoria si no, todos sirven con la otra categoria
        if(strpos($subscription_type, 'IVT') !== false){
            $category = '';
            if(strpos($subscription_type, 'MSL') !== false){
                $category = 'IVMSL';
            }else if(strpos($subscription_type, 'MD') !== false){
                $category = 'IVMD';
            }

            $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,$category);
        }else{
            $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,'SUBSCRIPTIONMD');
        }

        $this->set('total', intval($total_amount));
        $this->success();
    }

    public function promo_code_subscription_ot() {

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

        // $subscription_type = strtoupper(get('type',''));
        // if (empty($subscription_type) || $subscription_type != 'SUBSCRIPTIONOT'){
        //     $this->message('Invalid type subscription.');
        //     return;
        // }
        

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $this->loadModel('SpaLiveV1.CatAgreements');
        $this->loadModel('SpaLiveV1.DataTrainings');
        $resubscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID, 'DataSubscriptions.deleted' => 0, 'DataSubscriptions.subscription_type LIKE' => '%MSL%'])->first();
        $ent_subscription = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID, 'DataSubscriptions.deleted' => 0, 'DataSubscriptions.status' => 'ACTIVE', 'DataSubscriptions.subscription_type LIKE' => '%MSL%'])->first();
        $sub_active = false;
        $previous_services = [];

      
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

        $data_training_id = get('data_training_id',0);
        $course_id = get('data_course_id',0);
       

        $training_level = $this->DataTrainings->find()->select(['level' => 'Training.level'])->join([
            'Training' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'Training.id = DataTrainings.training_id'],
        ])->where(['DataTrainings.id' => $data_training_id])->first();


        $main_training_level = "";
        if (!empty($training_level)) {
            $main_training_level = $training_level->level;
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
            'FILLERS'

        ];

        if (in_array($main_training_level, $levels, true)) {

            $_total_md = 0;
            $_total_msl = 0;
            $total_msl_coverage = 0;
            switch($main_training_level){
                case 'LEVEL 1':
                case 'BOTH NEUROTOXINS':
                case 'NEUROTOXINS BASIC':
                    $total_msl_coverage = 1;
                    

                    $_total_md = $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'Neurotoxins MD Subscription',
                        'description' => $sub_active == false ? '' : '(Additional subscription)',
                        'subtotal' => $_total_md
                    ];
                    break;
                case 'LEVEL 3 FILLERS':
                case 'FILLERS':
                    $total_msl_coverage = 1;
                    
                    $_total_md = $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'Fillers MD Subscription',
                        'description' => $sub_active == false ? '' : '(Additional subscription)',
                        'subtotal' => $_total_md
                    ];
                    break;
                case 'MYSPALIVE_S_HYBRID_TOX_FILLER_COURSE':
                case 'MYSPALIVES_HYBRID_TOX_FILLER_COURSE':
                    $total_msl_coverage = 2;
                    
                    $total_tmp = 0;
                    $total_tmp = $sub_active == false ? $this->total_subscription_ot_main_md : $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'Neurotoxins MD Subscription',
                        'description' => $sub_active == false ? '' : '(Additional subscription)',
                        'subtotal' => $total_tmp
                    ];
                    $total_tmp += $this->total_subscription_ot_addon_md;
                    $sub_arr[] = [
                        'type' => 'Fillers MD Subscription',
                        'description' => '(Additional subscription)',
                        'subtotal' => $this->total_subscription_ot_addon_md
                    ];
                    $_total_md = $total_tmp;
                    break;
                default:
                    break;
            }

            $msl_description = '';
            $total_msl_coverage = $total_msl_coverage + count($previous_services);
            if($total_msl_coverage == 1){
                $msl_description = '(covers 1 type of treatment)';
            }else if($total_msl_coverage > 1){
                $msl_description = '(covers ' . $total_msl_coverage . ' types of treatments)';
            }
            
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
                $sub_arr[] = [
                    'type' => 'Software subscription',
                    'description' => $msl_description,
                    'subtotal' => $this->prices_msl[$total_msl_coverage - 1]
                    ];
                $_total_msl += $this->prices_msl[$total_msl_coverage - 1];
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
                    $sub_arr[] = [
                        'type' => 'Software subscription',
                        'description' => $msl_description,
                        'subtotal' => $this->prices_msl[$total_msl_coverage - 1]
                        ];
                    $_total_msl += $this->prices_msl[$total_msl_coverage - 1];
                }
            }
            if($sub_active == false){
                $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today $0</p>';
            }else{
                $content = '<p style="font-size: 28px; font-weight: bold; text-align: center;">Total due today $' . ($_total_md + $_total_msl) / 100 . '</p>';
            }
        }else{
            
            $ent_data_trainings = $this->DataTrainings
            ->find()->select([
                'name_key' => 'OtherTreatment.name_key',
                'name' => 'OtherTreatment.name',
                'require_mdsub' => 'OtherTreatment.require_mdsub',
                'msl' => 'CatAgreementMSL.uid',
                'md' => 'CatAgreementMD.uid',
                'msl_agreement' => 'DataAgreementMSL.id',
                'md_agreement' => 'DataAgreementMD.id',
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
                        AND CatAgreementMD.agreement_type = 'OTHER_TREATMENTS'
                        AND CatAgreementMD.other_treatment_id = OtherTreatment.id
                        AND CatAgreementMD.deleted = 0
                        AND CatAgreementMD.issue_type = 'MD'"
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
            ->group(['CatAgreementMD.id','CatAgreementMSL.id'])
            ->all();
            
            $total_msl_coverage = 0;
            $_total_md = 0;
            $_total_msl = 0;

            $sub_arr = [];
            foreach($ent_data_trainings as $row) {

                $total_msl_coverage = $row->total_coverage;
            
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
                $sub_arr[] = [
                    'type' => 'Software subscription',
                    'description' => $msl_description,
                    'subtotal' => $this->prices_msl[$total_msl_coverage - 1]
                    ];
                $_total_msl += $this->prices_msl[$total_msl_coverage - 1];
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
                    $sub_arr[] = [
                        'type' => 'Software subscription',
                        'description' => $msl_description,
                        'subtotal' => $this->prices_msl[$total_msl_coverage - 1]
                        ];
                    $_total_msl += $this->prices_msl[$total_msl_coverage - 1];
                }
            }


        }

        $subscription_total = $_total_md + $_total_msl;

       $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,'SUBSCRIPTIONOT');

        $this->set('total', intval($total_amount));
        $this->success();
    }

    public function promo_code_subscription_ot_schools() {

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
        if (empty($subscription_type) || $subscription_type != 'SUBSCRIPTIONOT'){
            $this->message('Invalid type subscription.');
            return;
        }
        
        $course_id = get('course_id',0);

      
        $this->loadModel('SpaLiveV1.DataTrainings');
         $this->loadModel('SpaLiveV1.DataTrainings');
        $this->loadModel('CatCourses');
        $ent_data_trainings = $this->CatCourses
        ->find()->select([
            'name' => 'OtherTreatment.name',
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

        $SUBS = new SubscriptionController();
       
        $sub_arr = [];
        foreach($ent_data_trainings as $row) {
        
            if (!empty($row->md_agreement) && !empty($row->msl_agreement) && (empty($row->md) || empty($row->msl)) ) {
                continue;
            }
            
            $tmp_total = 0;
            
            if (!empty($row->md) && !empty($row->md_agreement) && $row->require_mdsub == 1) {
                if ($_total_md == 0) {
                    $_total_md += $SUBS->total_subscription_ot_main_md;
                } else {
                    $_total_md += $SUBS->total_subscription_ot_addon_md;
                }
            }

            if (!empty($row->msl) && !empty($row->msl_agreement)) {
                $main_addon = 'Add on';
                if ($_total_msl == 0) {
                    $_total_msl += $SUBS->total_subscription_ot_main_msl;
                } else {
                    $_total_msl += $SUBS->total_subscription_ot_addon_msl;
                }
            }
                  
        }

        $subscription_total = $_total_md + $_total_msl;


       $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,'SUBSCRIPTIONOT');

        $this->set('total', intval($total_amount));
        $this->success();
    }

    public function custom_payment(){
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

        // VALIDATE USER

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid', 'SysUsers.payment_intent','SysUsers.amount','SysUsers.email','SysUsers.custom_pay'])
        ->where(['SysUsers.id' => USER_ID])->first();
        
        if (empty($ent_user)) {
            $this->message('User not found');
            return;
        }

        if ($ent_user->custom_pay == 0) {
            $this->message('Not allowed');
            return;
        }

        // MAKE PAYMENT     

        $amount = get('amount',0);
        if($amount <= 0){
            $this->message('Invalid amount');
            return;
        }
        
        $amount = $amount < 100 ? 100 : $amount;

        $payment_method = get('payment_method', '');

        if(empty($payment_method)){
            $this->message('Payment method empty.');
            return;
        }

        $str_uid = Text::uuid();
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
        
        $oldCustomer = $stripe->customers->all([
            "email" => get('email', ''),
            "limit" => 1,
        ]);

        if (count($oldCustomer) == 0) {
            $customer = $stripe->customers->create([
                'description' => get('description', ''),   
                "email" => get('email', ''),
            ]);
        } else $customer = $oldCustomer->data[0];

        $stripe_result = '';
        $error = '';

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount' => $amount,
                'currency' => 'USD',
                'metadata' => ['type' => 'TRAINING TREATMENT', 'uid' => $str_uid],
                'receipt_email' => get('email', ''),    
                'confirm' => true,
                'description' => 'TRAINING TREATMENT',
                'customer' => $customer['id'],
                'payment_method' => $payment_method,
                'transfer_group' => $str_uid,
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
        if (isset($intent->charges->data[0]->receipt_url)) {
            $receipt_url = $intent->charges->data[0]->receipt_url;
            $id_charge = $intent->charges->data[0]->id;
            $payment_id = $intent->id;
        }

        // SAVE CUSTOM PAYMENT ON DATABASE AND HANDLE ERROR
        if(!empty($error)){
            $this->message($error);
            return;
        }

        $this->loadModel('SpaLiveV1.DataCustomPayments');                

        $array_save = array(
            'uid' => $str_uid,
            'type' => 'TRAINING TREATMENT',
            'email' => get('email',''),
            'name' => get('name',''),
            'lname' => get('lname',''),
            'service_description' => get('description',''),
            'total' => get('amount',''),
            'payment_intent' => $payment_id,
            'payment' => $payment_id,
            'receipt' => $receipt_url,
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'createdby' => USER_ID
        );

        $c_entity = $this->DataCustomPayments->newEntity($array_save);
        if(!$c_entity->hasErrors()) {            
            $this->createPaymentRegister('TRAINING TREATMENT', $ent_user->id, 0, $str_uid, $payment_id, $id_charge, $receipt_url, $amount, $amount);
            if ($this->DataCustomPayments->save($c_entity)) {
                $this->set('uid', $str_uid);
                $this->success();
            }
        }        
    }

    public function get_nec_panel() {
        $this->loadModel('SpaLiveV1.SysUsers');

        $l3n4p = get('l3n4p', '');
        if($l3n4p != '6092482f7ce858.91169218') {
            $this->message('Not allowed');
            return;
        }   
        
        $uid = get('uid', '');
        if (empty($uid)) {
            $this->message('Empty uid');
            return;
        }   

        $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $uid])->first();
        
        if (empty($ent_user)) {
            $this->message('User not found');
            return;
        }

        if (!($ent_user->type == 'injector' || $ent_user->type == 'examiner' || $ent_user->type == 'gfe+ci')) {
            $this->message('Not allowed');
            $this->set('type', $ent_user->type);
            return;
        }
        
        //$year = get('year', 0);
        $year = 2025;       
        if(empty($year)){
            $year = intval(date('Y'));  
        }          
        $id = $ent_user->id;

        $html_bulk = $this->build_nec_user($id, $year,$ent_user); 
        
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));

        $html2pdf->writeHTML($html_bulk);
        // $html2pdf->Output(TMP . 'reports' . DS . $ent_tray->filename, 'F');
        $html2pdf->Output('nec.pdf', 'I'); //,'D'

    }

    public function get_nec() {
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

        // VALIDATE USER
        
        $id = USER_ID;
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $id])->first();
        
        if (empty($ent_user)) {
            $this->message('User not found');
            return;
        }

        if (!($ent_user->type == 'injector' || $ent_user->type == 'examiner' || $ent_user->type == 'gfe+ci')) {
            $this->message('Not allowed');
            $this->set('type', $ent_user->type);
            return;
        }
        
        //$year = get('year', 0);
        $year = 2025;
        if(empty($year)){
            $year = intval(date('Y'));  
        }          
        
        $html_bulk = $this->build_nec_user($id, $year,$ent_user); 

        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));

        $html2pdf->writeHTML($html_bulk);
        // $html2pdf->Output(TMP . 'reports' . DS . $ent_tray->filename, 'F');
        $html2pdf->Output('nec.pdf', 'I'); //,'D'  
    }

    private function build_nec_user($id, $year,$ent_user){
        $this->loadModel('SpaLiveV1.DataPayment'); 

        ini_set('memory_limit', '-1');
        // $arr_payment_types = array('CI COMMISSION', 
        //                            'TIP COMMISSION',
        //                            'GFE COMMISSION',);
        // $ent_payments = $this->DataPayment->find()
        //                      ->select(
        //                               [
        //                                'sum' => 'SUM(DataPayment.total)'
        //                               ]
        //                              )
        //                      ->where ([
        //                                'DataPayment.id_to' => $id, 
        //                                'DataPayment.is_visible' => 1, 
        //                                'DataPayment.prod' => 1,
        //                                'DataPayment.created >=' => $year . '-01-01 00:00:00',   
        //                                'DataPayment.created <=' => $year . '-12-30 23:59:59',
        //                                'DataPayment.type IN' => $arr_payment_types          
        //                               ]
        //                              )
        //                      ->first();
        
        $stripe_account = $ent_user->stripe_account;
        if($stripe_account != ''){        
            $limit = 100;
            $year = $year;
            
            $load_more = true;

            $arr_transfers = [];
            $arr_stripe_conditions = ['destination' => $stripe_account, 'limit' => $limit, 'created' => [
                    'gte' => strtotime("{$year}-01-01"),
                    'lte' => strtotime("{$year}-12-31"),
                ]
            ];
            
            
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key')); 
            
            $transfers = $stripe->transfers->all($arr_stripe_conditions);
            $last_obj = null;

            if($transfers){
                while ($load_more) {
                    $transfers = $stripe->transfers->all($arr_stripe_conditions);
    
                    foreach ($transfers->data as $key => $tr) {
                        $arr_transfers[] = $tr;
                        $last_obj = $tr->id;
                    }
                    if (count($transfers->data) < $limit) $load_more = false;
                    else $arr_stripe_conditions['starting_after'] = $last_obj;        }
    
                $grand_total = 0;
                foreach($arr_transfers as $transfer) {
                    $grand_total += $transfer->amount;
                }
    
                
                $this->loadModel('SpaLiveV1.DataWN');
                $ent_w9 = $this->DataWN->find()->where (['DataWN.user_id' => $id])->first();
                
                $payers_name_and_address = 'MySpaLive<br>130 N Preston road. #329 Prosper, TX, 75078<br>+1 (469) 277 0897';
                $payer_tin = '85-3546576';
                $receipt_tin = ($ent_w9) ? !empty($ent_w9->ssn) ? $ent_w9->ssn : $ent_w9->ein : $ent_user->ein;
                $recipient_name = !empty($ent_w9->name) ? $ent_w9->name : $ent_user->name . ' ' . $ent_user->lname;
                $recipient_street = !empty($ent_w9->address) ? $ent_w9->address : $ent_user->street;      
                $recipient_city = ($ent_w9) ? $ent_w9->city : $ent_user->city . ' TX, ' . $ent_user->zip ;
                $calendar_year = $year-2000;
                // $compensation = !empty($ent_payments->sum) ? $ent_payments->sum/100 : 0;
                $compensation = $grand_total / 100;
    
                // BUILD PDF AND SEND
    
                $html_bulk = $this->build_nec(
                    $payers_name_and_address,
                    $payer_tin,
                    $receipt_tin,
                    $recipient_name,
                    $recipient_street,
                    $recipient_city,    
                    $calendar_year,
                    $compensation
                );
    
                return $html_bulk;
                
            }else{
                $html_bulk = "
                <page>
                    <div style='width: 210mm; height: 97mm; position:relative;'>
                        <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                            <div style='position: absolute;left: 10mm;top: 20mm; max-width: 80mm; background-color: white'>No data found</div>
                        </div>
                    </div>
                </page>";
                return $html_bulk;
            }

        } else {
            $html_bulk = "
                <page>
                    <div style='width: 210mm; height: 97mm; position:relative;'>
                        <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                            <div style='position: absolute;left: 10mm;top: 20mm; max-width: 80mm; background-color: white'>No data found</div>
                        </div>
                    </div>
                </page>";
            return $html_bulk;
        }
    }

    private function build_nec(
        $payers_name_and_address,
        $payer_tin,
        $receipt_tin,
        $recipient_name,
        $recipient_street,
        $recipient_city,    
        $calendar_year,
        $compensation
        // $income_tax = '1001.00',
        // $state_tax_1 = '1002.00',
        // $state_tax_2 = '1003.00',   
        // $state_number_1 = '43',
        // $state_number_2 = '43',
        // $state_income_1 = '1006.00',
        // $state_income_2 = '1007.00'
    ){
        // $html_bulk = "
        //             <page>
        //                 <div style='width: 210mm; height: 97mm; position:relative;'>
        //                     <img style='width:210mm; height: 97mm; position:absolute; z-index: 1;' src='" . env('URL_ASSETS', 'https://api.spalivemd.com/assets/') . "nec.jpg' />
        //                     <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                                
        //                         <div style='position: absolute;left: 10mm;top: 20mm; max-width: 80mm; background-color: red'>".$payers_name_and_address."</div>
        //                         <div style='position: absolute;left: 55mm;top: 41mm; max-width: 35mm; background-color: white'>".$receipt_tin."</div>                                
        //                         <div style='position: absolute;left: 10mm;top: 53mm; max-width: 80mm; background-color: red'>".$recipient_name."</div>
        //                         <div style='position: absolute;left: 10mm;top: 64mm; max-width: 80mm; background-color: red'>".$recipient_street."</div>
        //                         <div style='position: absolute;left: 10mm;top: 72mm; max-width: 80mm; background-color: red'>".$recipient_city."</div>
        //                         <div style='position: absolute;left: 150mm;top: 32mm; background-color: red'>".$calendar_year."</div>
        //                         <div style='position: absolute;left: 105mm;top: 41mm; background-color: red'>".$compensation."</div>
        //                         <div style='position: absolute;left: 105mm;top: 68mm; background-color: red'>".$income_tax."</div>
        //                         <div style='position: absolute;left: 105mm;top: 77mm; background-color: red'>".$state_tax_1."</div>
        //                         <div style='position: absolute;left: 105mm;top: 81.5mm; background-color: red'>".$state_tax_2."</div>
        //                         <div style='position: absolute;left: 135mm;top: 77mm; background-color: red'>".$state_number_1."</div>
        //                         <div style='position: absolute;left: 135mm;top: 81.5mm; background-color: red'>".$state_number_2."</div>
        //                         <div style='position: absolute;left: 180mm;top: 77mm; background-color: red'>".$state_income_1."</div>
        //                         <div style='position: absolute;left: 180mm;top: 81.5mm; background-color: red'>".$state_income_2."</div>
                                
                                
        //                     </div>
        //                 </div>
        //             </page>";
        $html_bulk = "
                    <page>
                        <div style='width: 210mm; height: 97mm; position:relative;'>
                            <img style='width:210mm; height: 97mm; position:absolute; z-index: 1;' src='" . $this->URL_API . "/assets/nec.jpg' />
                            <div style='width:210mm; height: 97mm; position:absolute; z-index: 2;'>
                                
                                <div style='position: absolute;left: 10mm;top: 20mm; max-width: 80mm; background-color: white'>".$payers_name_and_address."</div>
                                <div style='position: absolute;left: 55mm;top: 41.5mm; max-width: 35mm; background-color: white'>".$payer_tin."</div>                                
                                <div style='position: absolute;left: 10mm;top: 41.5mm; max-width: 35mm; background-color: white'>".$receipt_tin."</div>                                
                                <div style='position: absolute;left: 10mm;top: 53mm; max-width: 80mm; background-color: white'>".$recipient_name."</div>
                                <div style='position: absolute;left: 10mm;top: 64mm; max-width: 80mm; background-color: white'>".$recipient_street."</div>
                                <div style='position: absolute;left: 10mm;top: 72.5mm; max-width: 80mm; background-color: white'>".$recipient_city."</div>
                                <div style='position: absolute;left: 150mm;top: 32mm; background-color: white'>".$calendar_year."</div>
                                <div style='position: absolute;left: 105mm;top: 41mm; background-color: white'>".number_format($compensation, 2)."</div>
                            </div>
                        </div>
                    </page>";
        return $html_bulk;
    }

    public function send_email_nec_bulk(
        $is_test = 0, 
        $user_email = ''
    ) {
        // Variables:
            // $user_email es para enviar el 1099 a un usuario en específico (get('email', '')).
            // $is_test es para mostrar el número de usuarios a los que se les enviará el 1099 (get('test', false)).

        $this->loadModel('SpaLiveV1.DataPayment');

        $arr_type_usr = ['injector', 'examiner', 'gfe+ci'];
        $arr_invalid_steps = array('HOME');
        $arr_invalid_types = array('REFUND', 'REFUND PRODUCT');
        
        //$user_email = get('email', '');
        // Variable $user_email es para enviar el 1099 a un usuario en específico
        if (empty($user_email)) {
            $ent_users = $this->DataPayment
            ->find()
            ->select(['SysUsers.id', 'SysUsers.uid', 'SysUsers.email', 'SysUsers.type', 'DataPayment.created'])
            ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataPayment.id_to = SysUsers.id']])
            ->where([
                'SysUsers.type IN' => $arr_type_usr, 
                'SysUsers.deleted' => 0, 
                'SysUsers.steps NOT IN' => $arr_invalid_steps,
                'DataPayment.type NOT IN' => $arr_invalid_types,
                'DataPayment.comission_payed' => 1,
                'DataPayment.created BETWEEN "2023-01-01 00:00:00" AND "2023-12-31 23:59:59"',
            ])
            ->order(['DataPayment.created' => 'DESC'])
            ->group(['SysUsers.id'])
            ->all();
        } else {
            $ent_users = $this->DataPayment
            ->find()
            ->select(['SysUsers.id', 'SysUsers.uid', 'SysUsers.email', 'SysUsers.type', 'DataPayment.created'])
            ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataPayment.id_to = SysUsers.id']])
            ->where([
                'SysUsers.type IN' => $arr_type_usr, 
                'SysUsers.deleted' => 0, 
                'SysUsers.steps NOT IN' => $arr_invalid_steps,
                'DataPayment.type NOT IN' => $arr_invalid_types,
                'DataPayment.comission_payed' => 1,
                'SysUsers.email' => $user_email,
                'DataPayment.created BETWEEN "2023-01-01 00:00:00" AND "2023-12-31 23:59:59"',
            ])
            ->order(['DataPayment.created' => 'DESC'])
            ->group(['SysUsers.id'])
            ->all();
        }
            
        if (count($ent_users) <= 0) {
            //$this->message('No users found');
            var_dump('No users found');
            return;
        }
        
        $users_to_send = [];

        $last_year = intval(date('Y')) - 1;
        var_dump('year: ' . $last_year);
        
        //$this->set('ent_users', $ent_users);return;
        foreach ($ent_users as $payment) {
            $users_to_send[] = $payment;
        }

        if (count($users_to_send) == 0) {
            //$this->message('0 users to send');
            var_dump('0 users to send');
            return;
        }

        // Variable para mostrar el número de usuarios a los que se les enviará el 1099
        //$is_test = get('test', false);

        if ($is_test == 1) {
            //$this->set('count_users', count($users_to_send));
            var_dump(count($users_to_send));
            $ids = [];
            foreach ($users_to_send as $user) {
                $ids[] = strval($user['SysUsers']['id']);
            }
            //$this->set('ids', $ids);
            var_dump($ids);
            $this->success();
            return;
        }

        // foreach para enviar el 1099 a cada usuario
        foreach ($users_to_send as $user) {
            $this->loadModel('SpaLiveV1.SysUsers');

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $user['SysUsers']['id']])->first();
            $html_bulk = $this->build_nec_user($user['SysUsers']['id'], $last_year,$ent_user);

            $pdfFilename = TMP . 'nec.pdf';

            $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
            $html2pdf->writeHTML($html_bulk);
            $html2pdf->Output($pdfFilename, 'F');

            // SEND EMAIL
            $subject = 'MySpaLive - 1099';
            $data = array(
                'from' => 'MySpaLive <noreplay@mg.myspalive.com>',
                'to' => $user['SysUsers']['email'],
                'subject' => $subject,
                'html' => 'Your 2023\'s 1099 file is attached',
                'attachment' => curl_file_create($pdfFilename, 'application/pdf', "MySpaLive_{$user['SysUsers']['type']}_" .'1099.pdf'),
            );

            $result = $this->send_email_with_attachment($data);

            // Eliminar el archivo PDF después de enviarlo
            unlink($pdfFilename);

            if (!$result) {
                //$this->message('Email not sent to ' . $user['SysUsers']['email']);
                var_dump('Email not sent to ' . $user['SysUsers']['email']);
            } else {
                //$this->message('Email sent to ' . $user['SysUsers']['email']);
                var_dump('Email sent to ' . $user['SysUsers']['email']);
            }
        }

        $this->success();
        var_dump('------ END ------');
    }

    private function send_email_with_attachment($data) {

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
        $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    
        curl_close($curl);
    
        return $statusCode == 200;
    }

    public function rcpt_purchase($return_path = false, $pay_uid = '', $force = false){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');
        
        if (!$force) {

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
            # code...
        }

        $transfer_group = (!empty($pay_uid)) ? $pay_uid : get('trgp','');
        if(empty($transfer_group)){ 
            $this->message("Invalid payment.");
            return;
        }
 
        $payment = $this->DataPayment->find()->where(['DataPayment.uid' => $transfer_group, 'DataPayment.id_from >' => 0, 'DataPayment.payment <>' => '' ])->first();
        if(empty($payment)){
            $this->message("Invalid item.");
            return;
        }

        $html_detail  = '';
        $concept_row  = '';
        $user_name    = '';
        $address      = '';
        $shippinFoot  = "";
        $discountFoot = '';
        $realTotal    = 0;
        $promoCode    = $payment->promo_code;
        $promoDisc    = intval($payment->promo_discount);
        $amount = number_format($payment->total / 100,2);

        $fields = ['SysUsers.name', 'SysUsers.lname','SysUsers.street','SysUsers.suite','SysUsers.city','State.abv','SysUsers.zip'];
        $user_info = $this->SysUsers->find()->select($fields)->join(['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SysUsers.state']])->where(['SysUsers.id' => $payment->id_from])->first();

        if($payment->type == 'GFE'){
            $this->loadModel('SpaLiveV1.DataConsultation');
            $realTotal = $payment->subtotal;
            $consl = $this->DataConsultation->find()->select(['DataConsultation.uid', 'SysUsers.name', 'SysUsers.lname', 'Examiner.name','Examiner.lname'])
            ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id']])
            ->join(['Examiner' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Examiner.id = DataConsultation.assistance_id']])
            ->where(['DataConsultation.uid' => $payment->service_uid])->first();
            $examiner = 'MySpaLive';
            if(!empty($consl)){
                $user_name = $consl->SysUsers['name'].' '.$consl->SysUsers['lname'];
                $examiner = (isset($consl->Examiner['name']) ? $consl->Examiner['name'].' '.$consl->Examiner['lname'] : 'SpaLiveMD');
            }else{
                $user_name = $user_info->name.' '.$user_info->lname;
            }
            $html_detail = "
                <tr>
                    <td colspan=\"2\">
                        &nbsp;<br>
                        <table style=\"margin-left: 30px;\">
                            <tbody>
                                <tr>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">Examiner:&nbsp;{$examiner} <br>&nbsp;</td>
                                </tr>
                                <tr>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">Patient:&nbsp;{$user_name} <br>&nbsp;</td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            ";
            $concept_row = "
                <tr>
                    <td style=\"text-align: left; width: 610px;\">1 Certificate</td>
                    <td style=\"text-align: right; \">$".number_format($realTotal / 100,2)."</td>
                </tr>
            ";

        }else if($payment->type == 'CI REGISTER' || $payment->type == 'BASIC COURSE' || $payment->type == 'ADVANCED COURSE'){
            $realTotal = $payment->subtotal;
            $concept_row = "
                <tr>
                    <td style=\"text-align: left; width: 610px;\">MySpaLive Certified injector application.</td>
                    <td style=\"text-align: right; \">$".number_format($realTotal / 100,2)."</td>
                </tr>
            ";

        }else if($payment->type == 'TREATMENT'){
            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.DataTreatmentDetail');

            $user_name = $user_info->name.' '.$user_info->lname;
            $treatment = $this->DataTreatment->find()->select(['DataTreatment.id', 'DataTreatment.address', 'DataTreatment.suite','DataTreatment.zip','DataTreatment.city','State.abv', 'Injector.name', 'Injector.lname'])->where(['DataTreatment.uid' => $payment->uid])
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Injector' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Injector.id = DataTreatment.assistance_id']
            ])->first();
            if(empty($treatment)){
                $this->message("Invalid treatment.");
                return;
            }
            $address = $treatment->address.(isset($treatment->suite) && !empty($treatment->suite) ? ', '.$treatment->suite : '').', '.$treatment->city.', '.$treatment->State['abv'].' '.$treatment->zip;
            $detail = $this->DataTreatmentDetail->find()
            ->select(['DataTreatmentDetail.quantity', 'DataTreatmentDetail.price', 'DataTreatmentDetail.total', 
                'product_name' => 'CatTreat.name', 'product_detail' => 'CatTreat.details'])
            ->join(['CatTreat' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'CatTreat.id = DataTreatmentDetail.cat_treatment_id']])
            ->where(['DataTreatmentDetail.treatment_id' => $treatment->id])->toArray();
            $injector = 'Injector: '.$treatment->Injector['name'].' '.$treatment->Injector['lname'];

            $html_detail = "
                <tr>
                    <td colspan=\"2\">
                        &nbsp;<br>
                        <table style=\"margin-left: 30px;\">
                            <tbody>
                                <tr>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">{$injector} <br>&nbsp;</td>
                                </tr>
                                <tr>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">Patient:&nbsp;{$user_name} <br>&nbsp;</td>
                                </tr>
                                <tr>
                                    <td colspan=\"2\" style=\"text-align: left; width: 290px;\">Utilized neurotoxins, fillers and materials:<br>&nbsp;</td>
                                </tr>
            ";
            $_total = 0;
            foreach ($detail as $item) {
                $prod = $item['quantity'].' '.$item['product_name'];
                $_total += $item['total'];
                $total = number_format($item['total'] / 100,2);
                $info = $item['product_detail'];
                $html_detail .= "
                    <tr>
                        <td style=\"text-align: left; width: 290px;\">-&nbsp;&nbsp;&nbsp;&nbsp;{$prod} <br>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<small>({$info})</small><br>&nbsp;</td>
                        <td style=\"text-align: right;width: 290px;\">\${$total}<br>&nbsp;</td>
                    </tr>
                ";
            }

            $realTotal = $_total;
            $html_detail .= "                        
                            </tbody>
                        </table>
                    </td>
                </tr>";

            $concept_row = "
                <tr>
                    <td style=\"text-align: left; width: 610px;\">1 Treatment. Details:</td>
                    <td style=\"text-align: right; \">$".number_format($_total / 100,2)."</td>
                </tr>
            ";

        }else if($payment->type == 'PURCHASE'){
            $this->loadModel('SpaLiveV1.DataPurchases');
            $this->loadModel('SpaLiveV1.DataPurchasesDetail');

            $user_name = $user_info->name.' '.$user_info->lname;
            $purchase = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.address', 'DataPurchases.suite','DataPurchases.zip','DataPurchases.city','State.abv', 'DataPurchases.shipping_cost'])->join(['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataPurchases.state']])
            ->where(['DataPurchases.uid' => $payment->uid])->first();
            if(empty($purchase)){
                $this->message("Invalid purchase.");
                return;
            }
            $address = $purchase->address.(isset($purchase->suite) && !empty($purchase->suite) ? ', '.$purchase->suite : '').', '.$purchase->city.', '.$purchase->State['abv'].' '.$purchase->zip;
            $detail =$this->DataPurchasesDetail->find()
            ->select(['quantity' => '(DataPurchasesDetail.qty + DataPurchasesDetail.refunded)', 'DataPurchasesDetail.price', 'total' => "((DataPurchasesDetail.qty + DataPurchasesDetail.refunded) * DataPurchasesDetail.price)", 
                'product_name' => 'CatProduct.name', 'product_detail' => 'CatProduct.sold_as'])
            ->join(['CatProduct' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'CatProduct.id = DataPurchasesDetail.product_id']])
            ->where(['DataPurchasesDetail.purchase_id' => $purchase->id])->toArray();

            $html_detail = "
                <tr>
                    <td colspan=\"2\">
                        &nbsp;<br>
                        <table style=\"margin-left: 30px;\">
                            <tbody>
            ";
            $_total = 0;
            foreach ($detail as $item) {
                $prod = $item['quantity'].' '.$item['product_name'];
                $_total += $item['total'];
                $total = number_format($item['total'] / 100,2);
                $info = $item['product_detail'];
                $html_detail .= "
                    <tr>
                        <td style=\"text-align: left; width: 290px;\">{$prod} <br>&nbsp;&nbsp;&nbsp;<small>({$info})</small><br>&nbsp;</td>
                        <td style=\"text-align: right;width: 290px;\">\${$total}<br>&nbsp;</td>
                    </tr>
                ";
            }

            $html_detail .= "                        
                            </tbody>
                        </table>
                    </td>
                </tr>";
            
            $concept_row = "
                <tr>
                    <td style=\"text-align: left; width: 610px;\">1 Neurotoxin, fillers and materials purchase. Details:</td>
                    <td style=\"text-align: right; \">$".number_format($_total / 100,2)."</td>
                </tr>
            ";

            $shpCost = number_format($purchase->shipping_cost / 100,2);
            $shippinFoot = "
                <tr>
                    <td style=\"text-align: right;\">&nbsp;<br>Shipping cost:&nbsp;&nbsp;&nbsp;</td>
                    <td style=\"text-align: right;\">&nbsp;<br>\${$shpCost}</td>
                </tr>
            ";
            $realTotal = ($_total + $purchase->shipping_cost);

        }else{
            $this->message("Invalid type.");
            return;
        }

        if(empty($address)){
            $address = $user_info->street.(isset($user_info->suite) && !empty($user_info->suite) ? ', '.$user_info->suite : '').', '.$user_info->city.', '.$user_info->State['abv'].' '.$user_info->zip;
        }

        if(!empty($promoCode) && $promoDisc > 0){
            $discount = $realTotal * (($promoDisc)/100);
            $totalToPay = $realTotal - $discount;
            // $discount = ($totalToPay < 100 ? $discount - (100 - $totalToPay) : $discount);

            $discount = number_format(($amount * 100 - $realTotal) / 100,2);
            $realTotal = number_format($realTotal / 100,2);
            $discountFoot = "
                <tr>
                    <td style=\"text-align: right;\">&nbsp;<br>Sub Total:&nbsp;&nbsp;&nbsp;</td>
                    <td style=\"text-align: right;\">&nbsp;<br>\${$realTotal}</td>
                </tr>
                <tr>
                    <td style=\"text-align: right;\">&nbsp;<br>Discount:&nbsp;&nbsp;&nbsp;</td>
                    <td style=\"text-align: right;\">&nbsp;<br>-\${$discount}</td>
                </tr>
            ";                
        }
        
        $date = $payment->created->i18nFormat('MM/dd/yyyy');
        $invoice = strval($payment->id + 1500);
        $len_inv = strlen($invoice);
        for ($i=$len_inv; $i < 6 ; $i++) { 
            $invoice = '0'.$invoice;
        }

        $filename = ($return_path == true ? TMP . 'reports' . DS : '') . 'invoice_' . ($payment->id+1500) . '.pdf';

        $html_content = "
            <div style=\"padding: 8px 1px 8px 1px; width: 100%\">
                <div style=\"width: 100%; display: inline-flex;\">
                    
                    <img height=\"90\" src=\"https://blog.myspalive.com/myspalive-logo1.png\">
                    
                    <div style=\"margin-top: -90px; float: right; margin-left: 300px;\">
                        <p style=\"line-height:22px;\">
                            Date: {$date}
                            <br>
                            Invoice: #{$invoice}
                            <br>
                            EIN: #85-3546576
                        </p>
                    </div>
                </div>
                <div style=\"padding: 0px 16px 0px 16px; margin-top: 24px;\">
                    <p style=\"line-height:20px;\">
                        MySpaLive, LLC
                        <br>
                        Address: 2450 East Prosper Trail, Suite 20, Prosper, TX 75078
                    </p>
                </div>
            </div> 
            <div style=\"margin-top:4px; padding-left: 16px; width: 100%\">
                 <p style=\"line-height:20px;\">
                    To: {$user_name}
                    <br>
                    Address: {$address}
                </p>
            </div> 
            <div style=\"margin-top:52px; padding: 0px 16px 16px 16px;\">
                <table width=\"100%\">
                    <thead>
                        <tr>
                            <th style=\"text-align: left; width: 500px; line-height:30px;\">PRODUCT/SERVICE<br>&nbsp;</th>
                            <th style=\"text-align: right;  line-height:30px;\">COST<br>&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$concept_row}
                        {$html_detail}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan=\"2\" style=\"line-height:60px;\">&nbsp;<br>&nbsp;</td>
                        </tr>
                        {$shippinFoot}
                        {$discountFoot}
                        <tr>
                            <td style=\"text-align: right;\">&nbsp;<br>Total paid:&nbsp;&nbsp;&nbsp;</td>
                            <td style=\"text-align: right;\">&nbsp;<br>\${$amount}</td>
                        </tr>
                        <tr>
                            <td style=\"text-align: right;\">&nbsp;<br>Balance:&nbsp;&nbsp;&nbsp;</td>
                            <td style=\"text-align: right;\"><br>$0</td>
                        </tr>
                        <tr>
                            <td style=\"text-align: center; color: red;\"><br><br><br><br><h1 style=\"font-size: 250%;\">PAID</h1></td>
                        </tr>
                    </tfoot>
                </table>
                
            </div> 
        ";

        // echo ($html_content.$end_hml);exit;

        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->setTestTdInOnePage(false);
        $html2pdf->WriteHTML($html_content);

        if($return_path == true){
            $html2pdf->Output($filename, 'F'); //,'D'
            return $filename;
        }else $html2pdf->Output($filename, 'I'); //,'D'
        exit;
    }

    private function send_receipt($str_email, $Main, $numInvo = 0, $uid = '', $html_msg = '',  $constants = array()){
        if (empty($str_email)) return;

		$this->loadModel('SpaLiveV1.CatNotifications');
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $html_msg])->first();
         
        if (!empty($ent_notification)) {
            $msg_mail = $ent_notification['body'];
            $msg_push = $ent_notification['body_push'];
            foreach($constants as $key => $value){
                $msg_mail = str_replace($key, $value, $msg_mail);
                $msg_push = str_replace($key, $value, $msg_push);
            }

            $conf_subject = $ent_notification['subject'];
            $conf_body = $msg_mail;
            $conf_body .= '<br><br>';
        } else {
            $conf_subject = 'MySpaLive Notification';
            $conf_body = $html_msg;
        }
        //$type = 'Receipt';
        $type = 'Invoice';
        $filename = $this->rcpt_purchase(true, $uid, true);
		
        if(empty($filename)){
            return;
        }
        
        $subject = 'MySpaLive '.$type;
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $str_email,
            'subject' => $subject,
            'html'    => "You have received a {$type} from MySpaLive.",
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . $numInvo . '.pdf'),
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
        // $this->Response->success();
    }


    public function pay_sales_rep() {

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');

        $total_to_transfer = 10000;

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

        if (USER_ID != 1) return;
        
        $dataPay = $this->DataPayment->find()->where(['DataPayment.id' => get('payment_id',0), 'DataPayment.type IN' => array('BASIC COURSE', 'ADVANCED COURSE'),'DataPayment.is_visible' => 1, 'DataPayment.payment <>' => ''])->first();
        
        if (empty($dataPay)) {
            $this->message('Source payment not found');
            return;
        }

        $salesRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email'])->join([
            'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
        ])->where(['DataAssignedToRegister.user_id' => $dataPay->id_from,'DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0])->last();
        
        
        if (empty($salesRep)) {
            $this->message('Sales rep not found or not enabled');
            return;
        }

        if ($salesRep['User']['stripe_account_confirm'] == 0) {
             $this->message('Sales rep has no enabled stripe account.');
            return;
        }

        try {

            $findPayment = $this->DataSalesRepresentativePayments->find()->where(['DataSalesRepresentativePayments.payment_id' => $dataPay->id])->first();
            if (!empty($findPayment)) {
                $this->message('Payment already sent.');
                return;
            }

            if($dataPay->payment_platform == 'stripe'){
                $transfer = \Stripe\Transfer::create([
                    'amount' => $total_to_transfer,
                    'currency' => 'USD',
                    'description' => 'SALES REP PAYMENT',
                    'destination' => $salesRep['User']['stripe_account'],
                    'source_transaction' => $dataPay->payment
                ]);
            }else{
                $transfer = \Stripe\Transfer::create([
                    'amount' => $total_to_transfer,
                    'currency' => 'USD',
                    'description' => 'SALES REP PAYMENT',
                    'destination' => $salesRep['User']['stripe_account'],
                ]);
            }

            if ($transfer) {
                $transfer_uid = Text::uuid();
                $array_save = array(
                    'uid' => $transfer_uid,
                    'payment_id' => $dataPay->id,
                    'amount' => $total_to_transfer,
                    'user_id' => $salesRep['User']['id'],
                    'payment_uid' => $transfer->id,
                    'payload' => json_encode($transfer),
                    'created' => date('Y-m-d H:i:s'),
                    'createdby' => defined('USER_ID') ? USER_ID : 0,
                );

                $c_entity = $this->DataSalesRepresentativePayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataSalesRepresentativePayments->save($c_entity);
                    $this->success(true);
                    $this->set('success', true);


                    $type = 'Receipt';
                    $filename = $this->receipt_sales_rep(true,$transfer_uid);

                    if(empty($filename)){
                        return;
                    }
                    
                    $subject = 'MySpaLive '.$type;
                    $data = array(
                        'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                        'to'    => $salesRep['User']['email'],
                        'subject' => $subject,
                        'html'    => "You have received a {$type} from MySpaLive.",
                        'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . ($dataPay->id+1500) . '.pdf'),
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
            }

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

        if(isset($error) && !empty($error)){
            $this->message($error);
        }
    }

    public function pay_sales_rep_schools($id_injector,$id_receipt) {

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataAssignedSchool');
        $this->loadModel('SpaLiveV1.DataSchoolReferredPayments');

        $total_to_transfer = 7500;

        $assign_school = $this->DataAssignedSchool->find()->where(['DataAssignedSchool.user_id' => $id_injector, 'DataAssignedSchool.deleted' => 0])->first();

        if(!empty($assign_school)) {
            $array_save_pay = array(
                'uid' => Text::uuid(),
                'payment_id' => $id_receipt,
                'amount' =>  9900,
                'user_id' => $assign_school->school_id,
                'status' => 'NOT PAID',
                'payload' => '',
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s'),
            );
    
            $c_entity = $this->DataSchoolReferredPayments->newEntity($array_save_pay);
            if(!$c_entity->hasErrors()) {
                $payment_ent_id = $this->DataSchoolReferredPayments->save($c_entity);
            }
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $patient = $this->SysUsers->find()->where(['id' => $id_injector, 'deleted' => 0, 'active' => 1])->first(); // injector valida que no sea un tester
        if(!empty($patient)){
            $str_user = $patient['email'] . " ". $patient['name'] ." ". $patient['lname'] . " ". $patient['mname'];
            $str_user = strtolower($str_user);                
            if (strpos($str_user, "tester") !== false) {                                
                return;
            }
        }

        $salesRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
            'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
        ])->where(['DataAssignedToRegister.user_id' => $id_injector,'DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.team' => 'OUTSIDE'])->first();
        
        if (empty($salesRep)) {        
            $salesRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
                'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
            ])->where(['DataAssignedToRegister.user_id' => $id_injector,'DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.team' => 'OUTSIDE'])->first();
        }

        if ($salesRep['User']['stripe_account_confirm'] == 0) {            
            return 'Sales rep has no enabled stripe account.'; ##testing purposes only
        }

        if ($salesRep['Rep']['rank'] == 'JUNIOR') { // Si el sales rep es junior, se busca al senior
            $salesRep = $this->DataAssignedToRegister->find()->select(['User.stripe_account','User.id','User.stripe_account_confirm','User.email', 'Rep.rank'])->join([
                'Rep' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'Rep.user_id = DataAssignedToRegister.representative_id'],
                'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = Rep.user_id'],
            ])->where(['DataAssignedToRegister.deleted' => 0,'Rep.deleted' => 0, 'Rep.rank' => 'SENIOR', 'Rep.team' => 'OUTSIDE'])->first();
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

        if($already_paid->count() > 0){            
            return 'Sales rep already paid.'; ##testing purposes only
        }

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
            'subtotal' => 0,
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
                'description' => 'SALES TEAM MD SUB',
                'destination' => $salesRep['User']['stripe_account'],
            ]);

            if ($transfer) {
                $transfer_uid = Text::uuid();
                $array_save = array(
                    'uid' => $transfer_uid,
                    'payment_id' => $payment_ent_id->id,
                    'description' => 'SALES TEAM MD SUB',
                    'amount' => $total_to_transfer,
                    'user_id' => $salesRep['User']['id'],
                    'payment_uid' => $transfer->id,
                    'payload' => json_encode($transfer),
                    'created' => date('Y-m-d H:i:s'),
                    'createdby' => defined('USER_ID') ? USER_ID : 0
                );

                $c_entity = $this->DataSalesRepresentativePayments->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $saved = $this->DataSalesRepresentativePayments->save($c_entity);

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

    public function custom_pay(){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
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

        if (USER_ID != 1) return;

        $description = get('description','');
        $amount = get('amount',0);
        $injector_id = get('injector_id',0);
        $category = get('category', '');             

        $ent_user = $this->SysUsers->find()->where(['id' => $injector_id])->first();

        if(empty($ent_user)) {
            $this->message('Invalid user.');
            return;
        }

        try {
             $transfer = \Stripe\Transfer::create([
              'amount' => $amount * 100,
              'currency' => 'USD',
              'description' => "Sales team payment: ".$description,
              'destination' => $ent_user['stripe_account'],
            ]);

            if ($transfer) {
                $transfer_uid = Text::uuid();

                $array_payment_save = array(
                    'id_from' => 0,
                    'id_to' => $ent_user['id'],
                    'uid' => $transfer_uid,
                    'service_uid' => '',
                    'type' => 'CUSTOM SALES REP',
                    'intent' => $transfer->id,
                    'payment' => $transfer->id,
                    'receipt' => $transfer->reversals->url,
                    'promo_discount' => 0,
                    'promo_code' => '',
                    'discount_credits' => 0,
                    'subtotal' => $amount * 100,
                    'total' => $amount * 100,
                    'prod' => 1,
                    'prepaid' => 1,
                    'is_visible' => 1,
                    'comission_payed' => 1,
                    'comission_generated' => 1,
                    'created' => date('Y-m-d H:i:s'),
                    'createdby' => 0,
                    'refund_id' => 0,
                    'transfer' => '',
                    'category' => $category,
                );

                $c_entity2 = $this->DataPayment->newEntity($array_payment_save);

                if(!$c_entity2->hasErrors()) {
                    $ent_saved = $this->DataPayment->save($c_entity2);
                    $array_save = array(
                        'uid' => $transfer_uid,
                        'payment_id' => $ent_saved->id,
                        'amount' => $amount * 100,
                        'user_id' => $ent_user['id'],
                        'payment_uid' => $transfer->id,
                        'description' => $description,
                        'payload' => json_encode($transfer),
                        'created' => date('Y-m-d H:i:s'),
                        'createdby' => defined('USER_ID') ? USER_ID : 0,
                    );
    
                    $c_entity = $this->DataSalesRepresentativePayments->newEntity($array_save);
                    $this->DataSalesRepresentativePayments->save($c_entity);
                    
                    $this->success(true);
                    $this->set('success', true);

                    $type = 'Receipt';
                    $filename = $this->receipt_sales_rep(true,$transfer_uid);

                    if(empty($filename)){
                        return;
                    }
                    
                    $subject = 'MySpaLive '.$type;
                    $data = array(
                        'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                        'to'    => $ent_user['email'],
                        'subject' => $subject,
                        'html'    => "You have received a {$type} from MySpaLive.",
                        'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . ($ent_saved->id+1500) . '.pdf'),
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
            }

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

        if(isset($error) && !empty($error)){
            $this->message($error);
        }
    }

    public function payment_invitations($injector_id, $pay_id, $payment_id, $transfer_uid){
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['id' => $injector_id, 'deleted' => 0, 'active' => 1])->first();

        if(empty($ent_user)) {
            $this->message('Invalid user.');
            return;
        }

        $dataPay = $this->DataPayment->find()->where(['DataPayment.id' => $pay_id, 'DataPayment.is_visible' => 1, 'DataPayment.payment <>' => ''])->first();
        
        if (empty($dataPay)) {
            $this->message('Source payment not found');
            return;
        }

        $data_sales_pay = $this->DataSalesRepresentativePayments->find()->where(['DataSalesRepresentativePayments.id' => $payment_id])->first();

        if($data_sales_pay->amount == 0){
            $this->DataSalesRepresentativePayments->updateAll(
                [
                    'payment_uid' => 'no payment',
                    'payload' => 'no payment',
                    'deleted' => 0,
                ],
                ['id' => $payment_id]
            );
        } else{
            if (!empty($data_sales_pay->payment_uid)) return;
            $patient = $this->SysUsers->find()->where(['id' => $dataPay->id_from, 'deleted' => 0, 'active' => 1])->first();
            if(!empty($patient)){
                $str_user = $patient['email'] . " ". $patient['name'] ." ". $patient['lname'] . " ". $patient['mname'];
                $str_user = strtolower($str_user);                
                if (strpos($str_user, "tester") !== false) {   $this->log(__LINE__ . ' ' . json_encode('autopay tester'));                              
                    $this->DataSalesRepresentativePayments->updateAll(
                        [
                            'payment_uid' => 'no payment tester',
                            'payload' => 'no payment tester',
                            'deleted' => 0,
                        ],
                        ['id' => $payment_id]
                    );
                    return;
                }
            }

            try {
                if($dataPay->payment_platform == 'stripe'){
                    $transfer = \Stripe\Transfer::create([
                        'amount' => $data_sales_pay->amount,
                        'currency' => 'USD',
                        'description' => !empty($data_sales_pay) ? $data_sales_pay->description : 'PAY COMISSION',
                        'destination' => $ent_user['stripe_account'],
                        'source_transaction' => $dataPay->payment
                    ]);
                }else{
                    $transfer = \Stripe\Transfer::create([
                        'amount' => $data_sales_pay->amount,
                        'currency' => 'USD',
                        'description' => !empty($data_sales_pay) ? $data_sales_pay->description : 'PAY COMISSION',
                        'destination' => $ent_user['stripe_account'],
                    ]);
                }
    
                if ($transfer) {
    
                    $this->DataSalesRepresentativePayments->updateAll(
                        [
                            'payment_uid' => $transfer->id,
                            'payload' => json_encode($transfer),
                            'deleted' => 0,
                            'created' => date('Y-m-d H:i:s'),
                        ],
                        ['id' => $payment_id]
                    );

                    if($data_sales_pay->description == 'PAY INVITATION'){
                        $this->email_for_referral($data_sales_pay->payment_id, $data_sales_pay->user_id);
                    }
                    
                    $this->success(true);
                    $this->set('success', true);
    
                    $type = 'Receipt';
                    $filename = $this->receipt_sales_rep(true,$transfer_uid);
    
                    if(empty($filename)){
                        return;
                    }
                    
                    $subject = 'MySpaLive '.$type;
                    $data = array(
                        'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                        'to'    => $ent_user['email'],
                        'subject' => $subject,
                        'html'    => "You have received a {$type} from MySpaLive.",
                        'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . ($payment_id+1500) . '.pdf'),
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
    
            if(isset($error) && !empty($error)){
                $this->message($error);
            }
        }
    }

    private function email_for_referral($payment_id, $user_id){

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['id' => $user_id, 'deleted' => 0, 'active' => 1])->first();

        $pay = $this->DataPayment->find()->select([
            'User.name',
            'User.lname',
        ])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataPayment.id_from'],
        ])
        ->where(['DataPayment.id' => $payment_id])
        ->first();

        $subject = "You've Received a Reward for Your Injector Referral";
        $html = "
            Hello " . $ent_user->name . " " . $ent_user->lname . ",
            We’ve confirmed the successful referral of " . $pay['User']['name'] . " " . $pay['User']['lname'] . ". Your reward has been issued to your Stripe account.
            Thank you for supporting the MySpaLive network.

            Best regards,
            The MySpaLive Team
        ";
        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $ent_user->email,
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

    public function payment_sales_rep_weight_loss($patient_id){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataSalesTeamPatients');
        $this->loadModel('SpaLiveV1.DataSalesRepresentative');
        $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');

        $sales = $this->DataSalesTeamPatients->find()->where(['DataSalesTeamPatients.patient_id' => $patient_id, 'DataSalesTeamPatients.deleted' => 0])->first();

        if(!empty($sales)){
            $dsrActive = $this->DataSalesRepresentative->find()->where([
                'DataSalesRepresentative.user_id' => $sales->sales_team_id,
                'DataSalesRepresentative.deleted' => 0,
            ])->first();
            if (empty($dsrActive)) {
                return;
            }

            $dataPay = $this->DataPayment->find()->where(['DataPayment.id_from' => $patient_id, 'DataPayment.type' => 'WEIGHT LOSS','DataPayment.is_visible' => 1, 'DataPayment.payment <>' => ''])->first();
            $sales_rep = $this->SysUsers->find()->where(['id' => $sales->sales_team_id, 'deleted' => 0])->first();
        
            if (empty($dataPay)) {
                $this->message('Source payment not found');
                return;
            }

            if (empty($sales_rep)) {
                $this->message('Source payment not found');
                return;
            }

            try {
                $transfer = \Stripe\Transfer::create([
                 'amount' => 7500,
                 'currency' => 'USD',
                 'description' => 'PAY SALES TEAM WEIGHT LOSS',
                 'destination' => $sales_rep['stripe_account'],
                 'source_transaction' => $dataPay->payment
               ]);
   
               if ($transfer) {

                   $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');

                   $transfer_uid = Text::uuid();

                    $array_team = array(
                        'uid' => $transfer_uid,
                        'payment_id' => $dataPay->id,
                        'amount' => 7500,
                        'user_id' => $sales->sales_team_id,
                        'payment_uid' => $transfer->id,
                        'description' => 'WEIGHT LOSS',
                        'payload' => json_encode($transfer),
                        'deleted' => 0,
                        'created' => date('Y-m-d H:i:s'),
                        'createdby' => defined('USER_ID') ? USER_ID : 0,
                    );

                    $entity_team = $this->DataSalesRepresentativePayments->newEntity($array_team);
                    $this->DataSalesRepresentativePayments->save($entity_team);
                   
                   /* $this->success(true);
                   $this->set('success', true);
   
                   $type = 'Receipt';
                   $filename = $this->receipt_sales_rep(true,$transfer_uid);
   
                   if(empty($filename)){
                       return;
                   }
                   
                   $subject = 'MySpaLive '.$type;
                   $data = array(
                       'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                       'to'    => $ent_user['email'],
                       'subject' => $subject,
                       'html'    => "You have received a {$type} from MySpaLive.",
                       'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . ($payment_id+1500) . '.pdf'),
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
   
                   unlink($filename); */
               }
   
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
    
            if(isset($error) && !empty($error)){
                $this->message($error);
            }
        }
    }

    public function payment_intent_msl_service_purchases() {
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataOtherServicesCheckIn');

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

        $payed = $this->DataConsultationOtherServices->find()
            ->where(['DataConsultationOtherServices.payment <>' => '', 
                    'DataConsultationOtherServices.deleted' => 0, 
                    'DataConsultationOtherServices.status' => 'PAID',
                    'DataConsultationOtherServices.patient_id' => USER_ID])
            ->last();

        /*if(!empty($payed)) {
            $this->message('Payment already done.');
            $c_entity_p = $this->DataPurchases->find()->where(['DataPurchases.uid' => $payed->uid])->last();
            $c_entity_p2 = $this->DataPurchasesOtherServices->find()->where(['DataPurchasesOtherServices.consultation_uid' => $payed->uid])->last();
            $c_entity_p3 = $this->DataOtherServices->find()->where(['DataOtherServices.uid' => $payed->uid])->last();
            $c_entity_p4 = $this->DataOtherServicesCheckIn->find()->where(['DataOtherServicesCheckIn.consultation_uid' => $payed->uid])->last();
            $this->set('data_purchases', $c_entity_p);
            $this->set('data_purchases_other_services', $c_entity_p2);
            $this->set('data_other_services', $c_entity_p3);
            $this->set('data_checkin', $c_entity_p4);
            $this->success();
            return;
        }*/

        /*$monthly_payment = get('monthly_payment', '');
        if(empty($monthly_payment) || $monthly_payment == ''){
            $this->message('Payment monthly empty.');
            return;
        }*/

        $subscription_pay = get('subscription_pay', 0);

        $service_pay = get('service_pay', 1);

        $consultation_uid = get('consultation_uid', '');

        $total_service_amount=0;
        $msj_test = '';
        /*if($monthly_payment == 'MONTHLY'){

            $total_service_amount = $this->weightloss_monthly;
            $msj_test = 'month-to-month weight loss';
        }else if($monthly_payment == '3 MONTHS'){*/
            $monthly_payment = "3 MONTHS";
            $wl_total = $this->weightloss_total;
            $wl_discount = $this->weightloss_discount;
            $total_service_amount = $wl_total - $wl_discount;
            $msj_test = '3-month weight loss';
        //}
       

        $pay_service = false;
        $pay_subs = false;
        $patient_id = USER_ID;

        if($service_pay == 1){
            $service_type = get('service_type', '');

            $payment_method = get('payment_method', '');

            if(empty($payment_method) || $payment_method == ''){
                $this->message('Payment method empty.');
                return;
            }
            
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
            $oldCustomer = $stripe->customers->all([
                "email" => $user['email'],
                "limit" => 1,
            ]);

            if(count($oldCustomer) == 0) {
                $customer = $stripe->customers->create([
                    'description' => $user['name'].' '.$user['mname'].' '.$user['lname'],
                    'email' => $user['email'],
                ]);
            } else $customer = $oldCustomer->data[0];

            $total_amount = $this->validateCode(get('promo_code',''),$total_service_amount,'SERVICES');
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'usd',
                    'customer' => $customer->id,
                    'payment_method' => $payment_method,
                    'metadata' => ['state' => USER_STATE],
                    'off_session' => true,
                    'confirm' => true,
                    'error_on_requires_action' => true,
                    'description' => $service_type ." " . $monthly_payment ,
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
            $paymen_intent = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }
            
            $uid = Text::uuid();
            if (empty($error) && $stripe_result->status == 'succeeded') {
                
                $this->createPaymentRegister($service_type, USER_ID, 0, $uid, $paymen_intent, $id_charge, $receipt_url, $total_amount, $total_amount);
                // Enviamos correo de confirmación de pago0
                $this->DataConsultationOtherServices->updateAll(
                    ['payment' => $payment_id,
                    'payment_method' => $payment_method,
                    'payment_intent' => $paymen_intent,
                    'status' => 'PAID',
                    'promo_code' =>  get('promo_code',''),
                    //'start_date' => date('Y-m-d H:i:s'),
                    //'end_date' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +90 days')),
                    'monthly_payment' => $monthly_payment], 
                    ['uid' => $consultation_uid]
                );
                $Main = new MainController();
                $constants_not = [
                    '[PATIENT_NAME]' => USER_NAME . ' ' . USER_LNAME,
                ];
                $Main->notify_devices('BUY_WS',array(USER_ID),false,true,true,array(),'',$constants_not,false);
                //* Commented since the sales representative assigned to the injector should not be paid
                //$this->payment_sales_rep_weight_loss(USER_ID);
                $pay_service = true;
                $isDev = env('IS_DEV', false);
                if (strpos(strtolower(USER_NAME), 'test') === false || strpos(strtolower(USER_LNAME), 'test') === false) {
                    // Email to 
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
                                                            <div style="color: #655489; font-size: 40px; text-align: center;"><b>
                                                                A new patient has purchased weight loss: </b></div><br>
                                                            <div style="text-align: center; color:#666666; font-size: 30px;">
                                                                ' . USER_NAME . ' ' . USER_LNAME . ' (' . USER_PHONE .')' . '
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
                        'to'    => !$isDev ? 'patientrelations@myspalive.com' : 'francisco@advantedigital.com',
                        'subject' => "New weight loss purchase.",
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
                    
                    if(!$isDev){
                        try {           
                            $sid    = env('TWILIO_ACCOUNT_SID'); 
                            $token  = env('TWILIO_AUTH_TOKEN'); 
                            $twilio = new Client($sid, $token); 
                                
                            $message = $twilio->messages 
                                        ->create( '+1' . '9034366629', // to 
                                                array(  
                                                    "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                                    "body" => 'New weight loss purchase: ' . USER_NAME . ' ' . USER_LNAME . ' (' . USER_PHONE .')'
                                                ) 
                                        ); 
                            } catch (TwilioException $e) {

                            }
                    }
                }

            } else {
                $this->message('Payment failed. Declined card. Please try again.');
                return;
            }

            $tentative_date = date('Y-m-d');
            $flag_purchase = true;
            $purchase_id = 0;
            $call_number = 1;

            for ($i = 0; $i < 3; $i++) {
                $total_pur = 0;
                $show_pur = 1;
                $call_type = 'FIRST CONSULTATION';
                $purchaseUid = $uid;

                if($i<1){
                    $total_pur = $total_amount;
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
                        'user_id' => $patient_id,
                        'status' => 'NEW',
                        'payment' =>  $payment_id,
                        'payment_intent' => $paymen_intent,
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
                    'user_id' => $patient_id,
                    'consultation_uid' => $consultation_uid,
                    'status' => 'NEW',
                    'payment' =>  $payment_id,
                    'payment_intent' => $paymen_intent,
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
                    // Pay Comission
                #region
                $this->loadModel('SpaLiveV1.DataReferredOtherServices');

                $referred = $this->DataReferredOtherServices->find()
                ->where(['DataReferredOtherServices.user_id' => USER_ID, 'DataReferredOtherServices.deleted' => 0])->first();

                /*if($monthly_payment == 'MONTHLY' && !empty($referred)){
                    $Main = new MainController();
                    $Main->createPaymentCommissionRegister("WEIGHT LOSS COMMISSION",USER_ID,$referred->referred_id,$uid,$paymen_intent,$id_charge,$receipt_url,3300);
                    $pay_comission = $this->DataPayment->find()->where(['DataPayment.uid' => $uid])->first();
                    $this->DataPayment->updateAll(
                        ['comission_generated' => 1],
                        ['id' => $pay_comission->id]
                    );
                    $Main->notify_devices('INVITED_MONTH_WS',array($referred->referred_id),false,true,true,array(),'',array(),false);
                }*/

                #endregion

                //$this->set('data_purchases_other_services', $ent_saved_p_os);
                // Guarda la tabla "other services"
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
                        'patient_id' => USER_ID,
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
                    $ent_saved_checkin = $this->DataOtherServicesCheckIn->save($cq_entity_first_consultation);

                    if($ent_saved_checkin){
                        $this->set('data_checkin', $ent_saved_checkin);
                        $this->success();
                    }
                }
                
            }else{
                $this->message('Purchases not created.');
                return;
            }


        }

        if($subscription_pay == 1){
            $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmslservice,'SUBSCRIPTION');

            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'usd',
                    'customer' => $customer->id,
                    'payment_method' => $payment_method,
                    'metadata' => ['state' => USER_STATE],
                    'off_session' => true,
                    'confirm' => true,
                    'error_on_requires_action' => true,
                    'description' => 'SUBSCRIPTIONMSLSERVICES',
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
            $paymen_intent = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }

            if (empty($error) && $stripe_result->status == 'succeeded') {
                $this->createPaymentSubscription(USER_ID, $customer->id, $payment_method, 'SUBSCRIPTIONMSLSERVICES', $this->total_services, $total_amount, $paymen_intent, $id_charge, $receipt_url);
                $this->DataConsultationOtherServices->updateAll(
                    ['payment' => $payment_id,
                    'payment_method' => $payment_method,
                    'payment_intent' => $paymen_intent,
                    'promo_code' =>  get('promo_code','')], 
                    ['uid' => $consultation_uid]
                );
                $pay_subs = true;
            }else {
                $this->message('Payment subscription failed. Declined card. Please try again.');
                return;
            }

        }

        if($pay_service || $pay_subs){
            $this->success();
        }
    }
    
    public function payment_intent_msl_service() {
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');
        $this->loadModel('SpaLiveV1.DataOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');

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

        $subscription_pay = get('subscription_pay', 0);

        $service_pay = get('service_pay', 1);

        $consultation_uid = get('consultation_uid', '');

        $total_service_amount = $this->weightloss_total;

        $pay_service = false;
        $pay_subs = false;
        $patient_id = USER_ID;

        if($service_pay == 1){
            $service_type = get('service_type', '');

            $payment_method = get('payment_method', '');

            if(empty($payment_method) || $payment_method == ''){
                $this->message('Payment method empty.');
                return;
            }
            
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
            $oldCustomer = $stripe->customers->all([
                "email" => $user['email'],
                "limit" => 1,
            ]);

            if(count($oldCustomer) == 0) {
                $customer = $stripe->customers->create([
                    'description' => $user['name'].' '.$user['mname'].' '.$user['lname'],
                    'email' => $user['email'],
                ]);
            } else $customer = $oldCustomer->data[0];

            $total_amount = $this->validateCode(get('promo_code',''),$total_service_amount,'SERVICES');
            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'usd',
                    'customer' => $customer->id,
                    'payment_method' => $payment_method,
                    'metadata' => ['state' => USER_STATE],
                    'off_session' => true,
                    'confirm' => true,
                    'error_on_requires_action' => true,
                    'description' => $service_type,
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
            $paymen_intent = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }    

            if (empty($error) && $stripe_result->status == 'succeeded') {
                $uid = Text::uuid();
                $this->createPaymentRegister($service_type, USER_ID, 0, $uid, $paymen_intent, $id_charge, $receipt_url, $total_amount, $total_amount);
                // Enviamos correo de confirmación de pago0
                $this->DataConsultationOtherServices->updateAll(
                    ['payment' => $payment_id,
                    'payment_method' => $payment_method,
                    'payment_intent' => $paymen_intent,
                    'status' => 'PAID',
                    'promo_code' =>  get('promo_code',''),
                    'start_date' => date('Y-m-d H:i:s'),
                    'end_date' => date('Y-m-d H:i:s', strtotime(date('Y-m-d H:i:s') . ' +90 days'))], 
                    ['uid' => $consultation_uid]
                );
                $pay_service = true;

            } else {
                $this->message('Payment failed. Declined card. Please try again.');
                return;
            }
            
            //guardar tabla purchases 
            $array_purchases = array(
                'uid' => Text::uuid(),
                'user_id' => $patient_id,
                'consultation_uid' => $consultation_uid,
                'status' => 'NEW',
                'payment' => $payment_id,
                'payment_intent' => $paymen_intent,
                'amount' => $total_amount,
                'status' => 'WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT',
                'shipping_date' => date('Y-m-d', strtotime(date('Y-m-d') . ' +1 days')),
                'deleted' => 0,
                'call_type' => 'FIRST CONSULTATION',
                'call_number' => 1,
                'created' => date('Y-m-d'),
                'show' => 1
            );
    
            $c_entity_p = $this->DataPurchasesOtherServices->newEntity($array_purchases);
            if(!$c_entity_p->hasErrors()) {
                $ent_saved_p = $this->DataPurchasesOtherServices->save($c_entity_p);
                $this->set('data', $ent_saved_p);
                $this->success();
                if ($ent_saved_p) {
                    return $ent_saved_p->id;
                }
            }

            //guardar tabla other services
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
            if(!$c_entity->hasErrors()) {
                $ent_saved = $this->DataOtherServices->save($c_entity);
                $this->set('data', $ent_saved);
                $this->success();
                if ($ent_saved) {
                    return $ent_saved->id;
                }
            }
        }

        if($subscription_pay == 1){
            $total_amount = $this->validateCode(get('promo_code',''),$this->total_subscriptionmslservice,'SUBSCRIPTION');

            try {
                $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $total_amount,
                    'currency' => 'usd',
                    'customer' => $customer->id,
                    'payment_method' => $payment_method,
                    'metadata' => ['state' => USER_STATE],
                    'off_session' => true,
                    'confirm' => true,
                    'error_on_requires_action' => true,
                    'description' => 'SUBSCRIPTIONMSLSERVICES',
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
            $paymen_intent = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }

            if (empty($error) && $stripe_result->status == 'succeeded') {
                $this->createPaymentSubscription(USER_ID, $customer->id, $payment_method, 'SUBSCRIPTIONMSLSERVICES', $this->total_services, $total_amount, $paymen_intent, $id_charge, $receipt_url);
                $this->DataConsultationOtherServices->updateAll(
                    ['payment' => $payment_id,
                    'payment_method' => $payment_method,
                    'payment_intent' => $paymen_intent,
                    'promo_code' =>  get('promo_code','')], 
                    ['uid' => $consultation_uid]
                );
                $pay_subs = true;
            }else {
                $this->message('Payment subscription failed. Declined card. Please try again.');
                return;
            }

        }

        if($pay_service || $pay_subs){
            $this->success();
        }
    }

    public function payment_intent_subscription(){
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

        $isDev = env('IS_DEV', false);

        $this->loadModel('SpaLiveV1.DataSubscriptions');
        $payment_method = get('payment_method','');

        $ent_subscriptions_md = $this->DataSubscriptions->find()->where(['DataSubscriptions.user_id' => USER_ID,'DataSubscriptions.deleted' => 0,'DataSubscriptions.status IN' => array('HOLD')])->all();

        if (count($ent_subscriptions_md) > 0 ) {
            $SUBS = new SubscriptionController();
            $SUBS->resubscription_monthly(USER_ID, $payment_method);
            $this->success();
            return;
        }

        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
        $this->loadModel('SpaLiveV1.DataSubscriptionsPaymentsError');
        $this->loadModel('SpaLiveV1.SysUsers');

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
        
        if ($subscription_type == "SUBSCRIPTIONMD"||$subscription_type == "SUBSCRIPTIONMDFILLERS"||$subscription_type == "SUBSCRIPTIONMDIVT"||$subscription_type == "SUBSCRIPTIONMDIVTFILLERS"){
            $subscription_total = $this->total_subscriptionmd;
        }
        
        // Validar que el codigo de descuento sea iv para cambiar la categoria si no, todos sirven con la otra categoria
        if($main_service == 'IV THERAPY'){
            $category = '';
            if(strpos($subscription_type, 'MSL') !== false){
                $category = 'IVMSL';
            }else if(strpos($subscription_type, 'MD') !== false){
                $category = 'IVMD';
            }

            $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,$category);
        }else{
            $total_amount = $this->validateCode(get('promo_code',''),$subscription_total,'SUBSCRIPTIONMD');
        }

        if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'SUBSCRIPTIONMSL' ||  $subscription_type == "SUBSCRIPTIONMSLIVT" ||  $subscription_type == "SUBSCRIPTIONMDIVT" ){
            $ent_subscription = $this->DataSubscriptions->find()->where([
                'DataSubscriptions.subscription_type' => $subscription_type,
                'DataSubscriptions.user_id' => USER_ID,
                'DataSubscriptions.status' => 'ACTIVE',
                'DataSubscriptions.deleted' => 0
            ])->first();
            if(!empty($ent_subscription)){
                // $this->message('You already have an active subscription.');
                $settings = get('is_from_settings', false);
                if(!$settings){
                    if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'SUBSCRIPTIONMSL'){
                        $subscription_type = $subscription_type == "SUBSCRIPTIONMD" ? "MDSCHOOLSUBSCRIPTION" : "MSLSCHOOLSUBSCRIPTION";
                        $this->SysUsers->updateAll(
                            ['steps' => ($subscription_type == "MSLSCHOOLSUBSCRIPTION") ? 'MDSCHOOLSUBSCRIPTION' : 'SCHOOLVIDEOWATCHED'],
                            ['id' => USER_ID]
                        );
                    } else {
                        $subscription_type = $subscription_type == "SUBSCRIPTIONMDIVT" ? "MDIVTSUBSCRIPTION" : "MSLIVTSUBSCRIPTION";
                        $this->SysUsers->updateAll(
                            ['steps' => ($subscription_type == "MSLIVTSUBSCRIPTION") ? 'MDIVTSUBSCRIPTION' : 'IVTHERAPYVIDEOWATCHED'],
                            ['id' => USER_ID]
                        );
                    }
                }
                $this->success();
                return;
            }

            $stripe_result = '';
            $error = '';

            try {
              $stripe_result = \Stripe\PaymentIntent::create([
                'amount' => $total_amount,
                'currency' => 'usd',
                'customer' => $customer['id'],
                'payment_method' => $payment_method,
                'metadata' => ['state' => USER_STATE],
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

            $receipt_url = '';
            $id_charge = '';
            $payment_id = '';
            if(isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $payment_id = $stripe_result->id;
            }

            if(empty($error) && $stripe_result->status == 'succeeded') {
                $s_entity = $this->DataSubscriptions->newEntity([
                    'uid'		=> $this->DataSubscriptions->new_uid(),
                    'event'		=> 'payment_intent_subscription',
                    'payload'	=> json_encode($stripe_result),
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

                if(!$s_entity->hasErrors()) {

                    $id = $this->DataSubscriptions->save($s_entity);
                    $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

                    $this->set('subscription_id', $id->id);
                    $c_entity = $this->DataSubscriptionPayments->newEntity([
                        'uid'   => Text::uuid(),
                        'subscription_id'  => $id->id,
                        'user_id'  => USER_ID,
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

                    $this->log(__LINE__ . ' ' . json_encode($c_entity));

                    if (empty($error)) {
                        if(!$c_entity->hasErrors()) {
                            $id_payment = $this->DataSubscriptionPayments->save($c_entity);
                        }
                    }else{
                        $c_entity = $this->DataSubscriptionsPaymentsError->newEntity([                                    
                            'subscription_id' => $id->id,
                            'user_id' => USER_ID, 
                            'error' => json_encode($error), 
                            'date' => date('Y-m-d H:i:s') , 
                            'stripe_result' => json_encode($stripe_result), 
                            'customer_id' => $customer['id'],
                            'payment_method'=> $payment_method,
                        ]);
                        if(!$c_entity->hasErrors()) {
                            $this->DataSubscriptionsPaymentsError->save($c_entity);
                            return;
                        }
                    }
                }
    
                if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'MDSCHOOLSUBSCRIPTION' || $subscription_type == 'SUBSCRIPTIONMDIVT'){
                    $injector = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID, 'SysUsers.md_id ' => 0])->first();
                    if (!empty($injector)){
                        $this->loadModel('SpaLiveV1.SysUserAdmin');
                        $md_id = $this->SysUserAdmin->getAssignedDoctorInjector(USER_ID);                
                        $this->SysUsers->updateAll(
                            ['md_id' => $md_id],
                            ['id' => USER_ID]
                        );
                    }

                    #region Pay comission to sales representative
                    $this->loadModel('SpaLiveV1.DataAssignedToRegister');

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

                    if (!empty($assignedRep)) {

                        if($subscription_type == 'MDSCHOOLSUBSCRIPTION' || $subscription_type == 'SUBSCRIPTIONMD'){
                            $this->loadModel('SpaLiveV1.DataCourses');

                            $school = $this->DataCourses->find()->where([
                                'DataCourses.user_id' => USER_ID, 
                                'DataCourses.deleted' => 0,
                                'DataCourses.status'  => 'DONE',
                            ])->first();

                            if(!empty($school)){
                                $x = $this->pay_sales_rep_schools(USER_ID, $id_payment->id);
                                $service = ucfirst(strtolower($main_service));
                                $this->send_email_sales_team_member(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep);
                                $this->email_injector_subscribe_os(USER_EMAIL);
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

                            if(!env('IS_DEV', false)){
                                $Ghl = new GhlController();
                                $array_ghl = array(
                                    'email' => USER_EMAIL,
                                    'name' => USER_NAME,
                                    'lname' => USER_LNAME,
                                    'phone' => USER_PHONE,
                                    'costo' => 0,
                                    'column' => 'Subscribed from another school',
                                );
                                $Ghl->updateOpportunityTags($array_ghl);
                            }
                        } else if($subscription_type == 'SUBSCRIPTIONMDIVT'){
                            $x = $this->pay_sales_rep_schools(USER_ID, $id_payment->id);
                            $service = ucfirst(strtolower($main_service));
                            $this->send_email_sales_team_member(USER_ID, $service, 'MD', 'Full', 7500, $assignedRep);
                        }
                    }

                    $isDev = env('IS_DEV', false);

                    if(!$isDev){
                        try {           
                            $sid    = env('TWILIO_ACCOUNT_SID'); 
                            $token  = env('TWILIO_AUTH_TOKEN'); 
                            $twilio = new Client($sid, $token); 
                                
                            $message = $twilio->messages 
                                        ->create( '+1' . '9034366629', // to 
                                                array(  
                                                    "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                                    "body" => 'The injector ' . USER_NAME . ' ' . USER_LNAME . ' (' . USER_PHONE .') from another school has paid the subscription.'
                                                ) 
                                        ); 
                            } catch (TwilioException $e) {

                            }
                    }

                    #endregion
                    
                    #region send email to injector notify_devices
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

                $this->loadModel('SpaLiveV1.SysUsers');
                $settings = get('is_from_settings', false);
                if(!$settings){
                    //$subscription_type = $subscription_type == "SUBSCRIPTIONMD" ? "MDSCHOOLSUBSCRIPTION" : "MSLSCHOOLSUBSCRIPTION";
                    if($subscription_type == 'SUBSCRIPTIONMD' || $subscription_type == 'SUBSCRIPTIONMSL'){
                        $subscription_type = $subscription_type == "SUBSCRIPTIONMD" ? "MDSCHOOLSUBSCRIPTION" : "MSLSCHOOLSUBSCRIPTION";
                        $this->SysUsers->updateAll(
                            ['steps' => ($subscription_type == "MSLSCHOOLSUBSCRIPTION") ? 'MDSCHOOLSUBSCRIPTION' : 'SCHOOLVIDEOWATCHED'],
                            ['id' => USER_ID]
                        );
                    } else {
                        $subscription_type = $subscription_type == "SUBSCRIPTIONMDIVT" ? "MDIVTSUBSCRIPTION" : "MSLIVTSUBSCRIPTION";
                        $this->SysUsers->updateAll(
                            ['steps' => ($subscription_type == "MSLIVTSUBSCRIPTION") ? 'MDIVTSUBSCRIPTION' : 'W9'],
                            ['id' => USER_ID]
                        );
                    }
                }

                $this->success();
            }else{
                $this->message('Your payment method is not valid. Add a new payment method and try again. ' .$error);
            }
        }

        shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . USER_UID . ' > /dev/null 2>&1 &');
    }

    /// monthly payment for weighloos
    public function payment_intent_follow_up(){
        $this->loadModel('SpaLiveV1.DataPurchases');

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

        $purchase_id = get('purchase_id','');
        if (empty($purchase_id)) {
            $this->message('purchase id not found.');
            return;
        }

        $monthly_payment = get('monthly_payment', '');
        if(empty($monthly_payment) || $monthly_payment == ''){
            $this->message('Payment monthly empty.');
            return;
        }

        $total_service_amount=0;
        if($monthly_payment == 'MONTHLY'){
            $total_service_amount = $this->weightloss_monthly;
        }

        if($monthly_payment == '3 MONTHS'){
            $wl_total = $this->weightloss_total;
            $wl_discount = $this->weightloss_discount;
            $total_service_amount = $wl_total - $wl_discount;
        }

        $payment_method = get('payment_method', '');
        if(empty($payment_method) || $payment_method == ''){
            $this->message('Payment method empty.');
            return;
        }
        
        $pay_service = false;
        $service_type = 'WEIGHT LOSS';

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
        $oldCustomer = $stripe->customers->all([
            "email" => $user['email'],
            "limit" => 1,
        ]);

        if(count($oldCustomer) == 0) {
            $customer = $stripe->customers->create([
                'description' => $user['name'].' '.$user['mname'].' '.$user['lname'],
                'email' => $user['email'],
            ]);
        } else $customer = $oldCustomer->data[0];

        $total_amount = $this->validateCode(get('promo_code',''),$total_service_amount,'SERVICES');

        try {
            $stripe_result = \Stripe\PaymentIntent::create([
                'amount' => $total_amount,
                'currency' => 'usd',
                'customer' => $customer->id,
                'payment_method' => $payment_method,
                'metadata' => ['state' => USER_STATE],
                'off_session' => true,
                'confirm' => true,
                'error_on_requires_action' => true,
                'description' => $service_type,
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
            $paymen_intent = '';
            if (isset($stripe_result->charges->data[0]->receipt_url)) {
                $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                $id_charge = $stripe_result->charges->data[0]->id;
                $paymen_intent = $stripe_result->charges->data[0]->payment_intent;
                $payment_id = $stripe_result->id;
            }    

            $uid = Text::uuid();
            if (empty($error) && $stripe_result->status == 'succeeded') { 
                
                $this->createPaymentRegister($service_type, USER_ID, 0, $uid, $paymen_intent, $id_charge, $receipt_url, $total_amount, $total_amount);
                
                $this->DataPurchases->updateAll(
                    [
                    'uid' => $uid,
                    'payment' => $payment_id,
                    'payment_intent' => $paymen_intent,
                    'status' => 'WAITING FOR THE EXAMINER TO CONFIRM YOUR PRODUCT',
                    'amount' => $total_amount
                    ], 
                    ['id' => $purchase_id]
                );

                $pay_service = true;
                $this->saveAdvancedReceipt($user['email']);
                $this->saveAdvancedReceipt('francisco@advantedigital.com');
            } else {
                $this->message('Payment failed. Declined card. Please try again.');
                return;
            }

            if($pay_service){
                $this->success();
            }

    }
    
    //getpaymetns by uid consultation
    public function getPaymetnsByUidConsultation(){
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

        //$token = get('token', '');
        //
        //if(!empty($token)){
        //    $user = $this->AppToken->validateToken($token, true);
        //    if($user === false){
        //        $this->message('Invalid token.');
        //        $this->set('session', false);
        //        return;
        //    }
        //    $this->set('session', true);
        //} else {
        //    $this->message('Invalid token.');
        //    $this->set('session', false);
        //    return;
        //}

        $consultation_uid = get('consultation_uid', '');

        $ent_consultations = $this->DataConsultationOtherServices->find()->where(
            ['DataConsultationOtherServices.uid' => $consultation_uid,
            'DataConsultationOtherServices.deleted' => 0
            ])->all();

        if($ent_consultations){
            $this->set('data', $ent_consultations);
            $this->success();
            //return;
        } else {
            $this->message('Consultation empty.');
            return;
        }
    }

    private function createPaymentSubscription($user_id, $customer_id, $payment_method, $subs_type, $subtotal, $total, $paymen_intent, $charge_id, $receipt_url) {

        // Variable to save the main subscription
        $main_service = 'NEUROTOXINS';

        if(strpos($subs_type, 'FILLERS') !== false){
            $main_service = 'FILLERS';
        }else if(strpos($subs_type, 'FILLERS') !== false){
            $main_service = 'IV THERAPY';
        }

        $this->loadModel('SpaLiveV1.DataSubscriptions');
         $array_save = array(
            'user_id' => $user_id,
            'uid' => Text::uuid(),
            'event' => 'payment_intent_msl_service',
            'payload' => '',
            'request_id' => '',
            'data_object_id' => '',
            'customer_id' => $customer_id,
            'payment_method' => $payment_method,
            'subscription_type' => $subs_type,
            'promo_code' =>  get('promo_code',''),
            'subtotal' => $subtotal,
            'total' => $total,
            'status' => 'ACTIVE',
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'agreement_id' => 0,
            'comments' => '',
            'main_service' => $main_service,
            'state' => USER_STATE,
        );

        $c_entity = $this->DataSubscriptions->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $sub = $this->DataSubscriptions->save($c_entity);
            $this->loadModel('SpaLiveV1.DataSubscriptionPayments');
            $array_save = array(
                'uid' => Text::uuid(),
                'user_id' => $user_id,
                'subscription_id' => $sub->id,
                'total' => $total,
                'payment_id' => $paymen_intent,
                'charge_id' => $charge_id,
                'receipt_id' => $receipt_url,
                'error' => '',
                'status' => 'DONE',
                'notes' => '',
                'created' => date('Y-m-d H:i:s'),
                'deleted' => 0,
                'state' => USER_STATE,
            );

            $c_entity = $this->DataSubscriptionPayments->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->DataSubscriptionPayments->save($c_entity);
            }
        }
    }

    public function charge_for_cancel_treatment($treatment_id){
                
        $token = get('token',"");
        $charge_for_cancel_treatment =false;
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            if($user === false){
                $this->message('Invalid token.');
                $this->set('session', false);
                return $charge_for_cancel_treatment;
            }
            $this->set('session', true);
        } else {
            $this->message('Invalid token.');
            $this->set('session', false);
            return $charge_for_cancel_treatment;
        }
        // VALIDATE USER
        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid', 'SysUsers.payment_intent','SysUsers.amount','SysUsers.email','SysUsers.custom_pay'])
        ->where(['SysUsers.id' => USER_ID])->first();
        
        if (empty($ent_user)) {
            $this->message('User not found');
            return $charge_for_cancel_treatment;
        }        

        // MAKE PAYMENT     
        $amount = $this->amount_cancel_treatment;
        if($amount <= 0){
            $this->message('Invalid amount');
            return $charge_for_cancel_treatment;
        }                
        $this->loadModel('SpaLiveV1.DataTreatment');        
        $payment_method = get('payment_method', '');        
        $str_uid = $treatment_id;       //
        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.id' => $treatment_id])->first();

        if(!empty($ent_treatment)){
            $str_uid = $ent_treatment->uid;           
        } 
        //PAYMENTS METHODS IN STRIPE
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));                
        $oldCustomer = $stripe->customers->all([
            "email" => $ent_user->email,
            "limit" => 1,
        ]);
        if (count($oldCustomer) > 0) {
           $customer = $oldCustomer->data[0];  
           $payment_methods = $stripe->customers->allPaymentMethods(
               $customer->id,
               ['type' => 'card']
            );            
            $arr_payment_methods = array();
            foreach($payment_methods as $method) {
                $arr_payment_methods[] = $method->id;
            }   
            
            foreach($arr_payment_methods as $payment_method) {
                \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
                $stripe_result = '';
                $error = '';
    
                try {
                  $stripe_result = \Stripe\PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => 'usd',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'metadata' => ['state' => USER_STATE],
                    'off_session' => true,
                    'confirm' => true,
                    'description' => "CANCEL_TREATMENT_".$treatment_id
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
                /*$c_entity = $this->DataSubscriptionPayments->newEntity([                    
                    'amount' => $amount,
                    'currency' => 'USD',                
                    'receipt_email' => USER_EMAIL,    
                    'confirm' => true,
                    'description' => 'CANCEL TREATMENT',
                    'customer' => $customer['id'],
                    'payment_method' => $payment_method,
                    'transfer_group' => $str_uid,
                    'error_on_requires_action' => true,
                ]);*/
    
                if (empty($error) && $stripe_result->status == 'succeeded') {               
                    //$receipt_url = '';
                    //$id_charge = '';
                    //$payment_id = '';
                    if (isset($stripe_result->charges->data[0]->receipt_url)) {
                        $receipt_url = $stripe_result->charges->data[0]->receipt_url;
                        $id_charge = $stripe_result->charges->data[0]->id;
                        $payment_id = $stripe_result->id; 
                    }    
                    
                    if (empty($error) && $stripe_result->status == 'succeeded') {
                        $this->set('code_valid', false);
                        $this->createPaymentRegister("CANCEL_TREATMENT", USER_ID, 0, $str_uid, $payment_id, $id_charge, $receipt_url, $amount, $amount);
            //private function createPaymentRegister($type, $from, $to, $uid, $intent, $payment, $receipt, $subtotal, $total,$discount_credits = 0, $prepaid = 0, $payment_option = 0) {
                $charge_for_cancel_treatment= true;
                        if($ent_treatment->assistance_id >0)
                            $this->transfer_cancel_treatment($ent_treatment->assistance_id,$str_uid, $payment_id, $id_charge);
                    }else {
                        $message='Payment subscription failed. Declined card. Please try again.';
                        $this->set('charge_for_cancel_treatment', false);
                        return array($charge_for_cancel_treatment,$message);
                    }                    
                    break;
                }else{
                    $message = $error; $this->log(__FILE__ . " ".json_encode($error));
                    return array($charge_for_cancel_treatment,$message);
                } 
            }            
        }                                
    }

    public function transfer_cancel_treatment($injector_id, $uid, $intent, $payment){
        
        $this->loadModel('SpaLiveV1.DataPayment');        
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['id' => $injector_id])->first();
        if(empty($ent_user)) {
            $this->message('Invalid user.');
            return;
        }
        if($ent_user['stripe_account'] =="") {
            $this->message('Invalid stripe account.');
            return;
        }

        $dataPay = $this->DataPayment->find()->where(['DataPayment.uid' => $uid, 'DataPayment.type' => 'CANCEL_TREATMENT','DataPayment.is_visible' => 1, 'DataPayment.intent ' => $intent, 'DataPayment.payment ' => $payment , 'DataPayment.receipt <> ""', 'DataPayment.id_to ' => 0  ])->first();        
        if (empty($dataPay)) {
            $this->message('Source payment not found');
            return;
        }

        try {
             $transfer = \Stripe\Transfer::create([
              'amount' => $this->amount_cancel_treatment*.95,
              'currency' => 'USD',
              'description' => 'CANCEL TREATMENT FEE INJECTOR',
              'destination' => $ent_user['stripe_account'],
              'source_transaction' => $dataPay->payment
            ]);

            if ($transfer) {
                $this->DataPayment->updateAll(
                    [                        
                        'id_to' => $injector_id,
                    ],
                    ['uid' => $uid]
                );                                
                $type = 'Receipt';
                $filename = $this->receipt_transfer_cancel_treatment(true,$dataPay->id, $transfer->id);

                if(empty($filename)){
                    return;
                }
                
                $subject = 'MySpaLive '.$type;
                $data = array(
                    'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
                    'to'    => $ent_user['email'],
                    'subject' => $subject,
                    'html'    => "You have received a {$type} from MySpaLive.",
                    'attachment[1]' => curl_file_create($filename, 'application/pdf', 'MySpaLive_' . $type . '_' . ($dataPay->id+1500) . '.pdf'),
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

        if(isset($error) && !empty($error)){
            $this->message($error); 
        }
    }

    public function receipt_transfer_cancel_treatment($return_path = false, $p_uid = '', $transferid=0){
        
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

        /*$findPayment = $this->DataSalesRepresentativePayments->find()->
        select(['User.name','User.lname','DataSalesRepresentativePayments.id','DataSalesRepresentativePayments.payment_uid','DataSalesRepresentativePayments.amount','DataSalesRepresentativePayments.created'])
            ->join(['User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataSalesRepresentativePayments.user_id'],])
            ->where(['DataSalesRepresentativePayments.uid' => $payment_uid,'DataSalesRepresentativePayments.deleted' => 0])->first();*/
        $dataPay = $this->DataPayment->find()->select(['User.name','User.lname','DataPayment.id','DataPayment.total','DataPayment.payment','DataPayment.created'])        
        ->join(['User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataPayment.id_to'],])
        ->where(['DataPayment.uid' => $payment_uid, ])
        ->first();        

        if (empty($dataPay)) { $this->message('Source payment not found'); return; }


        $amount = number_format(($this->amount_cancel_treatment*.95) / 100,2);
        $user_name = $dataPay['User']['name'] . ' ' . $dataPay['User']['lname'];
        $provider  = 'MySpalive LLC';
        $transfer  = $transferid;

        
        $date2 = $dataPay->created->i18nFormat('MM/dd/yyyy');
        $date = date('M d Y', strtotime($date2));

        // $url_panel = 'https://app.spalivemd.com/panel';
        $url_api = env('URL_API', 'https://api.myspalive.com/');
        $invoice = strval($dataPay->id + 2000);
        $len_inv = strlen($invoice);
        for ($i=$len_inv; $i < 6 ; $i++) { 
            $invoice = '0'.$invoice;
        }

        // $filename = 'transfer_' . ($dataPay->id+1500) . '.pdf';
        $filename = ($return_path == true ? TMP . 'reports' . DS : '') . 'transfer_' . ($dataPay->id+1500) . '.pdf';

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

    public function update_payment_method_billing_address(){
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
        $user_data = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        
        $payment_method = get('payment_method', '');
        if(empty($payment_method) || $payment_method == ''){
            $this->message('Payment method empty.');
            return;
        }
        
        $name = get('name', '');
        if(empty($name) || $name == ''){
            $name = $user_data->name . ' ' . $user_data->lname;
        }
        
        $line1 = get('line1', '');
        if(empty($line1) || $line1 == ''){
            $line1 = $user_data->street . ' ' . $user_data->suite;
        }
        
        $city = get('city', '');
        if(empty($city) || $city == ''){
            $city = $user_data->city;
        }
        
        $state_id = get('state', '');
        if(empty($state_id) || $state_id == ''){
            $state_id = 43;
        }

        $zip = get('zip', '');
        if(empty($zip) || $zip == ''){
            $zip = $user->zip;
        }
        
        $state = "";
        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->select(['CatStates.abv'])->where(['CatStates.id' => $state_id,'CatStates.deleted' => 0])->first();
 
        if (empty($ent_state)) {
            $this->message('State not found');
        } 
        else {
            $state=$ent_state['abv'];
        }
            $address = [
                'line1' => $line1,
                'line2' => '',
                'city' => $city,
                'state' => $state,
                'postal_code' => $zip,
                'country' => 'US'
            ];
            
            $data = [
                'name' => $name,
                'billing_details' => [
                    'address' => $address,
                ],
            ];

            try {
            $paymentMethod = \Stripe\PaymentMethod::retrieve($payment_method);

            $encodedData = json_encode($data);
            $paymentMethod->billing_details->name = $name;
            $paymentMethod->billing_details->address->line1 = $line1;
            $paymentMethod->billing_details->address->line2 = null;
            $paymentMethod->billing_details->address->city = $city;
            $paymentMethod->billing_details->address->state = $state;
            $paymentMethod->billing_details->address->postal_code = $zip;
            $paymentMethod->billing_details->address->country = 'US';
            $paymentMethod->save();
           
           
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
        
            $this->set('data', $data);
            $this->success();
    }

    public function payment_online_pharmacy_products(){
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

        $payment = get('payment', '');
        if(empty($payment) || $payment == ''){
            $this->message('Payment method empty.');
            return;
        }
        
        $payment_intent = get('payment_intent', '');
        if(empty($payment_intent) || $payment_intent == ''){
            $this->message('Payment intent empty.');
            return;
        }

        $user_uid = get('user_id', '');
        if(empty($user_uid) || $user_uid == ''){
            $this->message('Empty user id.');
            return;
        }
        
        $name = get('name', '');
        if(empty($name) || $name == ''){
            $this->message('Empty name.');
            return;
        }
       
        $address = get('address', '');
        if(empty($address) || $address == ''){
            $this->message('Empty address.');
            return;
        }
        
        $city = get('city', '');
        if(empty($city) || $city == ''){
            $this->message('Empty city.');
            return;
        }
        
        $zip = get('zip', '');
        if(empty($zip) || $zip == ''){
            $this->message('Empty zip.');
            return;
        }
        
        $amount = get('amount', '');
        if(empty($amount) || $amount == ''){
            $this->message('Empty amount.');
            return;
        }

        $products = get('products', '');
        if(empty($products) || $products == ''){
            $this->message('Empty products.');
            return;
        }

        $qty = get('qty', '');
        if(empty($qty) || $qty == ''){
            $this->message('Empty quantity.');
            return;
        }

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid) || $consultation_uid == ''){
            $this->message('Empty consultation uid.');
            return;
        }

        $state_abv = get('state', '');
        if(empty($state_abv) || $state_abv == ''){
            $this->message('Empty state.');
            return;
        }

        $state = "";
        $this->loadModel('SpaLiveV1.CatStates');
        $ent_state = $this->CatStates->find()->select(['CatStates.id'])->where(['CatStates.abv' => $state_abv,'CatStates.deleted' => 0])->first();
 
        if (empty($ent_state)) {
            $this->message('State not found');
        } 
        else {
            $state=$ent_state['id'];
        }
        
        $user_id=0;
        $this->loadModel('SpaLiveV1.SysUsers');
        $user_id = $this->SysUsers->find()->where(['uid' => $user_uid])->first();

        $array_save = array(
            'uid' => Text::uuid(),
            'user_id' => intval($user_id['id']),
            'name' => $name,
            'address' => $address,
            'state' => $state,
            'city' => $city,
            'zip' => $zip,
            'status' => 'NEW',
            'payment' => $payment,
            'payment_intent' =>  $payment_intent,
            'amount' => $amount,
            'created' => date('Y-m-d H:i:s'),
            'deleted' => 0,
        );

        $this->loadModel('SpaLiveV1.CatProductsOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesOtherServices');
        $this->loadModel('SpaLiveV1.DataPurchasesDetailOtherServices');
        $this->loadModel('SpaLiveV1.DataConsultationOtherServices');

        $ent_saved = $this->DataPurchasesOtherServices->newEntity($array_save);
        if(!$ent_saved->hasErrors()) {
            $this->DataPurchasesOtherServices->save($ent_saved);
        }
            if ($ent_saved) {
                $arr_products = explode(",", $products); 
                $arr_qty = explode(",", $qty); 
                $i=0;
                foreach ($arr_products as $id_product) {
                    $ent_products = $this->CatProductsOtherServices->find()->where(['CatProductsOtherServices.id' => intval($id_product), 'CatProductsOtherServices.deleted' => 0])->first();
                    $product_id = intval($ent_products['id']);
                    $price = floatval($ent_products['unit_price']);
                    $qty = intval($arr_qty[$i]);
                    $array_save_details = array(
                        'purchase_id' => $ent_saved->id,
                        'product_id' => $product_id,
                        'price' => $price,
                        'qty' =>$qty
                    );

                    $ent_saved_details = $this->DataPurchasesDetailOtherServices->newEntity($array_save_details);
                    if(!$ent_saved_details->hasErrors()) {
                        $this->DataPurchasesDetailOtherServices->save($ent_saved_details);
                    }
                    $i++;


                    $this->DataConsultationOtherServices->updateAll(
                        [
                            'status' => 'DONE',
                        ],
                        ['uid' => $consultation_uid]);
                }
                
                

                $this->success();
            }
    }

    public function get_incomes_weight_loss(){
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

        $payments_weight_loss = array();

        $this->loadModel('SpaLiveV1.DataPayment');
        
        $fields = [
            "Patient.name",
            "Patient.lname",
            "DataPayment.total",
            "DataPayment.created",
            "DataPayment.comission_payed",
        ];

        $_where = [
            'DataPayment.type' => 'WEIGHT LOSS COMMISSION',            
            'DataPayment.is_visible' => 1,
            'DataPayment.id_to' => USER_ID,
        ];

        $ent_payments = $this->DataPayment->find()
            ->select($fields)
            ->join(['Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataPayment.id_from'],])
            ->where ($_where)
            ->order(['DataPayment.created' => 'DESC'])
            ->all();

        foreach ($ent_payments as $ent_payment) {
            $payments_weight_loss[] = [
                'name' => $ent_payment['Patient']['name'] . ' ' . $ent_payment['Patient']['lname'],
                'amount' => $ent_payment['total'],
                'created' => $ent_payment['created']->i18nFormat('MM/dd/Y'),
                'comission_payed' => $ent_payment['comission_payed'],
            ];
        }

        $this->set('payments_weight_loss', $payments_weight_loss);
        $this->success();
    }

    public function stripe_report(){
       
        $from = get('date_from',date('Y-m-d'));
        $to   = get('date_to',date('Y-m-d'));
        
        
        if(!$this->isValidDate($from) || !$this->isValidDate($to)){
            $this->message('Invalid date.');
            return;
        
        }       	      		            
       
        shell_exec(env('COMMAND_PATH', '') . ' stripe ' . $from . ' ' . $to . ' > /dev/null 2>&1 &');
    }

    //$date = \DateTime::createFromFormat('Y-m-d H:i:s', get('schedule_date',''));
    function isValidDate($dateString, $format = 'Y-m-d') {
        $dateTime = \DateTime::createFromFormat($format, $dateString);
        return $dateTime && $dateTime->format($format) === $dateString;
    }

    public function get_stripe_report(){

        
//http://dev.apispalive.com/?action=get_stripe_report&key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&date_from=2024-06-01&date_to=2024-06-28&type=pdf&state=43
        

        $panel = get('l3n4p', '6092482f7ce858.91169218');
        

        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){
            return;
        }


        $type = get('type', 'MTD');
        $format = get('format', 'pdf');
        $token = get('token', 'pdf');
        $state = get('state', '');
        $DP = '';        
        if(is_string($state) && $state !== ''){	
            $DP = ' and DP.state IN (  ' .$state .')';            
        }        

        $firstDay = date('Y-m-01');
        $lastDay =  date('Y-m-t');
        if ($type == 'YTD') {
            $firstDay = date('Y-01-01');
        }

        $date_from = get('date_from', $firstDay);
        $date_to = get('date_to', $lastDay);
        $date_from = $date_from . ' 00:00:00';
        $date_to = $date_to . ' 23:59:59';
        $this->loadModel('SpaLiveV1.DataStripeTransfer');

        $sql = "
		SELECT sum(amount), sum(amount_reversed),sum(fee), description FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.id_tr and DP.type <> 'REFUND' where  date between '".$date_from."' and '".$date_to."' and DST.type = 'payment' and status = 'succeeded' {$DP} group by description
        union 
        SELECT sum(DP.total), sum(amount_reversed),sum(fee), description FROM data_stripe_transfer DST join  data_subscription_payments DP on DP.payment_id  = DST.id_tr  where  date between '".$date_from."' and '".$date_to."' and DST.type = 'payment' and DST.status = 'succeeded' {$DP} group by description

		";
        //$this->log(__LINE__ . ' ' . ($sql));
		$ent_query = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');


        
        $sql = "
		SELECT sum(amount), sum(amount_reversed),sum(fee),description FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group and DP.type = 'REFUND' where date between '".$date_from."' and '".$date_to."' and  DST.type = 'transfer' {$DP} group by description
		";
		$ent_query_refund = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        /*$sql = "		
        SELECT IFNULL(SUM(amount),0) amount, COUNT(id) count FROM data_stripe_transfer DST WHERE date BETWEEN '".$date_from."' AND '".$date_to."' AND			status = 'lost' 			AND 	type = 'dispute'	"; */
        $sql = "		
        SELECT IFNULL(SUM(amount),0) amount, COUNT(DST.id) count FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group WHERE date BETWEEN '".$date_from."' AND '".$date_to."' 			AND 	DST.type = 'dispute' {$DP}	"; 
		$ent_query_dispute = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        $sql = "		        
        SELECT IFNULL(SUM(amount),0) amount, COUNT(DST.id) total FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group and DP.type = 'REFUND' WHERE date BETWEEN '".$date_from."' AND '".$date_to."' AND description = 'REFUND FOR CHARGE (BASIC COURSE)' AND status = 'succeeded' AND amount >= 700 {$DP} "; 
		$ent_query_basic = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        $sql = "		        
        SELECT IFNULL(SUM(amount),0) amount, COUNT(DST.id) count FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group and DP.type = 'REFUND' WHERE date BETWEEN '".$date_from."' AND '".$date_to."' AND description = 'REFUND FOR CHARGE (ADVANCED COURSE)' AND status = 'succeeded' AND amount >= 700 {$DP}"; 
		$ent_query_advanced = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        $sql = "		        
        SELECT IFNULL(SUM(amount),0) amount, COUNT(DST.id) count FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group and DP.type = 'REFUND' WHERE date BETWEEN '".$date_from."' AND '".$date_to."' AND description = 'REFUND FOR CHARGE (FILLERS COURSE)' AND status = 'succeeded' AND amount >= 700 {$DP}"; 
		$ent_query_filler_course = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        $sql = "		        
        SELECT IFNULL(SUM(amount),0) amount, COUNT(DST.id) count FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group and DP.type = 'REFUND' WHERE date BETWEEN '".$date_from."' AND '".$date_to."' AND description = 'REFUND FOR CHARGE (ADVANCED TECHNIQUES MEDICAL)' AND status = 'succeeded' AND amount >= 700 {$DP}"; 
		$ent_query_level3 = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        $sql = "		        
        SELECT IFNULL(SUM(amount),0) amount, COUNT(DST.id) count FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.transfer_group and DP.type = 'REFUND' WHERE date BETWEEN '".$date_from."' AND '".$date_to."' AND description = 'REFUND FOR CHARGE (LEVEL 1-1 NEUROTOXINS)' AND status = 'succeeded' AND amount >= 700 {$DP}"; 
        
		$ent_query_level_1_1 = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');

        $sql = "		        
        SELECT sum(amount), sum(amount_reversed),sum(fee), description FROM data_stripe_transfer DST 
        join data_payment DP on DP.intent = DST.transfer_group  and DP.type = 'REFUND'
        where  date between '".$date_from."' AND '".$date_to."' and DST.type = 'refund'  and DST.status = 'succeeded'  group by description; {$DP}"; 
        
		$ent_query_type_refund = $this->DataStripeTransfer->getConnection()->execute($sql)->fetchAll('assoc');  
        
        $exams_sql = "
		SELECT sum(amount), sum(amount_reversed),sum(fee), description FROM data_stripe_transfer DST join data_payment DP on DP.intent = DST.id_tr and DP.type <> 'REFUND' where  date between '".$date_from."' and '".$date_to."' and DST.type = 'payment' and status = 'succeeded' and DP.id_from <> 0  and DP.id_from <> 0 and description = 'EXAM' {$DP}         

		";
        $exams = $this->DataStripeTransfer->getConnection()->execute($exams_sql)->fetchAll('assoc');  
        $type = get('type','pdf');
        if($type == 'pdf'){
            $this->report_stripe_pdf($ent_query, $ent_query_refund,$date_from, $date_to,$ent_query_dispute, $ent_query_basic, $ent_query_advanced,$ent_query_filler_course,$ent_query_level3,$ent_query_level_1_1,$ent_query_type_refund,$exams);
        }else{
            $this->report_stripe_xls($ent_query, $ent_query_refund,$date_from, $date_to,$ent_query_dispute, $ent_query_basic, $ent_query_advanced,$ent_query_filler_course,$ent_query_level3,$ent_query_level_1_1,$ent_query_type_refund,$exams);
        }
        //if ($format == 'pdf') $this->report_stripe_pdf($ent_query, $firstDay, $lastDay);
        //elseif ($format == 'xlsx') $this->report_unit_sales_xlsx($arr_balance, $firstDay, $lastDay, $user);

    }

    private function report_stripe_pdf($arr_data,$ent_query_refund, $date_from, $date_to,$ent_query_dispute, $ent_query_basic, $ent_query_advanced,$filler_course,$query_level3,$ent_query_level_1_1,$ent_query_type_refund,$exams     ) {
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(10,10,10,10));
        $this->log(__LINE__ . ' start report pdf' );
        $str_income = '';
        $str_output = '';
        $arr_description =[];
        $total_income =0;
        $total_expense =0;
        $total_fee =0;
        foreach($arr_data as $key => $balance) {           
            if ($balance['description'] == 'BASIC COURSE') {
                $total = 0;
                
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                /*$refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }*/
                $arr_description[] = [
                    'description' => 'Training class Level 1 $695',
                    'total' => $total ,
                    'order' => 1,
                ];
                
                /* $arr_description[] = [
                    'description' => 'Level 1 Refunds',
                    'total' => $refund_total ,
                    'order' => 71,
                ];*/

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }

                //$total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'ADVANCED COURSE') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training class Level 2 $795',
                    'total' => $total ,
                    'order' => 2,
                ];
                
                /*$refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'Level 2 Refunds',
                    'total' => $refund_total ,
                    'order' => 72,
                ];*/

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                //$total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'PURCHASE') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Shop',
                    'total' => $total ,
                    'order' => 3,
                ];
                $refund_total = 0;
                /*if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'Shop Refunds',
                    'total' => $refund_total ,
                    'order' => 75,
                ];*/
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }

                $total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'WEIGHT LOSS 3 MONTHS') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Weight Loss',
                    'total' => $total ,
                    'order' => 4,
                ];
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'Weight Loss Refunds',
                    'total' => $refund_total ,
                    'order' => 80,
                ];

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }

                $total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'TREATMENT') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Income From Injector',
                    'total' => $total ,
                    'order' => 5,
                ];
                //$total_expense+= $refund_total;
                $total_income+= $total;
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }else if ($balance['description'] == 'TIP') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Tips',
                    'total' => $total ,
                    'order' => 6,
                ];
                //$total_expense+= $refund_total;
                $total_income+= $total;
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }/*else if ($balance['description'] == 'EXAM') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'GFE',
                    'total' => $total ,
                    'order' => 7,
                ];
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'GFE Refunds',
                    'total' => $refund_total ,
                    'order' => 77,
                ];
                $total_expense+= $refund_total;
                $total_income+= $total;

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }*/else if ($balance['description'] == 'MD Subscription Fee' || $balance['description'] == 'SUBSCRIPTIONMD') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                    $total_income+= $total;
                }else{
                    $total = $balance['sum(amount)'];
                }
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Medical Director Subscription Fees');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'Medical Director Subscription Fees',
                        'total' => $total ,
                        'order' => 8,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];
                    //var_dump($tot);exit;
                    $total = $tot + $total;
                    $arr_description[$foundObject] = [
                        'description' => 'Medical Director Subscription Fees',
                        'total' => $total ,
                        'order' => 8,
                    ];
                }
                
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                    $total_expense+= $refund_total;                
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'MD Refunds');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'MD Refunds',
                        'total' => $refund_total ,
                        'order' => 78,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $refund_total = $tot + $refund_total;
                    $arr_description[$foundObject] = [
                        'description' => 'MD Refunds',
                        'total' => $refund_total ,
                        'order' => 78,
                    ];
                }
                
            }else if ($balance['description'] == 'MSL Subscription Fee' || $balance['description'] == 'SUBSCRIPTIONMSL') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                    $total_income+= $total;
                }else{
                    $total = $balance['sum(amount)'];
                }
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Software Usage Fees');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'Software Usage Fees',
                        'total' => $total ,
                        'order' => 9,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $total = $tot + $total;
                    $arr_description[$foundObject] = [
                        'description' => 'Software Usage Fees',
                        'total' => $total ,
                        'order' => 9,
                    ];
                }
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                    $total_expense+= $refund_total;                
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'MSL Refunds');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'MSL Refunds',
                        'total' => $refund_total ,
                        'order' => 76,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $refund_total = $tot + $refund_total;
                    $arr_description[$foundObject] = [
                        'description' => 'MSL Refunds',
                        'total' => $refund_total ,
                        'order' => 76,
                    ];
                }
            }else if ($balance['description'] == 'TRAINING TREATMENT') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training Treatment',
                    'total' => $total ,
                    'order' => 11,
                ];
                 //$total_expense+= $refund_total;
                 $total_income+= $total;
                 $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                }else if ($balance['description'] == 'FILLERS COURSE') {
                    $total = 0;
                    if( $balance['sum(amount)'] > 0){
                        $total = ($balance['sum(amount)']/100);
                    }else{
                        $total = $balance['sum(amount)'];
                    }
                    $arr_description[] = [
                        'description' => 'Fillers Course ',
                        'total' => $total ,
                        'order' => 13,
                    ];                                
    
                    $fee =0;
                    if( $balance['sum(amount)'] > 0){
                        $fee = ($balance['sum(fee)']/100);
                        $total_fee +=  $fee;
                    }else{
                        $fee = $balance['sum(fee)'];
                    }
                    
                    $total_income+= $total;                
            }else if ($balance['description'] == 'ADVANCED TECHNIQUES MEDICAL') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training class Level 3 ',
                    'total' => $total ,
                    'order' => 12,
                ];                                

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                
                $total_income+= $total;                
        }else if ($balance['description'] == 'LEVEL 1-1 NEUROTOXINS') {
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);
            }else{
                $total = $balance['sum(amount)'];
            }
            $arr_description[] = [
                'description' => 'Training class Tox TuneUp NEUROTOXINS ',
                'total' => $total ,
                'order' => 14,
            ];                                

            $fee =0;
            if( $balance['sum(amount)'] > 0){
                $fee = ($balance['sum(fee)']/100);
                $total_fee +=  $fee;
            }else{
                $fee = $balance['sum(fee)'];
            }
            
            $total_income+= $total;                
    }
            else {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                    $total_income+= $total;
                }else{
                    $total = $balance['sum(amount)'];
                }
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Others');                
                if($foundObject === false){
                    $arr_description[] = [
                        'description' => 'Others',
                        'total' => $total ,
                        'order' => 10,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $total = $tot + $total;
                    $arr_description[$foundObject] = [
                        'description' => 'Others',
                        'total' => $total ,
                        'order' => 10,
                    ];
                }                
      
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                    $total_expense+= $refund_total;                
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Others Refunds');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'Others Refunds',
                        'total' => $refund_total ,
                        'order' => 79,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $refund_total = $tot + $refund_total;
                    $arr_description[$foundObject] = [
                        'description' => 'Others Refunds',
                        'total' => $refund_total ,
                        'order' => 79,
                    ];
                }
            }
    }
    foreach($ent_query_type_refund as $key => $balance){
        if ($balance['description'] == 'REFUND FOR CHARGE (PURCHASE)') {
            $total = 0;                        
            $refund_total = 0;
            if( $balance['sum(amount)'] > 0){
                $refund_total = ($balance['sum(amount)']/100);
            }else{
                $refund_total = $balance['sum(amount)'];
            }
            $arr_description[] = [
                'description' => 'Shop Refunds',
                'total' => $refund_total ,
                'order' => 75,
            ];            
            $total_expense+= $refund_total;
            
        }
    }

    foreach($ent_query_refund as $key => $balance) { 
        if (stripos($balance['description'], 'SALES TEAM') === 0 || $balance['description'] == "SALES REP PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            }
            $foundObject = $this->findObjectById($arr_description, 'Sales Team');

            if($foundObject === false){
                $arr_description[] = [
                    'description' => 'Sales Team',
                    'total' => $total ,
                    'order' => 63,
                ];
            }else{
                $tot = $arr_description[$foundObject]['total'];
                //var_dump($tot);exit;
                $total = $tot + $total;
                $arr_description[$foundObject] = [
                    'description' => 'Sales Team',
                    'total' => $total ,
                    'order' => 63,
                ];
            }                         
        }else if ( $balance['description'] == "PAY INVITATION" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Referrals Payments',
                'total' => $total ,
                'order' => 19,
            ];
                                   
        }else if ( $balance['description'] == "WEIGHT LOSS COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Weight Loss Referrals Payments',
                'total' => $total ,
                'order' => 70,
            ];
                                   
        }else if ( $balance['description'] == "GFE COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Examiners',
                'total' => $total ,
                'order' => 15,
            ];
                                   
        }else if ( $balance['description'] == "CI COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Money to Injectors',
                'total' => $total ,
                'order' => 68,
            ];
                                   
        }else if ( $balance['description'] == "CHECK IN COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Check In Commission Payment',
                'total' => $total ,
                'order' => 81,
            ];
                                   
        }else if ( $balance['description'] == "FIRST CONSULTATION COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'First Consultation Commission Payment',
                'total' => $total ,
                'order' => 82,
            ];
                                   
        }
        
        
    }
    
    foreach ($ent_query_basic as $key => $value) {        
        $total = 0;            
        if( $value['amount'] > 0){
            $total = ($value['amount']/100);
        }else{
            $total = $value['amount'];
        }                     
    
    $arr_description[] = [
        'description' => 'Level 1 Refunds',
        'total' => ($total==0)?0 : $total ,
        'order' => 71,
    ];
    $total_expense+= $total;
}
foreach ($ent_query_advanced as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Level 2 Refunds',
        'total' => ($refund_total==0)?0 : $refund_total  ,
        'order' => 72,
    ];
    $total_expense+= $refund_total;
}

foreach ($filler_course as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Fillers Refunds',
        'total' => ($refund_total==0)?0 : $refund_total  ,
        'order' => 73,
    ];
    $total_expense+= $refund_total;
}

foreach ($query_level3 as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Training class Level 3 Refunds',
        'total' => ($refund_total==0)?0 : $refund_total  ,
        'order' => 74,
    ];
    $total_expense+= $refund_total;
}

foreach ($ent_query_level_1_1 as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Training class Tox TuneUp Refunds',
        'total' => ($refund_total==0)?0 : $refund_total  ,
        'order' => 82,
    ];
    $total_expense+= $refund_total;
}

foreach ($exams as $key => $value) {
    if ($value['description'] == 'EXAM') {
        $total = 0;
        if( $value['sum(amount)'] > 0){
            $total = ($value['sum(amount)']/100);
        }else{
            $total = $value['sum(amount)'];
        }
        $arr_description[] = [
            'description' => 'GFE',
            'total' => $total ,
            'order' => 7,
        ];
        $refund_total = 0;
        if( $value['sum(amount)'] > 0){
            $refund_total = ($value['sum(amount_reversed)']/100);
        }else{
            $refund_total = $value['sum(amount_reversed)'];
        }
        $arr_description[] = [
            'description' => 'GFE Refunds',
            'total' => $refund_total ,
            'order' => 77,
        ];
        $total_expense+= $refund_total;
        $total_income+= $total;

        $fee =0;
        if( $value['sum(amount)'] > 0){
            $fee = ($value['sum(fee)']/100);
            $total_fee +=  $fee;
        }else{
            $fee = $value['sum(fee)'];
        }
    }
}

foreach ($ent_query_dispute as $key => $value) {
    $total = 0;
    if( $value['amount'] > 0){
        $total = ($value['amount']/100) + $value['count'] * 15;
    }else{
        $total = $value['amount'];
    } 
    $total_expense+= $total;
    $arr_description[] = [
        'description' => 'Dispute Loss (Includes fees)',
        'total' => ($total==0)?0 : $total ,
        'order' => 67,
    ];
}

    $arr_description[] = [
        'description' => 'Monthly Gross Income',
        'total' => $total_income,
        'order' => 15,
    ];
   /* $arr_description[] = [
        'description' => 'Sales Team',
        'total' => 0 ,
        'order' => 13,
    ];*/
    /* $arr_description[] = [
        'description' => 'Medical director',
        'total' => 0 ,
        'order' => 14,
    ];*/
   /* $arr_description[] = [
        'description' => 'Examiners',
        'total' => 0 ,
        'order' => 15,
    ];*/
    $arr_description[] = [
        'description' => 'Stripe Fee',
        'total' => $total_fee ,
        'order' => 66,
    ];
    
    /*$arr_description[] = [
        'description' => 'Money to Injectors',
        'total' => 0 ,
        'order' => 18,
    ];*/
   
    $arr_description[] = [
        'description' => 'Monthly Expenses',
        'total' => $total_expense + $total_fee,
        'order' => 91,
    ];
    $arr_description = Hash::sort($arr_description, '{n}.order', 'asc');
         $this->log(__LINE__ . ' ' . json_encode($arr_description));   

    foreach ($arr_description as $key => $value) { 
        if($value['order'] > 60) {
            $str_income .= "
            <tr style=\"border-spacing: 0;\">
            <td style=\"padding: 2mm; width: 110mm;  ".($value['description'] =='Monthly Expenses' ?'font-weight: bold;border-bottom: 1px solid #000000;':'border-bottom: 1px solid #e4e6ef;')."\">" . $value['description'] . "</td>
            <td style=\"padding: 2mm; width:  20mm;  ".($value['description'] =='Monthly Expenses' ?'font-weight: bold;border-bottom: 1px solid #000000;':'border-bottom: 1px solid #e4e6ef;')."\" align='right';>$ " . number_format($value['total'],2) . "</td>                    
            </tr>";
        }else{
            $str_output .= "
            <tr style=\"border-spacing: 0;\">
            <td style=\"padding: 2mm; width: 110mm;  ".($value['description'] =='Monthly Gross Income' ?'font-weight: bold;border-bottom: 1px solid #000000;':'border-bottom: 1px solid #e4e6ef;')."\">" . $value['description'] . "</td>
            <td style=\"padding: 2mm; width:  20mm;  ".($value['description'] =='Monthly Gross Income' ?'font-weight: bold;border-bottom: 1px solid #000000;':'border-bottom: 1px solid #e4e6ef;')."\" align='right';>$ " . number_format($value['total'],2) . "</td>                    
            </tr>";
        }

            }
        // $url_root = $this->URL_ROOT . "api";
        
        $html2pdf->writeHTML("
            <page style=\"width: 190mm; height: 277mm; position:relative; color: #373a48;\">
                    <img src=\"" . $this->URL_API . "img/logo.png\" style=\"width:50mm;\">

                    <table style=\"margin-top: 10mm;\">
                        <tr>
                            <td style=\"width: 110mm;\">
                                <h1 style=\"font-size: 22px; margin: 0mm 0 0;\">MySpaLive Stripe Report</h1>
                            </td>
                            <td style=\"width: 75mm; text-align: right;\">
                                <p style=\"margin: 5mm 0 0; font-size: 15px;\"><b>From: " . date('m-d-Y', strtotime($date_from)) . " to: " . date('m-d-Y', strtotime($date_to)) . "</b></p>
                            </td>
                        </tr>
                    </table>

                    <table style=\"margin-top: 10mm; width: 185mm; border-spacing: 0; border-collapse: collapse; color: #525560;\">                        
                        <tr style=\"font-weight: bold;  border-spacing: 0;\">
                            <td style=\"padding: 2mm; width: 70mm; border-bottom: 1px solid #e4e6ef;\">Income Description</td>
                            <td style=\"padding: 2mm; width: 50mm; border-bottom: 1px solid #e4e6ef;\"> $</td>                            
                        </tr>

                        " . $str_output . "
                        </table>
    
                        <table style=\"margin-top: 10mm; width: 185mm; border-spacing: 0; border-collapse: collapse; color: #525560;\">                        
                            <tr style=\"font-weight: bold;  border-spacing: 0;\">
                                <td style=\"padding: 2mm; width: 70mm; border-bottom: 1px solid #e4e6ef;\">Description</td>
                                <td style=\"padding: 2mm; width: 50mm; border-bottom: 1px solid #e4e6ef;\"> $</td>                            
                            </tr>
    
                            " . $str_income . "
                    </table>

                <!-- <page_footer>
                    <div style=\"text-align: center; font-size: 15px;\">
                        [[page_cu]]/[[page_nb]]
                    </div>
                </page_footer> -->
            </page>");
            
        $html2pdf->Output('monthly_statement.pdf', 'I');
    }

    private function report_stripe_xls($arr_data,$ent_query_refund, $date_from, $date_to,$ent_query_dispute, $ent_query_basic, $ent_query_advanced,$filler_course,$level3,$ent_query_level_1_1,$ent_query_type_refund,$exams) {
        //$html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(10,10,10,10));
        $this->log(__LINE__ . ' start report pdf' );
        $str_income = '';
        $str_output = '';
        $arr_description =[];
        $total_income =0;
        $total_expense =0;
        $total_fee =0;
        foreach($arr_data as $key => $balance) {           
            if ($balance['description'] == 'BASIC COURSE') {
                $total = 0;
                
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                /*$refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }*/
                $arr_description[] = [
                    'description' => 'Training class Level 1 $695',
                    'total' => $total ,
                    'order' => 1,
                ];
                
                /* $arr_description[] = [
                    'description' => 'Level 1 Refunds',
                    'total' => $refund_total ,
                    'order' => 71,
                ];*/

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }

                //$total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'ADVANCED COURSE') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training class Level 2 $795',
                    'total' => $total ,
                    'order' => 2,
                ];
                
                /*$refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'Level 2 Refunds',
                    'total' => $refund_total ,
                    'order' => 72,
                ];*/

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                //$total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'PURCHASE' || $balance['description'] == "12-Month Subscription") {
                $total = ($balance['sum(amount)'] > 0) ? ($balance['sum(amount)']/100) : $balance['sum(amount)'];
                $fee = ($balance['sum(amount)'] > 0) ? ($balance['sum(fee)']/100) : $balance['sum(fee)'];
            
                $total_fee +=  $fee;
                $total_income += $total;
            
                // Verificamos si ya existe 'Shop' en el array
                $found = false;
                foreach ($arr_description as &$item) {
                    if ($item['description'] === 'Shop') {
                        $item['total'] += $total;
                        $found = true;
                        break;
                    }
                }
            
                // Si no se encontró, se agrega al array
                if (!$found) {
                    $arr_description[] = [
                        'description' => 'Shop',
                        'total' => $total,
                        'order' => 3,
                    ];
                  
                }
            }else if ($balance['description'] == 'WEIGHT LOSS 3 MONTHS') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Weight Loss',
                    'total' => $total ,
                    'order' => 4,
                ];
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'Weight Loss Refunds',
                    'total' => $refund_total ,
                    'order' => 80,
                ];

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }

                $total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'TREATMENT') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Income From Injector',
                    'total' => $total ,
                    'order' => 5,
                ];
                //$total_expense+= $refund_total;
                $total_income+= $total;
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }else if ($balance['description'] == 'TIP') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Tips',
                    'total' => $total ,
                    'order' => 6,
                ];
                //$total_expense+= $refund_total;
                $total_income+= $total;
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }/*else if ($balance['description'] == 'EXAM') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'GFE',
                    'total' => $total ,
                    'order' => 7,
                ];
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $arr_description[] = [
                    'description' => 'GFE Refunds',
                    'total' => $refund_total ,
                    'order' => 77,
                ];
                $total_expense+= $refund_total;
                $total_income+= $total;

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }*/else if ($balance['description'] == 'MD Subscription Fee' || $balance['description'] == 'SUBSCRIPTIONMD') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                    $total_income+= $total;
                }else{
                    $total = $balance['sum(amount)'];
                }
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Medical Director Subscription Fees');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'Medical Director Subscription Fees',
                        'total' => $total ,
                        'order' => 8,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];
                    //var_dump($tot);exit;
                    $total = $tot + $total;
                    $arr_description[$foundObject] = [
                        'description' => 'Medical Director Subscription Fees',
                        'total' => $total ,
                        'order' => 8,
                    ];
                }
                
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                    $total_expense+= $refund_total;                
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'MD Refunds');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'MD Refunds',
                        'total' => $refund_total ,
                        'order' => 78,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $refund_total = $tot + $refund_total;
                    $arr_description[$foundObject] = [
                        'description' => 'MD Refunds',
                        'total' => $refund_total ,
                        'order' => 78,
                    ];
                }
                
            }else if ($balance['description'] == 'MSL Subscription Fee' || $balance['description'] == 'SUBSCRIPTIONMSL') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                    $total_income+= $total;
                }else{
                    $total = $balance['sum(amount)'];
                }
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Software Usage Fees');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'Software Usage Fees',
                        'total' => $total ,
                        'order' => 9,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $total = $tot + $total;
                    $arr_description[$foundObject] = [
                        'description' => 'Software Usage Fees',
                        'total' => $total ,
                        'order' => 9,
                    ];
                }
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                    $total_expense+= $refund_total;                
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'MSL Refunds');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'MSL Refunds',
                        'total' => $refund_total ,
                        'order' => 76,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $refund_total = $tot + $refund_total;
                    $arr_description[$foundObject] = [
                        'description' => 'MSL Refunds',
                        'total' => $refund_total ,
                        'order' => 76,
                    ];
                }
            }else if ($balance['description'] == 'TRAINING TREATMENT') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training Treatment',
                    'total' => $total ,
                    'order' => 11,
                ];
                 //$total_expense+= $refund_total;
                 $total_income+= $total;
                 $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            }else if ($balance['description'] == 'FILLERS COURSE') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Fillers Course ',
                    'total' => $total ,
                    'order' => 2,
                ];               
                

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                //$total_expense+= $refund_total;
                $total_income+= $total;
            }else if ($balance['description'] == 'ADVANCED TECHNIQUES MEDICAL') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training class Level 3 ',
                    'total' => $total ,
                    'order' => 12,
                ];               
                

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }               
                $total_income+= $total;
            }else if ($balance['description'] == 'LEVEL 1-1 NEUROTOXINS') {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                }else{
                    $total = $balance['sum(amount)'];
                }
                $arr_description[] = [
                    'description' => 'Training class Tox TuneUp NEUROTOXINS ',
                    'total' => $total ,
                    'order' => 12,
                ];               
                

                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }               
                $total_income+= $total;
            }
            else {
                $total = 0;
                if( $balance['sum(amount)'] > 0){
                    $total = ($balance['sum(amount)']/100);
                    $total_income+= $total;
                }else{
                    $total = $balance['sum(amount)'];
                }
                $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Others');                
                if($foundObject === false){
                    $arr_description[] = [
                        'description' => 'Others',
                        'total' => $total ,
                        'order' => 10,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $total = $tot + $total;
                    $arr_description[$foundObject] = [
                        'description' => 'Others',
                        'total' => $total ,
                        'order' => 10,
                    ];
                }                
      
                $refund_total = 0;
                if( $balance['sum(amount)'] > 0){
                    $refund_total = ($balance['sum(amount_reversed)']/100);
                    $total_expense+= $refund_total;                
                }else{
                    $refund_total = $balance['sum(amount_reversed)'];
                }
                $foundObject = $this->findObjectById($arr_description, 'Others Refunds');

                if($foundObject == false){
                    $arr_description[] = [
                        'description' => 'Others Refunds',
                        'total' => $refund_total ,
                        'order' => 79,
                    ];
                }else{
                    $tot = $arr_description[$foundObject]['total'];                    
                    $refund_total = $tot + $refund_total;
                    $arr_description[$foundObject] = [
                        'description' => 'Others Refunds',
                        'total' => $refund_total ,
                        'order' => 79,
                    ];
                }
            }
    }
    foreach($ent_query_type_refund as $key => $balance){
        if ($balance['description'] == 'REFUND FOR CHARGE (PURCHASE)') {
            $total = 0;                        
            $refund_total = 0;
            if( $balance['sum(amount)'] > 0){
                $refund_total = ($balance['sum(amount)']/100);
            }else{
                $refund_total = $balance['sum(amount)'];
            }
            $arr_description[] = [
                'description' => 'Shop Refunds',
                'total' => $refund_total ,
                'order' => 75,
            ];             
            $total_expense+= $refund_total;
            
        }
    }
    foreach($ent_query_refund as $key => $balance) { 
        if (stripos($balance['description'], 'SALES TEAM') === 0 || $balance['description'] == "SALES REP PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            }
            $foundObject = $this->findObjectById($arr_description, 'Sales Team');

            if($foundObject === false){
                $arr_description[] = [
                    'description' => 'Sales Team',
                    'total' => $total ,
                    'order' => 63,
                ];
            }else{
                $tot = $arr_description[$foundObject]['total'];
                //var_dump($tot);exit;
                $total = $tot + $total;
                $arr_description[$foundObject] = [
                    'description' => 'Sales Team',
                    'total' => $total ,
                    'order' => 63,
                ];
            }                         
        }else if ( $balance['description'] == "PAY INVITATION" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Referrals Payments',
                'total' => $total ,
                'order' => 69,
            ];
                                   
        }else if ( $balance['description'] == "WEIGHT LOSS COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Weight Loss Referrals Payments',
                'total' => $total ,
                'order' => 70,
            ];
                                   
        }else if ( $balance['description'] == "GFE COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Examiners',
                'total' => $total ,
                'order' => 65,
            ];
                                   
        }else if ( $balance['description'] == "CI COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Money to Injectors',
                'total' => $total ,
                'order' => 68,
            ];
                                   
        }else if ( $balance['description'] == "CHECK IN COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'Check In Commission Payment',
                'total' => $total ,
                'order' => 81,
            ];
                                   
        }else if ( $balance['description'] == "FIRST CONSULTATION COMMISSION PAYMENT" ) {
            $fee =0;
                if( $balance['sum(amount)'] > 0){
                    $fee = ($balance['sum(fee)']/100);
                    $total_fee +=  $fee;
                }else{
                    $fee = $balance['sum(fee)'];
                }
            $total = 0;
            if( $balance['sum(amount)'] > 0){
                $total = ($balance['sum(amount)']/100);                    
                $total_expense+= $total;
            }else{
                $total = $balance['sum(amount)'];
            } 
            $arr_description[] = [
                'description' => 'First Consultation Commission Payment',
                'total' => $total ,
                'order' => 82,
            ];
                                   
        }

        
        
    }
    
    foreach ($ent_query_basic as $key => $value) {        
        $total = 0;            
        if( $value['amount'] > 0){
            $total = ($value['amount']/100);
        }else{
            $total = $value['amount'];
        }                     
    
    $arr_description[] = [
        'description' => 'Level 1 Refunds',
        'total' => ($total ==0) ? 0 : $total ,
        'order' => 71,
    ];
    $total_expense+= $total;
}
foreach ($ent_query_advanced as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Level 2 Refunds',
        'total' => ($refund_total == 0) ? 0 : $refund_total ,
        'order' => 72,
    ];
    $total_expense+= $refund_total;
}

foreach ($filler_course as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Fillers Course Refunds',
        'total' => ($refund_total == 0) ? 0 : $refund_total ,
        'order' => 73,
    ];
    $total_expense+= $refund_total;
}

foreach ($level3 as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Training class Level 3 Refunds',
        'total' => ($refund_total == 0) ? 0 : $refund_total ,
        'order' => 74,
    ];
    $total_expense+= $refund_total;
}

foreach ($ent_query_level_1_1 as $key => $value) {        
    $refund_total = 0;
    if( $value['amount'] > 0){
        $refund_total = ($value['amount']/100);
    }else{
        $refund_total = $value['amount'];
    }
    $arr_description[] = [
        'description' => 'Training class Tox TuneUp Refunds',
        'total' => ($refund_total == 0) ? 0 : $refund_total ,
        'order' => 82,
    ];
    $total_expense+= $refund_total;
}

foreach ($exams as $key => $value) {
    if ($value['description'] == 'EXAM') {
        $total = 0;
        if( $value['sum(amount)'] > 0){
            $total = ($value['sum(amount)']/100);
        }else{
            $total = $value['sum(amount)'];
        }
        $arr_description[] = [
            'description' => 'GFE',
            'total' => $total ,
            'order' => 7,
        ];
        $refund_total = 0;
        if( $value['sum(amount)'] > 0){
            $refund_total = ($value['sum(amount_reversed)']/100);
        }else{
            $refund_total = $value['sum(amount_reversed)'];
        }
        $arr_description[] = [
            'description' => 'GFE Refunds',
            'total' => $refund_total ,
            'order' => 77,
        ];
        $total_expense+= $refund_total;
        $total_income+= $total;

        $fee =0;
        if( $value['sum(amount)'] > 0){
            $fee = ($value['sum(fee)']/100);
            $total_fee +=  $fee;
        }else{
            $fee = $value['sum(fee)'];
        }
    }
}

foreach ($ent_query_dispute as $key => $value) {
    $total = 0;
    if( $value['amount'] > 0){
        $total = ($value['amount']/100) + $value['count'] * 15;
    }else{
        $total = $value['amount'];
    }
    $total_expense+= $total;
    $arr_description[] = [
        'description' => 'Dispute Loss (Includes fees)',
        'total' => ($total == 0)? 0 : $total ,
        'order' => 67,
    ];
}

    $arr_description[] = [
        'description' => 'Monthly Gross Income',
        'total' => $total_income,
        'order' => 15,
    ];
   /* $arr_description[] = [
        'description' => 'Sales Team',
        'total' => 0 ,
        'order' => 13,
    ];*/
    /*$arr_description[] = [
        'description' => 'Medical director',
        'total' => 0 ,
        'order' => 14,
    ];*/
   /* $arr_description[] = [
        'description' => 'Examiners',
        'total' => 0 ,
        'order' => 15,
    ];*/
    $arr_description[] = [
        'description' => 'Stripe Fee',
        'total' => $total_fee ,
        'order' => 66,
    ];
    
    /*$arr_description[] = [
        'description' => 'Money to Injectors',
        'total' => 0 ,
        'order' => 18,
    ];*/
   
    $arr_description[] = [
        'description' => 'Monthly Expenses',
        'total' => $total_expense + $total_fee,
        'order' => 91,
    ];
    $arr_description = Hash::sort($arr_description, '{n}.order', 'asc');
            

    

        $spreadsheet = new Spreadsheet();
		$sheet = $spreadsheet->getActiveSheet();
		$sheet->getCell('A1')->setValue('MySpaLive Stripe Report');
		$sheet->getStyle('A1')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('right');
        $legend =  "From: " . date('m-d-Y', strtotime($date_from)) . " to: " . date('m-d-Y', strtotime($date_to));
        $sheet->getCell('A2')->setValue($legend);
		$sheet->getStyle('A2')->applyFromArray(array('font' => array('name' => 'Arial','bold' => true,'size' => 16,),'alignment' => array('horizontal' => Alignment::HORIZONTAL_CENTER,'vertical' => Alignment::VERTICAL_CENTER,)));
        

		$initIndex = 5;//$this->log(__LINE__ . ' ' . json_encode($arr_description));
		foreach ($arr_description as $item) {  
            if($item['order'] == 15){
                $sheet->getCell('A' . $initIndex)->setValue($item['description']);
                $sheet->getCell('B' . $initIndex)->setValue('$ '.number_format($item['total'],2));
                
                $initIndex = $initIndex + 1;
                $sheet->getCell('A' . $initIndex)->setValue('');
                $sheet->getCell('B' . $initIndex)->setValue('');
               
                
            }else{
                $sheet->getCell('A' . $initIndex)->setValue($item['description']);
                $sheet->getCell('B' . $initIndex)->setValue('$ '.number_format($item['total'],2));
            }
			
            $sheet->getStyle('B'. $initIndex)->getAlignment()->setHorizontal('right');
			$sheet->getColumnDimension('A')->setAutoSize(true);
            $sheet->getColumnDimension('B')->setAutoSize(true);
			$initIndex = $initIndex + 1;
		}

		$writer = new Xlsx($spreadsheet);
        $time = date('ymdhms');
		$writer->save(TMP . 'reports' . DS . "stripe_".$time.".xls");

		//$this->Files->output_file(TMP . 'reports' . DS . "stripe_".$time.".xls");
        $fname = TMP . 'reports' . DS . "stripe_".$time.".xls";    
        //dd($fname); return;
        if (file_exists($fname)) {
            $size = filesize($fname);                
            header('Content-type: application/vnd.ms-excel');
            header("Content-Disposition: inline; filename=stripe.xls");
            header("Content-Length: {$size}");
            header('content-Transfer-Encoding:binary');
            header('Accept-Ranges:bytes');
            @ readfile($fname);
		exit;
    }
    exit;
}

    // Function to find an object by its 'id'
    function findObjectById($array, $id) {
        foreach ($array as $key=>$object) {
            if ($object['description'] == $id) {
                return $key;
            }
        }

        // Return null if the object with the specified id is not found
        return false;
    }


    public function cora_script_shipping_numbers(){
        $start_date = date('Y-m-d', strtotime(get('start_date',"")));
        $end_date = date('Y-m-d', strtotime(get('end_date',"")));

        $tableStyle = [
            'font'          => ['name' => 'Arial','size' => 13],
            'alignment'     => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '333440'],
                ],
            ],
            // 'horizontal' => Alignment::HORIZONTAL_CENTER, 
        ];

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // LOGO
        /*$drawing = new Drawing();
        $drawing->setName('SpaLiveMD');
        $drawing->setDescription('SpaLiveMD Logo');
        $drawing->setPath(WWW_ROOT . 'img/logo.png');
        $drawing->setHeight(150);
        $drawing->setCoordinates("B1");
        // $drawing->setOffsetX(10);
        $drawing->setWorksheet($spreadsheet->getActiveSheet());

        $rowIndex = 12;*/

        // HEADER
        $styleTitle = [
            'font'      => array('name' => 'Arial', 'italic' => true,'size' => 15),
            'alignment' => array('vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER),
        ];
    
        $sheet->setCellValue('A1', 'Shipping Numbers Report');
        $sheet->mergeCells('A1:N1');
        $sheet->getStyle('A1')->applyFromArray($styleTitle);

        $sheet->setCellValue('A3', 'From: '.$start_date.' To: '.$end_date);
        $sheet->mergeCells('A3:N3');
        $sheet->getStyle('A3')->applyFromArray($styleTitle);

        $payments = $this->get_uid_payments_for_shipping($start_date, $end_date);
        
        $shipping_array = $this->get_shipping_numbers($payments);

        //$this->set("shipping_array", $shipping_array);
        //return;

        //neurotoxins
        $sheet->setCellValue('A5', 'Neurotoxins');
        $sheet->mergeCells('A5:F5');
        $sheet->getStyle('A5')->applyFromArray($styleTitle);

        $sheet->setCellValue('A6', 'Total');
        $sheet->mergeCells('A6:C6');
        $sheet->getStyle('A6')->applyFromArray($styleTitle);

        $sheet->setCellValue('D6', 'Shipping');
        $sheet->mergeCells('D6:F6');
        $sheet->getStyle('D6')->applyFromArray($styleTitle);

        $sheet->setCellValue('A7', "$".($shipping_array["neurotoxins"][0]/100));
        $sheet->mergeCells('A7:C7');
        $sheet->getStyle('A7')->applyFromArray($styleTitle);

        $sheet->setCellValue('D7', "$".($shipping_array["neurotoxins"][1]/100));
        $sheet->mergeCells('D7:F7');
        $sheet->getStyle('D7')->applyFromArray($styleTitle);

        //IV Vials
        $sheet->setCellValue('I5', 'IV Vials');
        $sheet->mergeCells('I5:N5');
        $sheet->getStyle('I5')->applyFromArray($styleTitle);

        $sheet->setCellValue('I6', 'Total');
        $sheet->mergeCells('I6:K6');
        $sheet->getStyle('I6')->applyFromArray($styleTitle);

        $sheet->setCellValue('L6', 'Shipping');
        $sheet->mergeCells('L6:M6');
        $sheet->getStyle('L6')->applyFromArray($styleTitle);

        $sheet->setCellValue('I7', "$".($shipping_array["iv"][0]/100));
        $sheet->mergeCells('I7:K7');
        $sheet->getStyle('I7')->applyFromArray($styleTitle);

        $sheet->setCellValue('L7', "$".($shipping_array["iv"][1]/100));
        $sheet->mergeCells('L7:N7');
        $sheet->getStyle('L7')->applyFromArray($styleTitle);

        //fillers
        $sheet->setCellValue('A9', 'Fillers');
        $sheet->mergeCells('A9:F9');
        $sheet->getStyle('A9')->applyFromArray($styleTitle);

        $sheet->setCellValue('A10', 'Total');
        $sheet->mergeCells('A10:C10');
        $sheet->getStyle('A10')->applyFromArray($styleTitle);

        $sheet->setCellValue('D10', 'Shipping');
        $sheet->mergeCells('D10:F10');
        $sheet->getStyle('D10')->applyFromArray($styleTitle);

        $sheet->setCellValue('A11', "$".($shipping_array["fillers"][0]/100));
        $sheet->mergeCells('A11:C11');
        $sheet->getStyle('A11')->applyFromArray($styleTitle);

        $sheet->setCellValue('D11', "$".($shipping_array["fillers"][1]/100));
        $sheet->mergeCells('D11:F11');
        $sheet->getStyle('D11')->applyFromArray($styleTitle);

        //Materials
        $sheet->setCellValue('I9', 'Materials');
        $sheet->mergeCells('I9:N9');
        $sheet->getStyle('I9')->applyFromArray($styleTitle);

        $sheet->setCellValue('I10', 'Total');
        $sheet->mergeCells('I10:K10');
        $sheet->getStyle('I10')->applyFromArray($styleTitle);

        $sheet->setCellValue('L10', 'Shipping');
        $sheet->mergeCells('L10:N10');
        $sheet->getStyle('L10')->applyFromArray($styleTitle);

        $sheet->setCellValue('I11', "$".($shipping_array["materials"][0]/100));
        $sheet->mergeCells('I11:K11');
        $sheet->getStyle('I11')->applyFromArray($styleTitle);

        $sheet->setCellValue('L11', "$".($shipping_array["materials"][1]/100));
        $sheet->mergeCells('L11:N11');
        $sheet->getStyle('L11')->applyFromArray($styleTitle);

        //Miscelanous
        $sheet->setCellValue('E13', 'Miscelanous');
        $sheet->mergeCells('E13:J13');
        $sheet->getStyle('E13')->applyFromArray($styleTitle);

        $sheet->setCellValue('E14', 'Total');
        $sheet->mergeCells('E14:G14');
        $sheet->getStyle('E14')->applyFromArray($styleTitle);

        $sheet->setCellValue('H14', 'Shipping');
        $sheet->mergeCells('H14:J14');
        $sheet->getStyle('H14')->applyFromArray($styleTitle);

        $sheet->setCellValue('E15', "$".($shipping_array["miscelanous"][0]/100));
        $sheet->mergeCells('E15:G15');
        $sheet->getStyle('E15')->applyFromArray($styleTitle);

        $sheet->setCellValue('H15', "$".($shipping_array["miscelanous"][1]/100));
        $sheet->mergeCells('H15:J15');
        $sheet->getStyle('H15')->applyFromArray($styleTitle);

        //Training Advanced
        $sheet->setCellValue('A17', 'Training Advanced');
        $sheet->mergeCells('A17:F17');
        $sheet->getStyle('A17')->applyFromArray($styleTitle);

        $sheet->setCellValue('A18', 'Total:');
        $sheet->mergeCells('A18:C18');
        $sheet->getStyle('A18')->applyFromArray($styleTitle);

        $sheet->setCellValue('D18', "$".($shipping_array["advanced"]/100));
        $sheet->mergeCells('D18:F18');
        $sheet->getStyle('D18')->applyFromArray($styleTitle);

        //Training Advanced techniques
        $sheet->setCellValue('I17', 'Training Advanced Techniques');
        $sheet->mergeCells('I17:N17');
        $sheet->getStyle('I17')->applyFromArray($styleTitle);

        $sheet->setCellValue('I18', 'Total:');
        $sheet->mergeCells('I18:K18');
        $sheet->getStyle('I18')->applyFromArray($styleTitle);

        $sheet->setCellValue('L18', "$".($shipping_array["advanced_techniques"]/100));
        $sheet->mergeCells('L18:N18');
        $sheet->getStyle('L18')->applyFromArray($styleTitle);

        //MSL Shipping
        $sheet->setCellValue('A20', 'MSL Shipping');
        $sheet->mergeCells('A20:F20');
        $sheet->getStyle('A20')->applyFromArray($styleTitle);

        $sheet->setCellValue('A21', 'Total:');
        $sheet->mergeCells('A21:C21');
        $sheet->getStyle('A21')->applyFromArray($styleTitle);

        $sheet->setCellValue('D21', "$".(($shipping_array["neurotoxins"][1] + $shipping_array["fillers"][1] + $shipping_array["materials"][1] + $shipping_array["miscelanous"][1])/100));
        $sheet->mergeCells('D21:F21');
        $sheet->getStyle('D21')->applyFromArray($styleTitle);

        //DrugCrafters Shipping
        $sheet->setCellValue('I20', 'DrugCrafters Shipping');
        $sheet->mergeCells('I20:N20');
        $sheet->getStyle('I20')->applyFromArray($styleTitle);

        $sheet->setCellValue('I21', 'Total:');
        $sheet->mergeCells('I21:K21');
        $sheet->getStyle('I21')->applyFromArray($styleTitle);

        $sheet->setCellValue('L21', "$".($shipping_array["iv"][1]/100));
        $sheet->mergeCells('L21:N21');
        $sheet->getStyle('L21')->applyFromArray($styleTitle);

        //sumatoria total
        $all_payments = 0;
        foreach ($shipping_array as $s) {
            //$this->log(__LINE__ . ' ' . json_encode($s));
            if(isset($s[0]) ){                
                $all_payments += $s[0];
            }
            if(isset($s[1]) ){                
                $all_payments += $s[1];
            }
        }
        $all_payments += $shipping_array["advanced"];
        $sheet->setCellValue('E23', 'All payments');
        $sheet->mergeCells('E23:G23');
        $sheet->getStyle('E23')->applyFromArray($styleTitle);

        $sheet->setCellValue('H23', "$".($all_payments/100));
        $sheet->mergeCells('H23:J23');
        $sheet->getStyle('H23')->applyFromArray($styleTitle);

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, "Xlsx");

        header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
        // header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="shipping_numbers_report.xlsx"');
        $writer->save('php://output');

        exit;
    }

    public function get_uid_payments_for_shipping($start_date, $end_date){
        $this->loadModel('SpaLiveV1.DataPayment');
        
        $fields = ["DataPayment.id","DataPayment.total","DataPayment.subtotal","DataPayment.uid","DataPayment.promo_code","Users.name","Users.mname","Users.lname"];
        $where = [
            "DataPayment.intent !=" => "",
            "DataPayment.payment !=" => "",
            "DataPayment.receipt !=" => "",
            "DataPayment.is_visible" => 1,
            "DataPayment.created >=" => $start_date." 00:00:00",
            "DataPayment.created <=" => $end_date." 23:59:59",
            "DataPayment.type" => "PURCHASE",
        ];

        $_join = [
            'Users' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'DataPayment.id_from = Users.id']
        ];

        $array_payments = [];

        $is_dev = env('IS_DEV', false);

        $payments = $this->DataPayment->find()->select($fields)->join($_join)->where($where)->toArray();

        if($is_dev){//dev incluir ventas test

            foreach ($payments as $p) {
                $array_payments[] = $p;
            }

        }else{//no incluir ventas test
            $Otherservices = new OtherservicesController();

            foreach ($payments as $p) {
                $inyector_name = $p["Users"]["mname"] == '' ? trim($p["Users"]["name"]).' '.trim($p["Users"]["lname"]) : trim($p["Users"]["name"]).' '.trim($p["Users"]["mname"]).' '.trim($p["Users"]["lname"]);

                $is_test = $Otherservices->check_test($inyector_name);

                if(!$is_test){
                    $array_payments[] = $p;
                }

            }

        }

        return $array_payments;

    }

    public function get_shipping_numbers($payments){
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');

        $neurotoxins = [0,0];
        $toxins = array();
        $mats = array();
        $fills = array();
        $ivt = array();
        $mics = array();
        $advs= array();

        $materials = [0,0];
        $fillers = [0,0];
        $iv = [0,0];
        $miscelanous = [0,0];
        $advanced = 0;
        $advanced_techniques = 0;

        $fields = ["DataPurchases.id", "DataPurchases.amount", "DataPurchases.shipping_cost", "DataPurchases.is_pickup", "DataPurchases.created"];
        //$pur_id ='';  //$this->log(__LINE__ . ' ' . json_encode(count($payments)));
        foreach ($payments as $p) {
            //$this->log(__LINE__ . ' ' . json_encode($p['uid'] == '64a0e0af-1617-4df0-9510-09287b728546'));
        $where = ['DataPurchases.uid' => $p["uid"], /*'DataPurchases.deleted' => 0*/];//contar los refund por eso quitar el deleted
        /*if($p['id'] ==  18776                     // debugger
        ){$this->log(__LINE__ . ' ' . json_encode($p));
            $this->log(__LINE__ . ' ' . json_encode($p['uid']));
            $this->log(__LINE__ . ' ' . json_encode($p['promo_code']));
        }
        else continue;*/
            $purchase = $this->DataPurchases->find()->select($fields)->where($where)->first();

            if(!empty($purchase)){
                //$pur_id .= $purchase->id. ",";
                $categories = [];//para guardar todas las categorias que tiene la compra, por ejemplo neuro + iv + fillers

            $where_details = ['DataPurchasesDetail.purchase_id' => $purchase->id/*, 'Product.deleted' => 0*/];
                $fields_details = ['DataPurchasesDetail.product_id', 'DataPurchasesDetail.price', 'DataPurchasesDetail.qty', 'DataPurchasesDetail.refunded', 'Product.category'];

                $_join = [
                    'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'DataPurchasesDetail.product_id = Product.id']
                ];

                $details = $this->DataPurchasesDetail->find()->select($fields_details)->join($_join)->where($where_details)->order(['DataPurchasesDetail.price' => 'ASC'])->all();
  
                if(count($details)>0){

                    $has_discount = false;
                    if($p["promo_code"] != ""){
                        $promo_discount = $this->get_promo_shipping($p["promo_code"]);
                        if(!empty($promo_discount)){
                            $has_discount = true;
                        }
                    }
                    
                    $amount_discount = 0;
                    $difference = 0;
                    $count_neuro = 0;

                    foreach ($details as $d) {
                        //$qty = ($d->qty >0 ? $d->qty: $d->refunded);
                        //$price = $d->price * $d->qty;
                        $price = ($d->price * $d->qty) + ($d->price * $d->refunded);
                        
                        if($d["Product"]["category"] == "NEUROTOXINS" || $d["Product"]["category"] == "NEUROTOXIN PACKAGES"){
                            if($has_discount){
 
                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    if($d["Product"]["category"] == "NEUROTOXIN PACKAGES"){
                                        //$neurotoxins[0] += $p["total"] - $purchase->shipping_cost;                                        
                                        $neurotoxins[0] += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        if(isset($toxins[$purchase->id]['total'])){
                                            $toxins[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }else{
                                            $toxins[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }
                                    }else{
                                        $neurotoxins[0] += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        if(isset($toxins[$purchase->id]['total'])){
                                            $toxins[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }else{
                                            $toxins[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }
                                    }
                                }else{

                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $neurotoxins[0] += 100;
                                        if(isset($toxins[$purchase->id]['total'])){
                                            $toxins[$purchase->id]['total'] +=100;
                                        }else{
                                            $toxins[$purchase->id]['total'] =100;
                                        }
                                    }else{
                                        $neurotoxins[0] += $price - $amount_discount;
                                        $difference = 0;
                                        if(isset($toxins[$purchase->id]['total'])){
                                            $toxins[$purchase->id]['total'] +=$price - $amount_discount;
                                        }else{
                                            $toxins[$purchase->id]['total'] =$price - $amount_discount;
                                        }
                                    }

                                }

                            }else{
                                $neurotoxins[0] += $price;
                                if(isset($toxins[$purchase->id]['total'])){
                                    $toxins[$purchase->id]['total'] +=$price;
                                }else{
                                    $toxins[$purchase->id]['total'] =$price;
                                }
                            }

                            if (!in_array("NEUROTOXINS", $categories)) {
                                $categories[] = "NEUROTOXINS";
                            }

                            $count_neuro = $count_neuro + $d->qty;

                        }else if($d["Product"]["category"] == "FILLERS" || $d["Product"]["category"] == "FILLERS PACKAGES"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    $fillers[0] += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    if(isset($fills[$purchase->id]['total'])){
                                        $fills[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }else{
                                        $fills[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }
                                }else{
                                     
                                    $amount_discount = $difference + ($promo_discount->discount / count($details));
                                    if($promo_discount->code == "$10000$" || $promo_discount->code == "$1000$"){
                                        $fillers[0] += 99/count($details);
                                        if(isset($fills[$purchase->id]['total'])){
                                            $fills[$purchase->id]['total'] +=99/count($details);
                                        }else{
                                            $fills[$purchase->id]['total'] =99/count($details);
                                        }
                                    }else if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $fillers[0] += 100;
                                        if(isset($fills[$purchase->id]['total'])){
                                            $fills[$purchase->id]['total'] +=100;
                                        }else{
                                            $fills[$purchase->id]['total'] =100;
                                        }
                                    }else{
                                        $fillers[0] += $price - $amount_discount;
                                        if(isset($fills[$purchase->id]['total'])){
                                            $fills[$purchase->id]['total'] +=$price - $amount_discount;
                                        }else{
                                            $fills[$purchase->id]['total'] =$price - $amount_discount;
                                        }
                                        $difference = 0;
                                    }

                                }

                            }else{
                                $fillers[0] += $price;
                                if(isset($fills[$purchase->id]['total'])){
                                    $fills[$purchase->id]['total'] +=$price;
                                }else{
                                    $fills[$purchase->id]['total'] =$price;
                                }
                            }
                            
                            if (!in_array("FILLERS", $categories)) {
                                $categories[] = "FILLERS";
                            }

                        }else if($d["Product"]["category"] == "MATERIALS"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    $materials[0] += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    if(isset($mats[$purchase->id]['total'])){
                                        $mats[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }else{
                                        $mats[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    } 
                                }else{

                                    $amount_discount = $difference + ($promo_discount->discount / count($details));
                                    if($p['promo_code'] == "$1000$"){
                                        $materials[0] += (count($details)/100);                                                                                  
                                        if(isset($mats[$purchase->id]['total'])){
                                            $mats[$purchase->id]['total'] +=(count($details)/100);
                                        }else{
                                            $mats[$purchase->id]['total'] =(count($details)/100);
                                        }
                                    }else if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $materials[0] += 100;
                                        if(isset($mats[$purchase->id]['total'])){
                                            $mats[$purchase->id]['total'] +=100;
                                        }else{
                                            $mats[$purchase->id]['total'] =100;
                                        }
                                    }else{
                                        $materials[0] += $price - $amount_discount;
                                        $difference = 0;
                                        if(isset($mats[$purchase->id]['total'])){
                                            $mats[$purchase->id]['total'] +=$price - $amount_discount;
                                        }else{
                                            $mats[$purchase->id]['total'] =$price - $amount_discount;
                                        }
                                    }

                                }

                            }else{
                                $materials[0] += $price;
                                if(isset($mats[$purchase->id]['total'])){
                                    $mats[$purchase->id]['total'] +=$price;
                                }else{
                                    $mats[$purchase->id]['total'] =$price;
                                }
                            }

                            if (!in_array("MATERIALS", $categories)) {
                                $categories[] = "MATERIALS";
                            }

                        }else if ($d["Product"]["category"] == "IV VIALS"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    $iv[0] += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    if(isset($ivt[$purchase->id]['total'])){
                                        $ivt[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }else{
                                        $ivt[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }
                                }else{
                                    
                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $iv[0] += 100;
                                        if(isset($ivt[$purchase->id]['total'])){
                                            $ivt[$purchase->id]['total'] +=100;
                                        }else{
                                            $ivt[$purchase->id]['total'] =100;
                                        }
                                    }else{
                                        $iv[0] += $price - $amount_discount;
                                        if(isset($ivt[$purchase->id]['total'])){
                                            $ivt[$purchase->id]['total'] +=$price - $amount_discount;
                                        }else{
                                            $ivt[$purchase->id]['total'] =$price - $amount_discount;
                                        }
                                        $difference = 0;
                                    }

                                }

                            }else{
                                $iv[0] += $price;
                                if(isset($ivt[$purchase->id]['total'])){
                                    $ivt[$purchase->id]['total'] +=$price;
                                }else{
                                    $ivt[$purchase->id]['total'] =$price;
                                }
                            }

                            if (!in_array("IV VIALS", $categories)) {
                                $categories[] = "IV VIALS";
                            }

                        }else if($d["Product"]["category"] == "MISCELLANEOUS"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    if($d->product_id == 44){//advanced
                                        $advanced += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        if(isset($advs[$purchase->id]['total'])){
                                            $advs[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }else{
                                            $advs[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }
                                    }else if($d->product_id == 178){//advanced techniques
                                        $advanced_techniques += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }else{
                                        $miscelanous[0] += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        if(isset($mics[$purchase->id]['total'])){
                                            $mics[$purchase->id]['total'] +=$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }else{
                                            $mics[$purchase->id]['total'] =$this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                        }
                                    }

                                }else{
                                    
                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $miscelanous[0] += 100;
                                        if(isset($mics[$purchase->id]['total'])){
                                            $mics[$purchase->id]['total'] +=100;
                                        }else{
                                            $mics[$purchase->id]['total'] =100;
                                        }
                                    }else{

                                        if($d->product_id == 44){//advanced
                                            $advanced += $price - $amount_discount;
                                            if(isset($advs[$purchase->id]['total'])){
                                                $advs[$purchase->id]['total'] +=$price - $amount_discount;
                                            }else{
                                                $advs[$purchase->id]['total'] =$price - $amount_discount;
                                            }
                                        }else if($d->product_id == 178){//advanced techniques
                                            $advanced_techniques += $price - $amount_discount;
                                        }else{
                                            $miscelanous[0] += $price - $amount_discount;
                                            if(isset($mics[$purchase->id]['total'])){
                                                $mics[$purchase->id]['total'] +=$price - $amount_discount;
                                            }else{
                                                $mics[$purchase->id]['total'] =$price - $amount_discount;
                                            }
                                        }

                                        $difference = 0;
                                    }

                                }

                            }else{

                                if($d->product_id == 44){//advanced
                                    $advanced += $price;
                                    if(isset($advs[$purchase->id]['total'])){
                                        $advs[$purchase->id]['total'] +=$price;
                                    }else{
                                        $advs[$purchase->id]['total'] =$price;
                                    }
                                }else if($d->product_id == 178){//advanced techniques
                                    $advanced_techniques += $price;
                                }else{
                                    $miscelanous[0] += $price;
                                    if(isset($mics[$purchase->id]['total'])){
                                        $mics[$purchase->id]['total'] +=$price;
                                    }else{
                                        $mics[$purchase->id]['total'] =$price;
                                    }
                                }

                            }

                            if($d->product_id != 44 && $d->product_id != 178){
                                if (!in_array("MISCELLANEOUS", $categories)) {
                                    $categories[] = "MISCELLANEOUS";
                                }
                            }

                        }
                        
                    }

                    //repartir el shipping entre las categorias
                    $shipping = $purchase->shipping_cost;
                    if($count_neuro <= 5 && $purchase->is_pickup == 0){

                        if($has_discount){ 
                            if($promo_discount->type == "PERCENTAGE"){                                
                                $flag_edit = false;                                
                                if($purchase->id < 10102){
                                    $flag_edit = true;
                                }
                                if($d["Product"]["category"] != "NEUROTOXIN PACKAGES"){
                                    
                                    if ($flag_edit) {
                                        $shipping = $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $purchase->shipping_cost);                                        
                                    }
                                }else if($d["Product"]["category"] == "NEUROTOXIN PACKAGES"){
                                    if ($flag_edit) {
                                        $shipping = $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $purchase->shipping_cost);
                                    }
                                }
                            }else if($promo_discount->code == "$10000$" || $promo_discount->code == "$1000$"){
                                
                                    $shipping = 1;
                                
                            }
                        }

                        if((in_array("NEUROTOXINS", $categories) || in_array("FILLERS", $categories)) && 
                        (in_array("MATERIALS", $categories) || in_array("MISCELLANEOUS", $categories)) && in_array("IV VIALS", $categories)){
 
                            $iv[1] += ((50 * $shipping) / 100);
                            if(isset($ivt[$purchase->id]['ship'])){
                                $ivt[$purchase->id]['ship'] +=((6.25 * $shipping) / 100);
                            }else{
                                $ivt[$purchase->id]['ship'] =((6.25 * $shipping) / 100);
                            }

                            if((in_array("MATERIALS", $categories) && in_array("MISCELLANEOUS", $categories))){
                                $materials[1] += ((6.25 * $shipping) / 100);
                                $miscelanous[1] += ((6.25 * $shipping) / 100);
                                if(isset($mics[$purchase->id]['ship'])){
                                    $mics[$purchase->id]['ship'] +=((6.25 * $shipping) / 100);
                                }else{
                                    $mics[$purchase->id]['ship'] =((6.25 * $shipping) / 100);
                                }
                                if(isset($mats[$purchase->id]['ship'])){
                                    $mats[$purchase->id]['ship'] +=((6.25 * $shipping) / 100);
                                }else{
                                    $mats[$purchase->id]['ship'] =((6.25 * $shipping) / 100);
                                } 
                            }else if(in_array("MATERIALS", $categories)){
                                $materials[1] += ((12.5 * $shipping) / 100);
                                if(isset($mats[$purchase->id]['ship'])){
                                    $mats[$purchase->id]['ship'] +=((12.5 * $shipping) / 100);
                                }else{
                                    $mats[$purchase->id]['ship'] =((12.5 * $shipping) / 100);
                                } 
                            }else if(in_array("MISCELLANEOUS", $categories)){
                                $miscelanous[1] += ((12.5 * $shipping) / 100);
                                if(isset($mics[$purchase->id]['ship'])){
                                    $mics[$purchase->id]['ship'] +=((12.5 * $shipping) / 100);
                                }else{
                                    $mics[$purchase->id]['ship'] =((12.5 * $shipping) / 100);
                                }
                            }

                            if((in_array("NEUROTOXINS", $categories) && in_array("FILLERS", $categories))){
                                $neurotoxins[1] += ((18.75 * $shipping) / 100);
                                if(isset($toxins[$purchase->id]['ship'])){
                                    $toxins[$purchase->id]['ship'] +=((18.75 * $shipping) / 100);
                                }else{
                                    $toxins[$purchase->id]['ship'] =((18.75 * $shipping) / 100);
                                }  
                                $fillers[1] += ((18.75 * $shipping) / 100);
                                if(isset($fills[$purchase->id]['ship'])){
                                    $fills[$purchase->id]['ship'] +=((18.75 * $shipping) / 100);
                                }else{
                                    $fills[$purchase->id]['ship'] =((18.75 * $shipping) / 100);
                                }
                            }else if(in_array("NEUROTOXINS", $categories)){
                                $neurotoxins[1] += ((37.5 * $shipping) / 100);
                                if(isset($toxins[$purchase->id]['ship'])){
                                    $toxins[$purchase->id]['ship'] +=((37.5 * $shipping) / 100);
                                }else{
                                    $toxins[$purchase->id]['ship'] =((37.5 * $shipping) / 100);
                                }    
                            }else if(in_array("FILLERS", $categories)){
                                $fillers[1] += ((37.5 * $shipping) / 100);
                                if(isset($fills[$purchase->id]['ship'])){
                                    $fills[$purchase->id]['ship'] +=((37.5 * $shipping) / 100);
                                }else{
                                    $fills[$purchase->id]['ship'] =((37.5 * $shipping) / 100);
                                }
                            }

                            //para dar 80

                        }else if((in_array("NEUROTOXINS", $categories) || in_array("FILLERS", $categories)) && in_array("IV VIALS", $categories)){
                            
                            $iv[1] += ((50 * $shipping) / 100);
                            if(isset($ivt[$purchase->id]['ship'])){
                                $ivt[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                            }else{
                                $ivt[$purchase->id]['ship'] =((50 * $shipping) / 100);
                            }

                            if((in_array("NEUROTOXINS", $categories))){
                                $neurotoxins[1] += ((50 * $shipping) / 100);
                                if(isset($toxins[$purchase->id]['ship'])){
                                    $toxins[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                                }else{
                                    $toxins[$purchase->id]['ship'] =((50 * $shipping) / 100);
                                }     
                            }else if(in_array("FILLERS", $categories)){
                                $fillers[1] += ((50 * $shipping) / 100);
                                if(isset($fills[$purchase->id]['ship'])){
                                    $fills[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                                }else{
                                    $fills[$purchase->id]['ship'] =((50 * $shipping) / 100);
                                }
                            }

                            //para dar 80

                        }else if((in_array("MATERIALS", $categories) || in_array("MISCELLANEOUS", $categories)) && in_array("IV VIALS", $categories)){
                            
                            $iv[1] += ((80 * $shipping) / 100);
                            if(isset($ivt[$purchase->id]['ship'])){
                                $ivt[$purchase->id]['ship'] +=((80 * $shipping) / 100);
                            }else{
                                $ivt[$purchase->id]['ship'] =((80 * $shipping) / 100);
                            }

                            if(in_array("MATERIALS", $categories)){
                                $materials[1] += ((20 * $shipping) / 100);//por si compro material y miscelaneous, se dividen los 10 dolares
                                if(isset($mats[$purchase->id]['ship'])){
                                    $mats[$purchase->id]['ship'] +=((20 * $shipping) / 100);
                                }else{
                                    $mats[$purchase->id]['ship'] =((20 * $shipping) / 100);
                                } 
                            }else if(in_array("MISCELLANEOUS", $categories)){
                                $miscelanous[1] += ((20 * $shipping) / 100);
                                if(isset($mics[$purchase->id]['ship'])){
                                    $mics[$purchase->id]['ship'] +=((20 * $shipping) / 100);
                                }else{
                                    $mics[$purchase->id]['ship'] =((20 * $shipping) / 100);
                                }
                            }

                            //para dar 50

                        }else if((in_array("NEUROTOXINS", $categories) || in_array("FILLERS", $categories)) && 
                        (in_array("MATERIALS", $categories) || in_array("MISCELLANEOUS", $categories))){

                            if((in_array("MATERIALS", $categories) && in_array("MISCELLANEOUS", $categories))){
                                $materials[1] += ((12.5 * $shipping) / 100);
                                if(isset($mats[$purchase->id]['ship'])){
                                    $mats[$purchase->id]['ship'] +=((12.5 * $shipping) / 100);
                                }else{
                                    $mats[$purchase->id]['ship'] =((12.5 * $shipping) / 100);
                                } 
                                $miscelanous[1] += ((12.5 * $shipping) / 100);
                                if(isset($mics[$purchase->id]['ship'])){
                                    $mics[$purchase->id]['ship'] +=((12.5 * $shipping) / 100);
                                }else{
                                    $mics[$purchase->id]['ship'] =((12.5 * $shipping) / 100);
                                }
                            }else if(in_array("MATERIALS", $categories)){
                                $materials[1] += ((25 * $shipping) / 100);
                                if(isset($mats[$purchase->id]['ship'])){
                                    $mats[$purchase->id]['ship'] +=((25 * $shipping) / 100);
                                }else{
                                    $mats[$purchase->id]['ship'] =((25 * $shipping) / 100);
                                } 
                            }else if(in_array("MISCELLANEOUS", $categories)){
                                $miscelanous[1] += ((25 * $shipping) / 100);
                                if(isset($mics[$purchase->id]['ship'])){
                                    $mics[$purchase->id]['ship'] +=((25 * $shipping) / 100);
                                }else{
                                    $mics[$purchase->id]['ship'] =((25 * $shipping) / 100);
                                }
                            }

                            if((in_array("NEUROTOXINS", $categories) && in_array("FILLERS", $categories))){
                                $neurotoxins[1] += ((37.5 * $shipping) / 100);
                                if(isset($toxins[$purchase->id]['ship'])){
                                    $toxins[$purchase->id]['ship'] +=((37.5 * $shipping) / 100);
                                }else{
                                    $toxins[$purchase->id]['ship'] =((37.5 * $shipping) / 100);
                                }     
                                $fillers[1] += ((37.5 * $shipping) / 100);
                                if(isset($fills[$purchase->id]['ship'])){
                                    $fills[$purchase->id]['ship'] +=((37.5 * $shipping) / 100);
                                }else{
                                    $fills[$purchase->id]['ship'] =((37.5 * $shipping) / 100);
                                }
                            }else if(in_array("NEUROTOXINS", $categories)){
                                $neurotoxins[1] += ((75 * $shipping) / 100); 
                                if(isset($toxins[$purchase->id]['ship'])){
                                    $toxins[$purchase->id]['ship'] +=((75 * $shipping) / 100);
                                }else{
                                    $toxins[$purchase->id]['ship'] =((75 * $shipping) / 100);
                                }    
                            }else if(in_array("FILLERS", $categories)){
                                $fillers[1] += ((75 * $shipping) / 100);;
                                if(isset($fills[$purchase->id]['ship'])){
                                    $fills[$purchase->id]['ship'] +=((75 * $shipping) / 100);
                                }else{
                                    $fills[$purchase->id]['ship'] =((75 * $shipping) / 100);
                                }
                            }

                            //para dar 40

                        }else if(in_array("MATERIALS", $categories) && in_array("MISCELLANEOUS", $categories) && count($categories) <= 2){

                            if(in_array("MATERIALS", $categories)){
                                $materials[1] += ((50 * $shipping) / 100);
                                if(isset($mats[$purchase->id]['ship'])){
                                    $mats[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                                }else{
                                    $mats[$purchase->id]['ship'] =((50 * $shipping) / 100);
                                } 
                            }
                            
                            if(in_array("MISCELLANEOUS", $categories)){
                                $miscelanous[1] += ((50 * $shipping) / 100);
                                if(isset($mics[$purchase->id]['ship'])){
                                    $mics[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                                }else{
                                    $mics[$purchase->id]['ship'] =((50 * $shipping) / 100);
                                }
                            }

                            //para dar 10
                        
                        }else if(in_array("NEUROTOXINS", $categories) && in_array("FILLERS", $categories) && count($categories) <= 2){
                            
                            if(in_array("NEUROTOXINS", $categories)){
                                $neurotoxins[1] += ((50 * $shipping) / 100);
                                if(isset($toxins[$purchase->id]['ship'])){
                                    $toxins[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                                }else{
                                    $toxins[$purchase->id]['ship'] =((50 * $shipping) / 100);
                                }     
                            }
                            
                            if(in_array("FILLERS", $categories)){
                                $fillers[1] += ((50 * $shipping) / 100);
                                if(isset($fills[$purchase->id]['ship'])){
                                    $fills[$purchase->id]['ship'] +=((50 * $shipping) / 100);
                                }else{
                                    $fills[$purchase->id]['ship'] =((50 * $shipping) / 100);
                                }
                            }

                            //para dar 40

                        } else if(in_array("IV VIALS", $categories) && count($categories) == 1){

                            $iv[1] += $shipping;
                            if(isset($ivt[$purchase->id]['ship'])){
                                $ivt[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $ivt[$purchase->id]['ship'] =$shipping;
                            }

                        }else if(in_array("NEUROTOXINS", $categories) && count($categories) == 1){

                            $neurotoxins[1] += $shipping;
                            if(isset($toxins[$purchase->id]['ship'])){
                                $toxins[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $toxins[$purchase->id]['ship'] =$shipping;
                            }     

                        }else if(in_array("FILLERS", $categories) && count($categories) == 1){

                            $fillers[1] += $shipping;
                            if(isset($fills[$purchase->id]['ship'])){
                                $fills[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $fills[$purchase->id]['ship'] =$shipping;
                            }

                        }else if(in_array("MATERIALS", $categories) && count($categories) == 1){

                            $materials[1] += $shipping;
                            if(isset($mats[$purchase->id]['ship'])){
                                $mats[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $mats[$purchase->id]['ship'] =$shipping;
                            } 

                        }else if(in_array("MISCELLANEOUS", $categories) && count($categories) == 1){

                            $miscelanous[1] += $shipping;
                            if(isset($mics[$purchase->id]['ship'])){
                                $mics[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $mics[$purchase->id]['ship'] =$shipping;
                            }

                        }
                    }else if(in_array("NEUROTOXINS", $categories)){// $this->log(__LINE__ . ' ' . json_encode(''));
                        if($has_discount){ 
                            if($promo_discount->type == "PERCENTAGE"){                                
                                $flag_edit = false;                                
                                if($purchase->id < 10102){
                                    $flag_edit = true;
                                }
                                if($d["Product"]["category"] != "NEUROTOXIN PACKAGES"){
                                    
                                    if ($flag_edit) {
                                        $shipping = $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $purchase->shipping_cost);                                        
                                    }
                                }else if($d["Product"]["category"] == "NEUROTOXIN PACKAGES"){
                                    if ($flag_edit) {
                                        $shipping = $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $purchase->shipping_cost);
                                    }
                                }
                            }else if($promo_discount->code == "$10000$" || $promo_discount->code == "$1000$"){
                                
                                    $shipping = 1;
                                
                            }
                            $neurotoxins[1] += $shipping ;
                            if(isset($toxins[$purchase->id]['ship'])){
                                $toxins[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $toxins[$purchase->id]['ship'] =$shipping;
                            } 
                        }else{
                            $neurotoxins[1] += $shipping ;
                            if(isset($toxins[$purchase->id]['ship'])){
                                $toxins[$purchase->id]['ship'] +=$shipping;
                            }else{
                                $toxins[$purchase->id]['ship'] =$shipping;
                            } 
                        }
                    }

                }
            }
        }
    /*     $this->log(__LINE__ . 'toxins ' . json_encode($toxins));
         $this->log(__LINE__ . 'materials ' . count($mats));
         $this->log(__LINE__ . 'fillers ' . count($fills));
         $this->log(__LINE__ . 'iv ' . json_encode($ivt));
         $this->log(__LINE__ . 'miscelanius ' . count($mics));
         $total_tox = 0;
         $total_tox_s=0;

         $total_mat = 0;
         $total_mat_s=0;

         $total_fills = 0;
         $total_fills_s=0;

         $total_iv = 0;
         $total_iv_s=0;

         $total_mics = 0;
         $total_mics_s=0;
         $total_advs = 0;
         $total_advs_s=0;
         $str = '';
         $i=0;
         $all =[];
         //toxins
         foreach ($toxins as $key => $value) {
            //$this->log(__LINE__ . ' ' . json_encode($key));
            //$this->log(__LINE__ . ' ' . json_encode($value));
            $str .= $key;//id              
              //  $this->log(__LINE__ . ' ' . json_encode($toxins[$key]));    //$str .= ', toxins:';
                //foreach ($toxins[$key] as $kt => $valt) {                    
                foreach ($value as $kt => $valt) {                                      
                    if($kt == 'total'){
                        $total_tox+=$valt;
                        if(isset($all[$key]['toxins']['total'])){
                            $all[$key]['toxins']['total'] +=$valt;
                        }else{
                            $all[$key]['toxins']['total'] =$valt;
                        } 
                    }else if($kt == 'ship'){
                        $total_tox_s+=$valt;                        
                        if(isset($all[$key]['toxins']['ship'])){
                            $all[$key]['toxins']['ship'] +=$valt;
                        }else{
                            $all[$key]['toxins']['ship'] =$valt;
                        }
                    }
                }                                                          
            $str .= '\n ';                                    
         }
         //iv

         foreach ($ivt as $key => $value) {                                    
                foreach ($value as $ki => $vali) {                    
                    $str .= ", ivt: $ki , $vali";
                    if($ki == 'total'){
                        $total_iv+=$vali;
                    }else if($ki == 'ship'){
                        $total_iv_s+=$vali;
                    }
                    if($ki == 'total'){                        
                        if(isset($all[$key]['ivt']['total'])){
                            $all[$key]['ivt']['total'] +=$vali;
                        }else{
                            $all[$key]['ivt']['total'] =$vali;
                        } 
                    }else if($ki == 'ship'){                        
                        if(isset($all[$key]['ivt']['ship'])){
                            $all[$key]['ivt']['ship'] +=$vali;
                        }else{
                            $all[$key]['ivt']['ship'] =$vali;
                        }
                    }

                }            
         }
         //mats
          $this->log(__LINE__ . ' ' . json_encode($mats));
         foreach ($mats as $key => $value) {            
                foreach ($value as $km => $valm) {
                    $str .= ", mats: $km , $valm";
                    if($km == 'total'){
                        $total_mat+=$valm;
                    }else if($km == 'ship'){
                        $total_mat_s+=$valm;
                    }
                    if($km == 'total'){                        
                        if(isset($all[$key]['mats']['total'])){
                            $all[$key]['mats']['total'] +=$valm;
                        }else{
                            $all[$key]['mats']['total'] =$valm;
                        } 
                    }else if($km == 'ship'){                        
                        if(isset($all[$key]['mats']['ship'])){
                            $all[$key]['mats']['ship'] +=$valm;
                        }else{
                            $all[$key]['mats']['ship'] =$valm;
                        }
                    } 
                }                            
        }
        //fillers       
         
        foreach ($fills as $key => $value) {            
            foreach ($value as $kf => $valf) {
                if($kf == 'total'){
                    $total_fills+=$valf;
                }else if($kf == 'ship'){
                    $total_fills_s+=$valf;
                }
                if($kf == 'total'){
                    
                    if(isset($all[$key]['filler']['total'])){
                        $all[$key]['filler']['total'] +=$valf;
                    }else{
                        $all[$key]['filler']['total'] =$valf;
                    } 
                }else if($kf == 'ship'){                        
                    if(isset($all[$key]['filler']['ship'])){
                        $all[$key]['filler']['ship'] +=$valf;
                    }else{
                        $all[$key]['filler']['ship'] =$valf;
                    }
                }
            }
        }
        
        //misc
        foreach ($mics as $key => $value) { 
            foreach ($value as $km => $valm) {
                $str .= ", mics: $km , $valm";
                if($km == 'total'){
                    $total_mics+=$valm;
                }else if($km == 'ship'){
                    $total_mics_s+=$valm;
                }
                if($km == 'total'){                    
                    if(isset($all[$key]['mics']['total'])){
                        $all[$key]['mics']['total'] +=$valm;
                    }else{
                        $all[$key]['mics']['total'] =$valm;
                    } 
                }else if($km == 'ship'){                        
                    if(isset($all[$key]['mics']['ship'])){
                        $all[$key]['mics']['ship'] +=$valm;
                    }else{
                        $all[$key]['mics']['ship'] =$valm;
                    }
                }
            }
        }
        //advs $advs
        foreach ($advs as $key => $value) { 
            foreach ($value as $km => $valm) {
                $str .= ", advs: $km , $valm";
                if($km == 'total'){
                    $total_advs+=$valm;
                }else if($km == 'ship'){
                    $total_advs_s+=$valm;
                }
                if($km == 'total'){                    
                    if(isset($all[$key]['advs']['total'])){
                        $all[$key]['advs']['total'] +=$valm;
                    }else{
                        $all[$key]['advs']['total'] =$valm;
                    } 
                }else if($km == 'ship'){                        
                    if(isset($all[$key]['advs']['ship'])){
                        $all[$key]['advs']['ship'] +=$valm;
                    }else{
                        $all[$key]['advs']['ship'] =$valm;
                    }
                }
            }
        }
        

         $this->log(__LINE__ . 'total_tox ' . $total_tox);
         $this->log(__LINE__ . 'total_tox_s ' . $total_tox_s);
         $this->log(__LINE__ . 'tox ' . ($total_tox + $total_tox_s));
         $this->log(__LINE__ . 'total_mat ' . $total_mat);
         $this->log(__LINE__ . 'total_mat_s ' . $total_mat_s);
         $this->log(__LINE__ . 'mat ' . ($total_mat + $total_mat_s));
         $this->log(__LINE__ . 'total_fills ' . $total_fills);
         $this->log(__LINE__ . 'total_fills_s ' . $total_fills_s);
         $this->log(__LINE__ . 'fills ' . ($total_fills + $total_fills_s));
         $this->log(__LINE__ . 'total_iv ' . $total_iv);
         $this->log(__LINE__ . 'total_iv_s ' . $total_iv_s);
         $this->log(__LINE__ . 'fills ' . ($total_iv + $total_iv_s));
         $this->log(__LINE__ . 'total_mics ' . $total_mics);
         $this->log(__LINE__ . 'total_mics_s ' . $total_mics_s);
         $this->log(__LINE__ . 'misc ' . ($total_mics + $total_mics_s));
         $this->log(__LINE__ . 'total_advs ' . $total_advs);
         $this->log(__LINE__ . 'total_advs_s ' . $total_advs_s);
         $this->log(__LINE__ . 'advs ' . ($total_advs + $total_advs_s));
         $this->log(__LINE__ . 'final ' . ($total_tox + $total_tox_s +$total_mat + $total_mat_s +$total_fills + $total_fills_s +$total_iv + $total_iv_s + $total_mics + $total_mics_s+ $total_advs + $total_advs_s));
        
        // File path for the text file
        $filePath = 'example.txt';
        $filePathTot = 'example_total.txt';
        // Open the file for writing
        $file = fopen($filePath, 'w');
        $fileTot = fopen($filePathTot, 'w');
        foreach ($all as $key=>$row) {        
            $csv_str = $key.',';
            $csv_str_tot = $key.',';
            $ctot =0;
            foreach($row as $k=>$v){        
                $csv_str .= $k.',';
                foreach($v as $txt=>$tot){
                    $csv_str .= $txt.',' .$tot.',';
                    $ctot+=$tot;
                }                
            }          
        fwrite($file, $csv_str . PHP_EOL);
        fwrite($fileTot, $csv_str_tot . $ctot . PHP_EOL);
        }        
        // Close the CSV file
        fclose($file);
        fclose($fileTot);
        //echo 'CSV file has been successfully created at ' . $filePath;
         $this->log(__LINE__ . ' ' . json_encode($all));
         $this->log(__LINE__ . ' ' . json_encode(array(
            'neurotoxins' => $neurotoxins,
            'materials' => $materials,
            'fillers' => $fillers,
            'iv' => $iv,
            'miscelanous' => $miscelanous,
            'advanced' => $advanced,
            'advanced_techniques' => $advanced_techniques
        )));*/
        if($neurotoxins[0]){
            $neurotoxins[0] = $neurotoxins[0] *1.0315;
        }
        if($neurotoxins[1]){
            $neurotoxins[1] = $neurotoxins[1] *1.0315;
        }
        if($materials[0]>0){
            $materials[0] = $materials[0] *1.0315;
        }
        if($materials[1]>0){
            $materials[1] = $materials[1] *1.0315;
        }
        if($fillers[0]>0){
            $fillers[0] = $fillers[0] *1.0315;
        }
        if($fillers[1]>0){
            $fillers[1] = $fillers[1] *1.0315;
        }
        if($iv[0]>0){
            $iv[0] = $iv[0] *1.0315;
        }
        if($iv[1]>0){
            $iv[1] = $iv[1] *1.0315;
        }
        if($miscelanous[0]>0){
            $miscelanous[0] = $miscelanous[0] *1.0315;
        }
        if($miscelanous[1]>0){
            $miscelanous[1] = $miscelanous[1] *1.0315;
        }
        if($advanced>0){
            $advanced = $advanced *1.0315;
        }
        if($advanced_techniques>0){
            $advanced_techniques = $advanced_techniques *1.0315;
        }
         $response = array(
            'neurotoxins' => $neurotoxins,
            'materials' => $materials,
            'fillers' => $fillers,
            'iv' => $iv,
            'miscelanous' => $miscelanous,
            'advanced' => $advanced,
            'advanced_techniques' => $advanced_techniques
        );
        
        return $response; 
    }

    public function get_promo_shipping($promo_code){
        $this->loadModel('SpaLiveV1.DataPromoCodes');

        $fields = ["DataPromoCodes.discount", "DataPromoCodes.type"];
        $where = ['DataPromoCodes.code' => $promo_code];

        $promo = $this->DataPromoCodes->find()->where($where)->first();

        return $promo;

    }

    public function calculate_percentage_for_shipping($amount, $discount, $price){
        
        $percentage = ($price / $amount) * 100;
        $discount_amount = ($amount * $discount) / 100;

        $discount_per_product = ($percentage * $discount_amount) / 100;
        return round($price - $discount_per_product,0);

    }

    /************ para comparar cantidades calculadas y stripe*****************/ 
    public function get_all_data_payments(){
        $start_date = date('Y-m-d', strtotime(get('start_date',"")));
        $end_date = date('Y-m-d', strtotime(get('end_date',"")));

        $payments = $this->get_uid_payments_for_shipping($start_date, $end_date);

        $shipping_array = $this->get_shipping_numbers_total($payments);

        $this->set("shipping_array", $shipping_array);
        $this->success();
    }

    public function get_shipping_numbers_total($payments){
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');

        $array_payments = [];

        $fields = ["DataPurchases.id", "DataPurchases.amount", "DataPurchases.shipping_cost", "DataPurchases.is_pickup"];

        foreach ($payments as $p) {

        $where = ['DataPurchases.uid' => $p["uid"], /*'DataPurchases.deleted' => 0*/];//contar los refund por eso quitar el deleted

            $purchase = $this->DataPurchases->find()->select($fields)->where($where)->first();

            if(!empty($purchase)){

                $categories = [];//para guardar todas las categorias que tiene la compra, por ejemplo neuro + iv + fillers

                $total_calculated = 0;

                $where_details = ['DataPurchasesDetail.purchase_id' => $purchase->id, 'Product.deleted' => 0];
                $fields_details = ['DataPurchasesDetail.product_id', 'DataPurchasesDetail.price', 'DataPurchasesDetail.qty', 'Product.category'];

                $_join = [
                    'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'DataPurchasesDetail.product_id = Product.id']
                ];

                $details = $this->DataPurchasesDetail->find()->select($fields_details)->join($_join)->where($where_details)->order(['DataPurchasesDetail.price' => 'ASC'])->all();

                if(count($details)>0){

                    $has_discount = false;
                    if($p["promo_code"] != ""){
                        $promo_discount = $this->get_promo_shipping($p["promo_code"]);
                        if(!empty($promo_discount)){
                            $has_discount = true;
                        }
                    }

                    $amount_discount = 0;
                    $difference = 0;
                    $count_neuro = 0;

                    foreach ($details as $d) {

                        $price = $d->price * $d->qty;

                        if($d["Product"]["category"] == "NEUROTOXINS" || $d["Product"]["category"] == "NEUROTOXIN PACKAGES"){
                            
                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    if($d["Product"]["category"] == "NEUROTOXIN PACKAGES"){
                                        $total_calculated += $p["total"] - $purchase->shipping_cost;
                                    }else{
                                        $total_calculated += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }
                                }else{

                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $total_calculated += 100;
                                    }else{
                                        $total_calculated += $price - $amount_discount;
                                        $difference = 0;
                                    }

                                }

                            }else{
                                $total_calculated += $price;
                            }

                            if (!in_array("NEUROTOXINS", $categories)) {
                                $categories[] = "NEUROTOXINS";
                            }

                            $count_neuro = $count_neuro + $d->qty;

                        }else if($d["Product"]["category"] == "FILLERS" || $d["Product"]["category"] == "FILLERS PACKAGES"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    $total_calculated += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                }else{
                                    
                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $total_calculated += 100;
                                    }else{
                                        $total_calculated += $price - $amount_discount;
                                        $difference = 0;
                                    }

                                }

                            }else{
                                $total_calculated += $price;
                            }

                            if (!in_array("FILLERS", $categories)) {
                                $categories[] = "FILLERS";
                            }

                        }else if($d["Product"]["category"] == "MATERIALS"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    $total_calculated += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                }else{

                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $total_calculated += 100;
                                    }else{
                                        $total_calculated += $price - $amount_discount;
                                        $difference = 0;
                                    }

                                }

                            }else{
                                $total_calculated += $price;
                            }

                            if (!in_array("MATERIALS", $categories)) {
                                $categories[] = "MATERIALS";
                            }

                        }else if ($d["Product"]["category"] == "IV VIALS"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    $total_calculated += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                }else{
                                    
                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $total_calculated += 100;
                                    }else{
                                        $total_calculated += $price - $amount_discount;
                                        $difference = 0;
                                    }

                                }

                            }else{
                                $total_calculated += $price;
                            }

                            if (!in_array("IV VIALS", $categories)) {
                                $categories[] = "IV VIALS";
                            }

                        }else if($d["Product"]["category"] == "MISCELLANEOUS"){

                            if($has_discount){

                                if($promo_discount->type == "PERCENTAGE"){
                                    //aplicar el descuento al total, se divide entre la cantidad de productos para obtener la exacta
                                    if($d->product_id == 44){//advanced
                                        $advanced += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }else if($d->product_id == 178){//advanced techniques
                                        $advanced_techniques += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }else{
                                        $total_calculated += $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $price);
                                    }

                                }else{
                                    
                                    $amount_discount = $difference + ($promo_discount->discount / count($details));

                                    if($price < $amount_discount){
                                        $difference = ($amount_discount - $price) + 100;
                                        $total_calculated += 100;
                                    }else{

                                        if($d->product_id == 44){//advanced
                                            $advanced += $price - $amount_discount;
                                        }else if($d->product_id == 178){//advanced techniques
                                            $advanced_techniques += $price - $amount_discount;
                                        }else{
                                            $total_calculated += $price - $amount_discount;
                                        }

                                        $difference = 0;
                                    }

                                }

                            }else{

                                if($d->product_id == 44){//advanced
                                    $total_calculated += $price;
                                }else if($d->product_id == 178){//advanced techniques
                                    $total_calculated += $price;
                                }else{
                                    $total_calculated += $price;
                                }

                            }

                            if (!in_array("MISCELLANEOUS", $categories)) {
                                $categories[] = "MISCELLANEOUS";
                            }

                        }
                        
                    }

                    //repartir el shipping entre las categorias
                    $shipping = $purchase->shipping_cost;
                    $shipping_calculated = 0;
                    
                    if($count_neuro < 5 && $purchase->is_pickup == 0){

                        if($has_discount&&$promo_discount->type == "PERCENTAGE"){
                            if($d["Product"]["category"] != "NEUROTOXIN PACKAGES"){
                                $shipping = $this->calculate_percentage_for_shipping($p["subtotal"], $promo_discount->discount, $purchase->shipping_cost);
                            }
                        }

                        if((in_array("NEUROTOXINS", $categories) || in_array("FILLERS", $categories)) && 
                        (in_array("MATERIALS", $categories) || in_array("MISCELLANEOUS", $categories)) && in_array("IV VIALS", $categories)){

                            $shipping_calculated += ((50 * $shipping) / 100);

                            if((in_array("MATERIALS", $categories) && in_array("MISCELLANEOUS", $categories))){
                                $shipping_calculated += ((6.25 * $shipping) / 100);
                                $shipping_calculated += ((6.25 * $shipping) / 100);
                            }else if(in_array("MATERIALS", $categories)){
                                $shipping_calculated += ((12.5 * $shipping) / 100);
                            }else if(in_array("MISCELLANEOUS", $categories)){
                                $shipping_calculated += ((12.5 * $shipping) / 100);
                            }

                            if((in_array("NEUROTOXINS", $categories) && in_array("FILLERS", $categories))){
                                $shipping_calculated += ((18.75 * $shipping) / 100);
                                $shipping_calculated += ((18.75 * $shipping) / 100);
                            }else if(in_array("NEUROTOXINS", $categories)){
                                $shipping_calculated += ((37.5 * $shipping) / 100);
                            }else if(in_array("FILLERS", $categories)){
                                $shipping_calculated += ((37.5 * $shipping) / 100);
                            }

                            //para dar 80

                        }else if((in_array("NEUROTOXINS", $categories) || in_array("FILLERS", $categories)) && in_array("IV VIALS", $categories)){

                            $shipping_calculated += ((50 * $shipping) / 100);

                            if((in_array("NEUROTOXINS", $categories))){
                                $shipping_calculated += ((50 * $shipping) / 100);//por si compro material y miscelaneous, se dividen los 10 dolares
                            }else if(in_array("FILLERS", $categories)){
                                $shipping_calculated += ((50 * $shipping) / 100);
                            }

                            //para dar 80

                        }else if((in_array("MATERIALS", $categories) || in_array("MISCELLANEOUS", $categories)) && in_array("IV VIALS", $categories)){

                            $shipping_calculated += ((80 * $shipping) / 100);

                            if(in_array("MATERIALS", $categories)){
                                $shipping_calculated += ((20 * $shipping) / 100);//por si compro material y miscelaneous, se dividen los 10 dolares
                            }else if(in_array("MISCELLANEOUS", $categories)){
                                $shipping_calculated += ((20 * $shipping) / 100);
                            }

                            //para dar 50

                        }else if((in_array("NEUROTOXINS", $categories) || in_array("FILLERS", $categories)) && 
                        (in_array("MATERIALS", $categories) || in_array("MISCELLANEOUS", $categories))){

                            if((in_array("MATERIALS", $categories) && in_array("MISCELLANEOUS", $categories))){
                                $shipping_calculated += ((12.5 * $shipping) / 100);
                                $shipping_calculated += ((12.5 * $shipping) / 100);
                            }else if(in_array("MATERIALS", $categories)){
                                $shipping_calculated += ((25 * $shipping) / 100);
                            }else if(in_array("MISCELLANEOUS", $categories)){
                                $shipping_calculated += ((25 * $shipping) / 100);
                            }

                            if((in_array("NEUROTOXINS", $categories) && in_array("FILLERS", $categories))){
                                $shipping_calculated += ((37.5 * $shipping) / 100);
                                $shipping_calculated += ((37.5 * $shipping) / 100);
                            }else if(in_array("NEUROTOXINS", $categories)){
                                $shipping_calculated += ((75 * $shipping) / 100);
                            }else if(in_array("FILLERS", $categories)){
                                $shipping_calculated += ((75 * $shipping) / 100);
                            }

                            //para dar 40

                        }else if(in_array("MATERIALS", $categories) && in_array("MISCELLANEOUS", $categories) && count($categories) <= 2){

                            if(in_array("MATERIALS", $categories)){
                                $shipping_calculated += ((50 * $shipping) / 100);
                            }
                            
                            if(in_array("MISCELLANEOUS", $categories)){
                                $shipping_calculated += ((50 * $shipping) / 100);
                            }

                            //para dar 10
                        
                        }else if(in_array("NEUROTOXINS", $categories) && in_array("FILLERS", $categories) && count($categories) <= 2){

                            if(in_array("NEUROTOXINS", $categories)){
                                $shipping_calculated += ((50 * $shipping) / 100);
                            }
                            
                            if(in_array("FILLERS", $categories)){
                                $shipping_calculated += ((50 * $shipping) / 100);
                            }

                            //para dar 40

                        } else if(count($categories) == 1){

                            $shipping_calculated += $shipping;

                        }
                    }

                    $array_payments[] = array(
                        "uid" => $p->uid,
                        "has_discount" => $has_discount,
                        "total_calculated_with_shipping_calculates" => $total_calculated + $shipping_calculated,
                        //"total_calculated" => $total_calculated,
                        //"shipping_calculated" => $shipping_calculated,
                        //"shipping" => $shipping,
                        "total_in_stripe" => $p->total,
                        "subtotal" => $p->subtotal,
                        //"category" => $categories,
                        //"user" => $p["Users"]["name"]." ".$p["Users"]["mname"]." ".$p["Users"]["lname"]
                    );

                }
            }
        }

        return $array_payments; 
    }

    public function test_n(){
        

        $payments = $this->get_uid_payments_for_shipping('2023-12-01','2023-12-31');
        $shipping_array = $this->get_shipping_numbers($payments);

        print_r('payment test');exit;
    }

    public function get_answer_wl(){
        $panel = get('l3n4p', '6092482f7ce858.91169218');
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){
            return;
        }
         
        $id = get('id', 0);
        
        $this->loadModel('SpaLiveV1.DataOtherServicesAnswers');
        $sql = " SELECT * FROM data_other_services_answers where  check_in_id = $id "; 		        
        $ent_query = $this->DataOtherServicesAnswers->getConnection()->execute($sql)->fetchAll('assoc');

        $query_pat =  " SELECT u.name, u.mname, u.lname FROM sys_users u left join data_other_services_check_in  ch on ch.patient_id = u.id   where  ch.id = $id "; 
		        
        $pat = $this->DataOtherServicesAnswers->getConnection()->execute($query_pat)->fetchAll('assoc');
        $this->log(__LINE__ . ' ' . json_encode($pat));
        if(empty($ent_query)){
            return;
        }
        if(empty($ent_query[0])){
            return;
        }
        $str_name ='';
        if(!empty($pat[0])){
            $str_name  = $pat[0]['name'] . " " . $pat[0]['lname'];
        }

        $arr_data = json_decode($ent_query[0]['data'], true);         
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(10,10,10,10)); 
        $str_income = '';
        $str_output = '';

    foreach ($arr_data as $key => $value) {         
            $str_income .= "
            <tr style=\"border-spacing: 0;\">
            <td style=\"padding: 2mm;    border-bottom: 1px solid #e4e6ef;\">" . $value['question'] . " <span style=\"font-weight: bold; \">" . $value['answer'] ."  </span></td>
            
            </tr>";
            }
        // $url_root = $this->URL_ROOT . "api";
        
        $html2pdf->writeHTML("
            <page style=\"width: 190mm; height: 277mm; position:relative; color: #373a48;\">
                    <img src=\"" . $this->URL_API . "img/logo.png\" style=\"width:50mm;\">

                    <table style=\"margin-top: 10mm;\">
                        <tr>
                            <td style=\"width: 110mm;\">
                                <h1 style=\"font-size: 22px; margin: 0mm 0 0;\">MySpaLive </h1>
                            </td>
                            <td style=\"width: 75mm; text-align: right;\">
                                <p style=\"margin: 5mm 0 0; font-size: 15px;\">Patient: <b>".$str_name."</b></p>
                            </td>
                        </tr>
                    </table>

                    

                        " . $str_output . "
                        
    
                        <table style=\"margin-top: 10mm; width: 185mm; border-spacing: 0; border-collapse: collapse; color: #525560;\">                        
                            <tr style=\"font-weight: bold;  border-spacing: 0;\">
                                <td style=\"padding: 2mm; width: 70mm; border-bottom: 1px solid #e4e6ef;\">Weight Loss Questionnaire</td>                                
                            </tr>
    
                            " . $str_income . "
                    </table>

                <!-- <page_footer>
                    <div style=\"text-align: center; font-size: 15px;\">
                        [[page_cu]]/[[page_nb]]
                    </div>
                </page_footer> -->
            </page>");
             $this->log(__LINE__ . ' ' . ($str_income));
        $html2pdf->Output('monthly_statement.pdf', 'I');
        
        

    }

    public function pay_referal_os() {

        $this->loadModel('SpaLiveV1.DataSchoolReferredPayments');
        $this->loadModel('SpaLiveV1.DataAssignedSchool');        
        $this->loadModel('SpaLiveV1.DataSubscriptionPayments');

        $total_to_transfer = 7500;
        
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

        if (USER_ID != 1) return;

        $id = get('payment_id','');
        if(empty($id)){
            $this->message('Invalid id.');
                $this->set('session', false);
                return;
        }
        //data_school_referred_payments
        $data_school_referred_payments = $this->DataSchoolReferredPayments->find()->where(['DataSchoolReferredPayments.id' => $id])->first();
        //[{"id":1,"uid":"651r4br-64556h461e554h19e-r67yu84","payment_id":10554,"amount":7500,"user_id":10199,"status":"NOT PAID","payload":"","deleted":0,"created":"2024-05-06T08:51:09-05:00"}]
        if (empty($data_school_referred_payments)) {
            $this->message('Source payment not found');
            return;
        }

        $dataPay = $this->DataSubscriptionPayments->find()
        ->where(['DataSubscriptionPayments.id' => $data_school_referred_payments->payment_id, 'DataSubscriptionPayments.payment_type' => 'FULL', 'DataSubscriptionPayments.deleted' => 0, 'DataSubscriptionPayments.status' => 'DONE', 'DataSubscriptionPayments.receipt_id <>' => ''])->first();
         //{"id":10554,"uid":"648be331-481d-42ee-90f7-92f28aacb96a","user_id":10199,"subscription_id":2086,"total":19900,"payment_id":"pi_3PDpEyD0WNkFIbmK1WkREj8m","charge_id":"ch_3PDpEyD0WNkFIbmK1CJR1LJp","receipt_id":"https:\/\/pay.stripe.com\/receipts\/payment\/CAcaFwoVYWNjdF8xSk1hZE1EMFdOa0ZJYm1LKJn86LEGMga7zXxKH4w6LBahwjiAZeWSrLyIkhygQafNWOILI7d1Duibef2PK1_vg61YG4BYinfdq5pb","notes":null,"error":"","status":"DONE","created":"2024-05-07T09:43:38-05:00","deleted":0,"md_id":139,"payment_type":"FULL","payment_description":"SUBSCRIPTIONMD","main_service":"NEUROTOXINS","addons_services":"","payment_details":"{\"NEUROTOXINS\":19900}"}
        

        if (empty($dataPay)) {
            $this->message('Source payment not found');
            return;
        }

        $school = $this->DataAssignedSchool->find()->select(['DataAssignedSchool.school_id','User.id','User.email','User.stripe_account_confirm','User.stripe_account'])            
        ->join(['User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedSchool.school_id'],])
        ->where(['DataAssignedSchool.user_id' => $dataPay->user_id,'DataAssignedSchool.deleted' => 0,'User.deleted' => 0])->first();
        //{"school_id":10198,"User":{"id":"10198","email":"arthur_morgan@advantedigital.com","stripe_account_confirm":"1","stripe_account":"acct_1PDWpwD3VnVC5NQS"}}
        
        if (empty($school)) {
            $this->message('School not found or not enabled');
            return;
        }

        if ($school['User']['stripe_account'] == '' ) {
             $this->message('School has no enabled stripe account.');
            return;
        }

        try {

            /*$findPayment = $this->DataSalesRepresentativePayments->find()->where(['DataSalesRepresentativePayments.payment_id' => $dataPay->id])->first();
            if (!empty($findPayment)) {
                $this->message('Payment already sent.');
                return;
            }*/

            if($school['User']['stripe_account'] != ''){
                $transfer = \Stripe\Transfer::create([
                  'amount' => $data_school_referred_payments->amount,
                  'currency' => 'USD',
                  'description' => 'SCHOOL REF PAYMENT',
                  'destination' => $school['User']['stripe_account'],
                  'source_transaction' => $dataPay->charge_id //ch_3PDpEyD0WNkFIbmK1CJR1LJp
                ]);
            }/*else{
                $transfer = \Stripe\Transfer::create([
                    'amount' => $total_to_transfer,
                    'currency' => 'USD',
                    'description' => 'SALES REF PAYMENT',
                    'destination' => $school['User']['stripe_account'],
                  ]);
            }*/
            
            if ($transfer) {                
                $this->DataSchoolReferredPayments->updateAll(
                    ['status' =>  'PAID','payload'=>json_encode($transfer)],
                    ['id' => $data_school_referred_payments->id]
                );
                $this->set('session', true);
                $this->success();
                return;                
            }

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

        if(isset($error) && !empty($error)){
            $this->message($error);
        }
    }

    public function get_shipping_numbers_report($start_date,$end_date){
        

        $start_date = date('Y-m-d', strtotime($start_date));
        $end_date = date('Y-m-d', strtotime($end_date));

        $payments = $this->get_uid_payments_for_shipping($start_date, $end_date);
        $shipping_array = $this->get_shipping_numbers($payments);
        return $shipping_array;
        
    }

    public function email_injector_subscribe_os($injector_email){
        $subject = 'Welcome to the MySpaLive family';
        $html = '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body {
                    font-family: Arial, sans-serif;
                    line-height: 1.6;
                }
                h1 {
                    color: #2c3e50;
                }
                h2 {
                    color: #2980b9;
                }
                a {
                    color: #2980b9;
                    text-decoration: none;
                }
                a:hover {
                    text-decoration: underline;
                }
            </style>
        </head>
        <body>

            <h3>Welcome to the MySpaLive family!</h3>

            <div class="section">
                <p>Here are a few important steps to help you feel confident and informed about our company, along with a step-by-step video tutorial on our app.</p>
                <p>Please get connected with our trainer <strong>Kasey</strong> for your onboarding call. After this call, you will also have access to your dedicated business mentor, who will assist you in building your clientele and answering any marketing or business-related questions along the way.</p>
            </div><br>

            <div class="section">
                <p>In the meantime, please watch this short video on how to navigate the app you will be using:</p>
                <p class="video-link"><a href="https://drive.google.com/file/d/1f1gMp_mbJS_urChLsTQ2yla3IlgQo26g/view?usp=sharing" target="_blank">Watch the Video</a></p>
            </div><br>

            <div class="section">
                <p>We are committed to supporting your success every step of the way!</p>
                <p>Please email <a href="mailto:Directoroftraining@myspalive.com">Directoroftraining@myspalive.com</a> to RSVP for our weekly onboarding session, held every Tuesday from 6:30 PM to 8:00 PM CST.</p>
                <p><strong>Meeting ID:</strong> <a href="https://meet.google.com/bxk-sejj-dfi" target="_blank">https://meet.google.com/bxk-sejj-dfi</a></p>
            </div><br>

            <div class="section">
                <p>Once you have completed your onboarding call, please schedule a call here to speak with your business mentor:</p>
                <p><a href="https://calendly.com/patientrelations-msl/20min" target="_blank">Schedule a Call</a></p>
            </div>
        </body>
        </html>
        ';

        $data = array(
            'from'    => 'MySpaLive <noreply@mg.myspalive.com>',
            'to'    => $injector_email,
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
    public function send_pharmacy_email_skin_products($purchase_id = null) {
        // If called from webhook, no token validation needed
        if ($purchase_id === null) {
            $token = get('token', '');
            if (!empty($token)) {
                $user = $this->AppToken->validateToken($token, true);
                if ($user === false) {
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
            $purchase_id = get('purchase_id', 5517); // Default to test purchase ID
        }

        // Load required models
        $this->loadModel("SpaLiveV1.CatNotifications");
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
        $this->loadModel("SpaLiveV1.SysUsers");

        // Get purchase information with state name
        $ent_purchase = $this->DataPurchases->find()
            ->select([
                'DataPurchases.id',
                'DataPurchases.user_id',
                'DataPurchases.name',
                'DataPurchases.address',
                'DataPurchases.city',
                'DataPurchases.state',
                'DataPurchases.zip',
                'DataPurchases.status',
                'State.name'
            ])
            ->join([
                'State' => [
                    'table' => 'cat_states',
                    'type' => 'LEFT',
                    'conditions' => 'State.id = DataPurchases.state'
                ]
            ])
            ->where(['DataPurchases.id' => $purchase_id])
            ->first();

        if (empty($ent_purchase)) {
            if ($purchase_id === null) {
                $this->message('Purchase not found');
            }
            return false;
        }

        // Get purchase details with product information
        $purchase_details = $this->DataPurchasesDetail->find()
            ->select([
                'DataPurchasesDetail.id',
                'DataPurchasesDetail.purchase_id',
                'DataPurchasesDetail.product_id',
                'DataPurchasesDetail.price',
                'DataPurchasesDetail.qty',
                'DataPurchasesDetail.shipped_qty',
                'DataPurchasesDetail.refunded',
                'DataPurchasesDetail.refunded_amount',
                'DataPurchasesDetail.product_number',
                'DataPurchasesDetail.serial_number',
                'DataPurchasesDetail.lot_number',
                'DataPurchasesDetail.expiration_date',
                'DataPurchasesDetail.product_detail_question',
                'DataPurchasesDetail.product_detail',
                'Product.category',
                'Product.name'
            ])
            ->join([
                'Product' => [
                    'table' => 'cat_products', 
                    'type' => 'INNER', 
                    'conditions' => 'Product.id = DataPurchasesDetail.product_id'
                ],
            ])
            ->where(['DataPurchasesDetail.purchase_id' => $purchase_id])
            ->toArray();
        

        // Check if any products are in skin product categories
        $skin_products = array_filter($purchase_details, function($detail) {
            return in_array($detail['Product']['category'], ['ACNE PRODUCTS', 'BRIGHTENING PRODUCTS', 'ANTI-AGING PRODUCTS', 'BACKBAR PRODUCTS']);
        });

        if (empty($skin_products)) {
            if ($purchase_id === null) {
                $this->message('No skin products found in this purchase');
            }
            return false;
        }
        
        // Get user information with license
        $user_info = $this->SysUsers->find()
            ->select([
                'SysUsers.id',
                'SysUsers.name',
                'SysUsers.lname',
                'SysUsers.phone',
                'License.number'
            ])
            ->join([
                'License' => [
                    'table' => 'sys_licences',
                    'type' => 'LEFT',
                    'conditions' => 'License.user_id = SysUsers.id'
                ]
            ])
            ->where(['SysUsers.id' => $ent_purchase->user_id])
            ->first();

        if (empty($user_info)) {
            if ($purchase_id === null) {
                $this->message('User information not found');
            }
            return false;
        }
        
        // Get notification template
        $ent_notification = $this->CatNotifications->find()
            ->where(['CatNotifications.title' => 'PHARMACY_SKIN_PRODUCTS_ORDER'])
            ->first();
        if (empty($ent_notification)) {
            if ($purchase_id === null) {
                $this->message('Notification template not found');
            }
            return false;
        }

        // Prepare email content
        $msg_mail = $ent_notification['body'];

        // Build order details table
        $order_table = '<table border="1" cellpadding="5" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
        $order_table .= '<tr style="background-color: #f2f2f2;"><th>Product</th><th>Quantity</th><th>Price</th><th>Total</th></tr>';
        
        $total_amount = 0;
        foreach ($skin_products as $product) {
            $product_total = ($product['price'] / 100) * $product['qty'];
            $total_amount += $product_total;
            
            $order_table .= '<tr>';
            $order_table .= '<td>' . htmlspecialchars($product['Product']['name']) . '</td>';
            $order_table .= '<td>' . $product['qty'] . '</td>';
            $order_table .= '<td>$' . number_format($product['price'] / 100, 2) . '</td>';
            $order_table .= '<td>$' . number_format($product_total, 2) . '</td>';
            $order_table .= '</tr>';
        }
        $order_table .= '</table>';

        // Build shipping address from data_purchases with state name
        $shipping_address = '';
        if (!empty($ent_purchase->address)) {
            $shipping_address .= $ent_purchase->address;
        }
        if (!empty($ent_purchase->city)) {
            $shipping_address .= (!empty($shipping_address) ? ', ' : '') . $ent_purchase->city;
        }
        if (!empty($ent_purchase['State']['name'])) {
            $shipping_address .= (!empty($shipping_address) ? ', ' : '') . $ent_purchase['State']['name'];
        }
        if (!empty($ent_purchase->zip)) {
            $shipping_address .= (!empty($shipping_address) ? ' ' : '') . $ent_purchase->zip;
        }
        if (empty($shipping_address)) {
            $shipping_address = 'N/A';
        }

        // Replace placeholders in email template
        $constants = [
            '[FIRST_NAME]' => $user_info['name'] ?? 'N/A',
            '[LAST_NAME]' => $user_info['lname'] ?? 'N/A',
            '[LICENSE_NUMBER]' => $user_info['License']['number'] ?? 'N/A',
            '[PCA_CERTIFIED]' => 'Yes',
            '[SHIPPING_ADDRESS]' => $shipping_address,
            '[PHONE_NUMBER]' => $user_info['phone'] ?? 'N/A',
            '[ORDER_NUMBER]' => $purchase_id,
            '[ORDER_DETAILS]' => $order_table,
            '[TOTAL_AMOUNT]' => '$' . number_format($total_amount, 2)
        ];

        foreach ($constants as $key => $value) {
            $msg_mail = str_replace($key, $value, $msg_mail);
        }

        // Add logo to email
        $html_content = '<img src="' . $this->URL_PANEL . 'img/logo.png" width="100px"/>' . $msg_mail;

        $str_email = get('email','ashlan@myspalive.com');  
        $is_dev = env('IS_DEV', false);
        if($is_dev){
            $str_email = 'carlos@advantedigital.com, john@advantedigital.com';
        }else{
            $str_email = 'schools@pcaskin.com, francisco@advantedigital.com';
        }
        // Prepare email data
        $data = array(
            'from' => 'MySpaLive <noreply@mg.myspalive.com>',
            'to' => $str_email,
            'subject' => 'Order ' . $purchase_id . ' from MySpaLive',
            'html' => $html_content,
        );

        $mailgunKey = $this->getMailgunKey();

        // Send email via Mailgun
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
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_code == 200) {
            if ($purchase_id === null) {
                $this->set('result', 'Email sent successfully to pharmacy');
                $this->success();
            }
            return true;
        } else {
            if ($purchase_id === null) {
                $this->message('Failed to send email. HTTP Code: ' . $http_code);
            }
            return false;
        }
    }
    
}