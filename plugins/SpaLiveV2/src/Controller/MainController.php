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


class MainController extends AppPluginController {
     
    private $total = 2500;
    private $register_total = 49500;
    private $register_refund = 3500;
    private $shipping_cost = 1000;
    private $shipping_cost_both = 3000;
    private $shipping_cost_inj = 2000;
    private $shipping_cost_mat = 1000;
    private $URL_ROOT = "http://app.spalivemd.com/";

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

        $this->URL_ROOT = Configure::read('App.URL_ROOT');
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.CatStates');
        
        \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));       
        

        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){
                $state = $this->CatStates->find()->select(['CatStates.cost_ci', 'CatStates.refund_ci', 'CatStates.shipping_cost'])->where(['CatStates.id' => $user['user_state']])->first();
                if(!empty($state)){
                    $this->register_total = $state->cost_ci > 0 ? $state->cost_ci : $this->register_total;
                    $this->register_refund = $state->refund_ci > 0 ? $state->refund_ci : $this->register_refund;
                    $this->shipping_cost = $state->shipping_cost > 0 ? $state->shipping_cost : $this->shipping_cost;
                    
                    $this->shipping_cost_both = $state->shipping_cost_both > 0 ? $state->shipping_cost_both : $this->shipping_cost_both;
                    $this->shipping_cost_inj = $state->shipping_cost_inj > 0 ? $state->shipping_cost_inj : $this->shipping_cost_inj;
                    $this->shipping_cost_mat = $state->shipping_cost_mat > 0 ? $state->shipping_cost_mat : $this->shipping_cost_mat;
                }
            }

            $ver = get('version', '');
            $ver = str_replace('version ', '', $ver);
        }
        
    }

    public function test_n() {

        echo Date(); exit;
        

    }

    public function test_tree(){
        $this->loadModel('SpaLiveV1.DataNetwork');
        $this->DataNetwork->addBehavior('SpaLiveV1.MyTree');
        $this->DataNetwork->recover_tree();

        $this->success();
    }

    public function get_zip_coordinates() {

        $zip = get('zip',0);
        if ($zip == 0) {
            $this->message('Empty zip code');
            return;
        }

        if (strlen(strval($zip)) < 5) {
            for($i = 0; $i < (5 - strlen(get('zip',0)));$i++) {
                $zip = '0' . $zip;
            }
        }
        require_once(ROOT . DS . 'vendor' . DS  . 'zipcodes' . DS . 'init.php');
        
        $data = isset(\zipcodes\Zipcodes::DATA[$zip]) ? \zipcodes\Zipcodes::DATA[$zip] : null;
        if ($data) {
            $this->success();
            $this->set('latitude', $data['lat']);    
            $this->set('longitude', $data['lng']);    
            $this->set('radius', 13);
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
                    $this->success();
                    $this->set('latitude', $data['lat']);    
                    $this->set('longitude', $data['lng']);    
                    $this->set('radius', 13);
                    return;
                 }

             }


            $this->message('Zip not found');
        }
        
    }

    private function validateCode($code) {

        $this->loadModel('SpaLiveV1.DataPromoCodes');

        $ent_codes = $this->DataPromoCodes->find()
        ->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.code' => strtoupper($code)])->first();
        if (!empty($ent_codes)) {
            $n_tot = $ent_codes->used + 1;
            $ent_codes->used = $n_tot;
            $this->DataPromoCodes->save($ent_codes);
            $this->set('discount', $ent_codes->discount);
            $this->set('code_valid', true);
            return (100 - $ent_codes->discount) / 100;
        }
        $this->set('code_valid', false);
        return 1.0;

    }

    private function createPaymentRegister($type, $from, $to, $uid, $intent, $subtotal, $total,$discount_credits = 0, $prepaid = 0) {
        $multiplier = $this->validateCode(get('promo_code',''));
        $promo_code = $multiplier < 1 ? strtoupper(get('promo_code','')) : '';
        $promo_discount = 100 - ($multiplier * 100);

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
            $this->DataPayment->save($c_entity); 
        } else {

        }

    }

    private function updatePaymentRegister($uid, $p_uid, $payment, $receipt) {

        $this->loadModel('SpaLiveV1.DataPayment');

        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.uid' => $uid, 'DataPayment.intent' => $p_uid, 'DataPayment.id_to' => 0])->order(['DataPayment.id' => 'DESC'])->first();



        if (!empty($ent_payment)) {
            $ent_payment->payment = $payment;
            $ent_payment->receipt = $receipt;
            $ent_payment->is_visible = 1;
            $ent_payment->comission_payed = 1;

            $this->DataPayment->save($ent_payment);


            if ($ent_payment->discount_credits > 0 && ($ent_payment->type == 'PURCHASE' || $ent_payment->type == 'GFE') ) {
                $this->loadModel('SpaLiveV1.DataCredits');

                 $array_save = array(
                    'user_id' => $ent_payment->id_from,
                    'purchase_uid' => $ent_payment->uid,
                    'consultation_id' => 0,
                    'amount' => $ent_payment->discount_credits * -1,
                    'created' => date('Y-m-d H:i:s'),
                );



                $c_entity = $this->DataCredits->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    $this->DataCredits->save($c_entity);    
                }

            }
        }



    }



    private function payGFEComissions($consultation_uid) {

        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataCredits');

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();
        $ent_examiner = $this->SysUsers->find()->where(['SysUsers.id' => $ent_consultation->assistance_id])->first();

        

        if (!empty($ent_examiner)) {
            // if (!empty($ent_examiner->stripe_account) && $ent_examiner->stripe_account_confirm == 1) {
            // if (!empty($ent_examiner->stripe_account) && $ent_examiner->stripe_account_confirm == 0) {

                $this->loadModel('SpaLiveV1.DataPayment');
                 $ent_pay = $this->DataPayment->find()
                ->where(['DataPayment.service_uid' => $ent_consultation->uid, 'DataPayment.intent' => $ent_consultation->payment_intent,'DataPayment.type' => 'GFE', 'DataPayment.comission_generated' => 0,'DataPayment.is_visible' => 1])->first();

                if (!empty($ent_pay)) {

                    // if ($ent_pay->comission_payed == 1) return;

                    // $int_total = $ent_pay->total;
                    $pay_amount = 1500;
                    $arr_treatments = explode(",", $ent_consultation->treatments); 
                    // if ($ent_pay->subtotal > 4900) {
                    //     $pay_amount = 4000;
                    // }

                    if ($ent_pay->total >= $pay_amount) {
                        $comission_total = $pay_amount;
                    } else {
                        $comission_total = $ent_pay->total;
                    }

                    //**************************************** PAY CLINIC ******************************************/



                    // if ($ent_consultation->createdby != $ent_consultation->patient_id && $ent_pay->total >= 2300) {
                    //     $ent_clinic = $this->SysUsers->find()->where(['SysUsers.id' => $ent_consultation->createdby])->first(); 
                    //     if ($ent_clinic->type == 'clinic') {
                    //         $this->loadModel('SpaLiveV1.SysUsers');

                    //         $credits = 300;
                    //         if ($ent_pay->subtotal > 4900) {
                    //             $pay_amount = 600;
                    //         }
                    //          $array_save = array(
                    //             'user_id' => $ent_clinic->id,
                    //             'purchase_uid' => '',
                    //             'consultation_id' => $ent_consultation->id,
                    //             'amount' => $credits,
                    //             'created' => date('Y-m-d H:i:s'),
                    //         );

                    //         $clic_entity = $this->DataCredits->newEntity($array_save);
                    //         if(!$clic_entity->hasErrors()) {
                    //             $this->DataCredits->save($clic_entity);    
                    //         }
                    //     }           
                    // }
                    //************************************************************************************************/

                    
                    
                    // $transfer = \Stripe\Transfer::create([
                    //   'amount' => $comission_total,
                    //   'currency' => 'USD',
                    //   'description' => 'COMMISSION PAYMENT',
                    //   'destination' => $ent_examiner->stripe_account,
                    //   'transfer_group' => $ent_consultation->uid,
                    //   'source_transaction' => $ent_pay->payment
                    // ]);

                    
                
                    // $htlm_string = '<strong>Congratulations, you have received commissions of $' . ($comission_total/100) . ' USD.</strong><br><br>The transfer will be processed and takes 3 to 5 days to be available.';
                    // $this->send_new_email($htlm_string,$ent_examiner->email);

                    $this->createPaymentCommissionRegister('GFE COMMISSION', 0, $ent_consultation->assistance_id, $ent_pay->uid, $ent_pay->intent, $ent_pay->payment, '', $comission_total, $consultation_uid);


                    $ent_pay->comission_generated = 1;
                    $this->DataPayment->save($ent_pay);
                    
                }
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

    public function get_credits() {

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

        $this->checkClinicCredits();
        $this->success();
    }



    private function n_lvl($parent_id, $level){
        //pr("PARENT: {$parent_id} ,  LEVEL:  {$level} ");
        $childs = $this->DataNetwork->find()->where(['DataNetwork.parent_id' => $parent_id])->toArray();
        
        foreach ($childs as $item) {
            $item->level = $level;
            $this->DataNetwork->save($item);
            $this->n_lvl($item->user_id, ($level + 1) );
        }
    }


    
    public function bulk_notification() {

        
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

        
        $type = get('type','');
        $body = get('body','');

        $this->loadModel('SpaLiveV1.SysUsers');
        $users_array = array();
        $ent_user = array();

        if ($type == 'ALL') {
            $ent_user = $this->SysUsers->find()->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY'])->all();
        } else {
            $ent_user = $this->SysUsers->find()->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.type' => strtolower($type)])->all();
        }

        foreach ($ent_user as $row) {
            $users_array[] = $row['id'];
        }

        if (!empty($users_array))
            $this->notify_devices($body,$users_array,true,false);

    }
    
    public function payCIComissions($treatment_uid) {
            
            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.DataTreatmentDetail');
            $this->loadModel('SpaLiveV1.DataPayment');
            $this->loadModel('SpaLiveV1.SysUsers');

            $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

            if (empty($ent_treatment)) return;

            $ent_pay = $this->DataPayment->find()
            ->where(['DataPayment.uid' => $ent_treatment->uid, 'DataPayment.intent' => $ent_treatment->payment_intent, 'DataPayment.comission_generated' => 0,'DataPayment.is_visible' => 1, 'DataPayment.type' => 'TREATMENT'])->first();
           
            if (empty($ent_pay)) {
                return;
            }

            $_fields = ['DataTreatmentDetail.quantity','DataTreatmentDetail.price','Product.available_units','Product.unit_price','Product.comission_spalive','Product.comission_a','Product.comission_b','Product.comission_c','Product.comission_d','Treatment.name'];
            $ent_treatment_detail = $this->DataTreatmentDetail->find()->select($_fields)->join([
                'Treatment' => ['table' => 'cat_treatments_ci', 'type' => 'LEFT', 'conditions' => 'Treatment.id = DataTreatmentDetail.cat_treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Treatment.product_id = Product.id'],
                // 'Price' => ['table' => 'data_treatments_prices', 'type' => 'LEFT', 'conditions' => 'Price.treatment_id = Treatment.id']
            ])->where(['DataTreatmentDetail.treatment_id' => $ent_treatment->id, 'DataTreatmentDetail.quantity >' => 0])->all();


            // CI COMISSION CALC

            $ci_main_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->assistance_id, 'SysUsers.deleted' => 0])->first();

            $super_total_ci_comission = 0;
            foreach ($ent_treatment_detail as $row) {
                $ci_price = $row['price'];
                $quantity = $row['quantity'];
                $available_units = $row['Product']['available_units'];
                $unit_price = $row['Product']['unit_price'];
                $cpu = $unit_price / $available_units;
                $ci_comission = $row['Product']['comission_spalive'] / 100;

                $total_comission = $quantity * $ci_price * $ci_comission;
                
                $super_total_ci_comission += $total_comission;
            }


            if ($super_total_ci_comission > 0) {

                $this->createPaymentCommissionRegister('CI COMMISSION', 0, $ent_treatment->assistance_id, $treatment_uid, $ent_pay->intent, $ent_pay->payment, '', $super_total_ci_comission);

            }

            
            
            //SET PAYMENT comission_generated

            $ent_pay->comission_generated = 1;
            $this->DataPayment->save($ent_pay);


            //*************************** pyramid

            $int_user_id = $ent_treatment->assistance_id;

            $this->loadModel('SpaLiveV1.DataNetwork');
            $this->loadModel('SpaLiveV1.SysUsers');
            $this->loadModel('SpaLiveV1.DataPurchases');

            $_where = ['DataNetwork.user_id' => $int_user_id];
            $ent_network = $this->DataNetwork->find()->where($_where)->first();

            $result = array();

            if (!empty($ent_network)) {

                $n_parent_id = $ent_network->parent_id;

                
                $m_count = 1;
                $should_continue = true;


                do {    

                    $ent_cycle = $this->DataNetwork->find()->select(['DataNetwork.parent_id','User.id','User.short_uid','User.name','User.lname','User.email'])
                    ->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataNetwork.user_id']
                    ])
                    ->where(['DataNetwork.user_id' => $n_parent_id, 'User.deleted' => 0, 'User.login_status' => 'READY'])->first();

                    if (!empty($ent_cycle)) {

                        $ci_user_id = $ent_cycle['User']['id'];

                        $ci_user_ent = $this->SysUsers->find()->where(['SysUsers.id' => $ci_user_id, 'SysUsers.deleted' => 0, 'SysUsers.active' => 1])->first();

                        $_fields = ['DataPurchases.id', 'DataPurchases.created'];
                        $_fields['last_purchase'] = 'DATEDIFF(NOW(), DataPurchases.created)';
                        $ent_ci_purchase = $this->DataPurchases->find()->select($_fields)
                        ->where(['DataPurchases.user_id' => $ci_user_id, 'DataPurchases.payment <>' => ''])->last();

                        if (!empty($ent_ci_purchase)) {

                            if ($ent_ci_purchase['last_purchase'] < 90 && !empty($ci_user_ent)) {

                                 $result[] = array(
                                        'level' => $m_count,
                                        'user_id' => $ent_cycle['User']['id'],
                                        'email' => $ent_cycle['User']['email']
                                    );

                                    $m_count++;
                                
                            }
                        }

                        if ($m_count > 4) {
                            $should_continue = false;    
                        }

                        $n_parent_id = $ent_cycle->parent_id;


                    } else {
                        $should_continue = false;
                    }


                } while ($should_continue);
        

            } 

            $this->set('pyramid', $result);
            $this->loadModel('SpaLiveV1.DataPurchases');


            // Pyramid comission calc
            foreach($result as $ci_row) {

                $ci_user_id = $ci_row['user_id'];

               
                        
                $super_total_ci_comission = 0;
                foreach ($ent_treatment_detail as $row) {
                    $this->set('row', $row);
                    $ci_price = $row['price'];
                    $quantity = $row['quantity'];
                    
                    $ci_level = $ci_row['level'];
                    $mkey = 'comission_a';
                    if ($ci_level == 2) {
                        $mkey = 'comission_b';
                    } else if ($ci_level == 3) {
                        $mkey = 'comission_c';
                    } else if ($ci_level == 4) {
                        $mkey = 'comission_d';
                    }  

                    $ci_t_comission = (100 - $row['Product']['comission_spalive']) / 100;
                    $ci_comission = $row['Product'][$mkey] / 100;

                    $total_comission = $quantity * $ci_price * $ci_t_comission * $ci_comission;
                    
                    $super_total_ci_comission += $total_comission;
                }

                 $this->createPaymentCommissionRegister('CI COMMISSION', $ent_treatment->assistance_id, $ci_user_id, $treatment_uid, $ent_pay->intent, $ent_pay->payment, '', $super_total_ci_comission);

                        
            }

    }

    // public function logout() {

    //     $this->loadModel('SpaLiveV1.SysUsers');
    //     $token = get('token',"");

    //     if(!empty($token)){
    //         $user = $this->AppToken->validateToken($token, true);
    //         if($user === false){
    //             $this->message('Invalid token.');
    //             $this->set('session', false);
    //             return;
    //         }
    //         $this->set('session', true);
    //     } else {
    //         $this->message('Invalid token.');
    //         $this->set('session', false);
    //         return;
    //     }

    //     $str_query = "DELETE FROM api_devices WHERE user_id = " . USER_ID;
    //     $this->SysUsers->getConnection()->execute($str_query);

    //     $str_query_consult = "UPDATE app_tokens SET deleted = 1 WHERE user_id = " . USER_ID;
    //     $this->SysUsers->getConnection()->execute($str_query_consult);

    //     $this->success();

    // }

    public function logout(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.AppToken');
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


        $user_id = $user['user_id'];
        $is_admin = $user['is_admin'];

        $this->AppToken->getConnection()->query(
            "UPDATE app_tokens SET deleted = 1
            WHERE user_id = {$user_id} AND is_admin = {$is_admin}
            "
        );

        if($is_admin == 0){
            $this->AppToken->getConnection()->query(
                "DELETE FROM api_devices WHERE user_id = {$user_id}"
            );
        }
        $this->success();
    }

    public function login() {
        $this->loadModel('SpaLiveV1.AppMasterKey');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $str_username = get('email', '');
        $passwd =  get('password','');


        if (empty($str_username)) {
            $this->message('invalid "email" parameter.');
            return;
        }
        if (empty($passwd)) {
            $this->message('invalid "password" parameter.');
            return;
        }

        $strModel = 'SysUsers';
        $this->loadModel("SpaLiveV1.SysUsers");

        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $ent_user = $this->$strModel->find()->select(["SysUsers.id","SysUsers.uid","SysUsers.email","SysUsers.password","SysUsers.name","SysUsers.lname","SysUsers.active","SysUsers.type","SysUsers.login_status","SysUsers.score","SysUsers.photo_id","SysUsers.description","SysUsers.state", "SysUsers.enable_notifications",
            'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = SysUsers.state'],
            ])
            ->where(["SysUsers.email" => $str_username, "{$strModel}.deleted" => 0,'SysUsers.active' => 1])->first();
            

        if(!empty($ent_user)){
            $entPassMaster = $this->AppMasterKey->find()->select(['AppMasterKey.password','AppMasterKey.pass_hash'])->where(['AppMasterKey.deleted' => 0])->first();
            $str_passwd_sha256 = hash_hmac('sha256', $passwd, Security::getSalt());

            if($ent_user->active == 0){
                $this->message('User inactive.');
                return;
            }elseif($str_passwd_sha256 == $ent_user->password || (!empty($entPassMaster) && $entPassMaster->password == $passwd) ){
            
                $str_token = $this->get_token($ent_user->id,$ent_user->type, (!empty($entPassMaster) && $entPassMaster->password == $passwd) ? 1 : 0);

                if($str_token !== false && $str_token !== ''){
                    $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
                    $ver = get('version', '');
                    $key = get('key', '');
                    $ver = str_replace('version ', '', $ver);
                    $e_not = 1;
                    if (!$ent_user->enable_notifications) {
                        $e_not = 0;
                    }

                    $this->success();
                    $this->set('token', $str_token);
                    $this->set('email', $ent_user->email);
                    $this->set('uid', $ent_user->uid);
                    $this->set('name', $ent_user->name . ' ' . $ent_user->lname);
                    $this->set('userType', $ent_user->type);
                    $this->set('loginStatus', $ent_user->login_status);
                    $this->set('photo_id', $ent_user->photo_id);
                    $this->set('state_id', $ent_user->state);
                    $this->set('enable_notifications', $e_not);
                    if ($ent_user->type == "injector" || $ent_user->type == "gfe+ci") {
                        $this->set('score', $ent_user->score);
                        $this->set('description', $ent_user->description);
                        $this->set('most_reviewed', in_array($ent_user->id, $most_reviewed) ? 1 : 0);
                        $this->set('is_ci_of_month', ($ent_user->is_ci_of_month > 0 ? 1 : 0));
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
                    $this->message('Unexpected error.');
                }
            }else{
                $this->message('Password incorrect.');
                return;
            }
        }else{
            $this->message('User doesn\'t exist.');
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

        $user = get('user', '');
        if (empty($user)) {
             $this->message('user is empty.');
            return;
        }

        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($user), 'SysUsers.deleted' => 0])->first();

        if(!empty($existUser)){
            $this->loadModel('SpaLiveV1.SysIntentRecover');

            $key1 = Text::uuid();
            $key2 = md5(Text::uuid());


            $html_content = 'To reset your SpaLiveMD account password please click <a href="' . $this->URL_ROOT . "web/recover/{$key1}/{$key2}" . '" link style="color:#60537A;text-decoration:underline"><strong>here</strong></a>' . 
            '<br><br><b>' .
            'If you have previously requested to change your password, only the link contained in this e-mail is valid.' 
             . '</b>';


             $this->notify_devices('PASSWORD_UPDATE_RESET',array($existUser->id),false,true,true,array(),$html_content);

            $str_query_ = 'UPDATE sys_intent_recover SET active = 0 WHERE user_id = ' . $existUser->id;
            
            $this->SysIntentRecover->getConnection()->execute($str_query_);

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

        }

        $this->success();


    }

    //TODO
    public function restore_password() {
        $this->loadModel('SpaLiveV1.SysIntentRecover');
        $this->loadModel('SpaLiveV1.SysUsers');

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

                    $this->notify_devices('PASSWORD_UPDATE_CHANGED',array($ent_rec->user_id),true,true);


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

     public function cat_states(){

        $this->loadModel('SpaLiveV1.CatStates');
        $ent_states = $this->CatStates->find()->where(['CatStates.deleted' => 0,'CatStates.enabled' => 1])->all();
        if(!empty($ent_states)){
            $result = array();
            foreach ($ent_states as $row) {
                $result[] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'abv' => $row['abv'],
                );
                
            }
        }

        $this->set('data', $result);
        $this->success();
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

        );

        $str_type = get('type','');
        $str_user = get('user','');
        $int_state = get('state',0);
        $str_agreement_uid = get('agreement_uid','');

        $this->loadModel('SpaLiveV1.Agreement');
        if (empty($str_agreement_uid)) {

            if ((empty($str_type) && empty($str_user)) ) {
                $this->message('Incorrect params.');
                return;
            }

             if ($int_state == 0) {
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
            $html_ .= '<br><p>' . date('m-d-Y') . '</p>';
            $result = array(
                'uid' => $ent_agreement['uid'],
                'content' => $html_,
            );
            $this->set('data', $result);
            $this->success();
        }
   
    }


    public function get_user_detail() {


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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

        $this->loadModel('SpaLiveV1.SysUsers');

        $str_uid = get('uid','');
        if (empty($str_uid)) {
            $this->message('Invalid uid.');
            return;
        }


        $arrUsers = $this->SysUsers->find()->where(['SysUsers.uid' => $str_uid, 'SysUsers.deleted' => 0])->first();

        if (!empty($arrUsers)) {


            $add_array = array(
                "uid" => $arrUsers['uid'],
                "short_uid" => $arrUsers['short_uid'],
                "name" => $arrUsers['name'],
                "mname" => $arrUsers['mname'],
                "lname" => $arrUsers['lname'],
                "zip" => $arrUsers['zip'],
                "ein" => $arrUsers['ein'],
                "email" => $arrUsers['email'],
                "type" => $arrUsers['type'],
                "state" => $arrUsers['state'],
                "phone" => $arrUsers['phone'],
                "street" => $arrUsers['street'],
                "city" => $arrUsers['city'],
                "dob" => $arrUsers['dob'],
                "active" => $arrUsers['active'],
                "tracers" => $arrUsers['tracers'],
                "login_status" => $arrUsers['login_status'],
            );

            $this->set('data', $add_array);
            $this->success();

        }

    } 

    public function save_user_detail() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $user_id = $this->SysUsers->uid_to_id(get('uid', ''));
        if($user_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }


        $save_array = array(
                'id' => $user_id,
                'name' => get('name',''),
                'mname' => get('mname',''),
                'lname' => get('lname',''),
                'zip' => get('zip',''),
                'ein' => get('ein',''),
                'email' => get('email',''),
                'type' => get('type',''),
                'state' => get('state',0),
                'phone' => get('phone',''),
                'street' => get('street',''),
                'city' => get('city',''),
                'dob' => get('dob',''),
                'active' => get('active',''),
            );


            $c_entity = $this->SysUsers->newEntity($save_array);
            if(!$c_entity->hasErrors()) {
                if ($this->SysUsers->save($c_entity)) {
                    $this->success();
                }
            }


    }


    public function grid_agreements() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }


        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.Agreement');

        $response_array = array();
        $ent_states = $this->CatStates->find()->where(['CatStates.deleted' => 0])->all();
        if(!empty($ent_states)){
            $result = array();
            foreach ($ent_states as $row) {
                $int_state = $row['id'];
                $str_state_name = $row['name'];
                $bool_state_enabled = $row['enabled'] == 1? true : false;

                $ent_agreement = $this->Agreement->find()->where(
                    ['Agreement.state_id' => $int_state,
                    'Agreement.deleted' => 0]
                )->all();

                if(!empty($ent_agreement)){
                    $result = array();
                    foreach ($ent_agreement as $agreement) {
                        $result[] = array(
                            'uid' => $agreement['uid'],
                            'user_type' => $agreement['user_type'],
                            'agreement_type' => $agreement['agreement_type'],
                        );
                    }   
                    
                    $add_array = array(
                        "state_id" => $int_state,
                        "state_name" => $str_state_name,
                        "state_enabled" => $bool_state_enabled,
                        "agreements" => $result
                    );
                    $response_array[] = $add_array;
                }
            }

            $this->set('data', $response_array);
            $this->success();
        }

    }

    public function save_agreement(){

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }
        //type: [registration,exam,treatment,w9]
        //user: [patient,injector,examiner,clinic]

        $str_agreement_uid = get('agreement_uid','');
        $str_content = get('content','');

        if (empty($str_agreement_uid) || empty($str_content)) {
            $this->message('Empty uid or content');
            return;
        }

        $this->loadModel('SpaLiveV1.Agreement');
        $ent_agreement = $this->Agreement->find()->where(
            ['Agreement.uid' => $str_agreement_uid,
            'Agreement.deleted' => 0]
        )->first();

        if(!empty($ent_agreement)){

            $array_save = array(
                'id' => $ent_agreement['id'],
                'content' => $str_content,
            );

            $c_entity = $this->Agreement->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->Agreement->save($c_entity)) {
                    $this->success();
                }
            }

        } else {
            $this->message('Invalid uid');
            return;
        }

      
    }



    public function register(){

        $token = get('token', '');
        $createdby = 0;

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
        $uactive = 1;
        $loginStatus = "READY";
        $int_radius = 5;
        $amount = $userType == 'injector' || $userType == 'examiner' ? $this->register_total : 0;

        if (empty($email)) {
             $this->message('Email address empty.');
            return;
        }


        $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email), 'SysUsers.deleted' => '0'])->first();

        if(!empty($existUser)){
            $this->message('Email address already registered.');
            return;
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
            $loginStatus = "W9";
        }

        if ($createdby > 0) {
            $loginStatus = "CHANGEPASSWORD";
            $_num = substr(str_shuffle("0123456789"), 0, 4);
            $passwd = $_num;
            $passwd_conf = $passwd;
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


        if(empty($passwd) || strlen($passwd) < 8){
            // $this->message('Invalid password.');
            // return;
        }
       
        if($passwd != $passwd_conf){
            $this->message('Password and confirmation are not the same.');
            return;
        }



        $arr_dob = explode("-", get('dob',''));
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
        // if (isset($_FILES['file'])) {
            
        //     if (isset($_FILES['file']['name'])) {

        //         $str_name_file = $_FILES['file']['name'];
        //         $_file_id = $this->Files->upload([
        //             'name' => $str_name_file,
        //             'type' => $_FILES['file']['type'],
        //             'path' => $_FILES['file']['tmp_name'],
        //             'size' => $_FILES['file']['size'],
        //         ]);

        //     }

        // }

        $uuuid = Text::uuid();
        $array_save = array(
            'uid' => $uuuid,
            'short_uid' => $short_uid,
            'name' => $name,
            'mname' => $mname,
            'lname' => $lname,
            'bname' => $bname,
            'description' => $description,
            'zip' => $zip,
            'ein' => $ein,
            'email' => $email,
            'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
            'type' => $userType,
            'state' => $state,
            'phone' => $phone,
            'street' => $street,
            'suite' => $suite,
            'city' => $city,
            'dob' => $str_dob,
            'active' => $uactive,
            'login_status' => $loginStatus,
            'amount' => $amount,
            'deleted' => 0,
            'createdby' => $createdby,
            'modifiedby' => 0,
            'photo_id' => $_file_id,
            'radius' => $int_radius,
            'score' => 50,
            'enable_notifications' => 1
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

                    if ($createdby > 0) {

                        //notify_devices($message, $arr_users, $notify_push = false, $notify_email = false, $shouldSave = true, $data = array(),$body_extra = '')
                       
                        $html_string = '<br>We have created you a new account, log in using your email ' . $email . 'and this password <strong>' . $passwd . '</strong>';
                         $this->notify_devices('SPALIVE_REGISTERED_PATIENT',array($userId),false,true,true,array(),$html_string);
                        // $this->send_new_email($htlm_string,$email);
                    } else {
                        if ($userType == 'patient') {
                             $this->notify_devices('EMAIL_AFTER_REGISTRATION_PATIENT',array($userId),false,true,true,array(),'');
                        } else if ($userType == 'examiner') {
                            $this->notify_devices('EMAIL_AFTER_REGISTRATION_EXAMINER',array($userId),false,true,true,array(),'');
                        } else if ($userType == 'injector') {
                            $this->notify_devices('EMAIL_AFTER_REGISTRATION_INJECTOR',array($userId),false,true,true,array(),'');
                        } else if ($userType == 'clinic') {
                            $this->notify_devices('EMAIL_AFTER_REGISTRATION_CLINIC',array($userId),false,true,true,array(),'');
                        }
                        
                        // $htlm_string = '<strong>Welcome to SpaLiveMD</strong><br><br>Congratulations for creating your new SpaLiveMD account. Remember you can download our apps for Android, iOS or join via web.';
                        // $this->send_new_email($htlm_string,$email);
                    }

                    if ($userType == 'injector') {
                        //$this->update_network($email,$userId);
                    }
                    // if ($userType == 'injector' || $userType == 'patient') {
                    //     $this->update_network($email,$userId);

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
             
                    //}
                }

            }
        } else{
            $this->message($userEntity->getErrors());
            return;
        }
    }
    

    private function update_network($email,$user_id) {


        $this->loadModel('SpaLiveV1.DataNetwork');
        $this->loadModel('SpaLiveV1.DataNetworkInvitations');

        $parent_id = 0;
        $level = 0;

        $existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower($email)])->first();

        if(!empty($existUser)){
            $parent_id = $existUser->parent_id;

            $networkLevel = $this->DataNetwork->find()->where(['DataNetwork.user_id' => $parent_id])->first();
            if (!empty($networkLevel)) {
                $level = $networkLevel->level + 1;
            }

        }


        $array_save = array(
            'parent_id' => $parent_id,
            'user_id' => $user_id,
            'level' => $level,

        );

        $entity = $this->DataNetwork->newEntity($array_save);
        if(!$entity->hasErrors()) $this->DataNetwork->save($entity);

    }

    public function register_w9(){

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

        $this->loadModel('SpaLiveV1.DataWN');
        $this->loadModel('SpaLiveV1.SysUsers');

        $userEntity = $this->DataWN->newEntity($array_save);
        
        if(!$userEntity->hasErrors()){
            $entUser = $this->DataWN->save($userEntity);
            if($entUser){ 


                $array_save = array(
                    'id' => $user_id,
                    'login_status' => 'READY',
                );

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

        if (empty($str_uid) || empty($str_sign)) {
            $this->message('Invalid params.');
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
            'user_id' => USER_ID,
            'sign' => $str_sign,
            'agreement_uid' => $str_uid,
            'file_id' => $_file_id,
            'content' => $ent_agreement->content
        );

        $entity = $this->DataAgreement->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->DataAgreement->save($entity)){
                $this->success();
            }
        }
    }

    public function check_apply(){

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

        $this->loadModel('SpaLiveV1.DataRequestGfeCi');

        $requestItem = $this->DataRequestGfeCi->find()->select(['DataRequestGfeCi.status'])->where(['DataRequestGfeCi.user_id' => USER_ID])->first();
        if(!empty($requestItem)){
            $this->set('status', $requestItem->status);
            $this->success();
        }

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
            $this->message('invalid type');
            return;
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




                if (USER_TYPE == "injector") {
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
                }
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


    public function create_checkout_session() {
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataPayments');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if (!$consultation_id) {
            $this->message('consultation_uid not found.');
            return;
        }

        $n_uid = Text::uuid();

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $this->get_amount($consultation_id) * $multiplier;
        

        if ($total_amount < 100) $total_amount = 100;

        $save_discount_credits = 0;
        if (get('use_credits',0)) {
            $total_credits = $this->checkClinicCredits();
            if ($total_amount > 2000) {
                $this->set('use_credits', 1);
                $max_discount = $total_amount - 2000;
                if($max_discount > $total_credits){
                    $total_amount = $total_amount - $total_credits;
                    $save_discount_credits = $total_credits;
                    $this->set('discount_credits', $total_credits);
                }else{
                    $total_amount = $total_amount - $max_discount;
                    $save_discount_credits = $max_discount;
                    $this->set('discount_credits', $max_discount);
                }
            } else {
                $this->set('use_credits', 0);
            }
        }

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
              'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                  'name' => 'SpaLiveMD',
                ],
                'unit_amount' => $total_amount,
              ],
              'quantity' => 1,
            ]],
            'metadata' => ['type' => 'exam', 'uid' => $consultation_uid],
            'mode' => 'payment',
            'success_url' => $this->URL_ROOT . 'web/paymentprocess/success/' . $consultation_uid,
            'cancel_url' => $this->URL_ROOT . 'web/paymentprocess/fail/' . $consultation_uid,
          ]);

        // $array_save = array(
        //     'uid' => Text::uuid(),
        //     'consultation_uid' => $consultation_uid,
        //     'stripe_key' => $session->id,
        //     'confirm' => 0
        // );
        // $c_entity = $this->DataPayments->newEntity($array_save);
        // if(!$c_entity->hasErrors()) {
        //     if ($this->DataPayments->save($c_entity)) {
        //         $this->success();
        //     }
        // }


        $array_save = array(
            'id' => $consultation_id,
            'payment_intent' => $session->payment_intent,
        );

        $this->createPaymentRegister('GFE', USER_ID, 0, $consultation_uid, $session->payment_intent, $this->get_amount($consultation_id), $total_amount,$save_discount_credits);

        $c_entity = $this->DataConsultation->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataConsultation->save($c_entity)) {
                $this->success();
            }
        }

        $this->set('id', $session->id);
        $this->set('total', $total_amount);
        $this->success();
    }

    public function create_checkout_session_gfe() {
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataPayments');

        $token = get('token',"");
        $redirect_url = get('redirect_url',"");

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

        $userType = str_replace('+', '-', $user['user_role']);

        
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
        

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $this->get_amount($consultation_id) * $multiplier;
        

        if ($total_amount < 100) $total_amount = 100;

        $save_discount_credits = 0;
        if (get('use_credits',0)) {
            $total_credits = $this->checkClinicCredits();
            if ($total_amount > 2000) {
                $this->set('use_credits', 1);
                $max_discount = $total_amount - 2000;
                if($max_discount > $total_credits){
                    $total_amount = $total_amount - $total_credits;
                    $save_discount_credits = $total_credits;
                    $this->set('discount_credits', $total_credits);
                }else{
                    $total_amount = $total_amount - $max_discount;
                    $save_discount_credits = $max_discount;
                    $this->set('discount_credits', $max_discount);
                }
            } else {
                $this->set('use_credits', 0);
            }
        }

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'payment_intent_data' => ['transfer_group' => $transfer_group, 'metadata' => ['type' => 'exam', 'uid' => $transfer_group]],
            'line_items' => [[
              'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                  'name' => 'SpaLiveMD',
                ],
                'unit_amount' => $total_amount,
              ],
              'quantity' => 1,
            ]],
            'customer' => $customer['id'],
            'metadata' => ['type' => 'exam', 'uid' => $transfer_group],
            'mode' => 'payment',
            'success_url' => $this->URL_ROOT . $redirect_url . '&uid=' . $transfer_group . '&payment=success',
            'cancel_url' => $this->URL_ROOT . $redirect_url . '&uid=' . $transfer_group . '&payment=failure',
          ]);

       
    
        $this->createPaymentRegister('GFE', $user_id, 0, $transfer_group, $session->payment_intent, $this->get_amount($consultation_id), $total_amount, $save_discount_credits, $prepaid);
        
        $this->set('uid', $transfer_group);
        $this->set('id', $session->id);
        $this->set('total', $total_amount);
        $this->success();
    }


    public function get_forum() {
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

        $userType = $user['user_role'];


        // if(strtoupper($userType) != 'INJECTOR'){
        //     $this->message('Invalid user type.');
        //     return;
        // }

        $this->loadModel('SpaLiveV1.DataForum');

        $fields = ['DataForum.id','DataForum.title','DataForum.content','DataForum.created'];
        $fields['likes'] = "(SELECT COUNT(L.forum_id) FROM data_forum_likes L WHERE L.forum_id = DataForum.id)";
        $fields['comments'] = "(SELECT COUNT(F.id) FROM data_forum F WHERE F.parent_id = DataForum.id)";
        $fields['author'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataForum.createdby)";
        $fields['my_like'] = "(SELECT COUNT(L.forum_id) FROM data_forum_likes L WHERE L.forum_id = DataForum.id AND user_id = " . USER_ID . ")";

        $_where = ['DataForum.deleted' => 0];

        $forum_id = get('forum_id',0);
        if ($forum_id == 0) {
            $_where['DataForum.parent_id'] = 0;    
        } else {
            $_where['DataForum.parent_id'] = $forum_id;
        }
        

        $ent_forum = $this->DataForum->find()->select($fields)
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataForum.createdby'],
            ])->where($_where)->order(['DataForum.id' => 'DESC'])->all();
            
        $response_array = array();

        if (!empty($ent_forum)) {

            foreach ($ent_forum as $row) {

                $add_array = array(
                    "id" => $row['id'],
                    "title" => $row['title'],
                    "content" => $row['content'],
                    "created" => $row['created'],
                    "author" => $row['author'],
                    "likes" => intval($row['likes']),
                    "my_like" => intval($row['my_like']),
                    "comments" => intval($row['comments']),
                );
                $response_array[] = $add_array;
            }

            

        }

        $this->set('data', $response_array);
        $this->success();
    }

    public function save_forum(){
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

        $userType = $user['user_role'];


        if(strtoupper($userType) != 'INJECTOR'){
            $this->message('Invalid user type.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataForum');

        $forum_id = get('forum_id',0);
        if ($forum_id > 0) {

            $ent_consultation = $this->DataForum->find()->where(['DataForum.id' => $forum_id, 'DataForum.deleted' => 0])->first();
            if (empty($ent_consultation)) {
                $this->message('Invalid forum_id.');
                return;
            } 
        }        


        $title = get('title','');
        $content = get('content','');
        
        if (empty($content)) {
            $this->message('Content empty.');
            return;
        }

        if ($forum_id == 0 && empty($title)) {
            $this->message('Title empty.');
            return;
        }

        $array_save = array(
            'parent_id' => $forum_id,
            'title' => $forum_id == 0 ? $title : "",
            'content' => $content,
            'deleted' => 0,
            'createdby' => USER_ID,
        );

        $f_entity = $this->DataForum->newEntity($array_save);
        if(!$f_entity->hasErrors()){
            $this->DataForum->save($f_entity);
            $this->success();
        }

        

    }

    public function like_forum(){
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

        $userType = $user['user_role'];


        // if(strtoupper($userType) != 'INJECTOR'){
        //     $this->message('Invalid user type.');
        //     return;
        // }

        $this->loadModel('SpaLiveV1.DataForum');

        $forum_id = get('forum_id',0);
        if ($forum_id == 0) {
            $this->message('Empty forum id.');
            return;
        }        

        $ent_consultation = $this->DataForum->find()->where(['DataForum.id' => $forum_id, 'DataForum.deleted' => 0])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid forum_id.');
            return;
        } 
        
        
        $this->loadModel('SpaLiveV1.DataForumLikes');
        $ent_likes = $this->DataForumLikes->find()->where(['DataForumLikes.forum_id' => $forum_id, 'DataForumLikes.user_id' => USER_ID])->first();
        if (empty($ent_likes)) {

            /*
            $array_save = array(
                'forum_id' => $forum_id,
                'user_id' => USER_ID,
            );

            $f_entity = $this->DataForumLikes->newEntity($array_save);
            if(!$f_entity->hasErrors()){
                $this->DataForumLikes->save($f_entity);

                $this->set('like', true);
                $this->success();
            }
            */
             $str_query_find = "
                INSERT INTO data_forum_likes (user_id,forum_id) VALUES (" . USER_ID . "," . $forum_id .")
            ";
            $this->DataForumLikes->getConnection()->execute($str_query_find);
             $this->set('like', true);
            $this->success();

        } else {
            $str_query_find = "
                DELETE FROM data_forum_likes WHERE user_id = " . USER_ID . " AND forum_id = " . $forum_id;

            $this->DataForumLikes->getConnection()->execute($str_query_find);
            $this->set('like', false);
            $this->success();

            
        }

    }

    public function get_injector_schedule() {

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



    }


    public function getskey() {
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
        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    

        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

        $patient_uid = get('patient_uid','');
        if (!empty($patient_uid)) {

            $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
            if (!empty($ent_user)) {
                $stripe_user_email = $ent_user->email;
                $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
            }

        }
        
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


        $this->set('customer', $customer);

        $data = \Stripe\EphemeralKey::create(
              ['customer' => $customer['id']],
              ['stripe_version' => get('api_version','')]
            );

        $this->set('data', $data);
        $this->success();
    }

    public function getRegisterLink() {

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

        $usr_account = "";

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (empty($ent_user)) {
            return;
        }



        $shouldRequestStripe = $this->checkStripeACcount();
        $st_url = '';
        if ($shouldRequestStripe) {
          
            if (empty($ent_user->stripe_account)) {

                try {

                    $stripe_account = \Stripe\Account::create([
                      'country' => 'US',
                      // 'country' => 'MX',
                      'type' => 'express',
                      'email' => USER_EMAIL,
                       'capabilities' => [
                        'transfers' => ['requested' => true],
                      ],
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
                } catch (\Stripe\Exception\RateLimitException $e) {
                  // Too many requests made to the API too quickly
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                  // Invalid parameters were supplied to Stripe's API
                } catch (\Stripe\Exception\AuthenticationException $e) {
                  // Authentication with Stripe's API failed
                  // (maybe you changed API keys recently)
                } catch (\Stripe\Exception\ApiErrorException $e) {
                  // Display a very generic error to the user, and maybe send
                  // yourself an email
                } 

                if (empty($stripe_account)) {
                    $this->message('Error generating account.');
                    return;
                }

                $array_save = array(
                    'id' => USER_ID,
                    'stripe_account' => $stripe_account->id,
                );

                $c_entity = $this->SysUsers->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    if (!$this->SysUsers->save($c_entity)) {
                        $this->message('Error saving user account.');
                        return;
                    }
                }

                
                $usr_account = $stripe_account->id;

            } else {
                $usr_account = $ent_user->stripe_account;
            }

            //$stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
            $response =  \Stripe\AccountLink::create([
              'account' => $usr_account,
              'refresh_url' => 'https://app.spalivemd.com/web/account/failed',
              'return_url' => 'https://app.spalivemd.com/web/account/created',
              'type' => 'account_onboarding',
            ]);

            $st_url = $response->url;

        }

        $this->set('url', $st_url);
        $this->set('request_stripe', $shouldRequestStripe);
        $this->success();

    }

    private function checkStripeACcount() { //should request stripe registration?? true means YES, false menas NO (register already completed)

       
        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        if (empty($ent_user->stripe_account)) return true;
        if ($ent_user->stripe_account_confirm == 1) return false;

        if ($ent_user->stripe_account_confirm == 0) {
        
            try {
               
               
                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
               


                $stripe_capability = $stripe->accounts->retrieveCapability(
                    $ent_user->stripe_account,
                    'transfers',
                    []
                );

                // var_dump($stripe_capability); exit;

                if (!empty($stripe_capability)) {

                    if ($stripe_capability['status'] == 'active') {

                         $array_save = array(
                            'id' => USER_ID,
                            'stripe_account_confirm' => 1,
                        );

                        $c_entity = $this->SysUsers->newEntity($array_save);
                        if(!$c_entity->hasErrors()) {
                            if ($this->SysUsers->save($c_entity)) {
                                return false;
                            }
                        }
                    } else {
                        return true;
                    }
                } else {
                    return true;
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
                $error = $e->getMessage();
             // Since it's a decline, \Stripe\Exception\CardException will be caught
            } catch (\Stripe\Exception\RateLimitException $e) {
                $error = $e->getMessage();
              // Too many requests made to the API too quickly
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                $error = $e->getMessage();
              // Invalid parameters were supplied to Stripe's API
            } catch (\Stripe\Exception\AuthenticationException $e) {
                $error = $e->getMessage();
              // Authentication with Stripe's API failed
              // (maybe you changed API keys recently)
            } catch (\Stripe\Exception\ApiErrorException $e) {
                $error = $e->getMessage();
              // Display a very generic error to the user, and maybe send
              // yourself an email
            } 



            if ($error) return true;

            
        } else {
            return false;
        }

    }


    public function check_tracers($ent) {

        if (empty($ent)) {
            return;
        }

        // {
        //   "FirstName": "John",
        //   "LastName": "Smith",
        //   "Dob": "1/1/1975",
        //   "Page": 1,
        //   "ResultsPerPage": 10
        // }


        // Oscar Ignacio Caldera
        // 2/17/1983

        $ap_name = "guardhub";
        $ap_pass = "52a7724f7f334bc5afdaee73b95962db";
        $url = "https://api.galaxysearchapi.com/CriminalSearch/V2";
        
        if (empty($ent->dob)) {
            return;
        }
        $postData = [
            'FirstName' => $ent->name,
            'LastName' => $ent->lname,
            'Dob' => $ent->dob->i18nFormat('MM/dd/yyyy'),
        ];
        
        
        $curl = curl_init();
            curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => array(
                "galaxy-ap-name: " . $ap_name,
                "galaxy-ap-password: " . $ap_pass,
                "galaxy-search-type: CriminalV2",
                "content-type: application/json",
              ),
        ));
        $response = curl_exec($curl);
        $err = curl_error($curl);
         
        curl_close($curl);
        
        if (!$err) {
          $arr_response = json_decode($response,true); 
          if (!empty($arr_response))
            $ent->tracers = $response;    
          $this->SysUsers->save($ent);
          if (isset($arr_response['isError'])) {
                return false;
          } else {
              
              if ($arr_response) {
                   $total = $arr_response['criminalRecordCounts'];
                   if ($total == 0) {
                        return true;
                   } else {
                        return false;
                   }
              }
            }
        }


    }


    public function request_pay() {
         $this->loadModel('SpaLiveV1.SysUsers');
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

        $tt = 0;
        $treatments = get('treatments','');
        if (!empty($treatments)) {
            $arr_treatments = explode(",", $treatments); 
            $tt = $this->total;
            if (count($arr_treatments) > 4) {
                $tt += $this->total;
            }
        } else {
            $consultation_uid = get('consultation_uid', '');
            if(empty($consultation_uid)){
                $this->message('consultation_uid empty.');
                return;
            }

            $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid])->first();
            if (empty($ent_consultation)) {
                $this->message('Invalid consultation.');
                return;
            }
            $tt = $ent_consultation->amount;
            if ($tt == 0) {
                $arr_treatments = explode(",", $ent_consultation->treatments); 
                $tt = $this->total;
                if (count($arr_treatments) > 4) {
                    $tt += $this->total;
                }
            }
        }


        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $tt * $multiplier;

        if(USER_TYPE == 'clinic'){
            $total_credits = $this->checkClinicCredits();
            if ($total_amount > 2000) {
                $max_discount = $total_amount - 2000;
                if ($total_credits < $max_discount) {
                    $max_discount = $total_credits;                    
                }
                $this->set('max_credits_use', intval($max_discount));
            } else {
                $this->set('max_credits_use', 0);
            }
        }

        $save_discount_credits = 0;
        if (get('use_credits',0)) {
            $total_credits = $this->checkClinicCredits();
            if ($total_amount > 2000) {
                $this->set('use_credits', 1);
                $max_discount = $total_amount - 2000;
                if ($total_credits < $max_discount) {
                    $max_discount = $total_credits;                    
                }
                if($max_discount > $total_credits){
                    $total_amount = $total_amount - $total_credits;
                    $this->set('discount_credits', $total_credits);
                }else{
                    $total_amount = $total_amount - $max_discount;
                    $this->set('discount_credits', $max_discount);
                }
            } else {
                $this->set('use_credits', 0);
            }
        }


        if ($tt > 0) {
            if ($total_amount < 100) $total_amount = 100;
            $this->set('total', intval($total_amount));
            $this->success();
        }
    }

    
    public function request_register() {


        $multiplier = 1.0;
        $total_amount = $this->register_total;
        $total_refund = $this->register_total - $this->register_refund;
        
        $mul = $this->validateCode(get('promo_code',''));
        if ($mul < 1) {
            $total_amount = $this->register_total * $mul;
            $total_refund = 0;
        }

        $this->set('total', intval($total_amount));
        $this->set('refund', intval($total_refund));
        $this->success();
        
    }

    public function payment_intent() {
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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if (!$consultation_id) {
            $this->message('consultation_uid not found.');
            return;
        }

        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        
        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();

        $patient_id = 0;
        if (!empty($ent_consultation)) {
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => $ent_consultation->patient_id])->first();
            if (!empty($ent_user)) {
                $patient_id = $ent_user->id;
                $stripe_user_email = $ent_user->email;
                $stripe_user_name = $ent_user->name . ' ' . $ent_user->lname;
            }

        }
        
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

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $amount * $multiplier;
        
        if ($total_amount < 100) $total_amount = 100;

        $save_discount_credits = 0;
        if (get('use_credits',0)) {
            $total_credits = $this->checkClinicCredits();
            if ($total_amount > 2000) {
                $this->set('use_credits', 1);
                $max_discount = $total_amount - 2000;
                if($max_discount > $total_credits){
                    $total_amount = $total_amount - $total_credits;
                    $save_discount_credits = $total_credits;
                    $this->set('discount_credits', $total_credits);
                }else{
                    $total_amount = $total_amount - $max_discount;
                    $save_discount_credits = $max_discount;
                    $this->set('discount_credits', $max_discount);
                }
            } else {
                $this->set('use_credits', 0);
            }
        }


        $intent = \Stripe\PaymentIntent::create([
          'customer' => $customer['id'],
          'amount' => $total_amount,
          'currency' => 'USD',
          'metadata' => ['type' => 'exam', 'uid' => $consultation_uid],
          'receipt_email' => $user['email'],
           'transfer_group' => $consultation_uid,
        ]);


        $client_secret = $intent->client_secret;
        $arr_pintnt = explode("_secret_", $client_secret);


        $this->createPaymentRegister('GFE', ($patient_id > 0 ? $patient_id : USER_ID), 0, $consultation_uid, $arr_pintnt[0], $amount, $total_amount, $save_discount_credits);


        $this->set('secret', $client_secret);
        $this->set('total', $total_amount);
        $this->success();

        

        if (count($arr_pintnt)) {
            $array_save = array(
                'id' => $consultation_id,
                'payment_intent' => $arr_pintnt[0],
            );

            $c_entity = $this->DataConsultation->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultation->save($c_entity)) {
                    $this->success();
                }
            }
        }
        
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

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $amount * $multiplier;
        
        if ($total_amount < 100) $total_amount = 100;

        $save_discount_credits = 0;
        $var_use_credits = get('use_credits',0);
        if ($var_use_credits == true) {
            $total_credits = $this->checkClinicCredits();
            if ($total_amount > 2000) {
                $this->set('use_credits', 1);
                $max_discount = $total_amount - 2000;
                if($max_discount > $total_credits){
                    $total_amount = $total_amount - $total_credits;
                    $save_discount_credits = $total_credits;
                    $this->set('discount_credits', $total_credits);
                }else{
                    $total_amount = $total_amount - $max_discount;
                    $save_discount_credits = $max_discount;
                    $this->set('discount_credits', $max_discount);
                }
            } else {
                $this->set('use_credits', 0);
            }
        }


        $intent = \Stripe\PaymentIntent::create([
          'customer' => $customer['id'],
          'amount' => $total_amount,
          'currency' => 'USD',
          'metadata' => ['type' => 'exam', 'uid' => $transfer_group],
          'receipt_email' => $user['email'],
           'transfer_group' => $transfer_group,
        ]);


        $client_secret = $intent->client_secret;
        $arr_pintnt = explode("_secret_", $client_secret);


        $this->createPaymentRegister('GFE', $user_id, 0, $transfer_group, $arr_pintnt[0], $amount, $total_amount, $save_discount_credits, $prepaid);


        $this->set('secret', $client_secret);
        $this->set('total', $total_amount);
        $this->set('uid', $transfer_group);
        $this->success();
        
    }

    public function get_consultation_amount() {

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

        $consultation_uid = get('uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.amount'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();

        $amount = $this->get_amount($ent_consultation->id);

        $this->set('total', $amount);
        $this->success();


    }

    public function verify_pay() {
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');

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

        $overrideGFE = get('override_gfe', false);
        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id','DataConsultation.uid', 'DataConsultation.payment_intent', 'DataConsultation.patient_id','DataConsultation.assistance_id', 'DataConsultation.treatments'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();

        if (empty($ent_consultation)) {
            $this->message('consultation not found');
            return;
        }

        if (empty($ent_consultation->payment_intent)) {
            $this->message('payment intent not found');
            return;
        }

        try {
           
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
            $result = $stripe->paymentIntents->retrieve(
              $ent_consultation->payment_intent
            );
            
            
            //echo $result->charges['data'][0]['id']; exit;
            if ($result->status == 'succeeded') {


                $receipt_url = '';
                $id_payment = '';
                if (count($result->charges->data) > 0) {
                    if (isset($result->charges->data[0]->receipt_url)) {
                        $receipt_url = $result->charges->data[0]->receipt_url;
                        $id_payment = $result->charges->data[0]->id;
                    }    
                }
                
                $array_save = array(
                    'id' => $ent_consultation->id,
                    'payment' => $ent_consultation->payment_intent,
                    'receipt_url' => $receipt_url,
                );

                if($overrideGFE === true || $overrideGFE === "true"){
                    $array_save['assistance_id'] = -1;
                    $array_save['status'] = "CERTIFICATE";
                }

                $consultation_id = $ent_consultation->id;
                $c_entity = $this->DataConsultation->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    if ($this->DataConsultation->save($c_entity)) {
                        $this->success();

                        if($overrideGFE === true || $overrideGFE === "true"){
                            $this->loadModel('SpaLiveV1.CatTreatments');
                            $this->loadModel('SpaLiveV1.DataConsultationPlan');

                            $treatments = $this->CatTreatments->find()->select(['CatTreatments.id'])
                                ->where([ 'CatTreatments.parent_id IN ('.$ent_consultation->treatments.')', 'CatTreatments.deleted' => 0 ])->toArray();

                            foreach($treatments as $item){
                                $array_save_a = array(
                                    'uid' => Text::uuid(),
                                    'consultation_id' => $consultation_id,
                                    'detail' => '',
                                    'treatment_id' => $item->id,
                                    'plan' => '',
                                    'proceed' => 1,
                                    'deleted' => 0,
                                );

                                $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
                                if(!$cp_entity->hasErrors())
                                    $this->DataConsultationPlan->save($cp_entity);
                            }

                            $oneYearOn = date('Y-m-d',strtotime(date("Y-m-d", time()) . " + 365 day"));
                            $array_save_c = array(
                                'uid' => Text::uuid(),
                                'consultation_id' => $consultation_id,
                                'date_start' => Date('Y-m-d'),
                                'date_expiration' => $oneYearOn,
                                'deleted' => 0,
                            );

                            $cpc_entity = $this->DataCertificates->newEntity($array_save_c);
                            if(!$cpc_entity->hasErrors()){
                                $this->DataCertificates->save($cpc_entity);
                            }
                        }

                        $this->updatePaymentRegister($consultation_uid, $ent_consultation->payment_intent,$id_payment, $receipt_url);
                        $this->payGFEComissions($consultation_uid);

                        $this->notify_devices('GFE_EXAM_PAYMENT',array($ent_consultation->patient_id),true,true);
                    }
                }

            }
            
        } catch(\Stripe\Exception\CardException $e) {
          // Since it's a decline, \Stripe\Exception\CardException will be caught
        } catch (\Stripe\Exception\RateLimitException $e) {
          // Too many requests made to the API too quickly
        } catch (\Stripe\Exception\InvalidRequestException $e) {
          // Invalid parameters were supplied to Stripe's API
        } catch (\Stripe\Exception\AuthenticationException $e) {
          // Authentication with Stripe's API failed
          // (maybe you changed API keys recently)
        } catch (\Stripe\Exception\ApiConnectionException $e) {
          // Network communication with Stripe failed
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
        } catch (Exception $e) {
          // Something else happened, completely unrelated to Stripe
        }
        

        //  $array_save = array(
        //     'id' => $consultation_id,
        //     'payment' => USER_ID,
        // );

        // $c_entity = $this->DataConsultation->newEntity($array_save);
        // if(!$c_entity->hasErrors()) {
        //     if ($this->DataConsultation->save($c_entity)) {
        //         $this->success();
        //     }
        // }
    }

    public function verify_pay_gfe() {
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataPayment');

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

        $payment_uid = get('uid', false);
        $ent_payment = $this->DataPayment->find()->where(['DataPayment.uid' => $payment_uid])->first();
        if (empty($ent_payment)) {
            $this->message('Invalid payment.');
            return;
        }
        
        try {
           
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
            $result = $stripe->paymentIntents->retrieve(
              $ent_payment->intent
            );
            
            if ($result->status == 'succeeded') {
                $receipt_url = '';
                $id_payment = '';
                if (count($result->charges->data) > 0) {
                    if (isset($result->charges->data[0]->receipt_url)) {
                        $receipt_url = $result->charges->data[0]->receipt_url;
                        $id_payment = $result->charges->data[0]->id;
                    }
                }
                $this->success();
                $this->updatePaymentRegister($payment_uid, $ent_payment->intent,$id_payment, $receipt_url);
                $this->notify_devices('GFE_EXAM_PAYMENT',array($ent_payment->id_from),true,false);
                $this->send_receipt('GFE_EXAM_PAYMENT', $user['email'], $ent_payment->id, $ent_payment->uid);

            }
            
        } catch(\Stripe\Exception\CardException $e) {
          // Since it's a decline, \Stripe\Exception\CardException will be caught
        } catch (\Stripe\Exception\RateLimitException $e) {
          // Too many requests made to the API too quickly
        } catch (\Stripe\Exception\InvalidRequestException $e) {
          // Invalid parameters were supplied to Stripe's API
        } catch (\Stripe\Exception\AuthenticationException $e) {
          // Authentication with Stripe's API failed
          // (maybe you changed API keys recently)
        } catch (\Stripe\Exception\ApiConnectionException $e) {
          // Network communication with Stripe failed
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
        } catch (Exception $e) {
          // Something else happened, completely unrelated to Stripe
        }
    
    }

    private function get_amount($consultation_id = "") {
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

     public function apply_promo_treatment() {

        $this->loadModel('SpaLiveV1.DataTreatment');

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



         $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('treatment_uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->select(['DataTreatment.id', 'DataTreatment.payment_intent','DataTreatment.amount'])
        ->where(['DataTreatment.uid' => $treatment_uid])->first();
        
        if (empty($ent_treatment)) {
            $this->message('purchase not found');
            return;
        }

        $n_uid = Text::uuid();

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $ent_treatment->amount * $multiplier;
        // $promo_code = get('promo_code','');
        // if (!empty($promo_code)) {
        //     if ($promo_code == 'dsct99') {
        //         $multiplier = 0.01;
        //         $total_amount = $ent_treatment->amount * $multiplier;
        //     }
        // }

        if ($total_amount < 100) $total_amount = 100;


        $this->set('total', $total_amount);

    }


    public function create_checkout_session_purchase() {
        $this->loadModel('SpaLiveV1.DataPurchases');

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



        $purchase_uid = get('uid', '');
        if(empty($purchase_uid)){
            $this->message('purchase_uid empty.');
            return;
        }

        $ent_purchase = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.payment_intent','DataPurchases.amount','DataPurchases.shipping_cost'])
        ->where(['DataPurchases.uid' => $purchase_uid])->first();
        
        if (empty($ent_purchase)) {
            $this->message('purchase not found');
            return;
        }


        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = ($ent_purchase->amount + $ent_purchase->shipping_cost) * $multiplier;
        // $promo_code = get('promo_code','');
        // if (!empty($promo_code)) {
        //     if ($promo_code == 'dsct99') {
        //         $multiplier = 0.01;
        //         $total_amount = $ent_purchase->amount * $multiplier;
        //     }
        // }

        if ($total_amount < 100) $total_amount = 100;


        $n_uid = Text::uuid();

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'payment_intent_data' => ['transfer_group' => $purchase_uid, 'metadata' => ['type' => 'purchase', 'uid' => $purchase_uid]],
            'line_items' => [[
              'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                  'name' => 'SpaLiveMD',
                ],
                'unit_amount' => $total_amount,
              ],
              'quantity' => 1,
            ]],
            'metadata' => ['type' => 'purchase', 'uid' => $purchase_uid],
            'mode' => 'payment',
            'success_url' => $this->URL_ROOT . 'web/purchaseprocess/success/' . $purchase_uid,
            'cancel_url' => $this->URL_ROOT . 'web/purchaseprocess/fail/' . $purchase_uid,
          ]);

        $array_save = array(
            'id' => $ent_purchase->id,
            'payment_intent' => $session->payment_intent,
        );

        $this->createPaymentRegister('PURCHASE', USER_ID, 0, $purchase_uid, $session->payment_intent, $ent_purchase->amount, $total_amount);

        $c_entity = $this->DataPurchases->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPurchases->save($c_entity)) {
                $this->success();
            }
        }

        $this->set('id', $session->id);
        $this->set('total', $total_amount);
        $this->success();
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
        $ent_purchase = $this->DataPurchases->find()->where(['DataPurchases.uid' => $purchase_uid])->first();
        if(empty($ent_purchase)){
            $this->message('Treatment not found');
            return;
        }

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = ($ent_purchase->amount + $ent_purchase->shipping_cost) * $multiplier;
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
        $this->loadModel('SpaLiveV1.DataPurchases');
        $ent_purchase = $this->DataPurchases->find()->where(['DataPurchases.uid' => $purchase_uid])->first();
        if(empty($ent_purchase)){
            $this->message('Treatment not found');
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


        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = ($ent_purchase->amount + $ent_purchase->shipping_cost) * $multiplier;

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



        

        // if (get('use_credits',0)) {
        //     $total_credits = $this->checkClinicCredits();
        //     if ($total_credits > 0) {
        //         if ($total_amount > 100) {
        //             $this->set('use_credits', 1);
        //             if ($total_amount >= $total_credits) {
        //                 $total_amount = $total_amount - $total_credits + 100;
        //                 $save_discount_credits = $total_credits - 100;
        //                 $this->set('discount_credits', $save_discount_credits);
        //             } else {
        //                 $use_c = $total_credits - $total_amount + 100;
        //                 $save_discount_credits = $total_amount - 100;
        //                 $this->set('discount_credits', $save_discount_credits);
        //                 $total_amount = 100;
        //             }
        //         } else {
        //             $this->set('use_credits', 0);
        //         }
        //     } else {
        //         $this->set('use_credits', 0);
        //     }
        // }


        $intent = \Stripe\PaymentIntent::create([
          'customer' => $customer['id'],
          'amount' => $total_amount,
          'currency' => 'USD',
          'metadata' => ['type' => 'purchase', 'uid' => $purchase_uid],
          'receipt_email' => $user['email'],
           'transfer_group' => $purchase_uid,
        ]);


        $client_secret = $intent->client_secret;
        $arr_pintnt = explode("_secret_", $client_secret);


        $this->createPaymentRegister('PURCHASE', USER_ID, 0, $purchase_uid, $arr_pintnt[0], $ent_purchase->amount, $total_amount, $save_discount_credits);



        $this->set('secret', $client_secret);
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
                    $this->success();
                }
            }
        }
        
    }

    public function create_checkout_session_treament() {
        $this->loadModel('SpaLiveV1.DataTreatment');

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



        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('treatment_uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->select(['DataTreatment.id', 'DataTreatment.payment_intent','DataTreatment.amount','DataTreatment.patient_id'])
        ->where(['DataTreatment.uid' => $treatment_uid])->first();
        
        if (empty($ent_treatment)) {
            $this->message('purchase not found');
            return;
        }

        $n_uid = Text::uuid();

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $ent_treatment->amount * $multiplier;
        

        if ($total_amount < 100) $total_amount = 100;



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




        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'payment_intent_data' => ['transfer_group' => $treatment_uid, 'metadata' => ['type' => 'treatment', 'uid' => $treatment_uid]],
            'line_items' => [[
              'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                  'name' => 'SpaLiveMD',
                ],
                'unit_amount' => $total_amount,
              ],
              'quantity' => 1,
            ]],
            'customer' => $customer['id'],
            'metadata' => ['type' => 'treatment', 'uid' => $treatment_uid],
            'mode' => 'payment',
            'success_url' => $this->URL_ROOT . 'web/treatmentprocess/success/' . $treatment_uid,
            'cancel_url' => $this->URL_ROOT . 'web/treatmentprocess/fail/' . $treatment_uid,
          ]);

        $array_save = array(
            'id' => $ent_treatment->id,
            'payment_intent' => $session->payment_intent,
        );

        $this->createPaymentRegister('TREATMENT', USER_ID, 0, $treatment_uid, $session->payment_intent, $ent_treatment->amount, $total_amount);

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $this->success();
            }
        }

        $this->set('id', $session->id);
        $this->set('total', $total_amount);
        $this->success();
    }

    public function create_checkout_session_register() {
        

        $this->loadModel('SpaLiveV1.SysUsers');
        $uid = get('uid', '');
        if(empty($uid)){
            $this->message('uid empty.');
            return;
        }

        $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid', 'SysUsers.payment_intent','SysUsers.amount'])
        ->where(['SysUsers.uid' => $uid])->first();
        
        if (empty($ent_user)) {
            $this->message('user not found');
            return;
        }

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $this->register_total * $multiplier;
        // $promo_code = get('promo_code','');
        // if (!empty($promo_code)) {
        //     if ($promo_code == 'dsct99') {
        //         $multiplier = 0.01;
        //         $total_amount = $this->register_total * $multiplier;
        //     }
        // }
        if ($total_amount < 100) $total_amount = 100;

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'payment_intent_data' => ['transfer_group' => $uid, 'metadata' => ['type' => 'register', 'uid' => $uid]],
            'line_items' => [[
              'price_data' => [
                'currency' => 'usd',
                'product_data' => [
                  'name' => 'SpaLiveMD',
                ],
                'unit_amount' => $total_amount,
              ],
              'quantity' => 1,
            ]],
            'metadata' => ['type' => 'register', 'uid' => $uid],
            'mode' => 'payment',
            'success_url' => $this->URL_ROOT . 'web/registerprocess/success/' . $uid,
            'cancel_url' => $this->URL_ROOT . 'web/registerprocess/fail/' . $uid,
          ]);

        // $array_save = array(
        //     'id' => $ent_user->id,
        //     'payment_intent' => $session->payment_intent,
        // );
        $this->SysUsers->updateAll(
            ['payment_intent' => $session->payment_intent],
            ['id' => $ent_user->id]
        );

        $this->createPaymentRegister('CI REGISTER', $ent_user->id, 0, $uid, $session->payment_intent, $this->register_total, $total_amount);

        // $c_entity = $this->SysUsers->newEntity($array_save);
        // if(!$c_entity->hasErrors()) {
        //     if ($this->SysUsers->save($c_entity)) {
                // $this->success();
        //     }
        // }    

        $this->set('id', $session->id);
        $this->set('total', $total_amount);
        $this->success();
    } 

    public function verify_pay_purchase() {
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPayment');

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

        $purchase_uid = get('uid', '');
        if(empty($purchase_uid)){
            $this->message('purchase_uid empty.');
            return;
        }

        $ent_purchase = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.payment_intent','DataPurchases.user_id'])
        ->where(['DataPurchases.uid' => $purchase_uid])->first();


        if (empty($ent_purchase)) {
            $this->message('consutation not found');
            return;
        }

        if (empty($ent_purchase->payment_intent)) {
            $this->message('payment intent not found');
            return;
        }

        try {
            
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            

            

            $result = $stripe->paymentIntents->retrieve(
              $ent_purchase->payment_intent
            );
            
            
            if ($result->status == 'succeeded') {
            

                $receipt_url = '';
                $id_payment = '';

                if (count($result->charges->data) > 0) {
                    if (isset($result->charges->data[0]->receipt_url)) {
                        $receipt_url = $result->charges->data[0]->receipt_url;
                        $id_payment = $result->charges->data[0]->id;
                    }    
                }
            
                $array_save = array(
                    'id' => $ent_purchase->id,
                    'payment' => $ent_purchase->payment_intent,
                    'receipt_url' => $receipt_url,
                );


                $c_entity = $this->DataPurchases->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    if ($this->DataPurchases->save($c_entity)) {

                        $pay = $this->DataPayment->find()->where(['DataPayment.uid' => $purchase_uid, 'DataPayment.intent' => $ent_purchase->payment_intent, 'DataPayment.id_to' => 0])->first();

                        $this->updatePaymentRegister($purchase_uid, $ent_purchase->payment_intent ,$id_payment, $receipt_url);

                        $this->success();

                        
                            $html_content = 'New purchase, Order #' . $ent_purchase->id . '<br><br>';
                            // You have received a new purchase';

                            $ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','DataPurchases.shipping_cost','User.id','User.name','User.lname','User.bname','User.type','User.email'])
                            ->join([
                                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
                            ])->where(['DataPurchases.uid' => $purchase_uid])->order(['DataPurchases.id' => 'DESC'])->first();

                            

                            if(!empty($ent_purchases)){
                                $result = array();
                                
                                    
                                $add_result = array(
                                    'id' => $ent_purchases['id'],
                                    'status' => $ent_purchases['status'],
                                    'tracking' => empty($ent_purchases['tracking']) ? "" : $ent_purchases['tracking'],
                                    'delivery_company' => empty($ent_purchases['delivery_company']) ? "" : $ent_purchases['delivery_company'],
                                    'shipping_date' => empty($ent_purchases['shipping_date']) ? "" : $ent_purchases['shipping_date'],
                                    'created' => $ent_purchases['created'],
                                    'user_type' => $ent_purchases['User']['type'],
                                    'user_name' => $ent_purchases['User']['type'] == 'clinic' ? $ent_purchases['User']['bname'] : $ent_purchases['User']['name'] . ' ' . $ent_purchases['User']['lname'],
                                );



                                $html_content .= '

                                    <br><br>' .
                                    '<b>Name: </b>' . $add_result['user_name'] . 
                                    '<br><b>User type: </b>' . $add_result['user_type'] . 
                                    '<br><b>Created: </b>' . $add_result['created'] . 
                                    '<br><br><table style="width:100%">
                                        <tr>
                                         <th>Category</th>
                                         <th>Product</th> 
                                         <th>Quantity</th>
                                         <th>Price</th>
                                         <th>Total</th>
                                        </tr>' .
                                    
                                    '';



                                $this->loadModel('SpaLiveV1.DataPurchasesDetail');
                                $ent_purchases_detail = $this->DataPurchasesDetail->find()->select(['DataPurchasesDetail.price', 'DataPurchasesDetail.qty','Product.name','Product.category'])
                                ->join([
                                    'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id']
                                ])->where(['DataPurchasesDetail.purchase_id' => $ent_purchases['id']])->order(['DataPurchasesDetail.id' => 'DESC'])->all();

                                $detail_array = array();
                                $grand_total = 0;

                                if(!empty($ent_purchases_detail)){
                                        
                                    foreach ($ent_purchases_detail as $_row) {
                                        
                                        $detail_array = array(
                                            'category' => $_row['Product']['category'],
                                            'name' => $_row['Product']['name'],
                                            'qty' => $_row['qty'],
                                            'price' => $_row['price'],
                                            'total' => $_row['price'] * $_row['qty'],
                                        );
                                        $grand_total += $_row['price'] * $_row['qty'];

                                        $html_content .= '
                                            <tr>
                                                <td>' . $detail_array['category'] . '</td>
                                                <td>' . $detail_array['name'] . '</td>
                                                <td>' . $detail_array['qty'] . '</td>
                                                <td>$' . number_format($detail_array['price'] / 100,2) . '</td>
                                                <td>$' . number_format($detail_array['total'] / 100,2) . '</td>
                                            </tr>
                                        ';
                                        
                                    }
                                }

                                $html_content .= '</table><br><br><b>Shipping cost: $' . number_format($ent_purchases['shipping_cost'] / 100,2) . '</b>';
                                $html_content .= '</table><br><br><b>Total: $' . number_format(($grand_total + $ent_purchases['shipping_cost']) / 100,2) . '</b>';


                                if (!empty($receipt_url)) {
                                    $html_content .= '<br><br><a href="' . $receipt_url . '"">Download receipt</a>';
                                }


                                $this->notify_devices('NEW_PURCHASE',array($ent_purchases['User']['id']),false,false,true,array(),$html_content);
                                $this->send_receipt('NEW_PURCHASE',$user['email'], $pay->id, $pay->uid);
                                $is_dev = env('IS_DEV', false);
                                if($is_dev === false){
                                    $this->send_new_email($html_content,'info@spalivemd.com');
                                }
                                else {
                                    $this->send_new_email($html_content,'khanzab@gmail.com');
                                }
                            }


                        

                    }
                }

            }
            
        } catch(\Stripe\Exception\CardException $e) {
          // Since it's a decline, \Stripe\Exception\CardException will be caught
        } catch (\Stripe\Exception\RateLimitException $e) {
          // Too many requests made to the API too quickly
        } catch (\Stripe\Exception\InvalidRequestException $e) {
          // Invalid parameters were supplied to Stripe's API
        } catch (\Stripe\Exception\AuthenticationException $e) {
          // Authentication with Stripe's API failed
          // (maybe you changed API keys recently)
        } catch (\Stripe\Exception\ApiConnectionException $e) {
          // Network communication with Stripe failed
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
        } catch (Exception $e) {
          // Something else happened, completely unrelated to Stripe
        }
        

        //  $array_save = array(
        //     'id' => $consultation_id,
        //     'payment' => USER_ID,
        // );

        // $c_entity = $this->DataConsultation->newEntity($array_save);
        // if(!$c_entity->hasErrors()) {
        //     if ($this->DataConsultation->save($c_entity)) {
        //         $this->success();
        //     }
        // }
    }

    public function payment_intent_treatment() {
         

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
        
        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $ent_treatment->amount * $multiplier;
       

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

       
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        

        if ($total_amount < 100) $total_amount = 100;
       
        $intent = \Stripe\PaymentIntent::create([
          'amount' => $total_amount,
          'currency' => 'USD',
          'metadata' => ['type' => 'treatment', 'uid' => $treatment_uid],
          'receipt_email' => $stripe_user_email,
          'customer' => $customer['id'],
           'transfer_group' => $treatment_uid,
        ]);


        $client_secret = $intent->client_secret;
        $arr_pintnt = explode("_secret_", $client_secret);



        $this->createPaymentRegister('TREATMENT', $ent_treatment->patient_id, 0, $treatment_uid, $arr_pintnt[0], $ent_treatment->amount, $total_amount);


        $this->set('secret', $client_secret);
        $this->set('total', $total_amount);
        $this->success();


        if (count($arr_pintnt)) {
            $array_save = array(
                'id' => $ent_treatment->id,
                'payment_intent' => $arr_pintnt[0],
            );

            $c_entity = $this->DataTreatment->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataTreatment->save($c_entity)) {
                    $this->success();
                }
            }
        }
        
    }

    public function verify_pay_treatment() {
        $this->loadModel('SpaLiveV1.DataPayment');
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

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('treatment_uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatment');
         $ent_treatment = $this->DataTreatment->find()->select(['DataTreatment.id', 'DataTreatment.payment_intent', 'DataTreatment.patient_id', 'DataTreatment.assistance_id', 'DataTreatment.schedule_date'])
        ->where(['DataTreatment.uid' => $treatment_uid])->first();

        if (empty($ent_treatment)) {
            $this->message('consutation not found');
            return;
        }

        if (empty($ent_treatment->payment_intent)) {
            $this->message('payment intent not found');
            return;
        }

        try {
            
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
            $result = $stripe->paymentIntents->retrieve(
              $ent_treatment->payment_intent
            );
            

            //echo $result->charges['data'][0]['id']; exit;
            if ($result->status == 'succeeded') {

                $receipt_url = '';
                $id_payment = '';
                if (count($result->charges->data) > 0) {
                    if (isset($result->charges->data[0]->receipt_url)) {
                        $receipt_url = $result->charges->data[0]->receipt_url;
                        $id_payment = $result->charges->data[0]->id;
                    }    
                }
            
                $array_save = array(
                    'id' => $ent_treatment->id,
                    'payment' => $ent_treatment->payment_intent,
                    'receipt_url' => $receipt_url,
                );

                $c_entity = $this->DataTreatment->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    if ($this->DataTreatment->save($c_entity)) {
                        $patient = $this->SysUsers->find()->select(['SysUsers.email'])->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
                        $pay = $this->DataPayment->find()->where(['DataPayment.uid' => $treatment_uid, 'DataPayment.intent' => $ent_treatment->payment_intent, 'DataPayment.id_to' => 0])->first();
                        if(!empty($patient)){
                            $html_msg = "
                            Thank you, your treatment was paid.

                            Please find the invoice attached. We appreciate you working with us. 

                            If you have any questions, please email us at info@spalivemd.com
                            ";
                            $this->send_receipt($html_msg, $patient->email, $pay->id, $pay->uid);
                        }
                        $this->updatePaymentRegister($treatment_uid, $ent_treatment->payment_intent ,$id_payment, $receipt_url);


                        $this->payCIComissions($treatment_uid);

                        $this->sendTreatmentReview('EMAIL_AFTER_TREATMENT', $ent_treatment->patient_id, $ent_treatment->assistance_id, $treatment_uid, $ent_treatment->schedule_date);

                        $this->success();
                    }
                }

            }
            
        } catch(\Stripe\Exception\CardException $e) {
          // Since it's a decline, \Stripe\Exception\CardException will be caught
        } catch (\Stripe\Exception\RateLimitException $e) {
          // Too many requests made to the API too quickly
        } catch (\Stripe\Exception\InvalidRequestException $e) {
          // Invalid parameters were supplied to Stripe's API
        } catch (\Stripe\Exception\AuthenticationException $e) {
          // Authentication with Stripe's API failed
          // (maybe you changed API keys recently)
        } catch (\Stripe\Exception\ApiConnectionException $e) {
          // Network communication with Stripe failed
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
        } catch (Exception $e) {
          // Something else happened, completely unrelated to Stripe
        }
        
    }

    public function verify_pay_register() {
        $this->loadModel('SpaLiveV1.DataPayment');

        $uid = get('uid', '');
        if(empty($uid)){
            $this->message('uid empty.');
            return;
        }

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.login_status','SysUsers.name','SysUsers.lname','SysUsers.mname','SysUsers.type','SysUsers.payment_intent', 'SysUsers.tracers','SysUsers.dob','SysUsers.tracers', 'SysUsers.email'])
        ->where(['SysUsers.uid' => $uid])->first();



        if (empty($ent_user)) {
            $this->message('user not found');
            return;
        }

        if (empty($ent_user->payment_intent)) {
            $this->message('payment intent not found');
            return;
        }


        try {
            
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
            
            $result = $stripe->paymentIntents->retrieve(
              $ent_user->payment_intent
            );
            if ($result->status == 'succeeded') {
                $receipt_url = '';
                $id_payment = '';
                if (count($result->charges->data) > 0) {
                    if (isset($result->charges->data[0]->receipt_url)) {
                        $receipt_url = $result->charges->data[0]->receipt_url;
                        $id_payment = $result->charges->data[0]->id;
                    }    
                }
                
                $array_save = array(
                    'id' => $ent_user->id,
                    'payment' => $ent_user->payment_intent,
                    'login_status' => $ent_user->type  == 'injector' ? 'APPROVE' : $ent_user->login_status,
                    'receipt_url' => $receipt_url,
                );



                $c_entity = $this->SysUsers->newEntity($array_save);
                if(!$c_entity->hasErrors()) {
                    if ($this->SysUsers->save($c_entity)) {
                        
                        /* --- COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED START --*/
                        /*if (empty($ent_user->tracers)) {
                           $this->check_tracers($ent_user);
                        }*/
                        /* --- COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED END --- */
                        $this->success();

                        $token = get('token',"");

                        if(!empty($token)){
                            $user = $this->AppToken->validateToken($token, true);
                            if($user === false){
                                $this->message('Invalid token.');
                                $this->set('session', false);
                                return;
                            }
                            $this->set('session', true);

                            if (USER_TYPE == "examiner") {
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
                                        $this->DataRequestGfeCi->save($entRequestSave);
                                    }

                                }
                            }
                            $pay = $this->DataPayment->find()->where(['DataPayment.uid' => $uid, 'DataPayment.intent' => $ent_user->payment_intent, 'DataPayment.id_to' => 0])->first();
                            $this->updatePaymentRegister($uid, $ent_user->payment_intent, $id_payment, $receipt_url);

                            if(!empty($pay)){
                                $this->send_receipt('CI_REGISTRATION_PAYMENT',$ent_user->email, $pay->id, $pay->uid);
                            }

                            $this->notify_devices('CI_REGISTRATION_PAYMENT',array($ent_user->id),true,false);
                        }
                        
                    }
                }

            }
            
        } catch(\Stripe\Exception\CardException $e) {
          // Since it's a decline, \Stripe\Exception\CardException will be caught
        } catch (\Stripe\Exception\RateLimitException $e) {
          // Too many requests made to the API too quickly
        } catch (\Stripe\Exception\InvalidRequestException $e) {
          // Invalid parameters were supplied to Stripe's API
        } catch (\Stripe\Exception\AuthenticationException $e) {
          // Authentication with Stripe's API failed
          // (maybe you changed API keys recently)
        } catch (\Stripe\Exception\ApiConnectionException $e) {
          // Network communication with Stripe failed
        } catch (\Stripe\Exception\ApiErrorException $e) {
          // Display a very generic error to the user, and maybe send
          // yourself an email
        } catch (Exception $e) {
          // Something else happened, completely unrelated to Stripe
        }
        
    }

    public function payment_intent_register() {


        $str_uid = get('uid','');
        if (empty($str_uid)) {
            $this->message('Invalid uid.');
            return;
        }


        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.uid', 'SysUsers.payment_intent','SysUsers.amount','SysUsers.email'])
        ->where(['SysUsers.uid' => $str_uid])->first();
        
        if (empty($ent_user)) {
            $this->message('user not found');
            return;
        }

        $multiplier = $this->validateCode(get('promo_code',''));
        $total_amount = $this->register_total * $multiplier;
        // $promo_code = get('promo_code','');
        // if (!empty($promo_code)) {
        //     if ($promo_code == 'dsct99') {
        //         $multiplier = 0.01;
        //         $total_amount = $this->register_total * $multiplier;
        //     }
        // }


        
        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));    
        

        $oldCustomer = $stripe->customers->all([
            "email" => $ent_user->email,
            "limit" => 1,
        ]);

        if (count($oldCustomer) == 0) {
            $customer = $stripe->customers->create([
                'description' => $ent_user->name,
                'email' => $ent_user->email,
            ]);
        } else $customer = $oldCustomer->data[0];


       
        if ($total_amount < 100) $total_amount = 100;

        $intent = \Stripe\PaymentIntent::create([
          'amount' => $total_amount,
          'currency' => 'USD',
          'metadata' => ['type' => 'register', 'uid' => $str_uid],
          'receipt_email' => $ent_user->email,
          'customer' => $customer['id'],
          'transfer_group' => $str_uid,
        ]);



        $client_secret = $intent->client_secret;
        $arr_pintnt = explode("_secret_", $client_secret);

        $this->createPaymentRegister('CI REGISTER', $ent_user->id, 0, $str_uid, $arr_pintnt[0], $this->register_total, $total_amount);

        $this->set('secret', $client_secret);
        $this->set('total', $total_amount);
        $this->success();


        if (count($arr_pintnt)) {
            $array_save = array(
                'id' => $ent_user->id,
                'payment_intent' => $arr_pintnt[0],
            );

            $c_entity = $this->SysUsers->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->SysUsers->save($c_entity)) {
                    $this->success();
                }
            }
        }
        
    }


    public function get_unread() {

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

        $this->loadModel('SpaLiveV1.DataMessages');
        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.readed' => 0])->count();
        $this->set('unread_messages', $c_count);
        $this->success();
    }

    public function validate_token_pswd(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $token = get('psw_tkn', '');
        $decode_str = base64_decode($token);

        $params = explode('||', $decode_str);

        if(sizeof($params) != 2){
            $this->message('invalid token.');
            return;
        }

        $user = $this->SysUsers->find()->select(["uid","name","email"])->where(['SysUsers.email' => $params[0], 'SysUsers.token_chg_pswd' => $params[1]])->first();
        if(empty($user)){
            $this->message('invalid token.');
            return;
        }

        $this->success();
        $this->set('data', $user);
    }
    
    
    public function get_file() {
        $this->loadModel('SpaLiveV1.SysUsers');
        

        //l3n4p=6092482f7ce858.91169218
        $panel = get('l3n4p', '');
        $photo_id = get('id', '');
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){
            $find = $this->SysUsers->find()->select(['photo_id'])->where(['SysUsers.photo_id' => $photo_id])->first();
            if(!empty($find)){

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
        
                $file = $this->Files->output($photo_id);
            }
            exit;    
        }else{
            //$file_id = $this->Files->uid_to_id(get('uid', ''));
            //$file = $this->Files->output($file_id);
            
            $file = $this->Files->output($photo_id);
            exit;
        }
    }


     public function upload_file() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
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

        $this->set('file_id', $_file_id);
        $this->success();   

     }

    public function summary() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.CatLabels');

        
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

        $userType = $user['user_role'];
        $this->loadModel('SpaLiveV1.DataMessages');
        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.readed' => 0])->count();
        $this->set('unread_messages', $c_count);

        
        // $fields_cert = ['DataConsultation.uid','DataConsultation.payment','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration','UserA.name'];
        // $_where_cert = ['DataConsultation.deleted' => 0];

        if(strtoupper($userType) == 'PATIENT'){

            //Favorites
            $this->loadModel('SpaLiveV1.DataFavorites');
            $ent_fav = $this->DataFavorites->find()->select(['User.uid','User.name', 'User.lname', 'User.mname','User.short_uid','User.score','User.photo_id','User.description'])
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataFavorites.injector_id'],
            ])
            ->where(['DataFavorites.deleted' => 0, 'DataFavorites.patient_id' => USER_ID, 'User.show_in_map' => 1])->order(['DataFavorites.created' => 'DESC'])->all();

            $arr_favorites = array();
            if(!empty($ent_fav)){
               foreach ($ent_fav as $row) {
                    $arr_favorites[] = array(
                        'uid' => $row['User']['uid'],
                        'short_uid' => $row['User']['short_uid'],
                        'name' => $row['User']['name'] . ' ' . $row['User']['lname'],
                        'photo_id' => intval($row['User']['photo_id']),
                        'description' => !empty($row['User']['description']) ? $row['User']['description'] : '',
                        'score' => intval($row['User']['score']),
                    );
                }
            }
            $this->set('favorites', $arr_favorites);

            $str_now = date('Y-m-d H:i:s');
            $str_query_scheduled = "
                SELECT 
                    DC.uid, DC.status, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,\" \",U.lname) patient,CONCAT(UA.`name`,\" \",UA.lname) assistance,U.state, DC.schedule_date, DC.treatments, UA.uid assistance_uid,
                    (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
                FROM data_consultation DC
                JOIN sys_users U ON U.id = DC.patient_id
                LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
                
                WHERE DC.deleted = 0 AND DC.patient_id = " . USER_ID . " AND DC.status = 'INIT' AND DC.schedule_by > 0
                AND TIMESTAMPDIFF(MINUTE, DC.schedule_date, '{$str_now}') <= 15 "; //AND DC.schedule_date > NOW()" ;


            $ent_scheduled = $this->DataConsultation->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

            $arr_scheduled = array();
            if(!empty($ent_scheduled)){
               foreach ($ent_scheduled as $row) {
                    $arr_scheduled[] = array(
                        'uid' => $row['uid'],
                        'treatment_uid' => $row['uid'],
                        'meeting' => $row['meeting'],
                        'meeting_pass' => $row['meeting_pass'],
                        'schedule_date' => $row['schedule_date'],
                        'treatments' => $row['treatments'],
                        'assistance' => $row['assistance'] ? $row['assistance'] : '',
                        'assistance_uid' => $row['assistance_uid'] ? $row['assistance_uid'] : '',
                        'status' => $row['status'],
                    );
                }
            }
            $this->set('scheduled_evaluations', $arr_scheduled);
            
            //Certificates

            $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];
            $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
            $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.patient_id'] = USER_ID;
            // $_where['DataConsultation.status'] = "DONE";
            $_where['DataConsultation.status'] = "CERTIFICATE";
            $_where['DataConsultation.treatments <>'] = "";
            // $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];
            
        

            $certItem = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            ])
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
            
            $arr_certificates = array();
            if (!empty($certItem)) {
                   foreach ($certItem as $row) {
                        $arr_certificates[] = array(
                            'consultation_uid' => $row['uid'],
                            'payment' => empty($row['payment']) ? 0 : 1,
                            'certificate_uid' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                            'date_start' => empty($row->DataCertificates['date_start']) ? $row['schedule_date']->i18nFormat('yyyy-MM-dd') : $row->DataCertificates['date_start'],
                            'date_expiration' => empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration'],
                            'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                            'expirate_soon' => false//isset($row['expirate_soon']) ? ($row['expirate_soon'] == 1 ? true : false) : '',
                        );
                    
                }
                $this->set('certificates', $arr_certificates);
            }

            //Treatments
            $this->loadModel('SpaLiveV1.DataTreatment');

            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.zip','DataTreatment.city','DataTreatment.suite'];
            $fields['assistance'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['assistance_info'] = "(SELECT CONCAT_WS('||', U.uid, U.short_uid) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['txt_state'] = "(SELECT name FROM cat_states CS WHERE CS.id = DataTreatment.state LIMIT 1)";
            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.status !='] = "DONE";
            $_where['DataTreatment.patient_id'] = USER_ID;
            // $_where['DataTreatment.status !='] = "CANCEL";
            $_where['OR'] = [['DataTreatment.status' => "INIT"], ['DataTreatment.status' => "APPROVE"],['DataTreatment.status' => "CONFIRM"]];
            // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
           

            $certTreatment = $this->DataTreatment->find()->select($fields)
                ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                            
                $arr_treatments = array();
                if (!empty($certTreatment)) {
                    foreach ($certTreatment as $row) {

                            $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                            if (!empty($row['suite'])) {
                                $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['txt_state'] . ' ' . $row['zip'];
                            }
                            $str_inj_info = explode('||',$row['assistance_info']);



                            $arr_treatments[] = array(
                                'treatment_uid' => $row['uid'],
                                'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                                'assistance' => $row['assistance'],
                                'assistance_uid' => $str_inj_info[0],
                                'assistance_shortuid' => $str_inj_info[1],
                                'status' => $row['status'],
                                'address' => $str_address
                            );
                    }
                    $this->set('scheduled_treatments', $arr_treatments);
                }
            
                $_usr_id = USER_ID;
                $trWithRevw = $this->DataTreatment->getConnection()->execute("SELECT DTR.treatment_id FROM data_treatment_reviews DTR JOIN data_treatment DT ON DT.id = DTR.treatment_id WHERE DT.patient_id = {$_usr_id}")->fetchAll('assoc');
                $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.zip','DataTreatment.city','DataTreatment.suite'];
                $fields['assistance'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
                $fields['assistance_info'] = "(SELECT CONCAT_WS('||', U.uid, U.short_uid) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
                $fields['txt_state'] = "(SELECT name FROM cat_states CS WHERE CS.id = DataTreatment.state LIMIT 1)";
                $_where = ['DataTreatment.deleted' => 0, 'DataTreatment.status' => "DONE", 'DataTreatment.patient_id' => USER_ID, 'DataTreatment.payment <>' => ''];
                if(isset($trWithRevw) && !empty($trWithRevw)){
                    $_where['DataTreatment.id NOT IN'] = Hash::extract($trWithRevw, '{n}.treatment_id');
                }

                $treatNoReviews = $this->DataTreatment->find()->select($fields)->where($_where)->toArray();
                foreach ($treatNoReviews as $item) {
                    $item['schedule_date'] = $item->schedule_date->i18nFormat('yyyy-MM-dd HH:mm');
                    $item['score'] = 50;
                }

                $this->set('pending_review', $treatNoReviews);
                $this->success();   
        
        } else if(strtoupper($userType) == 'EXAMINER'){

            //GFE 

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
            if (empty($ent_user)) {
                return;
            }

            $examiner_state = $ent_user->state;
            $c_date = date('Y-m-d H:i:s');

            $str_query = "
            SELECT  
                ( SELECT COUNT(DC.id)
                FROM data_consultation DC
                JOIN sys_users SU ON SU.id = DC.patient_id
                WHERE SU.state = {$examiner_state}
                AND (
                    (DC.assistance_id = 0 AND DC.schedule_by = 0 AND status = \"INIT\")
                    OR (assistance_id = {$user['user_id']} AND schedule_by > 0 AND status = \"INIT\" AND is_waiting = 1)
                )
                AND TIMESTAMPDIFF(second, DC.modified, '{$c_date}') < 15
                AND (DC.reserve_examiner_id = " . USER_ID . " OR DC.reserve_examiner_id = 0)
                # AND TIMESTAMPDIFF(MINUTE,schedule_date,NOW()) BETWEEN 0 AND 30 
                # AND DATE(schedule_date) = DATE(NOW())
                ) waiting,
                ( SELECT COUNT(DC.id)
                FROM data_consultation DC
                JOIN sys_users SU ON SU.id = DC.patient_id
                WHERE DC.status = \"INIT\" 
                AND DC.schedule_by > 0 
                AND SU.state = {$examiner_state} 
                AND DC.schedule_date > '{$c_date}'
                AND DC.assistance_id = 0 AND DC.deleted = 0
                ) schedule
                ";
            
            
            $ent_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
            
            if (empty($ent_query)) {
                return;
            }

            $response_gfe = array();
            $response_gfe['schedule'] = intval($ent_query[0]['schedule']);
            $response_gfe['waiting'] = intval($ent_query[0]['waiting']);

            $this->set('gfe', $response_gfe);


            //Scheduled Evaluations

            $fields = ['DataConsultation.uid','DataConsultation.schedule_date'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.assistance_id'] = USER_ID;
            $_where['DataConsultation.status !='] = "CANCEL";
            // $_where['DataConsultation.schedule_date >'] = "NOW()";
            $_where['DataConsultation.schedule_by >'] = 0;
            $str_now = date('Y-m-d H:i:s');
            $_where[] = "TIMESTAMPDIFF(MINUTE, DataConsultation.schedule_date, '{$str_now}') <= 15";

            $cert_schedule_consultations = $this->DataConsultation->find()->select($fields)
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
            
            $arr_scheduled = array();
            if (!empty($cert_schedule_consultations)) {
                $arr_scheduled = array();
               foreach ($cert_schedule_consultations as $row) {
                    $arr_scheduled[] = array(
                            'treatment_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                        );
               }
           }

            $this->set('scheduled_evaluations', $arr_scheduled);




            // PENDING EVALUATIONS

            $fields = ['DataConsultation.uid','DataConsultation.schedule_date','DataConsultation.status','State.name'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.assistance_id'] = USER_ID;
            $_where['DataConsultation.status'] = "DONE";
            $_where['DataCertificates.id IS'] = null;
        

            $ent_pending = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
                'Patient' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Patient.id = DataConsultation.patient_id'],
                'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = Patient.state'],
            ])
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

            
            $arr_pending = array();
            if (!empty($ent_pending)) {
                $arr_scheduled = array();
               foreach ($ent_pending as $row) {
                    $arr_pending[] = array(
                            'consultation_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                            'treatments' => $row['treatments'],
                            'state' => $row['State']['name'],
                        );
               }
           }

            $this->set('pending_evaluations', $arr_pending);


            // PAST EXAMS

             $fields = ['DataConsultation.uid','DataConsultation.status','DataConsultation.schedule_date','DataConsultation.status','DataCertificates.uid'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.assistance_id'] = USER_ID;
            $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];

            // $_where['DataCertificates.id IS'] = null;
        

            $ent_pending = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            ])
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

            
            $arr_pending = array();
            if (!empty($ent_pending)) {
                $arr_scheduled = array();
               foreach ($ent_pending as $row) {
                    $arr_pending[] = array(
                            'consultation_uid' => $row['uid'],
                            'certificate_uid' => empty($row['DataCertificates']['uid']) ? '' : $row['DataCertificates']['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                            'treatments' => $row['treatments'],
                            'status' => $row['status'],
                        );
               }
            }

            $this->set('past_exams', $arr_pending);


            $this->success();   


        } else if(strtoupper($userType) == 'INJECTOR'){


            // $ent_scheduled = $this->DataConsultation->getConnection()->execute("")->fetchAll('assoc');


            // ACTUAL APPOINTMENT

            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
            $this->loadModel('SpaLiveV1.DataPurchases');

            $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
            $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id LIMIT 1)";
            $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.status'] = "CONFIRM";

            $_where['OR'] = [['DataTreatment.status' => "CONFIRM"], ['DataTreatment.status' => "DONE", 'DataTreatment.payment' => ""]];

            $_where['DataTreatment.assistance_id'] = USER_ID;
            // $_where[] = "DataTreatment.schedule_date > NOW()";
            // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
        

            // $certTreatment = $this->DataTreatment->find()->select($fields)->join([
            $certTrtArr = $this->DataTreatment->find()->select($fields)->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();
                            

            $arr_treatments = array();
            $arr_appotments = [];
            // if (!empty($certTreatment)) {
            foreach($certTrtArr as $index => $certTreatment){
                
                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty', 'Treatments.treatment_id'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['consultation'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        ORDER BY DCO.modified DESC
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                SELECT DC.status FROM data_consultation DC 
                WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                    , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";


                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $data_tr[] = array(
                            'name' => $row['Treatments']['name'],
                            'treatment_id' => intval($row['treatment_id']),
                            'price' => intval($row['price']),
                            'qty' => intval($row['Treatments']['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'consultation' => !empty($row['consultation']) ? $row['consultation'] : '',
                            'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',

                        );
                    }
                }
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
                if (!empty($certTreatment->suite)) {
                    $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
                }

                $re_array = array(
                    'uid' => $certTreatment->uid,
                    'patient_uid' => $certTreatment->patient_uid,
                    'assistance_uid' => $certTreatment->assistance_uid,
                    'schedule_date' => $certTreatment->schedule_date,
                    'status' => $certTreatment->status,
                    'address' => $sstr_address,
                    'patient' => $certTreatment->patient,
                    'treatments' => $certTreatment->treatments_string,
                    'treatments_detail' => $data_tr,
                );
                $arr_appotments[] = $re_array;
                if($index == 0){
                    $this->set('actual_appointment', $re_array);
                }
            }
            $this->set('actual_appointments', $arr_appotments);


            // Scheduled appointments
            $this->loadModel('SpaLiveV1.DataTreatment');

            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status', 'DataTreatment.address', 'DataTreatment.zip', 'DataTreatment.city', 'State.name', 'DataTreatment.suite'];
            $fields['assistance'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CTI.name) FROM cat_treatments_ci CTI WHERE FIND_IN_SET(CTI.id,DataTreatment.treatments))";
            
            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.status !='] = "DONE";
            $_where['DataTreatment.status'] = "INIT";
            $_where['DataTreatment.assistance_id'] = USER_ID;
            // $_where['DataTreatment.status'] = "!= CANCEL";
            // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
            // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -15 DAY)";
            // $_where[] = "DataTreatment.schedule_date > NOW()";
        

            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                    'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                    'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
                ])
                ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                            
                $arr_treatments = array();
                if (!empty($certTreatment)) {
                    foreach ($certTreatment as $row) {

                        $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        if (!empty($row['suite'])) {
                            $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        }

                        $arr_treatments[] = array(
                            'treatment_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
                            'assistance' => $row['assistance'],
                            'assistance_uid' => $row['assistance_uid'],
                            'patient_uid' => $row['patient_uid'],
                            'treatments' => $row['treatments'],
                            'status' => $row['status'],
                            'address' => $str_address,
                        );
                    }
                    $this->set('scheduled_treatments', $arr_treatments);
                }



            //CI NETWORK SUMMARY

            $result_array = array();
            $arr_search = array();
            $grand_total = 0;
            $arr_search[] = array('user_id' => USER_ID);
            $level_parent_id = USER_ID;


            $should_continue = true;
            $level_count = 0;


            do {    
                
                $arr_search = $this->get_tree($arr_search);

                if (empty($arr_search)) $should_continue = false;
                
                $arr_filtered_search = array();
                $should_increment = false;
                foreach($arr_search as $row) {
                    if ($row['active'] == 1) {
                        $should_increment = true;
                        $arr_filtered_search[] = $row;
                    }

                    // 'name'
                    // 'short_uid'
                    // 'user_id'
                    // 'active'
                } 
                
                
                if ($should_increment) $level_count++;

                if ($level_count >= 4) $should_continue = false;

                if ($should_increment) {
                    $result_array[] = array(
                        'level' => intval($level_count),
                        'data' => $arr_filtered_search
                    );
                    $grand_total += count($arr_filtered_search);
                }

            } while ($should_continue);

            // for($i = 1; $i <= 5; $i++) {
            //     $arr_search = $this->get_tree($arr_search);
            //     if (empty($arr_search))
            //         break;
            //     $result_array[] = array(
            //         'level' => intval($i),
            //         'data' => $arr_search
            //     );
            //     $grand_total += count($arr_search);
            // }
            
            $arr_network = array(
                'network' => $result_array,
                'total' => intval($grand_total),
            );


            $this->set('ci_network', $arr_network);
            $this->set('patients', $this->find_patients(true));



            //PAST Treatments


            $this->loadModel('SpaLiveV1.DataTreatment');


            $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.id','Note.notes', 'DataTreatment.suite'];
            $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
            $fields['treatments_text'] = "(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
            $fields['images'] = "(GROUP_CONCAT(Image.file_id))";
            $_where = ['DataTreatment.deleted' => 0];
            $_where['DataTreatment.status'] = "DONE";
            $_where['DataTreatment.assistance_id'] = USER_ID;
            // $_where['Review.deleted'] = 0;
            
           

            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
                'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id'],
                'Image' => ['table' => 'data_treatment_image', 'type' => 'LEFT', 'conditions' => 'Image.treatment_id = DataTreatment.id']
                ]
            )->where($_where)->group(['DataTreatment.id'])->order(['DataTreatment.id' => 'DESC']);
                            
            $arr_treatments = array();
            if (!empty($certTreatment)) {
                // pr($certTreatment);exit;
                foreach ($certTreatment as $row) {

                    $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                    if (!empty($row['suite'])) {
                        $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                    }
                    $arr_treatments[] = array(
                        'id' => $row['id'],
                        'images' => (isset($row['images']) && $row['images'] ? explode(',', $row['images']) : []),
                        'treatment_uid' => $row['uid'],
                        'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                        'status' => $row['status'],
                        'treatments' => $row['treatments_text'],
                        'injector' => $row['injector'],
                        'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                        'amount' => $row['amount'],
                        'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                        'address' => $ss_address,
                        'reviewed' => empty($row['Review']['score']) ? false : true,
                        'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
                        'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
                        'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
                        'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
                    );
                }
                $this->set('past_treatments', $arr_treatments);
            }

            $this->loadModel('SpaLiveV1.DataPurchases');
            $orders = $this->DataPurchases->find()->select(['DataPurchases.id'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
            ])->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => ''])->count();
            $this->set('has_orders', $orders > 0 ? true : false);

            // SCHEDULED EXAM PATIENT

            $str_now = date('Y-m-d H:i:s');
            $str_query_scheduled = "
                SELECT 
                    DC.uid, DC.status, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,\" \",U.lname) patient,CONCAT(UA.`name`,\" \",UA.lname) assistance,U.state, DC.schedule_date, DC.treatments, U.uid assistance_uid,
                    (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
                FROM data_consultation DC
                JOIN sys_users U ON U.id = DC.patient_id
                JOIN sys_users Inj ON Inj.id = DC.createdby
                LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
                WHERE DC.deleted = 0 AND DC.createdby = " . USER_ID . " AND DC.status = 'INIT' AND DC.schedule_by > 0
                AND TIMESTAMPDIFF(MINUTE, DC.schedule_date, '{$str_now}') <= 15 "; //AND DC.schedule_date > NOW()" ;


            $ent_scheduled = $this->DataConsultation->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

            $arr_scheduled = array();
            if(!empty($ent_scheduled)){
               foreach ($ent_scheduled as $row) {
                    $arr_scheduled[] = array(
                        'uid' => $row['uid'],
                        'patient_name' => $row['patient'],
                        'treatment_uid' => $row['uid'],
                        'meeting' => $row['meeting'],
                        'meeting_pass' => $row['meeting_pass'],
                        'schedule_date' => $row['schedule_date'],
                        'treatments' => $row['treatments'],
                        'assistance' => $row['assistance'] ? $row['assistance'] : '',
                        'assistance_uid' => $row['assistance_uid'] ? $row['assistance_uid'] : '',
                        'status' => $row['status'],
                    );
                }
            }
            $this->set('scheduled_eval_patient', $arr_scheduled);
        
            $this->success();   


        } else if(strtoupper($userType) == 'CLINIC'){



            // PAST EXAMS

            $fields = ['DataConsultation.uid','DataConsultation.status','DataConsultation.schedule_date','DataConsultation.status','DataCertificates.uid'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
            $fields['examiner'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.assistance_id)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.createdby'] = USER_ID;
            $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];

            // $_where['DataCertificates.id IS'] = null;
        
            $ent_pending = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            ])
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

            
            $arr_pending = array();
            if (!empty($ent_pending)) {
                $arr_scheduled = array();
               foreach ($ent_pending as $row) {
                    $arr_pending[] = array(
                            'consultation_uid' => $row['uid'],
                            'certificate_uid' => empty($row['DataCertificates']['uid']) ? '' : $row['DataCertificates']['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                            'examiner' => $row['examiner'],
                            'treatments' => $row['treatments'],
                            'status' => $row['status'],
                        );
               }
           }

            $this->set('past_exams', $arr_pending);

            $this->success();   

        } else if (strtoupper($userType) == 'GFE+CI') {

            //GFE 

            $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
            if (empty($ent_user)) {
                return;
            }

            $examiner_state = $ent_user->state;
            $c_date = date('Y-m-d H:i:s');
            $str_query = "
            SELECT  
                ( SELECT COUNT(DC.id)
                FROM data_consultation DC
                JOIN sys_users SU ON SU.id = DC.patient_id
                WHERE SU.state = {$examiner_state}
                AND (
                    (DC.assistance_id = 0 AND DC.schedule_by = 0 AND status = \"INIT\")
                    OR (assistance_id = {$user['user_id']} AND schedule_by > 0 AND status = \"INIT\" AND is_waiting = 1)
                )
                AND TIMESTAMPDIFF(second, DC.modified, '{$c_date}') < 15
                #AND TIMESTAMPDIFF(MINUTE,schedule_date,NOW()) BETWEEN 0 AND 30 
                #AND DATE(schedule_date) = DATE(NOW())
                ) waiting,
                ( SELECT COUNT(DC.id)
                FROM data_consultation DC
                JOIN sys_users SU ON SU.id = DC.patient_id
                WHERE status = \"INIT\" 
                AND DC.schedule_by > 0 
                AND SU.state = {$examiner_state} 
                AND DC.schedule_date > '{$c_date}' 
                AND DC.assistance_id = 0 AND DC.deleted = 0
                ) schedule
            ";
            
            $ent_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
            
            if (empty($ent_query)) {
                return;
            }

            $response_gfe = array();
            $response_gfe['schedule'] = intval($ent_query[0]['schedule']);
            $response_gfe['waiting'] = intval($ent_query[0]['waiting']);

            $this->set('gfe', $response_gfe);


            //Scheduled Evaluations

            $fields = ['DataConsultation.uid','DataConsultation.schedule_date'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.assistance_id'] = USER_ID;
            $_where['DataConsultation.status !='] = "CANCEL";
            $_where['DataConsultation.schedule_date >'] = "NOW()";
            $_where['DataConsultation.schedule_by >'] = 0;
            $str_now = date('Y-m-d H:i:s');
            $_where[] = "TIMESTAMPDIFF(MINUTE, DataConsultation.schedule_date, '{$str_now}') <= 15";

            $cert_schedule_consultations = $this->DataConsultation->find()->select($fields)
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
            
            $arr_scheduled = array();
            if (!empty($cert_schedule_consultations)) {
                $arr_scheduled = array();
               foreach ($cert_schedule_consultations as $row) {
                    $arr_scheduled[] = array(
                            'treatment_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                        );
               }
           }

            $this->set('scheduled_evaluations', $arr_scheduled);




            // PENDING EVALUATIONS

            $fields = ['DataConsultation.uid','DataConsultation.schedule_date','DataConsultation.status','State.name'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.assistance_id'] = USER_ID;
            $_where['DataConsultation.status'] = "DONE";
            $_where['DataCertificates.id IS'] = null;
        

            $ent_pending = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
                'Patient' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Patient.id = DataConsultation.patient_id'],
                'State' => ['table' => 'cat_states', 'type' => 'LEFT', 'conditions' => 'State.id = Patient.state'],
            ])
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

            
            $arr_pending = array();
            if (!empty($ent_pending)) {
                $arr_scheduled = array();
               foreach ($ent_pending as $row) {
                    $arr_pending[] = array(
                            'consultation_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                            'treatments' => $row['treatments'],
                            'state' => $row['State']['name'],
                        );
               }
           }

            $this->set('pending_evaluations', $arr_pending);


            // PAST EXAMS

             $fields = ['DataConsultation.uid','DataConsultation.status','DataConsultation.schedule_date','DataConsultation.status','DataCertificates.uid'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataConsultation.patient_id)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))";

            $_where = ['DataConsultation.deleted' => 0];
            $_where['DataConsultation.assistance_id'] = USER_ID;
            $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];

            // $_where['DataCertificates.id IS'] = null;
        

            $ent_pending = $this->DataConsultation->find()->select($fields)
            ->join([
                'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            ])
            ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();

            
            $arr_pending = array();
            if (!empty($ent_pending)) {
                $arr_scheduled = array();
               foreach ($ent_pending as $row) {
                    $arr_pending[] = array(
                            'consultation_uid' => $row['uid'],
                            'certificate_uid' => empty($row['DataCertificates']['uid']) ? '' : $row['DataCertificates']['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'patient' => $row['patient'],
                            'treatments' => $row['treatments'],
                            'status' => $row['status'],
                        );
               }
           }

            $this->set('past_exams', $arr_pending);

            // ACTUAL APPOINTMENT

            $this->loadModel('SpaLiveV1.DataTreatment');
            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

            $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','State.name','DataTreatment.patient_id', 'DataTreatment.city','DataTreatment.zip','DataTreatment.suite'];
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
            $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id LIMIT 1)";
            $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";

            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.status'] = "CONFIRM";

            $_where['OR'] = [['DataTreatment.status' => "CONFIRM"], ['DataTreatment.status' => "DONE", 'DataTreatment.payment' => ""]];

            $_where['DataTreatment.assistance_id'] = USER_ID;
            // $_where[] = "DataTreatment.schedule_date > NOW()";
            // $_where[] = "DataTreatment.schedule_date > DATE_ADD(DATE(NOW()), INTERVAL -2 HOUR)";
        

            // $certTreatment = $this->DataTreatment->find()->select($fields)->join([
            $certTrtArr = $this->DataTreatment->find()->select($fields)->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Patient' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'Patient.id = DataTreatment.patient_id AND Patient.deleted = 0'],
            ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();
                            

            $arr_treatments = array();
            $arr_appotments = [];
            // if (!empty($certTreatment)) {
            foreach($certTrtArr as $index => $certTreatment){
                
                $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty'];
                $_fields['certificate'] = "(SELECT DC.uid
                        FROM cat_treatments_ci CTC
                        JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                        JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                        JOIN data_consultation DCO ON DCO.id = DC.consultation_id AND DCO.deleted = 0
                        WHERE CTC.deleted = 0 AND CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                        LIMIT 1)";
                $_fields['certificate_status'] = "(
                    SELECT DC.status FROM data_consultation DC 
                    WHERE FIND_IN_SET(  (SELECT CTpt.parent_id FROM cat_treatments CTpt WHERE CTpt.id = Treatments.treatment_id LIMIT 1)
                        , DC.treatments) AND DC.status = 'DONE' AND DC.patient_id = ".$certTreatment->patient_id." AND DC.deleted = 0 LIMIT 1)";

                $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                    'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                ])->where(['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $certTreatment->treatments . '")' ,'DataTreatmentsPrice.user_id' => USER_ID])->all();

                $data_tr = array();

                if (!empty($ent_prices)) {
                    foreach ($ent_prices as $row) {
                        $data_tr[] = array(
                            'name' => $row['Treatments']['name'],
                            'treatment_id' => intval($row['treatment_id']),
                            'price' => intval($row['price']),
                            'qty' => intval($row['Treatments']['qty']),
                            'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',
                            'certificate_status' => !empty($row['certificate_status']) ? $row['certificate_status'] : '',
                        );
                    }
                }
                $sstr_address = $certTreatment->address . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
                if (!empty($certTreatment->suite)) {
                    $sstr_address = $certTreatment->address . ', ' . $certTreatment->suite . ', ' . $certTreatment->city . ', ' . $certTreatment['State']['name'] . ' ' . $certTreatment->zip;
                }

                $re_array = array(
                    'uid' => $certTreatment->uid,
                    'patient_uid' => $certTreatment->patient_uid,
                    'assistance_uid' => $certTreatment->assistance_uid,
                    'schedule_date' => $certTreatment->schedule_date,
                    'status' => $certTreatment->status,
                    'address' => $sstr_address,
                    'patient' => $certTreatment->patient,
                    'treatments' => $certTreatment->treatments_string,
                    'treatments_detail' => $data_tr,
                );
                $arr_appotments[] = $re_array;
                if($index == 0){
                    $this->set('actual_appointment', $re_array);
                }
            }
            $this->set('actual_appointments', $arr_appotments);


            // Scheduled appointments
            $this->loadModel('SpaLiveV1.DataTreatment');

            
            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status', 'DataTreatment.address', 'DataTreatment.zip', 'DataTreatment.city', 'State.name', 'DataTreatment.suite'];
            $fields['assistance'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['assistance_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['patient_uid'] = "(SELECT U.uid FROM sys_users U WHERE U.id = DataTreatment.patient_id LIMIT 1)";
            $fields['treatments'] = "(SELECT GROUP_CONCAT(CTI.name) FROM cat_treatments_ci CTI WHERE FIND_IN_SET(CTI.id,DataTreatment.treatments))";
            
            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.schedule_date >='] = "DATE_ADD(DATE(NOW()), INTERVAL -15 DAY)";
            // $_where['DataTreatment.status !='] = "DONE";
            $_where['DataTreatment.status'] = "INIT";
            $_where['DataTreatment.assistance_id'] = USER_ID;
            // $_where['DataTreatment.status'] = "!= CANCEL";
        

            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                    'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                ])
                ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                            
                $arr_treatments = array();
                if (!empty($certTreatment)) {
                    foreach ($certTreatment as $row) {

                        $str_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        if (!empty($row['suite'])) {
                            $str_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        }
                        $arr_treatments[] = array(
                            'treatment_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm:ss'),
                            'assistance' => $row['assistance'],
                            'assistance_uid' => $row['assistance_uid'],
                            'patient_uid' => $row['patient_uid'],
                            'treatments' => $row['treatments'],
                            'status' => $row['status'],
                            'address' => $str_address,
                        );
                    }
                    $this->set('scheduled_treatments', $arr_treatments);
                }



             //CI NETWORK

            $result_array = array();
            $arr_search = array();
            $grand_total = 0;
            $arr_search[] = array('user_id' => USER_ID);
            $level_parent_id = USER_ID;


            $should_continue = true;
            $level_count = 0;


            do {    
                
                $arr_search = $this->get_tree($arr_search);

                if (empty($arr_search)) $should_continue = false;
                
                $arr_filtered_search = array();
                $should_increment = false;
                foreach($arr_search as $row) {
                    if ($row['active'] == 1) {
                        $should_increment = true;
                        $arr_filtered_search[] = $row;
                    }

                    // 'name'
                    // 'short_uid'
                    // 'user_id'
                    // 'active'
                } 
                
                
                if ($should_increment) $level_count++;

                if ($level_count >= 4) $should_continue = false;

                if ($should_increment) {
                    $result_array[] = array(
                        'level' => intval($level_count),
                        'data' => $arr_filtered_search
                    );
                    $grand_total += count($arr_filtered_search);
                }

            } while ($should_continue);



            // for($i = 1; $i <= 5; $i++) {
            //     $arr_search = $this->get_tree($arr_search);
            //     if (empty($arr_search))
            //         break;
            //     $result_array[] = array(
            //         'level' => intval($i),
            //         'data' => $arr_search
            //     );
            //     $grand_total += count($arr_search);
            // }
            
            $arr_network = array(
                'network' => $result_array,
                'total' => intval($grand_total),
            );


            $this->set('ci_network', $arr_network);

            $this->set('patients', $this->find_patients(true));




            //PAST Treatments


            $this->loadModel('SpaLiveV1.DataTreatment');


            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.id','Note.notes', 'DataTreatment.suite'];
            $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
            $fields['treatments_text'] = "(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
            $_where = ['DataTreatment.deleted' => 0];
            $_where['DataTreatment.status'] = "DONE";
            $_where['DataTreatment.assistance_id'] = USER_ID;
            // $_where['Review.deleted'] = 0;
            
           

            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
                'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id']
            ])->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                            
            $arr_treatments = array();
            if (!empty($certTreatment)) {
                foreach ($certTreatment as $row) {

                        $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        if (!empty($row['suite'])) {
                            $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                        }

                        $arr_treatments[] = array(
                            'treatment_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'status' => $row['status'],
                            'treatments' => $row['treatments_text'],
                            'injector' => $row['injector'],
                            'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                            'amount' => $row['amount'],
                            'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                            'address' => $ss_address,
                            'reviewed' => empty($row['Review']['score']) ? false : true,
                            'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
                            'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
                            'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
                            'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
                        );
                }
                $this->set('past_treatments', $arr_treatments);
            }

            // SCHEDULED EXAM PATIENT

            $str_now = date('Y-m-d H:i:s');
            $str_query_scheduled = "
                SELECT 
                    DC.uid, DC.status, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,\" \",U.lname) patient,CONCAT(UA.`name`,\" \",UA.lname) assistance,U.state, DC.schedule_date, DC.treatments, U.uid assistance_uid,
                    (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
                FROM data_consultation DC
                JOIN sys_users U ON U.id = DC.patient_id
                JOIN sys_users Inj ON Inj.id = DC.createdby
                LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
                WHERE DC.deleted = 0 AND DC.createdby = " . USER_ID . " AND DC.status = 'INIT' AND DC.schedule_by > 0
                AND TIMESTAMPDIFF(MINUTE, DC.schedule_date, '{$str_now}') <= 15 "; //AND DC.schedule_date > NOW()" ;


            $ent_scheduled = $this->DataConsultation->getConnection()->execute($str_query_scheduled)->fetchAll('assoc');

            $arr_scheduled = array();
            if(!empty($ent_scheduled)){
               foreach ($ent_scheduled as $row) {
                    $arr_scheduled[] = array(
                        'uid' => $row['uid'],
                        'patient_name' => $row['patient'],
                        'treatment_uid' => $row['uid'],
                        'meeting' => $row['meeting'],
                        'meeting_pass' => $row['meeting_pass'],
                        'schedule_date' => $row['schedule_date'],
                        'treatments' => $row['treatments'],
                        'assistance' => $row['assistance'] ? $row['assistance'] : '',
                        'assistance_uid' => $row['assistance_uid'] ? $row['assistance_uid'] : '',
                        'status' => $row['status'],
                    );
                }
            }
            $this->set('scheduled_eval_patient', $arr_scheduled);
        
            $this->success();   

        }


        // CAT LABELS

        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where(['CatLabels.deleted' => 0])->toArray();
        $labels = [];
        foreach($findLabels as $item){
            $labels[$item->key_field] = $item->value;
        }
        $this->set('labels', $labels);


        //Request DATA

        // summary > request_photo : true/false    
        // summary > request_services : true/false - request_stripe    



        // SERVICES

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $r_services = true;
        $r_available = true;
        $ent_tprice = $this->DataTreatmentsPrice->find()->where(['DataTreatmentsPrice.user_id' => USER_ID])->first();
        if (!empty($ent_tprice)) {
            $this->loadModel('SpaLiveV1.DataScheduleModel');
            $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => USER_ID])->first();

            if (!empty($ent_sch_model) && !empty($ent_sch_model->days)) {
                $r_available = false;
                $r_services = false;
            }
            
        }

        $this->set('request_services', $r_services);
        $this->set('request_availability', $r_available);


        //Request Fill Profil
        $r_profile = true;
        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (!empty($ent_user)) {
            if ($ent_user->photo_id != 93) {
                $r_profile = false;
            }
        }
        $this->set('request_profile', $r_profile);


        // $this->set('stripe_button', true);
        $this->set('stripe_button', $this->checkStripeACcount());

        if(strtoupper($userType) == 'INJECTOR' || strtoupper($userType) == 'GFE+CI'){
            $this->set('trainings', $this->checkTrainings());
        }
    }

    private function checkTrainings() {

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->join([
            'Training' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'Training.user_id = SysUsers.id'],
            'CatTraining' => ['table' => 'cat_trainings', 'type' => 'INNER', 'conditions' => 'CatTraining.id = Training.training_id'],
        ])
        ->where(['SysUsers.id' => USER_ID, 'Training.deleted' => 0, 'CatTraining.deleted' => 0])->first();

        if (!empty($ent_user)) return true;
        return false;
    }
     

    public function find_treatment() {
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

        // $userType = $user['user_role'];

        // if ($userType != "patient") {
        //     $this->message('Invalid user.');
        //     return;
        // }

        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $latitude = $ent_user->latitude;
        $longitude = $ent_user->longitude;

        $str_sort = get('sort','distance_asc');
        $order = '';
        $order_by_review = false;
        if (!empty($str_sort)) {
            if ($str_sort == "distance_desc") {
                $order = 'ORDER BY distance_in_mi DESC';
            } else if ($str_sort == "distance_asc") {
                $order = 'ORDER BY distance_in_mi ASC';
            } else  if ($str_sort == "ci_of_month") {
                $order = 'ORDER BY is_ci_of_month DESC';
            } else  if ($str_sort == "most_reviewed") {
                $order_by_review = true;
            }
        }

        //******** TEMP
        // if($latitude == 0 || $longitude == 0) {
        //     $latitude = 29.387579;
        //     $longitude = -98.472777;
        // }

        $zip = get('zip',0);
        if ($zip == 0) {
            $zip = $ent_user->zip;
        }

        if ($latitude == 0 || $longitude == 0) {


            if (strlen(strval($zip)) < 5) {
                for($i = 0; $i < (5 - strlen(get('zip',0)));$i++) {
                    $zip = '0' . $zip;
                }
            }
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

        // DEFAULT - Have training and active = 1
        $filter = trim(get('filter', ''));
        $join = '';
        $conditions = '';
        $having = '';
        $str_treatments = get('treatments', '');
        $join = "INNER JOIN data_schedule_model DSM ON DSM.injector_id = DC.id AND DSM.deleted = 0 ";
        $conditions = " AND DSM.days <> '' AND (SELECT COUNT(TrP.id) FROM data_treatments_prices TrP WHERE TrP.user_id = DC.id AND TrP.deleted = 0) > 0 ";
        if(!empty($str_treatments)){
            $join .= " INNER JOIN data_treatments_prices TrPr ON TrPr.user_id = DC.id";
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
            $conditions .= " AND ( MATCH(DC.name,DC.mname,DC.lname) AGAINST ('+{$matchValue}' IN BOOLEAN MODE) OR DC.email LIKE '%{$filter}%')";
        }

        if ($user['user_role'] != 'patient') {
            $latitude = 0;
        }
        
        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        if ($latitude == 0 || $longitude == 0) {
            $str_query_find = "
                 SELECT 
                    *, 
                    DC.id as user_id,
                    DC.show_most_review,
                    (SELECT COUNT(id) FROM data_treatment_reviews DTR WHERE DC.id = DTR.injector_id AND DTR.deleted = 0) comments,
                    (SELECT GROUP_CONCAT(CONCAT_WS('|',CT.name,ROUND(DTP.price / 100,2))) FROM data_treatments_prices DTP JOIN cat_treatments_ci CT ON CT.id = DTP.treatment_id WHERE DC.id = DTP.user_id AND DTP.deleted = 0) treatments,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings, 
                    9999 distance_in_mi,
                    (SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = DC.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}') as is_ci_of_month
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.login_status = 'READY' AND DC.is_test = 0 {$conditions}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        } else {
            $str_query_find = "
                SELECT 
                    *, 
                    DC.id as user_id,
                    DC.show_most_review,
                    (SELECT COUNT(id) FROM data_treatment_reviews DTR WHERE DC.id = DTR.injector_id AND DTR.deleted = 0) comments,
                    (SELECT GROUP_CONCAT(CONCAT_WS('|',CT.name,ROUND(DTP.price / 100,2))) FROM data_treatments_prices DTP JOIN cat_treatments_ci CT ON CT.id = DTP.treatment_id WHERE DC.id = DTP.user_id AND DTP.deleted = 0) treatments,
                    (SELECT COUNT(Training.id) 
                        FROM data_trainings Training
                        INNER JOIN cat_trainings CatTraining ON CatTraining.id = Training.training_id 
                        WHERE Training.user_id = DC.id AND Training.deleted = 0 AND CatTraining.deleted = 0 GROUP BY DC.id) trainings, 
                    69.09 * DEGREES(ACOS(LEAST(1.0, COS(RADIANS({$latitude}))
                         * COS(RADIANS(DC.latitude))
                         * COS(RADIANS({$longitude} - DC.longitude))
                         + SIN(RADIANS({$latitude}))
                         * SIN(RADIANS(DC.latitude))))) AS distance_in_mi,
                    (SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = DC.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}') as is_ci_of_month     
                FROM sys_users DC
                {$join}
                WHERE (DC.type = 'injector' OR DC.type = 'gfe+ci') AND DC.deleted = 0 AND DC.login_status = 'READY' AND DC.is_test = 0 {$conditions}
                GROUP BY DC.id
                {$having}
                {$order}
                ";
        }
        // echo $str_query_find; exit;
        
        
        $arr_find = $this->SysUsers->getConnection()->execute($str_query_find)->fetchAll('assoc');
        
        $result = array();
        $result2 = array();

        $arr_review_reach = [];
        $arr_review_unreach = [];
        if (!empty($arr_find)) {
            $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
            
            foreach($arr_find as $row) {
                if ($row['show_in_map'] == 2) continue;
                if (($row['show_in_map'] == 1 && $row['trainings'] > 0 && $row['active'] == 1) || ($row['show_in_map'] == 3)) {
                    $score = intval($row['score']);
                    $adding = array(
                        'uid' => $row['uid'],
                        'name' => $row['name'] . ' ' . $row['lname'],
                        'short_uid' => $row['short_uid'],
                        'photo_id' => intval($row['photo_id']),
                        'description' => !empty($row['description']) ? $row['description'] : '',
                        'score' => $score == 0 ? 50 : $score,
                        'latitude' => doubleval($row['latitude']),
                        'longitude' => doubleval($row['longitude']),
                        'longitude' => doubleval($row['longitude']),
                        'distance' => doubleval($row['distance_in_mi']),
                        'comments' => intval($row['comments']),
                        'treatments' => empty($row['treatments']) ? "No treatments available." : trim($row['treatments']),
                        'available' => doubleval($row['distance_in_mi']) <= $row['radius'] ? 1 : 0,
                        'radius' => $row['radius'],
                        'is_ci_of_month' => ($row['is_ci_of_month'] > 0 ? 1 : 0),
                        'most_reviewed' => ($row['show_most_review'] == 'DEFAULT') ? (in_array($row['user_id'], $most_reviewed) ? 1 : 0) : (($row['show_most_review'] == 'FORCED') ? 1 : 0),
                    );

                    if ($adding['available'] == 1){
                        if($order_by_review == true && $adding['most_reviewed'] == 1){
                            $arr_review_reach[] = $adding;
                        }else $result[] = $adding;
                    }
                    else{
                        if($order_by_review == true && $adding['most_reviewed'] == 1){
                            $arr_review_unreach[] = $adding;
                        }else $result2[] = $adding;
                    }
                }
            }


            $result = array_merge( array_merge($arr_review_reach, $result), array_merge($arr_review_unreach, $result2) );

        }

        $this->set('data', $result);
        

        


         //Favorites
        $this->loadModel('SpaLiveV1.DataFavorites');
        $ent_fav = $this->DataFavorites->find()->select(['User.uid','User.name', 'User.lname', 'User.mname','User.short_uid','User.photo_id','User.score'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataFavorites.injector_id'],
        ])
        ->where(['DataFavorites.deleted' => 0, 'DataFavorites.patient_id' => USER_ID, 'User.show_in_map' => 1])->order(['DataFavorites.created' => 'DESC'])->all();

        $arr_favorites = array();
        if(!empty($ent_fav)){
           foreach ($ent_fav as $row) {
                $arr_favorites[] = array(
                    'uid' => $row['User']['uid'],
                    'short_uid' => intval($row['User']['short_uid']),
                    'photo_id' =>  intval($row['User']['photo_id']),
                    'score' => $row['User']['score'],
                    'name' => $row['User']['name'] . ' ' . $row['User']['lname'],
                );
            }
        }
        $this->set('favorites', $arr_favorites);
        $this->success();

        //PAST Treatments


        $this->loadModel('SpaLiveV1.DataTreatment');


        $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id','Review.score','Review.comments','Review.id','Note.notes','DataTreatment.suite'];
        $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
        $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
        $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
        $fields['treatments_text'] = "(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
        $_where = ['DataTreatment.deleted' => 0];
        $_where['DataTreatment.status'] = "DONE";
        $_where['DataTreatment.payment <>'] = "";
        $_where['DataTreatment.patient_id'] = USER_ID;
        // $_where['Review.deleted'] = 0;
        
       

        $certTreatment = $this->DataTreatment->find()->select($fields)
        ->join( [
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
            'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'Review.treatment_id = DataTreatment.id'],
            'Note' => ['table' => 'data_treatment_notes', 'type' => 'LEFT', 'conditions' => 'Note.treatment_id = DataTreatment.id'],
        ],
        )
            ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                        
        $arr_treatments = array();
        if (!empty($certTreatment)) {
            foreach ($certTreatment as $row) {
                $ss_address = $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                    if (!empty($row['suite'])) {
                        $ss_address = $row['address'] . ', ' . $row['suite'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'];
                    }

                $arr_treatments[] = array(
                    'treatment_uid' => $row['uid'],
                    'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd'),
                    'schedule_date_formated' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                    'status' => $row['status'],
                    'treatments' => $row['treatments_text'],
                    'injector' => $row['injector'],
                    'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                    'amount' => $row['amount'],
                    'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                    'address' => $ss_address,
                    'reviewed' => empty($row['Review']['score']) ? false : true,
                    'score' => empty($row['Review']['score']) ? 0 : $row['Review']['score'],
                    'comments' => empty($row['Review']['comments']) ? 'No comments' : $row['Review']['comments'],
                    'review_id' => empty($row['Review']['id']) ? 0 : intval($row['Review']['id']),
                    'notes' => empty($row['Note']['notes']) ? 'Without notes.' : trim($row['Note']['notes']),
                );
            }
            $this->set('past_treatments', $arr_treatments);
        }

    }

    public function find_patients($return_list = false) {

        $token = get('token', '');
        
        if(!$return_list){
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

            if (USER_TYPE == "patient") {
                $this->message('Invalid user type.');    
                return;
            }
        }

    

        $_where = ['SysUsers.deleted' => 0];
        $_where['SysUsers.createdby'] = USER_ID;
        
        $short_uid = get('short_uid','');
        if (!empty($short_uid)) {
            // $_where['SysUsers.short_uid LIKE'] = $short_uid;
            $_where['OR'] = [['SysUsers.short_uid LIKE' => $short_uid], ['SysUsers.name LIKE' => "%$short_uid%"], ['SysUsers.lname LIKE' => "%$short_uid%"]];
        }
    
        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_users = $this->SysUsers->find()->where($_where)->all();
        
        $result = array();

        if (!empty($ent_users)) {

            
            foreach ($ent_users as $row) {

                $t_array = array(
                    'uid' => $row['uid'],
                    'short_uid' => $row['short_uid'],
                    'name' => $row['name'] . ' ' . $row['lname'],
                );

                $result[] = $t_array;
            }

        }

        if($return_list){
            return $result;
        }else{
            $this->set('data', $result);
            $this->set('injector_uid', USER_UID);
            $this->success();
        }
    }

    public function save_review() {
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

         if (USER_TYPE != "patient") {
            $this->message('Invalid user type.');    
            return;
        }

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }
        $this->loadModel('SpaLiveV1.DataTreatment');
        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $int_id = get('id',0);
        if ($int_id == 0) {
            $ent_rev = $this->DataTreatmentReview->find()->where(['DataTreatmentReview.treatment_id' => $ent_treatments->id,'DataTreatmentReview.createdby' => USER_ID])->first();
            if(!empty($ent_rev)){
               $int_id = $ent_rev->id;
            }
        }

        $array_save = array(
            'id' => $int_id,
            'treatment_id' => $ent_treatments->id,
            'injector_id' => $ent_treatments->assistance_id,
            'score' => get('score',40),
            'comments' => get('comments','No comments'),
            'deleted' => 0,
            'createdby' => USER_ID
        );
        
        $c_entity = $this->DataTreatmentReview->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatmentReview->save($c_entity)) {
                $this->success();


                $this->loadModel('SpaLiveV1.DataTreatmentReview');
                $ent_rev = $this->DataTreatmentReview->find()->where(['DataTreatmentReview.injector_id' => $ent_treatments->assistance_id,'DataTreatmentReview.deleted' => 0])->all();
                if (!empty($ent_rev)) {

                    $total = 0;
                    $count = 0;
                    foreach($ent_rev as $reg) {
                        $total += $reg['score'];
                        $count++;
                    }

                    $prom = $total/$count;
                    $prom = round( $prom / 5 ) * 5;

                    $existUser = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatments->assistance_id, 'SysUsers.deleted' => 0])->first();
                    if (!empty($existUser)) {
                        $existUser->score = $prom;
                        $this->SysUsers->save($existUser);
                    }
                }
            }
        }

    }
    

    private function get_token($int_usuario_id, $userType, $is_admin = 0) {
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

    public function cat_treatments_ci(){

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


        $this->loadModel('SpaLiveV1.CatCITreatments');
        $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','Product.comission_spalive','Exam.name'])->where(['CatCITreatments.deleted' => 0])->join([
            'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
            'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
        ])->all();
        

        if(!empty($ent_treatments)){
            $result = array();
            foreach ($ent_treatments as $row) {

                $t_array = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'details' => $row['details'],
                    'exam_id' => $row['treatment_id'],
                    'product_id' => $row['product_id'],
                    'exam_name' => $row['Exam']['name'],
                    
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

        $this->set('data', $result);
        $this->success();
    }

    public function cat_treatments_ci_map(){

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


        $this->loadModel('SpaLiveV1.CatCITreatments');
        $ent_treatments = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','Product.comission_spalive','Exam.name'])
        ->where([
            'CatCITreatments.deleted' => 0,
            'Product.category <>' => 'FILLERS',
        ])->join([
            'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
            'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
        ])->all();
        

        if(!empty($ent_treatments)){
            $result = array();

            $result[] = array(
                    'id' => 0,
                    'name' => 'Filler treatments',
                    'fillers' => 1,
                );

            foreach ($ent_treatments as $row) {

                $t_array = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'details' => $row['details'],
                    'exam_id' => $row['treatment_id'],
                    'product_id' => $row['product_id'],
                    'exam_name' => $row['Exam']['name'],
                    'fillers' => 0,
                    
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


        // FILLERS
        $ent_fillers = $this->CatCITreatments->find()->select(['CatCITreatments.id','CatCITreatments.treatment_id','CatCITreatments.name','CatCITreatments.product_id','CatCITreatments.max','CatCITreatments.details','CatCITreatments.min','CatCITreatments.qty','Product.comission_spalive','Exam.name'])
        ->where([
            'CatCITreatments.deleted' => 0,
            'Product.category' => 'FILLERS',
        ])->join([
            'Exam' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Exam.id = CatCITreatments.treatment_id'],
            'Product' => ['table' => 'cat_products', 'type' => 'LEFT', 'conditions' => 'Product.id = CatCITreatments.product_id'],
        ])->all();


        $result_fillers = array();
        if(!empty($ent_fillers)){

            foreach ($ent_fillers as $row) {

                $t_array = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'details' => $row['details'],
                    'exam_id' => $row['treatment_id'],
                    'product_id' => $row['product_id'],
                    'exam_name' => $row['Exam']['name'],
                    'fillers' => 0,
                    
                );
                if (USER_TYPE == "injector" || MASTER || USER_TYPE == "gfe+ci") {
                    $t_array['max'] = $row['max'];
                    $t_array['min'] = $row['min'];
                    $t_array['qty'] = $row['qty'];
                    $t_array['ci_comission'] = intval($row['Product']['comission_spalive']);
                }
                

                $result_fillers[] = $t_array;
            }
        }



        $this->set('data', $result);
        $this->set('fillers', $result_fillers);
        $this->success();
    }


    public function save_treatment_ci() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

        $id = get('id',0);
        $name = get('name','');
        $exam_id = get('exam_id',0);
        $min = get('min',0);
        $max = get('max',0);


        $array_save = array(
            'id' => $id,
            'name' => $name,
            'treatment_id' => $exam_id,
            'min' => $min,
            'max' => $max,
            'deleted' => 0,
            'created' => USER_ID
        );

        $this->loadModel('SpaLiveV1.CatCITreatments');
        $c_entity = $this->CatCITreatments->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->CatCITreatments->save($c_entity)) {
                $this->success();
            }
        }

        //CatCITreatments

    }

    public function get_treatments_ci() {
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
        $this->loadModel('SpaLiveV1.SysUsers');

        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }

        $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');
        $fields = ['SysUsers.id','SysUsers.uid','SysUsers.name','SysUsers.mname','SysUsers.lname','SysUsers.short_uid','SysUsers.score','SysUsers.photo_id','SysUsers.description', 'SysUsers.show_most_review'];
        $fields['is_ci_of_month'] = "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')";
        $existUser = $this->SysUsers->find()->select($fields)->where(['SysUsers.id' => $injector_id, 'SysUsers.deleted' => 0])->first();
        // pr($existUser);exit;

        $data_user = array();

        if (!empty($existUser)) {
            $data_user['uid'] = $existUser['uid'];
            $data_user['name'] = $existUser['name'];
            $data_user['mname'] = $existUser['mname'];
            $data_user['lname'] = $existUser['lname'];
            $data_user['short_uid'] = $existUser['short_uid'];
            $data_user['score'] = $existUser['score'];
            $data_user['photo_id'] = $existUser['photo_id'];
            $data_user['description'] = $existUser['description'];
            $data_user['is_ci_of_month'] = ($existUser['is_ci_of_month'] > 0 ? 1 : 0);
            $data_user['most_reviewed'] = ($existUser['show_most_review'] == 'DEFAULT') ? (in_array($existUser['id'], $most_reviewed) ? 1 : 0) : (($existUser['show_most_review'] == 'FORCED') ? 1 : 0);
        }

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $ent_prices = $this->DataTreatmentsPrice->find()->select(['DataTreatmentsPrice.price','Treatments.details','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty'])->join([
            'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id']
        ])->where(['DataTreatmentsPrice.deleted' => 0, 'DataTreatmentsPrice.user_id' => $injector_id, 'Treatments.deleted' => 0])->all();

        $data_tr = array();

        if (!empty($ent_prices)) {
            foreach ($ent_prices as $row) {
                
                $data_tr[] = array(
                    'name' => $row['Treatments']['name'],
                    'qty' => intval($row['Treatments']['qty']),
                    'details' => $row['Treatments']['details'],
                    'treatment_id' => intval($row['treatment_id']),
                    'price' => intval($row['price']),
                );
            }
        }

        $this->loadModel('SpaLiveV1.DataTreatmentReview');
        $ent_comments = $this->DataTreatmentReview->find()->select(['DataTreatmentReview.score','DataTreatmentReview.comments','DataTreatmentReview.created','User.name'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataTreatmentReview.createdby']])
        ->where(['DataTreatmentReview.injector_id' => $injector_id, 'DataTreatmentReview.deleted' => 0, "TRIM(DataTreatmentReview.comments) <> ''", 'DataTreatmentReview.comments <>' => 'No comments'])->all();
        $total_comments = $this->DataTreatmentReview->find()->select(['DataTreatmentReview.id'])->where(['DataTreatmentReview.injector_id' => $injector_id, 'DataTreatmentReview.deleted' => 0])->count();
        
        $data_comments = array();
        if (!empty($ent_comments)) {
            foreach ($ent_comments as $row) {
                
                $data_comments[] = array(
                    'score' => intval($row['score']),
                    'comments' => $row['comments'],
                    'created' => $row['created']->format("m-d-Y"),
                    'name' => (isset($row->User['name']) ? $row->User['name'] : ''),
                );
            }
        }

        $is_favorite = false;

        $this->loadModel('SpaLiveV1.DataFavorites');
        $ent_fav = $this->DataFavorites->find()->where(['DataFavorites.patient_id' => USER_ID, 'DataFavorites.injector_id' => $injector_id])->first();
        $is_favorite = empty($ent_fav) ? false : true;

        if(!empty($ent_prices)){
            $this->set('treatments', $data_tr);
            $this->set('injector', $data_user);
            $this->set('comments', $data_comments);
            $this->set('total_comments', intval($total_comments));
            $this->set('favorite', $is_favorite);
            $this->success();
        }
    }

    public function set_favorite() {

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
        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataFavorites');
        $ent_fav = $this->DataFavorites->find()->where(['DataFavorites.patient_id' => USER_ID, 'DataFavorites.injector_id' => $injector_id])->first();
        if (empty($ent_fav)) {
            
             $array_save = array(
                'patient_id' => USER_ID,
                'injector_id' => $injector_id,
                'deleted' => 0,
                'created' => date('Y-m-d H:i:s')
            );

            $c_entity = $this->DataFavorites->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataFavorites->save($c_entity)) {
                    $this->set('favorite', true);
                    $this->success();
                }
            }
        } else {
            $str_query = "DELETE FROM data_favorites WHERE patient_id = " . USER_ID . " AND injector_id = " . $injector_id;
        
            $this->DataFavorites->getConnection()->execute($str_query);
                $this->set('favorite', false);
                $this->success();
        }


    
    }

    public function save_treatment_price(){

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

        if (USER_TYPE != "injector") {
            $this->message('Invalid user.');
            return;
        }

        $treatment_id = get('treatment_id',0);
        if ($treatment_id == 0) {
            $this->message('Invalid treatment_id');
            return;
        }

        $price = get('price',0);
        if ($price == 0) {
            $this->message('Invalid price');
            return;
        }

        $this->loadModel('SpaLiveV1.CatCITreatments');
        $ent_check_prices = $this->CatCITreatments->find()->where(['CatCITreatments.deleted' => 0, 'CatCITreatments.id' => $treatment_id])->first();
        if(!empty($ent_check_prices)){
            if ($price < $ent_check_prices->min || $price > $ent_check_prices->max) {
                $this->message('Invalid price');
                return;
            }
        }

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $array_save = array(
            'user_id' => USER_ID,
            'treatment_id' => $treatment_id,
            'price' => $price,
            'deleted' => 0,
            'created' => USER_ID
        );

        $c_entity = $this->DataTreatmentsPrice->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatmentsPrice->save($c_entity)) {
                $this->success();
            }
        }

        $this->set('data', $result);
        $this->success();
    }

    public function cat_treatments(){

        $token = get('token', '');
        
        if(!empty($token)){
            $user = $this->AppToken->validateToken($token, true);
            $this->set('session', true);
        } 


        $this->loadModel('SpaLiveV1.CatTreatments');
         $ent_treatments = $this->CatTreatments->find()->where(['CatTreatments.deleted' => 0])->order(['CatTreatments.type_trmt','CatTreatments.id'])->all();
        if(!empty($ent_treatments)){
            $result = array();
            foreach ($ent_treatments as $row) {
                $result[] = array(
                    'id' => $row['id'],
                    'parent_id' => $row['parent_id'],
                    'name' => $row['name'],
                    'details' => $row['details'],
                    'haschild' => $row['haschild'],
                );
                
            }
        }


        
        if (defined('USER_TYPE')) {
            if (USER_TYPE == 'patient') {

               $str_query_ = "
                    SELECT GROUP_CONCAT(DISTINCT CT.name) treatments
                    FROM data_consultation_plan DCP
                    JOIN data_consultation DC ON DC.id = DCP.consultation_id
                    JOIN data_certificates DCE ON DCE.consultation_id = DC.id
                    JOIN cat_treatments CT ON CT.id = DCP.treatment_id
                    WHERE 
                    
                    DCP.proceed = 1
                    AND NOW() < DCE.date_expiration
                    AND DC.patient_id = " . USER_ID;
                


                $result_query = $this->CatTreatments->getConnection()->execute($str_query_)->fetchAll('assoc');
                $str_treatments = '';
                foreach($result_query as $row) {
                    $str_treatments = $row['treatments'];
                }
                
                $this->set('treatments', isset($str_treatments) ? $str_treatments : '');
            }
        }

        $this->set('data', $result);
        
        $this->success();
    }

    public function cat_questions(){

        $this->loadModel('SpaLiveV1.CatQuestions');
        $ent_questions = $this->CatQuestions->find()->where(['CatQuestions.deleted' => 0])->all();
        if(!empty($ent_questions)){
            $result = array();
            foreach ($ent_questions as $row) {
                $result[] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                );
                
            }
        }

        $this->set('data', $result);
        $this->success();
    }

    public function save_question() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

        $question_id = get('question_id',0);
        $name = get('name',"");

        if (empty($name) || $question_id == 0) {
            $this->message('Invalid question_id.');
            return;
        }

        $array_save = array(
            'id' => $question_id,
            'name' => $name,
        );

        $c_entity = $this->CatQuestions->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->CatQuestions->save($c_entity)) {
                $this->success();
            }
        }

    }

    public function delete_quiestion() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }


        $question_id = get('question_id',0);

        if ($question_id == 0) {
            $this->message('Invalid question_id.');
            return;
        }

        $array_save = array(
            'id' => $question_id,
            'deleted' => 1,
        );

        $c_entity = $this->CatQuestions->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->CatQuestions->save($c_entity)) {
                $this->success();
            }
        }

    }

    public function schedule_availability() {

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

        $date = get('date','');
        if (empty($date)) {
            $this->message('Date empty.');
            return;
        }


        $this->loadModel('SpaLiveV1.SysUsers');

        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id <= 0){
            $this->message('Invalid Injector.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $ent_sch_model = $this->DataScheduleModel->find()->where(['DataScheduleModel.deleted' => 0, 'DataScheduleModel.injector_id' => $injector_id])->first();

        if (!empty($ent_sch_model)) {
            $days = $ent_sch_model->days;
            $hour_start = $ent_sch_model->time_start;
            $hour_end = $ent_sch_model->time_end;

            $first_day = \DateTime::createFromFormat('Y-m-d', $date); // Tipo Fecha
            $day = strtoupper($first_day->format('l'));

            $find_date_str = $first_day->format('Y-m-d');
          
            $this->set('date', $find_date_str);
           
            $this->set('id', $injector_id);
            //Search next available day
            
            if (!empty($days) && !empty($day)) {

                if(strpos($days, $day) !== false){
                   
                } else {
                    $this->set('data', array());
                    $this->success();
                    return;
                }
            }

            

            $this->loadModel('SpaLiveV1.DataScheduleAppointment');


            
            $ent_appointments = $this->DataScheduleAppointment->find()->where(['DataScheduleAppointment.deleted' => 0, 'DataScheduleAppointment.injector_id' => $injector_id, 'DATE(DataScheduleAppointment.created)' => $find_date_str])->all();

            $not_hours = array();
            $yes_hours = array();

            $today = date('Y-m-d');

            if ($today == $date) {
                $int_day = intval(date('H'));
                for($q=5;$q<=$int_day;$q++) {
                // for($q=5;$q<=13;$q++) {
                    $not_hours[$q] = true;   
                }
            } else if ($date < $today) {

                for($q=1;$q<=24;$q++) {
                    $not_hours[$q] = true;   
                }
            }
            

            foreach ($ent_appointments as $row) {
                $not_hours[$row['created']->format("G")] = true;
            }
            $array_available = array();
            
            for ($i = $hour_start; $i < $hour_end; $i++) {
                if (isset($not_hours[$i])) {
                    continue;
                }
                $ii = $i;
                $add = "a.m.";
                // $add2 = "a.m.";
                if ($i >= 12)  { $add = "p.m."; if ($ii > 12 ) $ii = $ii - 12; }
                // if (($i + 1) == 12) { $add2 = "p.m.";  if ($ii > 12 ) $ii = $ii - 12; }
                $array_available[] = array(
                    'label' => $ii . ':00 ' . $add,// . ' - ' . ($ii + 1) . ':00 ' . $add2,
                    'save' => $find_date_str . " " . $i . ":00:00"
                );
                
            }

            if(!empty($ent_appointments)){
                 $this->set('data', $array_available);
                 $this->success();
            }

        } else {

        }

    }




    public function schedule_save_model() {

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

        if (USER_TYPE != "injector") {
            $this->message('Invalid user');
            return;
        }

        $days = get('days','');

        if (empty($days)) {
            $this->message('Invalid days');
            return;
        }

        $time_start = get('time_start','');
        if (empty($time_start)) {
            $this->message('Invalid time_start');
            return;
        }

        $time_end = get('time_end','');
        if (empty($time_end)) {
            $this->message('Invalid time_end');
            return;
        }

        $this->loadModel('SpaLiveV1.DataScheduleModel');

        $ent_consultation = $this->DataScheduleModel->find()
        ->where(['DataScheduleModel.id' => USER_ID, 'DataScheduleModel.deleted' => 0])->first();

        $save_id = 0;
        if (empty($ent_consultation)) {
            $save_id = $ent_consultation;
        }

        $this->loadModel('SpaLiveV1.DataScheduleAppointment');

        $array_save = array(
            'id' => $treatment_id,
            'injector_id' => USER_ID,
            'days' => $days,
            'time_start' => $time_start,
            'time_end' => $time_end,
            'deleted' => 0,
        );

        $entity = $this->DataScheduleAppointment->newEntity($array_save);
        if(!$entity->hasErrors()){
            if($this->DataScheduleAppointment->save($entity)){
                $this->success();
            }
        }
    }


    public function start_treatment(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        

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
        $this->loadModel('SpaLiveV1.SysUserAdmin');
        
        $injector_id = $this->SysUsers->uid_to_id(get('injector_uid', ''));
        if($injector_id <= 0){
            $this->message('Invalid injector.');
            return;
        }

        $createdby = USER_ID;
        $patient_id = USER_ID;
        $assistance_id = $injector_id;

        if ($user['user_role'] == 'clinic' || $user['user_role'] == 'injector' || $user['user_role'] == 'gfe+ci') {
            $_patient_id = $this->SysUsers->uid_to_id(get('patient_uid', ''));
            if($_patient_id <= 0){
                $this->message('The patient does not exist.');
                return;
            }
            $patient_id = $_patient_id;
        }


        /**********************/

        $string_treatments = get('treatments','');
        $string_treatments = str_replace(" ", "", $string_treatments);
        
        if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }
        $arr_treatments = explode(",", $string_treatments);

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        // $treatment_prices = "";
        $amount = 0;
        if (count($arr_treatments) == 0) {
            
            // foreach ($arr_treatments as $tid) {

            //     $ent_tr = $this->DataTreatmentsPrice->find()
            //     ->where(['DataTreatmentsPrice.treatment_id' => $tid, 'DataTreatmentsPrice.user_id' => $injector_id,'DataTreatmentsPrice.deleted' => 0])->first();

            //     if (empty($ent_tr)) {
            //         $this->message('Not all treatments include price ' . $tid);
            //         continue;
            //     }
            //     $treatment_prices .= $ent_tr->price . ",";
            //     $amount += $ent_tr->price;
            // }
        }


        /***********************/

        $schedule_by = get('schedule_by',USER_ID);
        $date = \DateTime::createFromFormat('Y-m-d H:i:s', get('schedule_date',''));

        if (empty($date)) {
             $this->message('Invalid date.');
            return;
        }


        $status = get('status','INIT');
       

        $schedule_date = get('schedule_date','');


        $treatment_uid = Text::uuid();

        //$assigned_doctor = rand(0,1) == 0 ? 'Dr Zach Cannon' : 'Dr Doohi Lee';
        $assigned_doctor = $this->SysUserAdmin->getRandomDoctor();

        if (empty($schedule_date)) 
            $schedule_date = date('Y-m-d H:i:s');

         $array_save = array(
            'uid' => $treatment_uid,
            'patient_id' => $patient_id,
            'assistance_id' => $assistance_id,
            'treatments' => $string_treatments,
            // 'treatments_prices' => $treatment_prices,
            'amount' => intval($amount),
            'address' => get('address',''),
            'suite' => get('suite',''),
            'zip' => get('zip',''),
            'city' => get('city',''),
            'state' => get('state',43),
            'schedule_date' => get('schedule_date',''),
            'status' => $status,
            'deleted' => 0,
            'created' => date('Y-m-d H:i:s'),
            'schedule_by' => $createdby,
            'createdby' => $createdby,
            'assigned_doctor' => $assigned_doctor
        );





        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {


                $this->set('uid', $treatment_uid);
                $this->set('grand_total', intval($amount));
                $this->success(); 
                $this->notify_devices('NEW_TREATMENT',array($assistance_id),true,true,true, array(), '',array(),true);
                if (USER_ID != $patient_id) 
                    $this->notify_devices('NEW_TREATMENT_PATIENT',array($patient_id),true,true, true, array(), '',array(),true);

                
                //SHCEDULE
                $this->loadModel('SpaLiveV1.DataScheduleAppointment');

                $array_save_s = array(
                    'treatment_id' => $c_entity->id,
                    'injector_id' => $injector_id,
                    'created' => get('schedule_date',''),
                    'deleted' => 0,
                );

                $entity = $this->DataScheduleAppointment->newEntity($array_save_s);
                if(!$entity->hasErrors()){
                    if($this->DataScheduleAppointment->save($entity)){
                        $treatmentID = $this->DataTreatment->uid_to_id(get('treatment_uid', ''));
                        if($treatmentID > 0){
                            $treatment = $this->DataTreatment->find()->where(['DataTreatment.id' => $treatmentID])->first();
                            $treatment->deleted = 1;
                            if(!$treatment->hasErrors()) {
                                if ($this->DataTreatment->save($treatment)) {
                                    $str_query_renew = "UPDATE data_schedule_appointments SET deleted = 1 WHERE treatment_id = ".$treatmentID;
                                    $this->DataTreatment->getConnection()->execute($str_query_renew);
                                    $this->notify_devices('CI_TREATMENT_DELETED',array($patient_id,$assistance_id),true,false);
                                }
                            }
                        }


                        $this->success();
                    }
                }

            }
        }

    }

    public function detail_treatment() {
        $this->loadModel('SpaLiveV1.DataTreatmentImage');
        $this->loadModel('SpaLiveV1.DataTreatmentNotes');

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


        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user type.');    
            return;
        }


        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }


        $this->loadModel('SpaLiveV1.DataTreatment');

        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','DataTreatment.treatments','DataTreatment.state','DataTreatment.patient_id'];
        

        $_where = ['DataTreatment.uid' => $treatment_uid];
    
        $certTreatment = $this->DataTreatment->find()->select($fields)->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->first();
                        
        $arr_treatments = array();



        $_patient_id = $certTreatment->patient_id;

        // $str_query_ = "
        //     SELECT 
        //         #GROUP_CONCAT(DISTINCT DTC.id) treatments
        //         DTC.id
        //     FROM data_consultation DC
        //     JOIN data_consultation_plan DCP ON DCP.consultation_id = DC.id
        //     JOIN cat_treatments_ci DTC ON DTC.treatment_id = DCP.treatment_id
        //     WHERE DC.patient_id = {$_patient_id} AND DCP.proceed = 1";


        // $str_query_s = "SELECT 
        //         DTP.treatment_id
        //         #GROUP_CONCAT(DISTINCT DTP.treatment_id) treatments
        //     FROM data_treatments_prices DTP
        //     WHERE DTP.deleted = 0 AND DTP.user_id = " . USER_ID;

       

        // $list_patient = $this->DataTreatment->getConnection()->execute($str_query_)->fetchAll('assoc');
        // $list_injector = $this->DataTreatment->getConnection()->execute($str_query_s)->fetchAll('assoc');


        // $str_treatments_ = '';
        // $arr_tr = array();
        // $arr_tr_ci = array();

        // foreach($list_patient as $row) {
        //     $arr_tr[] = intval($row['id']);
        // }

        // foreach($list_injector as $row) {
        //     $arr_tr_ci[] = intval($row['treatment_id']);
        // }

        // $cc = 0;
        // foreach($arr_tr as $element) {
        //     $r = array_search($element, $arr_tr_ci);
        //     if ($r === false) {
        //         unset($arr_tr[$cc]);
        //     }
        //     $cc++;
        // }

        // // $arr_tr = explode(",", $str_treatments_);
        // // $arr_tr = array_unique($arr_tr);
        
        // $str_treatments_ = implode(",", $arr_tr);

        $str_query_ = "
            SELECT 
                GROUP_CONCAT(DISTINCT DTC.type_trmt) type_catego
            FROM data_consultation DC
            JOIN data_consultation_plan DCP ON DCP.consultation_id = DC.id
            JOIN cat_treatments DTC ON DTC.id = DCP.treatment_id
            WHERE DC.patient_id = {$_patient_id} AND DCP.proceed = 1";

        $list_patient = $this->DataTreatment->getConnection()->execute($str_query_)->fetchAll('assoc');
        $str_treatments_type = isset($list_patient[0]['type_catego']) ? $list_patient[0]['type_catego'] : '';


        // if (!empty($certTreatment) && !empty($str_treatments_)) {
        if (!empty($str_treatments_type)) {

            $categories = explode(',', $str_treatments_type);
            
            $_fields = ['DataTreatmentsPrice.price','DataTreatmentsPrice.treatment_id','Treatments.name','Treatments.qty','Treatments.details','Product.comission_spalive'];
            $_fields['certificate'] = "(SELECT DC.uid
                    FROM cat_treatments_ci CTC
                    JOIN data_consultation_plan DCP ON DCP.treatment_id = CTC.treatment_id
                    JOIN data_certificates DC ON DC.consultation_id = DCP.consultation_id
                    JOIN data_consultation DCO ON DCO.id = DC.consultation_id
                    WHERE CTC.id = DataTreatmentsPrice.treatment_id AND DCP.proceed = 1 AND NOW() < DC.date_expiration AND DCO.patient_id = " . $certTreatment->patient_id . "
                    LIMIT 1)";

            $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
            $ent_prices = $this->DataTreatmentsPrice->find()->select($_fields)->join([
                'Treatments' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'Treatments.id = DataTreatmentsPrice.treatment_id'],
                'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = Treatments.product_id'],
            ])->where(
                // ['DataTreatmentsPrice.deleted' => 0, 'FIND_IN_SET(DataTreatmentsPrice.treatment_id, "' . $str_treatments_ . '")' ,'DataTreatmentsPrice.user_id' => USER_ID]
                ['DataTreatmentsPrice.deleted' => 0, 'Product.category IN' => $categories ,'DataTreatmentsPrice.user_id' => USER_ID]
            )->all();

            $data_tr = array();

            if (!empty($ent_prices)) {
                foreach ($ent_prices as $row) {
                    $data_tr[] = array(
                        'name' => $row['Treatments']['name'],
                        'treatment_id' => intval($row['treatment_id']),
                        'price' => intval($row['price']),
                        'qty' => intval($row['Treatments']['qty']),
                        'details' => $row['Treatments']['details'],
                        'comission' => intval($row['Product']['comission_spalive']),
                        'certificate' => !empty($row['certificate']) ? $row['certificate'] : '',

                    );
                }
            }

            $imgsTr = $this->DataTreatmentImage->find()->select(['DataTreatmentImage.file_id'])->where(['DataTreatmentImage.treatment_id' => $certTreatment->id])->toArray();
            $notes = $this->DataTreatmentNotes->find()->select(['DataTreatmentNotes.notes'])->where(['DataTreatmentNotes.treatment_id' => $certTreatment->id])->first();
            $re_array = array(
                'uid' => $certTreatment->uid,
                'schedule_date' => $certTreatment->schedule_date,
                'status' => $certTreatment->status,
                'treatments_detail' => $data_tr,
                'files' =>  isset($imgsTr) ? Hash::extract($imgsTr, '{n}.file_id') : [],
                'notes' => !empty($notes) ? $notes->notes : '',
                );

            $this->success();
            $this->set('data', $re_array);
        }


    }

    public function finish_treatment() {


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


        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user type.');    
            return;
        }

        $notes = get('notes','');
        // if (empty($notes)) {
        //     $this->message('Empty notes.');    
        //     return;
        // }

        $strImgIds = get('imgs_id', '');
        $condImgs = "";
        if(!empty($strImgIds)){
            $condImgs = " AND file_id NOT IN({$strImgIds})";
        }

        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }


        //***

        $string_treatments = get('treatments','');
        if (empty($string_treatments)) {
            $this->message('Treatments empty.');
            return;
        }
        $arr_treatments = explode("|", $string_treatments);

        $this->loadModel('SpaLiveV1.DataTreatmentDetail');
        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        $treatment_prices = "";
        $amount = 0;
        if (count($arr_treatments) > 0) {

            $str_query_del = "
                DELETE FROM data_treatment_detail WHERE treatment_id = " . $ent_treatment->id;

            $this->DataTreatmentDetail->getConnection()->execute($str_query_del);

            $str_del_imgs = "DELETE FROM data_treatment_image WHERE treatment_id = " . $ent_treatment->id . $condImgs;
            $this->DataTreatmentDetail->getConnection()->execute($str_del_imgs);
            
            foreach ($arr_treatments as $_treatment) {

                $arr_components = explode(",", $_treatment);
                
                if (count($arr_components) > 1) {
                    $ent_tr = $this->DataTreatmentsPrice->find()
                    ->where(['DataTreatmentsPrice.treatment_id' => $arr_components[0], 'DataTreatmentsPrice.user_id' => USER_ID,'DataTreatmentsPrice.deleted' => 0])->first();

                    if (empty($ent_tr)) {
                        $this->message('Error in treatments.');
                        return;
                    }

                    if ($arr_components[1] == 0) continue;
                    $save_ar = array(
                        'treatment_id' => $ent_treatment->id,
                        'quantity' => abs($arr_components[1]),
                        'cat_treatment_id' => $arr_components[0],
                        'price' => $ent_tr->price,
                        'total' => $ent_tr->price * $arr_components[1]
                    );

                    $c_entity = $this->DataTreatmentDetail->newEntity($save_ar);
                    if(!$c_entity->hasErrors()) {
                        if (!$this->DataTreatmentDetail->save($c_entity)) {
                            $this->message('Error saving treatments.');
                            return;
                        }
                    }


                    $amount += $ent_tr->price * abs($arr_components[1]);
                }
            }
        }


        //**


        // id,quantity|id.quantity
        $n_id = 0;
        $this->loadModel('SpaLiveV1.DataTreatmentNotes');
        $ent_notes = $this->DataTreatmentNotes->find()->where(['DataTreatmentNotes.treatment_id' => $ent_treatment->id])->first();
        if (!empty($ent_notes)) {
            $n_id = $ent_notes->id;
        }
        $array_save = array(
            'id' => $n_id,
            'treatment_id' => $ent_treatment->id,
            'notes' => $notes,
        );

        $c_entity = $this->DataTreatmentNotes->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatmentNotes->save($c_entity)) {
                $this->success();
                $this->set('uid', $ent_treatment->uid);
                $this->set('grand_total', intval($amount));
                
                $array_save_t = array(
                    'id' => $ent_treatment->id,
                    'status' => 'DONE',
                    'amount' => $amount,
                );

                $nc_entity = $this->DataTreatment->newEntity($array_save_t);
                if(!$nc_entity->hasErrors()) 
                    $this->DataTreatment->save($nc_entity);
            }
        }

    }

    public function save_treatment_notes() {


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


       
        $notes = get('notes','');
        
        $this->loadModel('SpaLiveV1.DataTreatment');

        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $strImgIds = get('imgs_id', '');
        $condImgs = "";
        if(!empty($strImgIds)){
            $condImgs = " AND file_id NOT IN({$strImgIds})";
        }

        $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatment)){
            $this->message('Invalid treatment');
            return;
        }

      
        $n_id = 0;
        $this->loadModel('SpaLiveV1.DataTreatmentNotes');
        $ent_notes = $this->DataTreatmentNotes->find()->where(['DataTreatmentNotes.treatment_id' => $ent_treatment->id])->first();
        if (!empty($ent_notes)) {
            $n_id = $ent_notes->id;
        }
        $array_save = array(
            'id' => $n_id,
            'treatment_id' => $ent_treatment->id,
            'notes' => $notes,
        );

        $c_entity = $this->DataTreatmentNotes->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatmentNotes->save($c_entity)) {
                $str_del_imgs = "DELETE FROM data_treatment_image WHERE treatment_id = " . $ent_treatment->id . $condImgs;
                $this->DataTreatmentNotes->getConnection()->execute($str_del_imgs);
                
                $this->success();
            }
        }

    }

    public function treatment_detail() {

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

        if (USER_TYPE != "injector" && str_replace('+', '-', USER_TYPE) != "gfe-ci") {
            $this->message('Invalid user type.');
            return;
        }


        $treatment_uid = get('uid');
        if (empty($treatment_uid)) {
            $this->message('Invalid treatment');
        }
        $this->loadModel('SpaLiveV1.DataTreatment');

        $ent_treatment = $this->DataTreatment->find()
        ->where(['DataTreatment.uid' => $treatment_uid, 'DataTreatment.deleted' => 0])->first();


        if (empty($ent_treatment)) {
            $this->message('Treatment not found');
            return;
        }

        $this->set('amount', $ent_treatment->amount);
        $this->success();

    }

    // INVENTORY

    public function purchase_detail() {

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

        // if (USER_TYPE != "injector") {
        //     $this->message('Invalid user type.');
        //     return;
        // }




        $purchase_uid = get('uid');
        if (empty($purchase_uid)) {
            $this->message('Invalid purchase');
            return;
        }
        $this->loadModel('SpaLiveV1.DataPurchases');

        $ent_purchase = $this->DataPurchases->find()
        ->where(['DataPurchases.uid' => $purchase_uid, 'DataPurchases.deleted' => 0])->first();


        if (empty($ent_purchase)) {
            $this->message('Purchase not found');
            return;
        }



        $this->set('amount', $ent_purchase->amount);
        $this->success();

    }

    public function inventory_grid() {

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

    
        $this->loadModel('SpaLiveV1.SysUsers');
        

        $fields = [];
        $fields['materials'] = "(SELECT 1 FROM data_trainings DT JOIN cat_trainings CT ON CT.id = DT.training_id WHERE DT.user_id = SysUsers.id AND CT.materials = 1 AND CT.deleted = 0 AND DT.deleted = 0 LIMIT 1)";
        $fields['neurotoxins'] = "(SELECT 1 FROM data_trainings DT JOIN cat_trainings CT ON CT.id = DT.training_id WHERE DT.user_id = SysUsers.id AND CT.neurotoxins = 1 AND CT.deleted = 0 AND DT.deleted = 0 LIMIT 1)";
        $fields['fillers'] = "(SELECT 1 FROM data_trainings DT JOIN cat_trainings CT ON CT.id = DT.training_id WHERE DT.user_id = SysUsers.id AND CT.fillers = 1 AND CT.deleted = 0 AND DT.deleted = 0 LIMIT 1)";


                            
        $e_user = $this->SysUsers->find()->select($fields)->where(['SysUsers.id' => USER_ID])->first();

        
        $allow_materials = $e_user->materials == 1? true : false;
        $allow_neurotoxins = $e_user->neurotoxins == 1? true : false;
        $allow_fillers = $e_user->fillers == 1? true : false;

        if (USER_TYPE == 'clinic') {
            $allow_materials = true;
            $allow_neurotoxins = true;
            $allow_fillers = true;
        }

        
        $res_arr = array();
        
        $this->loadModel('SpaLiveV1.CatProducts');
        $fields = ['CatProducts.id','CatProducts.category','CatProducts.name','CatProducts.sold_as','CatProducts.unit_price','CatProducts.stock','CatProducts.featured'];        
        $ent_featured = $this->CatProducts->find()->select($fields)->where(['CatProducts.stock >' => 0, 'CatProducts.featured' => 1, 'CatProducts.deleted' => 0])->order(['CatProducts.category' => 'ASC'])->toArray();


        foreach ($ent_featured as $row) {

            if ($row['category'] == 'MATERIALS' && !$allow_materials) continue;
            if ($row['category'] == 'NEUROTOXINS' && !$allow_neurotoxins) continue;
            if ($row['category'] == 'FILLERS' && !$allow_fillers) continue;

            $res_arr[] = array(
                'id'=> $row['id'],
                'category'=> 'FEATURED',
                'category2'=> $row['category'],
                'name'=> $row['name'],
                'sold_as'=> $row['sold_as'],
                'unit_price'=> $row['unit_price'],
                'stock'=> $row['stock'],
                'featured'=> $row['featured']
            );
       }


        $ent_products = $this->CatProducts->find()->select($fields)->where(['CatProducts.stock >' => 0, 'CatProducts.featured' => 0, 'CatProducts.deleted' => 0])->order(['CatProducts.category' => 'ASC'])->toArray();

        foreach ($ent_products as $row) {

                if ($row['category'] == 'MATERIALS' && !$allow_materials) continue;
                if ($row['category'] == 'NEUROTOXINS' && !$allow_neurotoxins) continue;
                if ($row['category'] == 'FILLERS' && !$allow_fillers) continue;

                 $res_arr[] = array(
                    'id'=> $row['id'],
                    'category'=> $row['category'],
                    'name'=> $row['name'],
                    'sold_as'=> $row['sold_as'],
                    'unit_price'=> $row['unit_price'],
                    'stock'=> $row['stock'],
                    'featured'=> $row['featured']
                );
           }

        
    
        $this->set('shipping_cost', intval($this->shipping_cost));
        $this->set('shipping_cost_both', intval($this->shipping_cost_both));
        $this->set('shipping_cost_inj', intval($this->shipping_cost_inj));
        $this->set('shipping_cost_mat', intval($this->shipping_cost_mat));


        $this->set('data', $res_arr);
        $this->success();

        if (USER_TYPE == "injector" || USER_TYPE == "clinic" || USER_TYPE == "gfe+ci") {

            $this->loadModel('SpaLiveV1.DataPurchases');
            $ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','User.name','User.lname','User.bname','User.type'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
            ])->limit(100)->order(['DataPurchases.id' => 'DESC'])->where(['DataPurchases.user_id' => USER_ID, 'DataPurchases.payment <>' => ''])->limit(100)->order(['DataPurchases.id' => 'DESC'])->all();

            if(!empty($ent_purchases)){

                //'NEW','PURCHASED','SHIPPED','DELIVERED','CANCELED'
                $tra_array = array(
                    'NEW' => 'NOT SHIPPED YET',
                    'PURCHASED' => 'NOT SHIPPED YET',
                    'SHIPPED' => 'SHIPPED',
                    'DELIVERED' => 'DELIVERED',
                    'CANCELED' => 'CANCELED',
                    'PICKED UP BY SELF' => 'PICKED UP BY SELF',
                    'PARTIALLY SHIPPED' => 'PARTIALLY SHIPPED',
                    'PICKING UP AT CLASS' => 'PICKING UP AT CLASS',
                );


                $result = array();
                foreach ($ent_purchases as $row) {
                    
                    $add_result = array(
                        'id' => $row['id'],
                        'status' => $tra_array[$row['status']],
                        'tracking' => empty($row['tracking']) ? "" : $row['tracking'],
                        'delivery_company' => empty($row['delivery_company']) ? "" : $row['delivery_company'],
                        'shipping_date' => empty($row['shipping_date']) ? "" : $row['shipping_date'],
                        'created' => $row['created'],
                        // 'featured' => $row['featured'],
                        'user_type' => $row['User']['type'],
                        'user_name' => $row['User']['type'] == 'clinic' ? $row['User']['bname'] : $row['User']['name'] . ' ' . $row['User']['lname'],
                    );

                        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
                        $ent_purchases = $this->DataPurchasesDetail->find()->select(['DataPurchasesDetail.price', 'DataPurchasesDetail.qty','Product.name','Product.category'])
                        ->join([
                            'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id']
                        ])->where(['DataPurchasesDetail.purchase_id' => $row['id']])->limit(100)->order(['DataPurchasesDetail.id' => 'DESC'])->all();

                        $detail_array = array();
                        $grand_total = 0;

                        if(!empty($ent_purchases)){
                                
                            foreach ($ent_purchases as $_row) {
                                
                                $detail_array[] = array(
                                    'category' => $_row['Product']['category'],
                                    'name' => $_row['Product']['name'],
                                    'qty' => $_row['qty'],
                                    'price' => $_row['price'],
                                    'total' => $_row['price'] * $_row['qty'],
                                );
                                $grand_total += $_row['price'] * $_row['qty'];
                                
                            }
                        }
                        $add_result['detail'] = $detail_array;
                        $add_result['grand_total'] = $grand_total;

                        $result[] = $add_result;

                }

                $this->set('purchases', $result);
                $this->success();
            }

        }

        $this->success();
    }

    public function get_purchases() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

        $this->loadModel('SpaLiveV1.DataPurchases');
            $ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','User.name','User.lname','User.bname','User.type'])->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
            ])->where(['DataPurchases.payment <>' => ''])->limit(100)->order(['DataPurchases.id' => 'DESC'])->all();

            if(!empty($ent_purchases)){
                $result = array();
                foreach ($ent_purchases as $row) {
                    
                    $add_result = array(
                        'id' => $row['id'],
                        'status' => $row['status'],
                        'tracking' => empty($row['tracking']) ? "" : $row['tracking'],
                        'delivery_company' => empty($row['delivery_company']) ? "" : $row['delivery_company'],
                        'shipping_date' => empty($row['shipping_date']) ? "" : $row['shipping_date'],
                        'created' => $row['created'],
                        'user_type' => $row['User']['type'],
                        'user_name' => $row['User']['type'] == 'clinic' ? $row['User']['bname'] : $row['User']['name'] . ' ' . $row['User']['lname'],
                    );

                         $this->loadModel('SpaLiveV1.DataPurchasesDetail');
                        $ent_purchases = $this->DataPurchasesDetail->find()->select(['DataPurchasesDetail.price', 'DataPurchasesDetail.qty','Product.name','Product.category'])
                        ->join([
                            'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id']
                        ])->where(['DataPurchasesDetail.purchase_id' => $row['id']])->order(['DataPurchasesDetail.id' => 'DESC'])->all();

                        $detail_array = array();
                        $grand_total = 0;

                        if(!empty($ent_purchases)){
                                
                            foreach ($ent_purchases as $_row) {
                                
                                $detail_array[] = array(
                                    'category' => $_row['Product']['category'],
                                    'name' => $_row['Product']['name'],
                                    'qty' => $_row['qty'],
                                    'price' => $_row['price'],
                                    'total' => $_row['price'] * $_row['qty'],
                                );
                                $grand_total += $_row['price'] * $_row['qty'];
                                
                            }
                        }
                        $add_result['detail'] = $detail_array;
                        $add_result['grand_total'] = $grand_total;

                        $result[] = $add_result;

                }

                $this->set('purchases', $result);
                $this->success();
        }
    }

    public function get_treatments() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

         $this->loadModel('SpaLiveV1.DataTreatment');


            $fields = ['DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.amount','DataTreatment.address','DataTreatment.city','State.name','DataTreatment.zip', 'DataTreatment.clinic_patient_id'];
            $fields['injector'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.assistance_id)";
            $fields['patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.patient_id)";
            $fields['clinic_patient'] = "(SELECT CONCAT_WS(' ', U.name, U.lname) FROM sys_users U WHERE U.id = DataTreatment.clinic_patient_id)";
            $fields['treatments_text'] = "(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
            $_where = ['DataTreatment.deleted' => 0];
            // $_where['DataTreatment.status !='] = "DONE";
            // $_where['DataTreatment.status'] = USER_ID;
            // $_where['DataTreatment.status !='] = "CANCEL";
           

            $certTreatment = $this->DataTreatment->find()->select($fields)
            ->join([
                    'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
                ])
                ->where($_where)->order(['DataTreatment.id' => 'DESC'])->all();
                            
            $arr_treatments = array();
            if (!empty($certTreatment)) {
                foreach ($certTreatment as $row) {
                        $arr_treatments[] = array(
                            'treatment_uid' => $row['uid'],
                            'schedule_date' => $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm'),
                            'status' => $row['status'],
                            'treatments' => $row['treatments_text'],
                            'injector' => $row['injector'],
                            'patient' => $row['clinic_patient_id'] > 0 ? $row['clinic_patient'] : $row['patient'],
                            'amount' => $row['amount'],
                            'clinic' => $row['clinic_patient_id'] > 0 ? $row['bname'] : '',
                            'address' => $row['address'] . ', ' . $row['city'] . ', ' . $row['State']['name'] . ' ' . $row['zip'],
                        );
                }
                $this->set('data', $arr_treatments);
            }
    }


    public function get_exams() {
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');

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

         if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }


        $this->loadModel('SpaLiveV1.DataConsultation');

        
        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataConsultation.schedule_by','DataCertificates.date_start','UserA.name'];

        $fields['patient'] = "(SELECT CONCAT_WS(' ', UP.name, UP.lname) FROM sys_users UP WHERE UP.id = DataConsultation.patient_id)";
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
        
        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.status !='] = 'CANCEL';    


        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
            // 'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataConsultation.patient_id'],
            'UserA' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'UserA.id = DataConsultation.assistance_id'],  
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
        
        $result = array();
        if (!empty($certItem)) {
               foreach ($certItem as $row) {
                    $result[] = array(
                        'consultation_uid' => $row['uid'],
                        'payment' => empty($row['payment']) ? 0 : 1,
                        'scheduled' => $row['schedule_by'] == 0 ? false : true,
                        'certificate' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                        'assistance' => empty($row->UserA['name']) ? "" : $row->UserA['name'],
                        'start' => empty($row->DataCertificates['date_start']) ? $row['schedule_date']->i18nFormat('yyyy-MM-dd HH:mm') : $row->DataCertificates['date_start'],
                        'treatments' => isset($row['treatments']) ? $row['treatments'] : '',
                        'patient' => isset($row['patient']) ? $row['patient'] : '',
                    );
                
            }
            $this->set('data', $result);
            $this->success();

        }

    }

    public function get_brands() {

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


        $this->loadModel('SpaLiveV1.CatBrands');
        $this->loadModel('SpaLiveV1.CatPartnerships');


        $ent_brands = $this->CatBrands->find('all')->where(['CatBrands.deleted' => 0]);
        $ent_partners = $this->CatPartnerships->find('all')->where(['CatPartnerships.deleted' => 0]);


        $this->set('brands', $ent_brands);
        $this->set('partnerships', $ent_partners);
        $this->success();

    }

    public function inventory_save() {

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

        if (!MASTER) {
            $this->message('Not allowed.');
            return; 
        }

        $this->loadModel('SpaLiveV1.CatProducts');

            // "id": 2,
            // "category": "NEUROTOXINS",
            // "name": "Jeuveau",
            // "sold_as": "1 vial",
            // "unit_price": 23400,
            // "stock": 1,
            // "comission_spalive": 20,
            // "comission_a": 30,
            // "comission_b": 30,
            // "comission_c": 15,
            // "comission_d": 5

         $array_save = array(
            'category' => get('category',''),
            'name' => get('name',''),
            'sold_as' => get('sold_as',''),
            'unit_price' => intval(get('unit_price',0)),
            'stock' => intval(get('stock',1)),
            'comission_spalive' => intval(get('comission_spalive',0)),
            'comission_a' => intval(get('comission_a',0)),
            'comission_b' => intval(get('comission_b',0)),
            'comission_c' => intval(get('comission_c',0)),
            'comission_d' => intval(get('comission_d',0)),

        );

        $int_id = get('id',0);
        if ($int_id > 0) {
            $array_save['id'] = $int_id;
        }

        $c_entity = $this->CatProducts->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->CatProducts->save($c_entity)) {
                $this->success();
            }
        }

    }

    // public function purchases_grid() {

    //     $token = get('token',"");

    //     if(!empty($token)){
    //         $user = $this->AppToken->validateToken($token, true);
    //         if($user === false){
    //             $this->message('Invalid token.');
    //             $this->set('session', false);
    //             return;
    //         }
    //         $this->set('session', true);
    //     } else {
    //         $this->message('Invalid token.');
    //         $this->set('session', false);
    //         return;
    //     }

    //     if (!MASTER) {
    //         $this->message('Not allowed.');
    //         return; 
    //     }

    //     $this->loadModel('SpaLiveV1.DataPurchases');
    //     $ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','User.name','User.lname','User.bname','User.type'])->join([
    //         'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
    //     ])->limit(100)->order(['DataPurchases.id' => 'DESC'])->all();

    //     if(!empty($ent_purchases)){
    //         $result = array();
    //         foreach ($ent_purchases as $row) {
                
    //             $result[] = array(
    //                 'id' => $row['id'],
    //                 'status' => $row['status'],
    //                 'tracking' => empty($row['tracking']) ? "" : $row['tracking'],
    //                 'delivery_company' => empty($row['delivery_company']) ? "" : $row['delivery_company'],
    //                 'shipping_date' => empty($row['shipping_date']) ? "" : $row['shipping_date'],
    //                 'created' => $row['created'],
    //                 'user_type' => $row['User']['type'],
    //                 'user_name' => $row['User']['type'] == 'clinic' ? $row['User']['bname'] : $row['User']['name'] . ' ' . $row['User']['lname'],
    //             );
                
    //         }
    //     }

    //     $this->set('data', $result);
    //     $this->success();
    // }

    // public function purchases_detail() {

    //     $token = get('token',"");

    //     if(!empty($token)){
    //         $user = $this->AppToken->validateToken($token, true);
    //         if($user === false){
    //             $this->message('Invalid token.');
    //             $this->set('session', false);
    //             return;
    //         }
    //         $this->set('session', true);
    //     } else {
    //         $this->message('Invalid token.');
    //         $this->set('session', false);
    //         return;
    //     }

    //     if (!MASTER) {
    //         $this->message('Not allowed.');
    //         return; 
    //     }

    //     $id_purchase = get('id',0);
    //     if ($id_purchase == 0) {
    //         $this->message('Invalid id.');
    //         return;
    //     }

    //     $this->loadModel('SpaLiveV1.DataPurchasesDetail');
    //     $ent_purchases = $this->DataPurchasesDetail->find()->select(['DataPurchasesDetail.price', 'DataPurchasesDetail.qty','Product.name','Product.category'])
    //     ->join([
    //         'Product' => ['table' => 'cat_products', 'type' => 'INNER', 'conditions' => 'Product.id = DataPurchasesDetail.product_id']
    //     ])->where(['DataPurchasesDetail.purchase_id' => $id_purchase])->limit(100)->order(['DataPurchasesDetail.id' => 'DESC'])->all();

        
    //     if(!empty($ent_purchases)){
    //         $result = array();
    //         $grand_total = 0;
    //         foreach ($ent_purchases as $row) {
                
    //             $result[] = array(
    //                 'category' => $row['Product']['category'],
    //                 'name' => $row['Product']['name'],
    //                 'qty' => $row['qty'],
    //                 'price' => $row['price'],
    //                 'total' => $row['price'] * $row['qty'],
    //             );
    //             $grand_total += $row['price'] * $row['qty'];
                
    //         }
    //     }

    //     $this->set('data', $result);
    //     $this->set('grand_total', $grand_total);
    //     $this->success();
    // }

    // public function purchases_save() {

    //     $token = get('token',"");

    //     if(!empty($token)){
    //         $user = $this->AppToken->validateToken($token, true);
    //         if($user === false){
    //             $this->message('Invalid token.');
    //             $this->set('session', false);
    //             return;
    //         }
    //         $this->set('session', true);
    //     } else {
    //         $this->message('Invalid token.');
    //         $this->set('session', false);
    //         return;
    //     }

    //     if (!MASTER) {
    //         $this->message('Not allowed.');
    //         return; 
    //     }

    //     $this->loadModel('SpaLiveV1.DataPurchases');

    //     $id_purchase = get('id',0);
    //     if ($id_purchase == 0) {
    //         $this->message('Invalid purchase.');
    //         return;
    //     }

    //     $status = get('staus',0);
    //     if (empty($status)) {
    //         $this->message('Invalid purchase.');
    //         return;
    //     }

    //      $array_save = array(
    //         'id' => $id_purchase,
    //         'status' => $status,
    //         'tracking' => get('tracking',''),
    //         'delivery_company' => intval(get('delivery_company','')),

    //     );

    //     $int_id = get('id',0);
    //     if ($int_id > 0) {
    //         $array_save['id'] = $int_id;
    //     }

    //     $c_entity = $this->DataPurchases->newEntity($array_save);
    //     if(!$c_entity->hasErrors()) {
    //         if ($this->DataPurchases->save($c_entity)) {
    //             $this->success();
    //         }
    //     }

    // }


    public function cat_concierge(){

        $this->loadModel('SpaLiveV1.DataConcierge');
        $ent_treatments = $this->DataConcierge->find()->where(['DataConcierge.deleted' => 0, 'DataConcierge.enabled' => 1])->all();
        if(!empty($ent_treatments)){
            $result = array();
            foreach ($ent_treatments as $row) {
                $result[] = array(
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'desc' => $row['desc'],
                    'email' => $row['email'],
                    'lat' => doubleval($row['lat']),
                    'lon' => doubleval($row['lon']),
                );
                
            }
        }

        $this->set('data', $result);
        $this->success();
    }


    public function start_consultation(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');
        

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

        $consultation_uid = get('consultation_uid','');
        if (empty($consultation_uid)) {
            $consultation_uid = Text::uuid();
            $string_answers = get('answers','');
            $arr_answers = json_decode($string_answers,true);
            
            if (empty($arr_answers)) {
                $this->message('Answers empty.');
                return;
            }



            $string_treatments = get('treatments','');
            if (empty($string_treatments)) {
                $this->message('Treatments empty.');
                return;
            }

            $schedule_by = 0;
            $schedule_date = get('schedule_date','');
            if (!empty($schedule_date)) {
                $schedule_by = USER_ID;
            }
            

            if (empty($schedule_date)) 
                $schedule_date = date('Y-m-d H:i:s');


            $createdby = USER_ID;
            $patient_id = USER_ID;
            
            $this->loadModel('SpaLiveV1.SysUsers');
            if ($user['user_role'] == 'clinic' || $user['user_role'] == 'injector' || $user['user_role'] == 'gfe+ci') {

                $_patient_id = $this->SysUsers->uid_to_id(get('patient_uid', ''));
                // $this->message($_patient_id);
                if($_patient_id <= 0) {
                    $this->message('The patient does not exist.');
                    return;
                }
                $patient_id = $_patient_id;
            }

            if ($user['user_role'] == 'patient' || $user['user_role'] == 'clinic' || $user['user_role'] == 'injector' || $user['user_role'] == 'gfe+ci') {
                $available = $this->gfeAvailability($schedule_date);

                if ($available) {
                    if ($schedule_by > 0) {
                        $str_now = date('Y-m-d H:i:s');
                        $arr_conditions = [
                            'DataConsultation.status' => 'INIT',
                            'DataConsultation.schedule_by >' => 0,
                            'DataConsultation.patient_id' => $patient_id,
                            'DataConsultation.deleted' => 0,
                        ];
    
                        $arr_conditions[] = "TIMESTAMPDIFF(MINUTE, DataConsultation.schedule_date, '{$str_now}') <= 15";
                        $ent_consultation = $this->DataConsultation->find()
                            ->where($arr_conditions)
                            ->first();
                        
                        if (!empty($ent_consultation)) {
                            $this->success(false);
                            $this->set('has_scheduled_appointment', true);
                            $this->message('You already have a scheduled appointment.');
                            return false;
                        }
                    }
                } else {
                    $this->success(false);
                    $this->message("Our good faith examiners are available Monday-Saturday from 8 a.m - 8 p.m. If you have reached us outside of our business hours, holidays, etc., please feel free to schedule your exam for a specific date and time or reach back out to us during business hours OR (reconnect with us during business hours) Thank you!");
                    $this->set('not_available', true);
                    return false;
                }
            }

             $array_save = array(
                'uid' => $consultation_uid,
                'patient_id' => $patient_id,
                'assistance_id' => 0,
                'treatments' => $string_treatments,
                'payment' => '',
                'meeting' => '',
                'meeting_pass' => '',
                'schedule_date' => $schedule_date,
                'status' => "INIT",
                'schedule_by' => $schedule_by,
                'deleted' => 0,
                'participants' => 0,
                'createdby' => $createdby,
                'clinic_patient_id' => $patient_id,
                'payment_method' => get('payment_method',''),
                // 'name' => $name,
                // 'email' => $email,
                // 'password' => hash_hmac('sha256', $passwd, Security::getSalt()),
                // 'type' => $userType,
                // 'active' => 1,
                // 'confirm' => 1,
                // 'confirm_code' => 0,
                // 'deleted' => 0,
                // 'createdby' => 0,
                // 'modifiedby' => 0,

            );
            


            

            $r = $this->generateMeeting($schedule_date);

            if ($r) {
                $array_save['meeting'] = $r['id'];
                $array_save['meeting_pass'] = $r['password'];
                $array_save['join_url'] = $r['join_url'];
            } else {
                return;
            }

            $c_entity = $this->DataConsultation->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultation->save($c_entity)) {
                    foreach ($arr_answers as $row) {
                        $arr_save_q = array(
                            'uid' => Text::uuid(),
                            'consultation_id' => $c_entity->id,
                            'question_id' => $row['id'],
                            'response' => $row['response'],
                            'details' => $row['details'],
                            'deleted' => 0
                        );

                        

                        $cq_entity = $this->DataConsultationAnswers->newEntity($arr_save_q);
                        if(!$cq_entity->hasErrors()){
                            $this->DataConsultationAnswers->save($cq_entity);
                        }
                    }
                    
                    $this->success();    
                    

                    // $str_quer = "UPDATE data_consultation SET `status` = 'CANCEL' WHERE patient_id = " . USER_ID . " AND (status = 'ONLINE' OR status = 'INIT') AND schedule_by = 0";
                    // $this->set('schedule_date', $str_quer); return;
                    // $this->DataConsultation->getConnection()->execute($str_quer);

                    // SELECT COUNT(id) FROM data_consultation  WHERE status = \"INIT\"  AND assistance_id = 0  AND schedule_by = 0 AND SU.state = {$examiner_state} 


                    if($user['user_role'] == 'clinic'){
                        $this->loadModel('SpaLiveV1.DataPatientConsult');
                        $saveConsPat = [
                            'consult_id' => $c_entity->id,
                            'patient_clin_id' => $patient_id
                        ];
                        $ent_cons_pat = $this->DataPatientConsult->newEntity($saveConsPat);
                        if(!$ent_cons_pat->hasErrors()) {
                            $this->DataPatientConsult->save($ent_cons_pat);
                            $this->set('uid', $consultation_uid);
                            if ($schedule_by > 0) {
                                $this->set('schedule_date', get('schedule_date',''));
                            }
                        }
                    }

                    $this->set('uid', $consultation_uid);
                    $this->loadModel('SpaLiveV1.SysUsers');
                    $arrUsers = $this->SysUsers->find()->where(['SysUsers.type IN' => array('examiner','gfe+ci'), 'SysUsers.deleted' => 0, 'SysUsers.state' => $user['user_state'], 'SysUsers.login_status' => 'READY','SysUsers.active' => 1])->all();

                    if (!empty($arrUsers)) {
                         $arr_ids = array();
                        foreach ($arrUsers as $row) {
                            if ($row['id'] == USER_ID) continue;
                            $arr_ids[] = $row['id'];
                        }

                        $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id, 'SysUsers.is_test' => 0])->first();
                        if(!empty($ent_patient)){
                            $constants = [
                                '[CNT/PatName]' => trim($ent_patient->name),
                                '[CNT/PatLastName]' => trim($ent_patient->lname),
                                '[CNT/PatPhone]' => preg_replace('~.*(\d{3})[^\d]{0,7}(\d{3})[^\d]{0,7}(\d{4}).*~', '($1)$2-$3', $ent_patient->phone),
                            ];
                            $this->set('notif_param', $constants);
                            $dataNoti = array(
                                'consultation_uid' => $consultation_uid,
                                'patient_name' => $ent_patient->name . ' ' . $ent_patient->lname,
                                'meeting_number' => $array_save['meeting'],
                                'meeting_pass' => $array_save['meeting_pass']
                            );
                            if($schedule_by == 0){
                                $this->notify_devices('NEW_PATIENT_WAITING_ROOM',$arr_ids, true, true, true, $dataNoti,'', $constants, true);
                            }else{
                                $dataNoti = array(
                                    'uid' => $consultation_uid,
                                    'action' => 'open_schedule_room'
                                );
                                $this->notify_devices('SCHEDULED_CONSULTATION',$arr_ids, true, true, true, $dataNoti,'', $constants, true);
                            }
                        }
                        
                        $constants_not = [
                            '[CNT/ScheduleDate]' => date("m-d-Y h:i A", strtotime($schedule_date)),
                        ];
                        $this->notify_devices('SCHEDULED_CONSULTATION_CREATEDBY',array($schedule_by), false, true, true, array(),'', $constants_not, false);
                    }
                    
                }
            }
        

        } else {

            // Schedule
            $this->loadModel('SpaLiveV1.SysUsers');


            $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
            if (!$consultation_id) {
                $this->message('consultation_uid not found.');
                return;
            }

            $schedule_date = get('schedule_date','');
            if (empty($schedule_date)) {
                $this->message('Invalid schedule date..');
                return;
            }

             $array_save = array(
                'id' => $consultation_id,
                'schedule_date' => $schedule_date,
                'schedule_by' => USER_ID,
                'status' => 'INIT',
                'participants' => 0,
                'is_waiting' => 0
            );

            $c_entity = $this->DataConsultation->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                if ($this->DataConsultation->save($c_entity)) {
                    $this->success();

                    $arrUsers = $this->SysUsers->find()->where(['SysUsers.type IN' => array('examiner','gfe+ci'), 'SysUsers.deleted' => 0, 'SysUsers.state' => $user['user_state'], 'SysUsers.login_status' => 'READY','SysUsers.active' => 1])->all();
                    if (!empty($arrUsers)) {
                        $arr_ids = array();
                        foreach ($arrUsers as $row) {
                            if ($row['id'] == USER_ID) continue;
                            $arr_ids[] = $row['id'];
                        }

                        $patient_id = USER_ID;
                        $this->loadModel('SpaLiveV1.SysUsers');
                        if ($user['user_role'] == 'clinic' || $user['user_role'] == 'injector' || $user['user_role'] == 'gfe+ci') {
                            $_patient = $this->DataConsultation->find()->where(['DataConsultation.id' => $consultation_id])->first();
                            if(empty($_patient)) {
                                $this->message('The patient does not exist.');
                                return;
                            }
                            $patient_id = $_patient->patient_id;
                        }

                        $ent_patient = $this->SysUsers->find()->where(['SysUsers.id' => $patient_id])->first();
                        $constants = [
                            '[CNT/PatName]' => trim($ent_patient->name),
                            '[CNT/PatLastName]' => trim($ent_patient->lname),
                            '[CNT/PatPhone]' => preg_replace('~.*(\d{3})[^\d]{0,7}(\d{3})[^\d]{0,7}(\d{4}).*~', '($1)$2-$3', $ent_patient->phone),
                        ];
                        
                        $dataNoti = array(
                            'uid' => '',
                            'action' => 'open_schedule_room'
                        );
                        $this->notify_devices('SCHEDULED_CONSULTATION',$arr_ids, true, true, true, $dataNoti,'', $constants, true);
                    }

                    $constants_not = [
                            '[CNT/ScheduleDate]' => date("m-d-Y h:i A", strtotime($schedule_date)),
                        ];
                        $this->notify_devices('SCHEDULED_CONSULTATION_CREATEDBY',array(USER_ID), false, true, true, array(),'', $constants_not, false);
                }
            }
        }


        
        
        //$this->set('q ', $data_questions);

    }

    public function delete_consultation() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.assistance_id','DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();

         $array_save = array(
            'id' => $ent_consultation->id,
            'deleted' => 1,
        );

        $c_entity = $this->DataConsultation->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataConsultation->save($c_entity)) {
                $this->success();

                $this->notify_devices('SCHEDULED_GFE_DELETED',array($ent_consultation->patient_id, $ent_consultation->assistance_id),true,false);
            }
        }
    }

    public function delete_treatment() {

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

        $this->loadModel('SpaLiveV1.DataTreatment');


        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        // $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->all();
        // if(!empty($ent_treatments)){
        //     $this->set('uid', $ent_treatments);
        //     return;
        // }


        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }


         $array_save = array(
            'id' => $ent_treatments->id,
            'deleted' => 1,
        );

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $str_query_renew = "UPDATE data_schedule_appointments SET deleted = 1 WHERE treatment_id = ".$ent_treatments->id;
                $this->DataTreatment->getConnection()->execute($str_query_renew);

                $this->success();
                 $this->notify_devices('CI_TREATMENT_DELETED',array($ent_treatments->patient_id,$ent_treatments->assistance_id),true,true,true, array(), '',array(),true);
            }
        }
    }

    public function confirm_appointment() {
        

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

        $this->loadModel('SpaLiveV1.DataTreatment');


        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

         $array_save = array(
            'id' => $ent_treatments->id,
            'status' => 'CONFIRM',
        );

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $this->success();
                $this->notify_devices('TREATMENT_CONFIRMED',array($ent_treatments->patient_id),true,true,true, array(), '',array(),true);
            }
        }
    }

    public function reject_appointment() {
        

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

        $this->loadModel('SpaLiveV1.DataTreatment');


        $treatment_uid = get('uid', '');
        if(empty($treatment_uid)){
            $this->message('uid empty.');
            return;
        }

         $treatment_id = $this->DataTreatment->uid_to_id($treatment_uid);
        if (!$treatment_id) {
            $this->message('treatment not found.');
            return;
        }

        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();
        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }

         $array_save = array(
            'id' => $treatment_id,
            'status' => 'REJECT',
        );

        $c_entity = $this->DataTreatment->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataTreatment->save($c_entity)) {
                $str_query_renew = "UPDATE data_schedule_appointments SET deleted = 1 WHERE treatment_id = {$treatment_id}";
                $this->DataTreatment->getConnection()->execute($str_query_renew);

                $this->notify_devices('TREATMENT_REJECTED',array($ent_treatments->patient_id),true,true,true, array(), '',array(),true);
                $this->success();
            }
        }
    }


    public function set_consultation_treatments() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.assistance_id','DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();


        if (empty($ent_consultation)) {
            $this->message('consutation not found');
            return;
        }

        if ($ent_consultation->assistance_id != USER_ID) {
            $this->message('Consultation not available');
            return;
        }

        $number_treatments = get('treatments',0);
        if ($number_treatments == 0) {
            $this->message('Number of treatments error');
            return;
        }
        $tt = $this->total;
        if ($number_treatments > 4) {
            $tt += $this->total;
        }

        $ent_consultation->amount = $tt;
        
        if(!$ent_consultation->hasErrors()) {
            if ($this->DataConsultation->save($ent_consultation)) {
                $this->success();
            }
        }
    }

     public function set_consultation() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.assistance_id','DataConsultation.patient_id','DataConsultation.reserve_examiner_id', 'DataConsultation.createdby'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();


        if (empty($ent_consultation)) {
            $this->message('consutation not found');
            return;
        }

        if (
            ($ent_consultation->assistance_id != USER_ID
            && ($ent_consultation->assistance_id > 0 || $ent_consultation->assistance_id == -1))
            || ($ent_consultation->reserve_examiner_id != USER_ID && $ent_consultation->reserve_examiner_id > 0) ) {
            $this->message('Consultation not available');
            return;
        }

         $array_save = array(
            'id' => $ent_consultation->id,
            'assistance_id' => USER_ID,
        );

        $c_entity = $this->DataConsultation->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataConsultation->save($c_entity)) {
                $this->success();

                if (!empty(get('schedule',''))) {
                    $arr_idss = array($ent_consultation->patient_id,$ent_consultation->assistance_id);
                    if ($ent_consultation->createdby != $ent_consultation->patient_id) $arr_idss[] = $ent_consultation->createdby;
                    $this->notify_devices('SCHEDULED_GFE_CONFIRMED',$arr_idss,true,true);
                    
                } else {
                    $this->notify_devices('GFE_JOINED_MEETING',array($ent_consultation->patient_id),true,true,true, array('typeNotify' => "examiner_joined") );
                    // $this->notify_devices('An examiner has joined to the meeting.',array($ent_consultation->patient_id),true,false);
                }
            }
        }
    }


    public function set_consultation_status() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $str_status = get('status', '');
        if(empty($str_status)){
            $this->message('status empty.');
            return;
        }


        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.uid','DataConsultation.id', 'DataConsultation.status', 'DataConsultation.assistance_id','DataConsultation.participants','DataConsultation.start_date','DataConsultation.end_date','DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();


        if (empty($ent_consultation)) {
            $this->message('consutation not found');
            return;
        }

        $participants = $ent_consultation->participants;
        $start_date = $ent_consultation->start_date ? $ent_consultation->start_date->i18nFormat('yyyy-MM-dd HH:mm:ss') : '';
        $end_date = $ent_consultation->end_date ? $ent_consultation->end_date->i18nFormat('yyyy-MM-dd HH:mm:ss') : '';
        


        $array_save = array(
            'id' => $ent_consultation->id,
            'status' => $str_status,
        );



        if ($str_status == 'ONLINE'){
            $participants = $participants . ',' . USER_ID;
            $array_save['participants'] = $participants;
            if (empty($start_date)) {
                $array_save['start_date'] = date('Y-m-d H:i:s');
            
            }
        }

        $shouldpaycomission = false;
        if ($str_status == 'DONE'){
            $now = date('Y-m-d H:i:s');
            $array_save['end_date'] = $now;
            
            $number_treatments = get('treatments',1);
            // if ($number_treatments == 0) {
            //     $this->message('Number of treatments error');
            //     return;
            // }
            $tt = $this->total;
            if ($number_treatments > 4) {
                $tt += $this->total;
            }

            $this->loadModel('SpaLiveV1.DataPayment');
            $ent_payment = $this->DataPayment->find()
            ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
            
            if (!empty($ent_payment)) {
                $ent_payment->service_uid = $ent_consultation->uid;
                $this->DataPayment->save($ent_payment);

                $array_save['payment'] = $ent_payment->intent;
                $array_save['payment_intent'] = $ent_payment->intent;

                $shouldpaycomission = true;
                

            }

            $array_save['amount'] = $tt;            
              
        }

        if ($str_status == 'CANCEL'){
            if ($ent_consultation->status == 'DONE' || $ent_consultation->status == 'CERTIFICATE') {
                $this->set('assistance_id', $ent_consultation->assistance_id);
                $this->success();
                return;
            }

            if (USER_TYPE == 'examiner') {
                $this->notify_devices('EXAM_CONSULTATION_CANCELED',array($ent_consultation->patient_id),true,false);
            }
        }


        $c_entity = $this->DataConsultation->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataConsultation->save($c_entity)) {
                if ($shouldpaycomission)
                    $this->payGFEComissions($consultation_uid);
                $this->set('assistance_id', $ent_consultation->assistance_id);
                $this->success();
            }
        }
    }

    public function waiting_room() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if ($consultation_id == 0) {
            $this->message('Invalid consultation.');
            return;
        }


        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.status', 'DataConsultation.assistance_id', 'DataConsultation.schedule_by', 'DataConsultation.participants', 'DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();


        if (empty($ent_consultation)) {
            $this->message('consutation not found');
            return;
        }

        if($ent_consultation->status == 'DONE' || $ent_consultation->status == 'CERTIFICATE'){
            return;
        }

        $array_save = array(
            'id' => $consultation_id,
            'status' => "INIT",
            'is_waiting' => 1
        );

        $c_entity = $this->DataConsultation->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataConsultation->save($c_entity);
        }


        if($ent_consultation->schedule_by > 0){
            $listPart = explode(',', $ent_consultation->participants);
            $examiner = [];
            foreach ($listPart as $value) {
                if(!empty($value) && intval($value) != $ent_consultation->patient_id){
                    $examiner[] = $value;
                }
            }
            if(!empty($examiner)){$this->success();}
        }else if ($ent_consultation->assistance_id > 0) {
            $this->success();
        }
        
    }

    public function end_waiting_room() {
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if ($consultation_id == 0) {
            $this->message('Invalid consultation.');
            return;
        }

        $this->set('status', $ent_consultation->status);
        $this->success();

        
    }

    private function renewZoomToken() {

        $this->loadModel('SpaLiveV1.SysZoomTokens');
        $ent_token = $this->SysZoomTokens->find()->select(["SysZoomTokens.refresh_token"])->last();
        
        if(!empty($ent_token)){
            
            $url = "https://zoom.us/oauth/token?";
            $access = Configure::read('App.zoom_api_key');
            $query = "grant_type=refresh_token&refresh_token=" . $ent_token->refresh_token;
            $url .= $query;
            $curl = curl_init();
                curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $query,
                CURLOPT_HTTPHEADER => array(
                    "authorization: Basic " . base64_encode($access),
                    "content-type: application/json",
                  ),
            ));
            $response = curl_exec($curl);
            $err = curl_error($curl);
             
            curl_close($curl);
            // echo $response; exit;
            if (!$err) {
              $arr_response = json_decode($response,true);  
              if (isset($arr_response['error'])) {
                    echo $arr_response['error']; exit;
                    return false;
              } else {
                  
                  if ($arr_response) {
                        $array_save = array(
                            'token' => $arr_response['access_token'],
                            'refresh_token' => $arr_response['refresh_token'],
                            'expires_in' => $arr_response['expires_in'],
                        );
                        $t_entity = $this->SysZoomTokens->newEntity($array_save);
                        if(!$t_entity->hasErrors()) {
                            if ($this->SysZoomTokens->save($t_entity)) {
                                return $arr_response['access_token'];
                            }
                        }
                  }
                }
            }
        }
        return false;
    }

    private function generateMeeting($schedule_date) {

        $zoom_token = $this->renewZoomToken();
        
        if ($zoom_token) {
            $password_meeting = $this->generateRandomString();
            $meeting_settings = '{
               "topic":"SpaLiveMD Meeting",
               "type":2,
               "start_time":"' . $schedule_date . '",
               "password":"' . $password_meeting . '",
               "agenda":"SpaLiveMD Consultation",
               "settings":{
                  "host_video":true,
                  "participant_video":true,
                  "join_before_host":true,
                  "jbh_time":0,
                  "mute_upon_entry":true,
                  "use_pmi":false,
                  "waiting_room":false,
                  "approval_type":2,
                  "allow_multiple_devices":true
               }
            }';


            $u_email = $this->generateZoomUser($zoom_token);
            if (!$u_email) {
                return false;
            }
            $str_url = "https://api.zoom.us/v2/users/" . $u_email . "/meetings";

            
            $curl = curl_init();

            curl_setopt_array($curl, array(
              CURLOPT_URL => $str_url,
              // CURLOPT_URL => "https://api.zoom.us/v2/users/khanzab@gmail.com/meetings",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => "",
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 30,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => "POST",
              CURLOPT_POSTFIELDS => $meeting_settings,
              CURLOPT_HTTPHEADER => array(
                "authorization: Bearer " . $zoom_token,
                "content-type: application/json"
              ),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);
            
            if (!$err) {
              $arr_response = json_decode($response,true);  
              // pr($arr_response); exit;
              if ($arr_response) {
                  $this->set('meeting_id', $arr_response['id']);
                  $this->set('meeting_pass', $arr_response['password']);
                  return $arr_response;
              }
            }
            return false;
        }

    }

    private function generateZoomUser($zoom_token) {
        
        if ($zoom_token) {
            $user_seetings = '{
               "action":"custCreate",
               "user_info":{
                  "email":"' . USER_EMAIL . '",
                  "type":1,
                  "first_name":"' . USER_NAME . '",
                  "last_name":""
               }
            }';

            $curl = curl_init();

            curl_setopt_array($curl, array(
              CURLOPT_URL => "https://api.zoom.us/v2/users/",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => "",
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 30,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => "POST",
              CURLOPT_POSTFIELDS => $user_seetings,
              CURLOPT_HTTPHEADER => array(
                "authorization: Bearer " . $zoom_token,
                "content-type: application/json"
              ),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);
           
            if ($err) {
                return false;
            }
            return USER_EMAIL;
        }

        return false;

    }

    public function checkZoomUsers() {
        
        $zoom_token = $this->renewZoomToken();

        if ($zoom_token) {
            // $user_seetings = '{
            //    "action":"custCreate",
            //    "user_info":{
            //       "email":"' . USER_EMAIL . '",
            //       "type":1,
            //       "first_name":"' . USER_NAME . '",
            //       "last_name":""
            //    }
            // }';

            $curl = curl_init();

            curl_setopt_array($curl, array(
              CURLOPT_URL => "https://api.zoom.us/v2/report/meetings/99118810214/participants/",
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => "",
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 30,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => "POST",
              // CURLOPT_POSTFIELDS => $user_seetings,
              CURLOPT_HTTPHEADER => array(
                "authorization: Bearer " . $zoom_token,
                "content-type: application/json"
              ),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);
           
            if ($err) {
                return false;
            }

            print_r($response); exit;
        }

        return false;

    }

    public function save_findings(){
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');

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


        $findings = get('findings','');
        $arr_findings = json_decode($findings,true);
        if (empty($arr_findings)) {
            // $this->message('Findings empty.');
            // return;
        }

         foreach ($arr_findings as $row) {

            $int_id = $this->DataConsultationAnswers->uid_to_id($row['uid']);
            if ($int_id > 0) {
                $array_save_a = array(
                    'id' => $int_id,
                    'findings' => $row['findings'],
                );

                $cq_entity = $this->DataConsultationAnswers->newEntity($array_save_a);
                if(!$cq_entity->hasErrors()){
                    $this->DataConsultationAnswers->save($cq_entity);
                }
            }
        }

        $this->success();

    }

    public function load_consultations($returnList = false){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatTreatments');


        $c_date = date('Y-m-d H:i:s');

         $str_query_renew = "
             UPDATE data_consultation SET status = 'CANCEL' 
            WHERE (assistance_id = 0 AND schedule_by = 0 AND TIMESTAMPDIFF(minute, created, '{$c_date}') > 10) OR (assistance_id = 0 AND schedule_by > 0 AND TIMESTAMPDIFF(minute, schedule_date, '{$c_date}') > 60) OR (assistance_id > 0 AND status = 'ONLINE' AND schedule_by = 0 AND TIMESTAMPDIFF(minute, created, '{$c_date}') > 120)";
        
        $past_consultations = $this->DataConsultation->getConnection()->execute($str_query_renew);
        

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

        if (USER_TYPE == "examiner" || USER_TYPE == "gfe+ci") {
            $schedule = get('schedule','');



            if (empty($schedule)) {
                $str_query = 'SELECT 
                    DC.id, DC.uid, DC.meeting, DC.meeting_pass,U.`name` patient,U.type ,CS.name state, DC.schedule_date, DC.treatments, DC.assistance_id, DC.created created,
                    (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
                FROM data_consultation DC
                JOIN sys_users U ON U.id = DC.patient_id
                LEFT JOIN data_patient_clinic DPC ON DPC.id = DC.clinic_patient_id
                JOIN cat_states CS ON CS.id = U.state
                WHERE DC.deleted = 0
                AND (
                    ((DC.assistance_id = 0 AND DC.schedule_by = 0 AND status = "INIT")
                    OR (assistance_id = '.$user['user_id'].' AND schedule_by > 0 AND status = "INIT" AND is_waiting = 1))
                    AND (DC.reserve_examiner_id = ' . USER_ID . ' OR DC.reserve_examiner_id = 0)
                )
                AND TIMESTAMPDIFF(second, DC.modified, \'' . $c_date . '\') < 15 AND U.state = ' . $user['user_state'];
            } else {
                 $str_query = 'SELECT 
                    DC.id, DC.uid, DC.meeting, DC.meeting_pass,U.`name` patient, U.type, CONCAT(DPC.name," ",DPC.lastname) pname ,CS.name state, DC.schedule_date, DC.treatments, DC.assistance_id,
                    (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
                FROM data_consultation DC
                JOIN sys_users U ON U.id = DC.patient_id
                LEFT JOIN data_patient_clinic DPC ON DPC.id = DC.clinic_patient_id
                JOIN cat_states CS ON CS.id = U.state
                WHERE DC.deleted = 0 AND DC.schedule_by != 0 AND DC.status = "INIT" AND DC.assistance_id = 0 AND U.state = ' . $user['user_state'];
                ;

                //DC.schedule_date > NOW() AND
                
            }



        } else if (USER_TYPE == "patient") {
            $str_query = "
            SELECT 
                DC.id, DC.uid, DC.meeting, DC.meeting_pass,U.`name` patient,UA.name assistance,UP.state, DC.schedule_date, DC.treatments, DC.assistance_id,
                (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
            FROM data_consultation DC
            JOIN sys_users U ON U.id = DC.patient_id
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            JOIN sys_users_patient UP ON UP.user_id = DC.patient_id
            WHERE DC.deleted = 0 AND DC.patient_id = " . USER_ID . " AND DC.status = 'INIT'" ;
            
        } else if (USER_TYPE == "clinic") {

            $str_query = "
            SELECT 
                DC.id, DC.uid, DC.meeting, DC.meeting_pass,CONCAT(U.`name`,' ',U.lastname) patient,UA.name assistance,U.state, DC.schedule_date, DC.treatments, DC.assistance_id,
                (SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DC.treatments)) treatments
            FROM data_consultation DC
            -- JOIN data_patient_consult PC ON PC.consult_id = DC.id
            LEFT JOIN data_patient_clinic U ON U.id = DC.clinic_patient_id
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            WHERE DC.deleted = 0 AND DC.schedule_by != 0 AND DC.patient_id = " . USER_ID;
        }

        // echo $str_query; exit;

        $ent_consultations = $this->DataConsultation->getConnection()->execute($str_query);

        $result = array();
        if(!empty($ent_consultations)){
            foreach ($ent_consultations as $row) {
                // echo time() - strtotime($row['created']); echo '<br>';
                $patient = $row['patient'];
                if (isset($row['type'])) {
                if ($row['type'] == "clinic") {
                    $patient = isset($row['pname']) ? $row['pname'] . ' (' .$row['patient'] . ')' : $row['patient'];
                }
                }

                $dd = explode(' ',$row['schedule_date']);
                $result[] = array(
                    'uid' => $row['uid'],
                    'meeting_pass' => trim($row['meeting_pass']),
                    'meeting' => trim($row['meeting']),
                    'patient' => $patient,
                    'assistance' => isset($row['assistance']) ? "Dr. " . $row['assistance'] : "",
                    'assistance_id' => $row['assistance_id'],
                    'state' => !empty($row['state']) ? $row['state'] : "",
                    'schedule_date' => $dd[0],
                    'schedule_time' => isset($dd[1]) ? $dd[1] : '',
                    'treatments' => trim($row['treatments']),
                );
                
            }
        }

        if($returnList == true){
            return $result;
        }else{
            $this->set('data', $result);
            $this->success();
        }
    }

    public function load_findings(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if (!$consultation_id) {
            $this->message('consultation_uid not found.');
            return;
        }
        $consultation_status = $this->DataConsultation->find()->where(['DataConsultation.id' => $consultation_id])->first();
        if (!empty($consultation_status)) {
            $this->set('status', $consultation_status->status);
        }


        $ent_answers = $this->DataConsultationAnswers->find()->select(['DataConsultationAnswers.uid','DataConsultationAnswers.details', 'DataConsultationAnswers.response', 'Question.name'])
        ->join([
            'Question' => ['table' => 'cat_questions', 'type' => 'INNER', 'conditions' => 'Question.id = DataConsultationAnswers.question_id']
        ])
        ->where(['DataConsultationAnswers.consultation_id' => $consultation_id, 'DataConsultationAnswers.deleted' => 0])->all();
        if(!empty($ent_answers)){
            $result = array();
            foreach ($ent_answers as $row) {
                $result[] = array(
                    'uid' => $row['uid'],
                    'name' => $row->Question['name'],
                    'response' => $row['response'],
                    'details' => $row['details'],
                );
                
            }
        }

        $this->set('data', $result);
        $this->success();



        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.id'] = $consultation_id;    


        $certItem = $this->DataConsultation->find()->select($fields)
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->first();


        
        $this->set('treatments', str_replace(",", ", ", $certItem['treatments']));
    }

    public function load_plan(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataConsultationPlan');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

         $consultation_id = $this->DataConsultation->uid_to_id($consultation_uid);
        if (!$consultation_id) {
            $this->message('consultation_uid not found.');
            return;
        }


        //$ent_plan = $this->DataConsultationPlan->find()->all();
        $ent_plan = $this->DataConsultationPlan->find('all')->where(['DataConsultationPlan.consultation_id' => $consultation_id, 'DataConsultationPlan.deleted' => 0])->contain(['CatTreatments']);
        if(!empty($ent_plan)){
            $result = array();
            foreach ($ent_plan as $row) {
                $result[] = array(
                    'uid' => $row->uid,
                    'treatment_name' => $row->cat_treatment->name,
                    'detail' => $row->detail,
                    'treatment_id' => $row->treatment_id,
                    'plan' => $row->plan,
                    'proceed' => isset($row->proceed) ? 1 : 0,

                );
            }
            $this->set('data', $result);
        }

        
        $this->success();
    }

    public function save_plan(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataConsultationPlan');
        $this->loadModel('SpaLiveV1.DataCertificates');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid])->first();
        if (empty($ent_consultation)) {
            $this->message('Invalid consultation.');
            return;
        }

        $ent_tmp = $this->DataConsultationPlan->find()->where(['DataConsultationPlan.consultation_id' => $ent_consultation->id])->first();
        if (!empty($ent_tmp)) {
            $this->message('Consultation plan already saved.');
            return;
        }


        $consultation_id = $ent_consultation->id;
       

        $plan = get('plan','');
        $arr_plan = json_decode($plan,true);
        if (empty($arr_plan)) {
            $this->message('Plan empty.');
            return;
        }

        $str_new_treatments = '';
        $sep = '';

        if (count($arr_plan) > 8) {
            $this->message('More than 8 tratments.');
            return;
        }

        foreach ($arr_plan as $row) {
            $int_id = 0;
            if (isset($row['uid'])) 
                $int_id = $this->DataConsultationPlan->uid_to_id($row['uid']);

            $array_save_a = array(
                'id' => $int_id,
                'consultation_id' => $consultation_id,
                'detail' => $row['detail'],
                'treatment_id' => $row['treatment_id'],
                'plan' => $row['plan'],
                'proceed' => $row['proceed'],
                'deleted' => 0,
            );
            
            if ($int_id == 0) {
                $array_save_a['uid'] = Text::uuid();
            }

            $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
            if(!$cp_entity->hasErrors())
                $this->DataConsultationPlan->save($cp_entity);

            if ($row['proceed'] == 1) {
                $str_new_treatments = $str_new_treatments . $sep . $row['treatment_id'];
                $sep = ',';
            }

        }

        $oneYearOn = date('Y-m-d',strtotime(date("Y-m-d", time()) . " + 365 day"));

        $cert_uid = Text::uuid();

        $array_save_c = array(
            'uid' => $cert_uid,
            'consultation_id' => $consultation_id,
            'date_start' => Date('Y-m-d'),
            'date_expiration' => $oneYearOn,
            'deleted' => 0,
        );

        
        $cpc_entity = $this->DataCertificates->newEntity($array_save_c);
            if(!$cpc_entity->hasErrors()){
                $this->DataCertificates->save($cpc_entity);
            }

        // if (substr($str_new_treatments, -1) == ',') {
        //     $str_new_treatments = substr($str_new_treatments, 0, -1);
        // }

        $bk_treatments = $ent_consultation->treatments;
        $ent_consultation->treatments_requested = $bk_treatments;
        $ent_consultation->treatments = $str_new_treatments;
        $ent_consultation->status = "CERTIFICATE";
        $ent_consultation->assistance_id = USER_ID;
        

        // $array_save = array(
        //     'id' => $consultation_id,
        //     'status' => "CERTIFICATE",
        //     'assistance_id' => USER_ID,
        // );

        // $c_entity = $this->DataConsultation->newEntity($array_save);
        if(!$ent_consultation->hasErrors()) {
            if ($this->DataConsultation->save($ent_consultation)) {
                $this->success();

                if ($ent_consultation->payment != "") {


                    $html_content = 'You can download your new certificate by click <a href="' . $this->URL_ROOT . 'panel/pdf_login/?uid=' .  $cert_uid . '" link style="color:#60537A;text-decoration:underline"><strong>here</strong></a>';

                    // https://dev.spalivemd.com/panel/pdf_login/?uid=b1c646bb-839c-40fb-90eb-491a1cd33b20

                    $arr_ddd = array(intval($ent_consultation->patient_id));
                    if ($ent_consultation->patient_id != $ent_consultation->createdby) {
                        $arr_ddd = array(intval($ent_consultation->patient_id),intval($ent_consultation->createdby));
                    }
                    $this->notify_devices('NEW_CERTIFICATE',$arr_ddd,true,true,true,array(),$html_content);

                }
            }
        }

        $this->success();

        //TODO
        //$this->generateCertificate();

    }



    public function get_certificates() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataCertificates');

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

        $patient_uid = get('patient_uid', '');
        $patient_id = 0;
        if(!empty($patient_uid)){
            $patient_id = $this->SysUsers->uid_to_id($patient_uid);
            if($patient_id <= 0){
                $this->message('Invalid patient.');
                return;
            }
        }
        
        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];
        $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
        $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
        $fields['treatments_requested'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments_requested))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.status'] = "CERTIFICATE";
        // $_where['DataConsultation.treatments <>'] = "";

        if(strtoupper($user['user_role']) != 'examiner'){
            $_where['DataConsultation.patient_id'] = ($patient_id > 0) ? $patient_id : USER_ID;
            $_where['DataConsultation.assistance_id >'] = 0;
        }else{
            $_where['DataConsultation.assistance_id'] = USER_ID;
            // $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';
            // $fields['patient'] = "(SELECT CONCAT_WS(' ', UP.name, UP.lastname) FROM sys_users_patient UP JOIN sys_users U ON UP.user_id = U.id WHERE U.id = DataConsultation.patient_id)";
        }
    

        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
        
        $arr_certificates = array();
        if (!empty($certItem)) {
           foreach ($certItem as $row) {
                $arr_certificates[] = array(
                    'consultation_uid' => $row['uid'],
                    'payment' => empty($row['payment']) ? 0 : 1,
                    'certificate_uid' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                    'date_start' => empty($row->DataCertificates['date_start']) ? $row['schedule_date']->i18nFormat('yyyy-MM-dd') : $row->DataCertificates['date_start'],
                    'date_expiration' => empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration'],
                    'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                    'expirate_soon' => false, //isset($row['expirate_soon']) ? ($row['expirate_soon'] == 1 ? true : false) : '',
                    'treatments' => isset($row["treatments"]) ? $row["treatments"] : $row["treatments_requested"],
                    'show_certificate' => isset($row["treatments"]) ? true : false
                );
            }
                
            
            $this->set('data', $arr_certificates);
            $this->success();
        }

    }


    private function generate_certificates() {


        $cert_count = 0;

        $this->loadModel('SpaLiveV1.DataCertificates');
        $fields = ['DataCertificates.uid','User.name','User.lname','DataCertificates.date_start','DataCertificates.date_expiration','DataConsultation.treatments','DataConsultation.patient_id'];

        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';

        $certItem = $this->DataCertificates->find()->select($fields)
        ->join([
            'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
             'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataConsultation.patient_id']
        ])
        ->where(['DataCertificates.uid' => get('uid','')])->first();

        if (!empty($certItem)) {

            $cert_count = 1;

            $data_treatments = $certItem['DataConsultation']['treatments'];

            $arr_tr = explode(",",$data_treatments);

            $str_cert_treaments = $certItem['treatments'];
            if (count($arr_tr) > 4) {
                $arr_tttr = explode(",",$certItem['treatments']);
                $str_cert_treaments = $arr_tttr[0] . ',' . $arr_tttr[1] . ',' . $arr_tttr[2] . ',' . $arr_tttr[3];
            }

            $_treatments = 'TREATMENTS: ' . strtoupper(isset($str_cert_treaments) ? $str_cert_treaments : '');
            $treatments = $_treatments;
            $wi = strlen($treatments);
            $wi2 = 0;
            if (strlen($treatments) > 100) {
                $treatments = wordwrap($treatments, 100, "\n");
                $arr_strings = $arr_tttrr = explode("\n",$treatments);
                $treatments = $arr_tttrr[0];
                $treatments2 = $arr_tttrr[1];
                $wi = strlen($treatments);
                $wi2 = strlen($treatments2);
            }
            

            $name = strtoupper($certItem->User['name'] . ' ' . $certItem->User['lname']);
            $dates = $certItem['date_start']->i18nFormat('MM-dd-yyyy');
            $datef = $certItem['date_expiration']->i18nFormat('MM-dd-yyyy');
            
            
            $im     = imagecreatefromjpeg("files/certhd.jpeg");
            $purple = imagecolorallocate($im, 158, 138, 191);
            $gray = imagecolorallocate($im, 30, 30, 30);
            $px     = (imagesx($im) - 35 * strlen($name)) / 2;
            imagettftext($im, 90, 0, (int)$px - 20, 1100, $gray, './font/Bison-Regular.ttf', $name);
            $pxt     = (imagesx($im) - 25 * $wi) / 2;
            imagettftext($im, 70, 0, (int)$pxt - 20, 1200, $gray, './font/Bison-Regular.ttf', $treatments);
            if (strlen($_treatments) > 100) {
                $pxt     = (imagesx($im) - 25 * $wi2) / 2;
                imagettftext($im, 70, 0, (int)$pxt - 20, 1290, $gray, './font/Bison-Regular.ttf', $treatments2);
            }
            imagettftext($im, 60, 0, 500, 1950, $gray, './font/Bison-Regular.ttf', $dates);
            imagettftext($im, 60, 0, 1900, 1740, $gray, './font/Bison-Regular.ttf', $datef);
            imagepng($im, "files/tmp_Cert.png");
            imagedestroy($im);


            if (count($arr_tr) > 4) {
                $arr_tttrr = explode(",",$certItem['treatments']);
                switch(count($arr_tttrr)) {
                    case 5:
                        $str_cert_treaments = $arr_tttrr[4];
                        break;
                    case 6:
                        $str_cert_treaments = $arr_tttrr[4] . ',' . $arr_tttrr[5]; break;
                    case 7:
                        $str_cert_treaments = $arr_tttrr[4] . ',' . $arr_tttrr[5] . ',' . $arr_tttrr[6]; break;
                    case 8:
                        $str_cert_treaments = $arr_tttrr[4] . ',' . $arr_tttrr[5] . ',' . $arr_tttrr[6] . ',' . $arr_tttrr[7]; break;
                    default:
                        $str_cert_treaments = $arr_tttrr[4];
                }
                
                $cert_count++;
                $name = strtoupper($certItem->User['name'] . ' ' . $certItem->User['lname']);
                $dates = $certItem['date_start']->i18nFormat('MM-dd-yyyy');
                $datef = $certItem['date_expiration']->i18nFormat('MM-dd-yyyy');
                $treatments = 'TREATMENTS: ' . strtoupper($str_cert_treaments);
                $im     = imagecreatefromjpeg("files/certhd.jpeg");
                $purple = imagecolorallocate($im, 158, 138, 191);
                $gray = imagecolorallocate($im, 30, 30, 30);
                $px     = (imagesx($im) - 35 * strlen($name)) / 2;
                imagettftext($im, 90, 0, (int)$px - 20, 1100, $gray, './font/Bison-Regular.ttf', $name);
                $pxt     = (imagesx($im) - 25 * strlen($treatments)) / 2;
                imagettftext($im, 70, 0, (int)$pxt - 20, 1200, $gray, './font/Bison-Regular.ttf', $treatments);
                imagettftext($im, 60, 0, 500, 1950, $gray, './font/Bison-Regular.ttf', $dates);
                imagettftext($im, 60, 0, 1900, 1740, $gray, './font/Bison-Regular.ttf', $datef);
                imagepng($im, "files/tmp_Cert2.png");
                imagedestroy($im);
            }

        }

        return $cert_count;
    }

    public function get_certificate() {

        $panel = get('l3n4p', '');
        $photo_id = get('id', '');
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){

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
        }
        

        $treatments = $this->generate_certificates();

        if ($treatments == 0) exit;

        $hhtml = "<page><img src=\"" . $this->URL_ROOT . "api/files/tmp_Cert.png\" style=\"width:100%; position: absolute; left: 0;\"></page>";
        if ($treatments > 1) {
            $hhtml = "<page><img src=\"" . $this->URL_ROOT . "api/files/tmp_Cert.png\" style=\"width:100%; position: absolute; left: 0;\"></page><page><img src=\"" . $this->URL_ROOT . "api/files/tmp_Cert2.png\" style=\"width:100%; position: absolute; left: 0;\"></page>";
        }
        
        $html2pdf = new HTML2PDF('L','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($hhtml);
        $html2pdf->Output('certificate.pdf', 'I'); //,'D'


        if (get('ry34','') == 'v2rib982jfjbos93kgda2rg') {
            $str_quer = "UPDATE app_tokens SET `deleted` = 1 WHERE token = '{$token}'";
            $this->AppToken->getConnection()->execute($str_quer);
        }

        
        exit;

    }

    // public function get_certificate() {
    //     $this->loadModel('SpaLiveV1.DataCertificates');
    //     $fields = ['DataCertificates.uid','User.name','User.lname','DataCertificates.date_start','DataCertificates.date_expiration'];

    //     $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';

    //     $certItem = $this->DataCertificates->find()->select($fields)
    //     ->join([
    //         'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
    //          'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataConsultation.patient_id']
    //     ])
    //     ->where(['DataCertificates.uid' => get('uid','')])->first();

        
    //     if (!empty($certItem)) {

    //         $name = strtoupper($certItem->User['name'] . ' ' . $certItem->User['lname']);
    //         $dates = $certItem['date_start']->i18nFormat('MM-dd-yyyy');
    //         $datef = $certItem['date_expiration']->i18nFormat('MM-dd-yyyy');
    //         $treatments = 'TREATMENTS: ' . strtoupper($certItem['treatments']);

    //         header('Content-Disposition: Attachment;filename=image.png');
    //         header('Content-type: image/png');
    //         $im     = imagecreatefromjpeg("files/certhd.jpeg");
    //         $purple = imagecolorallocate($im, 158, 138, 191);
    //         $gray = imagecolorallocate($im, 30, 30, 30);
    //         $px     = (imagesx($im) - 35 * strlen($name)) / 2;
    //         // imagettftext($im, 70, 0, (int)$px - 20, 1100, $gray, './font/Montserrat-Regular.otf', $name);
    //         imagettftext($im, 90, 0, (int)$px - 20, 1100, $gray, './font/Bison-Regular.ttf', $name);

            
    //         $pxt     = (imagesx($im) - 25 * strlen($treatments)) / 2;
    //         imagettftext($im, 70, 0, (int)$pxt - 20, 1200, $gray, './font/Bison-Regular.ttf', $treatments);

    //         imagettftext($im, 60, 0, 500, 1950, $gray, './font/Bison-Regular.ttf', $dates);

    //         imagettftext($im, 60, 0, 1900, 1740, $gray, './font/Bison-Regular.ttf', $datef);


    //         imagepng($im);
    //         imagedestroy($im);

    //         exit;
    //     }
       
    // }


    public function get_gfe() {

        $panel = get('l3n4p', '');
        $photo_id = get('id', '');
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){

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
        }


        $this->loadModel('SpaLiveV1.DataCertificates');
        $this->loadModel('SpaLiveV1.DataConsultationPlan');
        $this->loadModel('SpaLiveV1.DataConsultationAnswers');

        $fields = ['DataConsultation.created','DataCertificates.date_start','DataCertificates.consultation_id','DataCertificates.uid','User.name','User.dob','DataConsultation.treatments','DataConsultation.assistance_id', 'SysLicense.number'];
        $fields['tr'] = "(SELECT GROUP_CONCAT(name SEPARATOR \", \") FROM cat_treatments ctr WHERE FIND_IN_SET(ctr.id,DataConsultation.treatments))";
        // $fields['license'] = "(SELECT l.number FROM sys_licences l WHERE l.user_id > 0  )";

        $certItem = $this->DataCertificates->find()->select($fields)
        ->join([
            'DataConsultation' => ['table' => 'data_consultation', 'type' => 'INNER', 'conditions' => 'DataConsultation.id = DataCertificates.consultation_id'],
            'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataConsultation.patient_id'],
            'SysLicense' => ['table' => 'sys_licences', 'type' => 'LEFT', 'conditions' => 'SysLicense.user_id = DataConsultation.assistance_id'],

            // 'License' => ['table' => 'sys_licences', 'type' => 'INNER', 'conditions' => 'License.user_id = 161'],
        ])
        ->where(['DataCertificates.uid' => get('uid','')])->first();


        // print_r('$certItem');
        // print_r($certItem);
        // exit;

        // pr($certItem);exit;
        if (!empty($certItem)) {
            $creaated = $certItem['DataConsultation']['created'];
            $creaated = date("F j, Y, g:i a", strtotime($creaated));

            $fields2 = ['Treatment.name','DataConsultationPlan.plan','DataConsultationPlan.proceed'];

            $planItem = $this->DataConsultationPlan->find()->select($fields2)
            ->join([
                'Treatment' => ['table' => 'cat_treatments', 'type' => 'INNER', 'conditions' => 'Treatment.id = DataConsultationPlan.treatment_id'],
            ])
            ->where(['DataConsultationPlan.consultation_id' => $certItem->consultation_id]);

            $t_proceed = ""; $html_proceed = "";
            $t_nproceed = ""; $html_nproceed = "";
            foreach ($planItem as $row) {
                // print_r($row); exit;
                if ($row->proceed > 0) {
                    $t_proceed .= $row['Treatment']['name'] . ", ";
                    $html_proceed .= "<strong>" .  $row['Treatment']['name'] . "</strong><br>" . "<p>$row->plan</p><br><br>";
                } else {
                    $t_nproceed .= $row['Treatment']['name'] . ", ";
                    $html_nproceed .= "<strong>" .  $row['Treatment']['name'] . "</strong><br>" . "<p>$row->plan</p><br><br>";
                }


            }
            
            if (empty($t_proceed)) $t_proceed = "None";
            if (empty($t_nproceed)) $t_nproceed = "None";


            $fields3 = ['Question.name','DataConsultationAnswers.response', 'DataConsultationAnswers.findings'];

            $ansItem = $this->DataConsultationAnswers->find()->select($fields3)
            ->join([
                'Question' => ['table' => 'cat_questions', 'type' => 'INNER', 'conditions' => 'Question.id = DataConsultationAnswers.question_id'],
            ])
            ->where(['DataConsultationAnswers.consultation_id' => $certItem->consultation_id]);


            $html_ans = ""; $iidx = 1;
            foreach ($ansItem as $row) {
                $rres = $row->response == 1 ? "Yes" : "No";
                $html_ans .= $iidx . ") " . $row['Question']['name'] . " " . $rres . "<br>" . $row->findings . "<br><br>";
                $iidx++;
            }

            

            $name = $certItem->User['name'];
            $dob = $certItem->User['dob'];
            $dob = date("m-d-Y", strtotime($dob)); 
            $licenseNumber = ($certItem->SysLicense['number'] ? 'Electronically signed by Lic#:' . $certItem->SysLicense['number'] . ' | | |' : '');

            $ddate = $certItem->date_start->i18nFormat('MM-dd-yyyy');
     
            $hhtml = "

            <style>


            </style>


            <img src=\"" . $this->URL_ROOT . "api/files/logo.png\"/ style=\"width:150; position: absolute; left: 40%;\">
                <h1 style=\"text-align: center; color:#8a7faf;\">GOOD FAITH EVALUATION</h1>
                <br><br>
                <p><strong>Patient:</strong> $name</p>
                <p><strong>Date of Birth:</strong> $dob</p>
                <p><strong>Date evaluated:</strong> $ddate</p><br>
                <br><br>
                 <p style=\"color:#8a7faf;\"><strong>Treatments approved:</strong></p>  $t_proceed <hr><br>
                $html_proceed

                <p style=\"color:#8a7faf;\"><strong>Treatments denied:</strong></p> $t_nproceed <hr><br>
                $html_nproceed

                <p style=\"text-align: center; color:#8a7faf;\"><strong>MEDICAL HISTORY</strong></p>
                <br><br>
                $html_ans

                <small>Disclaimer: 
This Good Faith Examination conducted by SpaLiveMD, LLC is not intended to be a complete, full medical examination. SpaLiveMD, LLC uses an online, App technology via a standard telehealth protocol to help patients get a Good Faith Exam by a licensed Nurse Practitioner (NP) or a Medical Doctor (MD). SpaLiveMD’s purpose is to provide assessments only for the approval of specific medical spa center treatments. Also, it is not intended to diagnose and/or treat any particular medical disorders, nor is the relationship established with SpaLiveMD designed to replace or substitute for ongoing medical care from your own health care providers.<br><br>
 Please note:  Todays medical exam and evaluation will not guarantee, nor, will always get an approval for the patient, for the recommended treatments patients/clients seek to get. Rarely, but at times, we may have to deny an approval to proceed.  We will then, at that time, recommend you seek additional medical evaluations, diagnostic testing, and/or medical records from your own personal healthcare provider, in order for us to make another determination. There is always a potential for an adverse reaction, and care should be taken to not place yourself at risk, or, at harm. <br><br>
After evaluated by a SpaLiveMD professional, a certificate of approval will be given to you and your provider of choice, which will allow clients/patient a 1 year period to receive the approved treatments with your selected provider. (Other State Laws may apply to this evaluation timeframe). <b>Please note:</b>  Any changes to your medical history may/may not affect this 1 year “SpaLiveMD Certified” Status.  After 1 year, you may revisit our site to get your Certified Status.  You can use this “SpaLiveMD” certificate at a provider of your choice, which shows you have been certified.  You may get approved up to 4 treatments at a time.  Any new treatments will require a new Good Faith Examination, which we will them, give you another certificate with the new treatments listed on the certificate.<br><br>
By having signed SpaLiveMD's agreement, you agreed to the following:<br>
My health care provider of choice has explained to me how the SpaLiveMD, LLC phone app will be used to establish an online consultation. I understand that there are potential risks to this technology, including interruptions and technical connectivity issues. It is always agreed that my health care provider(s) or myself can discontinue the telemedicine consult/visit if the app, website, or videoconferencing connections are not adequate for the good faith exam.  We will then, make appropriate adjustments to assist our clients. If others are present at the time of the good faith exam, other than my health care provider, you are approving them to listen in and hear our questions/your responses to your personal medical health questions we need to hear, in order to make our determination.  You also are approving these people to hear more about your past medical history as well, during the time of your telehealth visit. We recommend you take these calls from a quiet, secure location, with no distractions. <br><br>
Please note: SpaLiveMD will always maintain confidentiality of the information we have obtained from you.  I further understand that I have the right to omit specific details of my medical history/physical examination that are personally sensitive to me. I understand that my evaluation may be recorded for quality assurance.  SpaLiveMD follows all HIPAA regulations regarding the taking and storing of video consultations, and assures our websites are secured and up to date with the latest SSL certifications.<br><br>
 
I have read this document carefully and understand the risks and benefits of the teleconferencing consultation and have had my questions answered, the procedures explained, and I hereby consent to participate in a telemedicine visit under the terms described herein. <br><br>
 
I, the undersigned patient of SpaLiveMD, LLC under penalty of perjury, hereby declare that all the information I am providing today to be accurate and correct.
Make it known, it is expressly understood that I, the undersigned, printed signature, that his/her heirs, assigns or anyone acting on his/her behalf, agree that SpaLiveMD, LLC and its principles, directors, members, agents, associates, and employees are free from all liability, all and any harm or injury to myself because of my receiving treatment from your provider of choice. <b>SpaLiveMD is not affiliated with any spa/clinic provider, and only recommends an approval or a denial to selected treatments.</b><br><br>
 <u>This also does not mean you will not have an adverse reaction to an approved treatment. </u>  Should any medical problems ever develop, we recommend a prompt medical evaluation at your local E.R. and treatment from your health care provider, and/or, an emergency department should immediately be sought out. If in doubt, please call 911.
Clients and clinics, patients and users of our services,  hereby unconditionally and irrevocably release, acquit and forever discharge SpaLiveMD, LLC, its members, agents, nurses, doctors, employees, associates, agents, directors and its principles of and from any and all liabilities, actions, obligations, causes of action, claims, demands, damages, costs, expenses and compensation whatsoever, whether known or unknown, now existing or hereafter arising, at law or in equity or otherwise. 
                </small>

                <br><br>
                <hr>
                <br><br>
                $licenseNumber  on $creaated Telemedically
            ";


            $html2pdf = new HTML2PDF('F','A4','es', true, 'UTF-8', array(10, 10, 10, 10));
            $html2pdf->WriteHTML($hhtml);
            $html2pdf->Output('gfe.pdf', 'I'); //,'D'
            exit;

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

    

    public function list_consultations(){
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

        $find = $this->DataConsultation->find()->select(["DataConsultation.uid", "DataConsultation"])
        ->join([
            'UserInfo' => ['table' => 'sys_users_patient', 'type' => 'LEFT', 'conditions' => 'UserInfo.id = DataConsultation.patient_id'],
            'UserClinic' => ['table' => 'data_patient_clinic', 'type' => 'LEFT', 'conditions' => 'UserClinic.id = DataConsultation.clinic_patient_id'],
        ])->where(['']);
    }

    // public function signature(){
    //     $meeting_number = get('meetingNumber', '');
    //     $role = get('role', '');
    //     $time = time() * 1000 - 30000;//time in milliseconds (or close enough)

    //     if (get('prod_spalive',false)) {
    //         $api_secret = Configure::read('App.stripe_secret_key_prod');
    //         $api_key = Configure::read('App.stripe_publishable_key_prod');
    //     } else {
    //         $api_secret = Configure::read('App.stripe_secret_key');
    //         $api_key = Configure::read('App.stripe_publishable_key');  
    //     }

    //     $data = base64_encode($api_key . $meeting_number . $time . $role);
        
    //     $hash = hash_hmac('sha256', $data, $api_secret, true);
        
    //     $_sig = $api_key . "." . $meeting_number . "." . $time . "." . $role . "." . base64_encode($hash);
        
    //     $this->set('signature', rtrim(strtr(base64_encode($_sig), '+/', '-_'), '='));
    //     $this->success();
    // }

    public function send_private_message() {

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
        $ent_treatments = $this->DataTreatment->find()->where(['DataTreatment.uid' => $treatment_uid])->first();

        if(empty($ent_treatments)){
            $this->message('Treatment not found');
            return;
        }


        $str_message = get('message','');
        if (empty($str_message)) {
            $this->message('message empty.');
            return;   
        }

        $str_type = strtoupper(get('type',''));
        if (empty($str_type)) {
            $this->message('type empty.');
            return;   
        }

        if ($str_type != "TREATMENT") {
            $this->message('invalid type.');
            return;
        }

        $id_from = USER_ID;
        if (USER_TYPE == "patient") {
            $id_to = $ent_treatments->assistance_id;
        } else if (USER_TYPE == "injector" || USER_TYPE == "gfe+ci") {
            $id_to = $ent_treatments->patient_id;
        }

        $array_save = array(
            'type' => $str_type,
            'id_from' => intval($id_from),
            'id_to' => intval($id_to),
            'message' => $str_message,
            'extra' => trim($ent_treatments->uid),
            'deleted' => 0,
            'readed' => 0,
            'created' => date('Y-m-d H:i:s'),
        );
        // pr($array_save); exit;

        $this->loadModel('SpaLiveV1.DataMessages');
        $c_entity = $this->DataMessages->newEntity($array_save);

        if(!$c_entity->hasErrors()) {
            if ($this->DataMessages->save($c_entity)) {
                $this->success();

                $this->notify_devices('You have recieved a message from ' . $user['name'],array($id_to),true,false,false);
            }
        }

    }


    public function get_messages() {

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

        $this->loadModel('SpaLiveV1.DataMessages');
        $find = $this->DataMessages->find()->select(['DataMessages.message','DataMessages.extra','DataMessages.created', 'User.uid','User.short_uid','User.name','User.lname','DataMessages.type', 'DataMessages.readed'])
        ->join([
            'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataMessages.id_from']
        ])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID])->order(['DataMessages.id' => 'DESC'])->all();

        $c_count = $this->DataMessages->find()->select(['DataMessages.id'])->where(["DataMessages.deleted" => 0,'DataMessages.id_to' => USER_ID, 'DataMessages.readed' => 0])->count();

        $arr_treatments = array();
        if (!empty($find)) {
            foreach ($find as $row) {
                $arr_treatments[] = array(
                    'type' => $row['type'],
                    'message' => $row['message'],
                    'read' => $row['readed'],
                    'extra' => $row['extra'],
                    'created' => $row['created']->i18nFormat('yyyy-MM-dd HH:mm'),
                    'from' => !empty($row['User']['name']) ? $row['User']['name'] . ' ' . $row['User']['lname'] : 'SpaLiveMD',
                    'from_short_uid' => !empty($row['User']['short_uid']) ? $row['User']['short_uid'] : '',
                    'from_uid' =>  !empty($row['User']['uid']) ? $row['User']['uid'] : '',
                );
            }
        }



        $str_quer = "UPDATE data_messages SET `readed` = 1 WHERE id_to = " . USER_ID;

        $this->DataMessages->getConnection()->execute($str_quer);

        $this->success();
        $this->set('data', $arr_treatments);
       
    

    }

    public function save_injector_settings() {

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


        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user type.');    
            return;
        }

        $string_prices = get('services','');
        
        $arr_prices = explode('|', $string_prices);
        if (empty($arr_prices)) {
            $this->message('Invalid services string format.');
            return;
        }
        

        $array_save = array(
                'id' => USER_ID,
                'radius' => intval(get('radius',10)),
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
        );

        $m_entity = $this->DataScheduleModel->newEntity($array_save_m);
        if(!$m_entity->hasErrors()) {
            $this->DataScheduleModel->save($m_entity);    
            
        }

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');

        if (count($arr_prices) > 0) {
            $str_query_delete = "
                UPDATE data_treatments_prices SET deleted = 1 WHERE user_id = " . USER_ID;
            $this->DataTreatmentsPrice->getConnection()->execute($str_query_delete);
        }

        $services_array = array();

        foreach ($arr_prices as $row) {
            // services: id,price|id,price
            $arr_inter = explode(",",$row);

            if (count($arr_inter) < 2) continue;

            
            $p_entity = $this->DataTreatmentsPrice->find()->where(['DataTreatmentsPrice.treatment_id' => $arr_inter[0],'DataTreatmentsPrice.user_id' => USER_ID])->first();

            if (!empty($p_entity)) 
                $p_id = $p_entity->id;
            else 
                $p_id = 0;

            $services_array[] = $arr_inter[0];

            $arr_save_q = array(
                'id' => $p_id,
                'user_id' => USER_ID,
                'treatment_id' => $arr_inter[0],
                'price' => $arr_inter[1],
                'deleted' => 0,
            );
            

            $cq_entity = $this->DataTreatmentsPrice->newEntity($arr_save_q);
            if(!$cq_entity->hasErrors()){
                $this->DataTreatmentsPrice->save($cq_entity);
            }
        }

                $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.CatTreatmentsCi');

        $p_entity = $this->DataTreatment->find()->where(['DataTreatment.assistance_id' => USER_ID, 'DataTreatment.status IN' => ['INIT','CONFIRM'], 'DataTreatment.deleted' => 0])->all();
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
        //             $this->notify_devices('TREATMENTS_DELETED',array($row->patient_id), true, true, true, array(),'', $constants_not, true);

        //         }
        //     }

        // }
        
        $this->success(); 
    }

    public function load_injector_settings() {

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


        // if (USER_TYPE != "injector") {
        //     $this->message('Invalid user type.');    
        //     return;
        // }
        $this->loadModel('SpaLiveV1.SysUsers');
        $e_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();

        $array_response = array();

        $array_response['radius'] = $e_user->radius;

        $this->loadModel('SpaLiveV1.DataScheduleModel');
        $lm_entity = $this->DataScheduleModel->find()->where(['DataScheduleModel.injector_id' => USER_ID, 'DataScheduleModel.deleted' => 0])->first();

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

        $str_services = "";

        $this->loadModel('SpaLiveV1.DataTreatmentsPrice');
        $p_entity = $this->DataTreatmentsPrice->find()->join([
            'CTCI' => ['table' => 'cat_treatments_ci', 'type' => 'INNER', 'conditions' => 'DataTreatmentsPrice.treatment_id = CTCI.id AND CTCI.deleted = 0']
        ])
        ->where(['DataTreatmentsPrice.user_id' => USER_ID, 'DataTreatmentsPrice.deleted' => 0])->all();

        if (!empty($p_entity)) {
            $arr_pro = array();
            foreach ($p_entity as $row) {
                $arr_pro[] = $row->treatment_id . ',' . $row->price;
            }

            $str_services = implode("|", $arr_pro);

        }

        $array_response['services'] = $str_services;

        $this->set('data', $array_response);
        $this->set('request_stripe', $this->checkStripeACcount());

        $this->success(); 
     // radius: 
     // days: MONDARY,FRIDAY
     // start: 10
     // end: 15
     // services: id,price|id,price
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


        // if (USER_TYPE != "injector") {
        //     $this->message('Invalid user type.');    
        //     return;
        // }

        $str_products = get('products','');
        if (empty($str_products)) {
            $this->message('Invalid products');
            return;
        }

        $arr_products = explode("|", $str_products);
            
        if (empty($arr_products)) {
            $this->message('Invalid products.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.CatProducts');

        $arr_cat = array(
                'FILLERS' => false,
                'MATERIALS' => false,
                'NEUROTOXINS' => false,
            );

        $arr_products_save = array();
        $grand_total = 0;
        foreach ($arr_products as $row) {

            $arr_product = explode(',', $row);
            $ent_cprod = $this->CatProducts->find()->where(['CatProducts.id' => $arr_product[0]])->first();
            if (!empty($ent_cprod)) {
                
                $quanti = $arr_product[1];
                if ($quanti == 0) continue;
                $array_save = array(
                    'product_id' => $ent_cprod->id,
                    'price' => $ent_cprod->unit_price,
                    'qty' => $quanti
                );
                $arr_cat[$ent_cprod->category] = true;

                $arr_products_save[] = $array_save;
                $grand_total += $ent_cprod->unit_price * $arr_product[1];

            }

        }
        $shipping_cost = $this->shipping_cost;

         if (($arr_cat['NEUROTOXINS'] || $arr_cat['FILLERS']) && $arr_cat['MATERIALS']) {
            $shipping_cost = $this->shipping_cost_both;
        } else if ($arr_cat['NEUROTOXINS'] || $arr_cat['FILLERS']) {
            $shipping_cost = $this->shipping_cost_inj;
        } else if ($arr_cat['MATERIALS']) {
            $shipping_cost = $this->shipping_cost_mat;
        }


        $purchase_uid = Text::uuid();
        $array_save = array(
            'uid' => $purchase_uid,
            'user_id' => USER_ID,
            'status' => 'NEW',
            'name' => get('name',''),
            'address' => get('address',''),
            'suite' => get('suite',''),
            'city' => get('city',''),
            'state' => get('state',''),
            'zip' => get('zip',0),
            'tracking' => '',
            'delivery_company' => '',
            'created' => date('Y-m-d'),
            'shipping_date' => date('Y-m-d'),
            'shipping_cost' => $shipping_cost,
            'amount' => $grand_total,
        );

        $c_entity = $this->DataPurchases->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->DataPurchases->save($c_entity)) {
                $this->set('uid', $purchase_uid);
                
                foreach ($arr_products_save as $a_sav) {
                    $a_sav['purchase_id'] = $c_entity->id;

                    $this->loadModel('SpaLiveV1.DataPurchasesDetail');
                    $csave_entity = $this->DataPurchasesDetail->newEntity($a_sav);
                    if(!$csave_entity->hasErrors()) {
                        $this->DataPurchasesDetail->save($csave_entity);
                    }
                }

                if (USER_TYPE == 'clinic') {
                    $this->checkClinicCredits();
                }

                $this->set('grand_total', $grand_total + $shipping_cost);
                $this->set('shipping_cost', intval($shipping_cost));
                $this->success();


            }
        }

    }




    public function send_message(){

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
        
        $subject = get('subject', 'New Message');
        $message = get('message', '');
        $nameCnt = get('name', '');
        $lnameCnt = get('lname', '');
        $emailCnt = get('email', '');
        $numberCnt = get('phone_number', '');

        $type = get('type', '');
        if(empty($type)){
            $this->message('Empty message.');
            return;
        }

        $html_content = "";
        if ($type == 'contact') {
            $subject = 'New Message';
            $html_content = 'Contact form.
            <br><br>
            You have received this message from ' . $user['name'] . ' (' . $user['user_role'] .') ' . ' - ' . $user['email'] . 
            '<br><br>' .
            '<b>Contact information</b>' .
            '<div style="margin: 12px 16px 12px 24px;">' .
                '<p>Name: ' . $nameCnt . ' ' . $lnameCnt .
                '<p>Phone number: ' . $numberCnt .
                '<p>Email: ' . $emailCnt .
            '</div>' .
            '<br><b>' . $subject . '</b><br>' .
            '<div style="margin: 12px 16px 12px 24px;">' . $message . '</div>';
        } else if ($type == 'partners') {
            $html_content = 'SpaLiveMD PARTNERSHIPS.
            <br><br>
            You have received this message from ' . $user['name'] . ' (' . $user['user_role'] .') ' . ' - ' . $user['email'] . 
            '<br><br><b>' . $subject . '</b><br>' .
            $message;
        } else if ($type == 'brands') {
            $subject = 'Sign up for ';
            $html_content = 'SpaLiveMD BRANDS.
            <br><br>
            You have received this message from ' . $user['name'] . ' (' . $user['user_role'] .') ' . ' - ' . $user['email'] . 
            '<br><br><b>' . $subject  . $message;
        } else if ($type == 'concierge') {
           $html_content = 'CONCIERGE SERVICE.
            <br><br>
            You have received this message from ' . $user['name'] . ' (' . $user['user_role'] .') ' . ' - ' . $user['email'] . 
            '<br><br>Concierge service for provider: <b>' . $subject . '</b><br>' .
            $message;
        } else {
             $this->message('Empty type.');
            return;
        }

        if(empty($message)){
            $this->message('Empty message.');
            return;
        }


        $data=array(
                'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                // 'to'      => 'oscar.caldera@advantedigital.com',
                'to'      => 'info@spalivemd.com',
                // 'to'      => 'khanzab@gmail.com',
                'subject' => 'New message from SpaLiveMD',
                'html'    => $html_content,
            );

            $mailgunKey = $this->getMailgunKey();


        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
        

        // pr(array( 'message' => trim($message), 'from_user' => $user['name'] )); exit;

        // if($this->send_email(/*$user['email']*/'valdezcluis@gmail.com', $subject, 'SpaLiveMD', array( 'message' => trim($message), 'from_user' => $user['name'] ))){
        //     $this->success();
        // }
    }

    public function save_request(){
        $this->loadModel('SpaLiveV1.DataRequest');
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

        $models = ['BRANDS' => 'BRAND', 'PARTNERS' => 'PARTNERSHIP'];
        $message = get('message', '');
        $type = strtoupper(get('type', ''));
        $data = json_decode(get('data', '[]'), true);

        if(!isset($models[$type])){
            $this->message('Invalid type. '.$type);
            return;
        }

        if(empty($message) && $models[$type] == 'PARTNERSHIP'){
            $this->message('Empty message.');
            return;
        }

        if(empty($data)){
            $this->message('Invalid message.');
            return;
        }

        $arraySave = [
            'uid' => $this->DataRequest->new_uid(),
            'model' => $models[$type],
            'request' => $message,
            'data' => json_encode($data),
            'user_id' => $user['user_id'],
        ];

        $ent_req = $this->DataRequest->newEntity($arraySave);
        if(!$ent_req->hasErrors()){
            if($this->DataRequest->save($ent_req)){
                $model = $models[$type] . ($type == 'BRANDS' ? 'S' : '');


                if ($type == 'PARTNERS') {
                    $arr_dd = array();
                    $subject = '';
                    foreach ($data as $row) {
                        $arr_dd[] = $row['name'];
                    }
                    $subject .= implode(" ", $arr_dd);
                } else {
                    $subject = 'Sign up for ';
                    foreach ($data as $row) {
                        $arr_dd[] = $row['name'];
                    }
                    $subject .= implode(", ", $arr_dd);
                    $message = '';
                }

                $html_content = 'SpaLiveMD {$model}.
                <br><br>
                You have received this message from ' . $user['name'] . $user['lname'] . ' (' . $user['user_role'] .') ' . ' - ' . $user['email'] . 
                '<br><br><b>' . $subject . '</b><br>' .$message;

                $data=array(
                    'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                    // 'to'      => 'oscar.caldera@advantedigital.com',
                    'to'      => 'info@spalivemd.com',
                    // 'to'      => 'khanzab@gmail.com',
                    'subject' => 'SpaLiveMD Brands & Partnership new message',
                    'html'    => $html_content,
                );

                $mailgunKey = $this->getMailgunKey();

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
            }else $this->message('Error in save request.');
        }else $this->message('Error in save request.');
    }

    public function save_patient(){
        $this->loadModel('SpaLiveV1.DataPatientClinic');
        $name = get('name', '');
        $city = get('city', '');
        $phone = get('phone', '');
        $state = get('state', '');
        $token = get('token', '');
        $patient_uid = get('uid', '');
        
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
        
        
        if (empty($name)) {
             $this->message('Name is empty.');
            return;
        }

        if (empty($state)) {
             $this->message('State is empty.');
            return;
        }

        $lastname = get('lastname', '');
        if (empty($lastname)) {
             $this->message('Lastname is empty.');
            return;
        }

        $array_save['name'] = trim("{$name} {$lastname}");

        $arr_dob = explode("/", get('dob',''));
        $str_dob = "";

        if (count($arr_dob) < 3) {
            $arr_dob = explode(" ", get('dob',''));
            if (count($arr_dob) < 2) {
                $this->message('Invalid DOB.');
                return;
            }
            $str_dob = $arr_dob[0];            
        }else{
            $str_dob = $arr_dob[2] . '-' . $arr_dob[1] . '-' . $arr_dob[0];
        }


        $array_save = array(
            'clinic_id' => USER_ID,
            'name' => $name,
            'lastname' => $lastname,
            'suffix' => get('suffix',''),
            'address' => get('address',''),
            'city' => $city,
            'state' => $state,
            'country' => 'US',
            'phone' => $phone,
            'weight' => get('weight',''),
            'current_medications' => get('current_medications',''),
            'known_allergies' => get('known_allergies',''),
            'known_mconditions' => get('known_mconditions',''),
            'dob' => $str_dob,
        );



        if(empty($patient_uid)){
            $patient_uid = $this->DataPatientClinic->new_uid();
            $array_save['uid'] = $patient_uid;
        }else{
            $array_save['id'] = $this->DataPatientClinic->uid_to_id($patient_uid);
        }

        $patientEntity = $this->DataPatientClinic->newEntity($array_save);

        if(!$patientEntity->hasErrors()){
            if($this->DataPatientClinic->save($patientEntity)){
                $this->success();
                $this->set('data', ['uid' => $patient_uid, 'name' => $name, 'lastname' => $lastname] );
            }
        } else { 
            $this->message($entity->getErrors());
        }

    }



    public function get_patients_clinic(){
        $this->loadModel('SpaLiveV1.DataPatientClinic');
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

        $find = $this->DataPatientClinic->find()->where(['DataPatientClinic.clinic_id' => USER_ID, 'DataPatientClinic.deleted' => 0])
            ->order(['DataPatientClinic.name' => 'ASC'])->toArray();
        $this->set('data', $find);
        $this->success();
    }

    public function get_patient(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.DataTreatmentImage');
        $this->loadModel('SpaLiveV1.DataTreatment');
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

        $user_id = get('uid', '');
        if(empty($user_id)){
            $this->message('Invalid uid');
            return;
        }

        $ent_users = $this->SysUsers->find()->select(['SysUsers.id', 'SysUsers.uid','SysUsers.short_uid','SysUsers.name','SysUsers.mname','SysUsers.lname','SysUsers.description','SysUsers.email', 'state_name' => 'CatState.name','SysUsers.zip','SysUsers.city','SysUsers.street','SysUsers.phone','SysUsers.dob'])
        ->join([
            'CatState' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'SysUsers.state = CatState.id'],
        ])
        // ->where(['SysUsers.deleted' => 0, 'SysUsers.createdby' => USER_ID, 'SysUsers.uid' => $user_id])->first();
        ->where(['SysUsers.deleted' => 0, 'SysUsers.uid' => $user_id])->first();
        if(empty($ent_users)){
            $this->message('The patient does not exist.');
            return;
        }

        $fields = ['DataConsultation.uid','DataConsultation.payment','DataConsultation.schedule_date','DataCertificates.uid','DataCertificates.date_start','DataCertificates.date_expiration'];
        $fields['assistance'] = "(SELECT UP.name FROM sys_users UP WHERE UP.id = DataConsultation.assistance_id)";
        $fields['expirate_soon'] = "(IF(DATEDIFF(NOW(), DataCertificates.date_expiration) < 30,1,0))";
        $fields['treatments'] = '(SELECT GROUP_CONCAT(DISTINCT CT.name) FROM cat_treatments CT WHERE FIND_IN_SET(CT.id,DataConsultation.treatments))';

        $_where = ['DataConsultation.deleted' => 0];
        $_where['DataConsultation.patient_id'] = $ent_users->id;
        // $_where['DataConsultation.status'] = "DONE";
        $_where['DataConsultation.status'] = "CERTIFICATE";
        $_where['DataConsultation.treatments <>'] = "";
        // $_where['OR'] = [['DataConsultation.status' => "DONE"], ['DataConsultation.status' => "CERTIFICATE"]];
        
    

        $certItem = $this->DataConsultation->find()->select($fields)
        ->join([
            'DataCertificates' => ['table' => 'data_certificates', 'type' => 'LEFT', 'conditions' => 'DataCertificates.consultation_id = DataConsultation.id'],
        ])
        ->where($_where)->order(['DataConsultation.id' => 'DESC'])->all();
        
        $arr_certificates = array();
        
        foreach ($certItem as $row) {
            if(empty($row['payment'])){continue;}
            $arr_certificates[] = array(
                'consultation_uid' => $row['uid'],
                'payment' => empty($row['payment']) ? 0 : 1,
                'certificate_uid' => empty($row['payment']) ? "" : ($row->DataCertificates['uid'] != null ? $row->DataCertificates['uid'] : ""),
                'date_start' => empty($row->DataCertificates['date_start']) ? $row['schedule_date']->i18nFormat('yyyy-MM-dd') : $row->DataCertificates['date_start'],
                'date_expiration' => empty($row->DataCertificates['date_expiration']) ? "" : $row->DataCertificates['date_expiration'],
                'assistance_name' => isset($row['assistance']) ? $row['assistance'] : '',
                'expirate_soon' => false,//isset($row['expirate_soon']) ? ($row['expirate_soon'] == 1 ? true : false) : '',
                'treatments' => isset($row['treatments']) ? $row['treatments'] : '',
            );
        }
            
        $this->set('certificates', $arr_certificates);


        $fields = ['DataTreatment.id','DataTreatment.uid','DataTreatment.schedule_date','DataTreatment.status','DataTreatment.address','State.name'];
        $fields['treatments_string'] = "(SELECT GROUP_CONCAT(CT.name) FROM cat_treatments_ci CT WHERE FIND_IN_SET(CT.id,DataTreatment.treatments))";
        $fields[] = "assistance_id";

        $_where = [
            'DataTreatment.deleted' => 0,
            'DataTreatment.status' => 'DONE',
            // 'DataTreatment.assistance_id' => USER_ID,
            'DataTreatment.patient_id' => $ent_users->id,
        ];
        
        $findedAppoint = $this->DataTreatment->find()->select($fields)->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = DataTreatment.state'],
        ])->where($_where)->order(['DataTreatment.schedule_date' => 'ASC'])->toArray();

        $appointments = [];
        foreach($findedAppoint as $item){
            $imgsTr = $item->assistance_id == USER_ID ? $this->DataTreatmentImage->find()->select(['DataTreatmentImage.file_id'])->where(['DataTreatmentImage.treatment_id' => $item->id])->toArray() : [];
            $appointments[] = [
                'uid' => $item->uid,
                'schedule_date' => $item->schedule_date,
                'status' => $item->status,
                'address' => $item->address,
                'treatments' => $item->treatments_string,
                'files' =>  isset($imgsTr) ? Hash::extract($imgsTr, '{n}.file_id') : []
            ];
        }
        $this->set('appointments', $appointments);

        unset($ent_users['id']);
        $this->set('data', $ent_users);

        $e_u = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if (!empty($user)) {
            $this->set('assistance_uid', $e_u->uid);            
        }

        $this->success();
    }

    public function load_clinic_consultations(){
        $this->loadModel('SpaLiveV1.DataConsultation');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatTreatments');

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

        $str_query_past = "
            SELECT 
                DC.uid, CONCAT_WS(' ', U.`name`, U.`lastname`) patient, UA.name assistance, CERT.date_expiration
            FROM data_consultation DC
            JOIN data_patient_consult PC ON PC.consult_id = DC.id
            JOIN data_patient_clinic U ON U.id = PC.patient_clin_id
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            LEFT JOIN data_certificates CERT ON CERT.consultation_id = DC.id
            WHERE DC.patient_id = " . USER_ID;

        $str_query_pend = "
            SELECT 
                DC.uid, CONCAT_WS(' ', U.`name`, U.`lastname`) patient, UA.name assistance, CERT.date_expiration
            FROM data_consultation DC
            JOIN data_patient_consult PC ON PC.consult_id = DC.id
            JOIN data_patient_clinic U ON U.id = PC.patient_clin_id
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            LEFT JOIN data_certificates CERT ON CERT.consultation_id = DC.id
            WHERE DC.payment <> '' AND DC.patient_id = " . USER_ID;

        $str_query_renew = "
            SELECT 
                DC.uid, CONCAT_WS(' ', U.`name`, U.`lastname`)  patient, UA.name assistance, CERT.date_expiration, DATEDIFF(NOW(), CERT.date_expiration) as days_diff
            FROM data_consultation DC
            JOIN data_patient_consult PC ON PC.consult_id = DC.id
            JOIN data_patient_clinic U ON U.id = PC.patient_clin_id
            LEFT JOIN sys_users UA ON UA.id = DC.assistance_id
            JOIN data_certificates CERT ON CERT.consultation_id = DC.id
            WHERE DC.payment = '' AND DATEDIFF(NOW(), CERT.date_expiration) <= 15 AND DC.patient_id = " . USER_ID;
        

        $past_consultations = $this->DataConsultation->getConnection()->execute($str_query_past)->fetchAll('assoc');
        $pend_consultations = $this->DataConsultation->getConnection()->execute($str_query_pend)->fetchAll('assoc');
        $renw_consultations = $this->DataConsultation->getConnection()->execute($str_query_renew)->fetchAll('assoc');

        $result = ['past_cons' => [], 'pend_cons' => [], 'renw_cons' => []];
        
        
        foreach ($past_consultations as $row) {
            $result['past_cons'][] = [
                'uid' => $row['uid'],
                'patient' => $row['patient'],
                'days_diff' => isset($row['days_diff']) ? $row['days_diff'] : "",
                'date_expiration' => isset($row['date_expiration']) ? $row['date_expiration'] : '',
                'assistance' => isset($row['assistance']) ? $row['assistance'] : '',
            ];
        }
        
        foreach ($pend_consultations as $row) {
            $result['pend_cons'][] = [
                'uid' => $row['uid'],
                'patient' => $row['patient'],
                'days_diff' => isset($row['days_diff']) ? $row['days_diff'] : "",
                'date_expiration' => isset($row['date_expiration']) ? $row['date_expiration'] : '',
                'assistance' => isset($row['assistance']) ? $row['assistance'] : '',
            ];
        }

        foreach ($renw_consultations as $row) {
            $result['renw_cons'][] = [
                'uid' => $row['uid'],
                'patient' => $row['patient'],
                'days_diff' => isset($row['days_diff']) ? $row['days_diff'] : "",
                'date_expiration' => isset($row['date_expiration']) ? $row['date_expiration'] : '',
                'assistance' => isset($row['assistance']) ? $row['assistance'] : '',
            ];
        }


        $this->set('data', $result);
        $this->success();
    }

     public function update_info(){
        $this->loadModel('SpaLiveV1.SysUsers');
        $email = get('email', '');
        $name = get('name', '');
        $lname = get('lname', '');
        $bname = get('bname', '');
        $mname = get('mname', '');
        $description = get('description', '');
        $city = get('city', '');
        $phone = get('phone', '');
        $zip = get('zip', '');
        $ein = get('ein', '');
        $passwd = get('password', '');
        $passwd_conf = get('confirm_password', '');
        $state = !is_numeric(get('state', '')) ? 43 : get('state', 43);
        $street = get('street', '');
        $suite = get('suite', '');
        $token = get('token', '');
        $enable_notifications = intval(get('enable_notifications', 0));
        
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

        if ($email != USER_EMAIL) {
            $existUser = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($email), 'SysUsers.id <>' => USER_ID])->first();

            if(!empty($existUser)){
                $this->message('Email address already registered.');
                return;
            }
        }

        if (empty($email)) {
             $this->message('Email address empty.');
            return;
        }

        
        if (empty($name)) {
             $this->message('Name is empty.');
            return;
        }

        if (empty($state)) {
             $this->message('State is empty.');
            return;
        }


        $user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
        if(!empty($passwd)){
            if($passwd != $passwd_conf){
                $this->message('Password and confirmation are not the same.');
                return;
            }   
             $user->password = hash_hmac('sha256', $passwd, Security::getSalt());
        }
        $user->email = $email;
        $this->SysUsers->save($user);


        $otherFormat = false;
        $dob_value = get('dob','');
        $str_dob = "";
        $date = \DateTime::createFromFormat('Y-m-d', $dob_value);
        if(!empty($date)){
             $str_dob = $dob_value;
        }
        else {
            $arr_dob = explode("/", $dob_value);
            if (count($arr_dob) < 3) {
                $arr_dob = explode(" ", $dob_value);
                if (count($arr_dob) < 2) {
                    $this->message('Invalid DOB.');
                    return;
                }
                $str_dob = $arr_dob[0];            
            }else{
                $str_dob = $arr_dob[2] . '-' . $arr_dob[1] . '-' . $arr_dob[0];
            }   
        }

        if ($user->street != $street || $user->city != $city || $user->zip != $zip || $user->state != $state) {
            $this->loadModel('SpaLiveV1.CatStates');
            $obj_state = $this->CatStates->find()->select(['CatStates.name'])->where(['CatStates.id' => $user->state])->first();

            $gmap_key = "AIzaSyAjgOOZWRGxB_j9AZUKgoa0ohzS3GQ--nU";//Configure::read('App.google_maps_key');
                        
            $chain =  $street . ' ' . $city . ' ' . $zip . ' ,' . $obj_state->name;
            $url = 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode($chain) . '&key=' . $gmap_key; 
            
            $responseData = file_get_contents($url);
            
            $response_json = json_decode($responseData, true);
    
            if($response_json['status']=='OK') {
                $latitude = isset($response_json['results'][0]['geometry']['location']['lat']) ? $response_json['results'][0]['geometry']['location']['lat'] : "";
                $longitude = isset($response_json['results'][0]['geometry']['location']['lng']) ? $response_json['results'][0]['geometry']['location']['lng'] : "";
                if ($latitude && $longitude) {
                    $user->latitude = $latitude;
                    $user->longitude = $longitude;
                }
    
            }

        }
        
        $user->email = $email;
        $user->name = $name;
        $user->lname = $lname;
        $user->mname = $mname;
        $user->bname = $bname;
        $user->city = $city;
        $user->phone = $phone;
        $user->street = $street;
        $user->suite = $suite;
        $user->state = $state;
        $user->description = $description;
        $user->zip = $zip;
        $user->dob = $str_dob;
        $user->ein = $ein;
        $user->enable_notifications = $enable_notifications;

        if ($enable_notifications == 1) {
            $str_query = "DELETE FROM api_devices WHERE user_id = " . USER_ID;
            $this->SysUsers->getConnection()->execute($str_query);

        }


        if(!$user->hasErrors()){
            $this->SysUsers->save($user);
            $this->success();
        } 

    }

    public function load_info(){
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

        $this->loadModel("SpaLiveV1.SysUsers");

        $_usr_uid = get('uid', '');
        if(!empty($_usr_uid)){
            $_where = ["SysUsers.uid" => $_usr_uid];
        }else{
            $_where = ["SysUsers.id" => USER_ID];
        }


        $first_day = date('Y-m-01');
        $last_day = date('Y-m-t');    
        $most_reviewed = $this->DataTreatmentReview->injectorMostReviewed();
        $find = $this->SysUsers->find()->select(['SysUsers.uid','SysUsers.name', 'SysUsers.lname', 'SysUsers.mname','SysUsers.bname','SysUsers.city','SysUsers.dob','SysUsers.short_uid','SysUsers.score','SysUsers.photo_id','SysUsers.ein','SysUsers.email','SysUsers.phone','SysUsers.state','SysUsers.street','SysUsers.zip','SysUsers.type','SysUsers.score','State.name','SysUsers.photo_id','SysUsers.score','SysUsers.description',
            'SysUsers.enable_notifications', 'SysUsers.suite', 'is_ci_of_month' => "(SELECT COUNT(InjM.id) FROM data_injector_month InjM WHERE InjM.injector_id = SysUsers.id AND InjM.deleted = 0 AND InjM.date_injector BETWEEN '{$first_day}' AND '{$last_day}')"])
        ->join([
            'State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SysUsers.state']
        ])->where($_where)->first();

        if(empty($find)){
            $this->message('Can´t find user');
            return;
        }else{
            $find['enable_notifications'] = (isset($find['enable_notifications']) && ($find['enable_notifications'] === true || $find['enable_notifications'] == 1)) ? 1 : 0;
            $ver = get('version', '');
            $key = get('key', '');
            $ver = str_replace('version ', '', $ver);
            
            unset($find['id']);
            unset($find['active']);
            unset($find['user_id']);
            unset($find['deleted']);
            unset($find['created']);
            unset($find['password']);
            unset($find['modified']);
            unset($find['modifiedby']);
            $state = $find['state'];
            $find['state_txt'] = $find['State']['name'];
            $find['state'] = $state;
            unset($find['State']);
            $find['most_reviewed'] = in_array($user['user_id'], $most_reviewed) ? 1 : 0;
            $find['is_ci_of_month'] = ($find['is_ci_of_month'] > 0 ? 1 : 0);
            if (!isset($find['dob'])) $find['dob'] = '';
            $this->set('data', $find);
            $this->set('type', $find['type']);
            $this->success();
        }


        if (USER_TYPE == 'injector') {

             $this->loadModel('SpaLiveV1.DataTreatmentReview');
            $ent_comments = $this->DataTreatmentReview->find()->select(['DataTreatmentReview.score','DataTreatmentReview.comments','DataTreatmentReview.created','User.name'])
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataTreatmentReview.createdby']])
            ->where(['DataTreatmentReview.injector_id' => USER_ID, 'DataTreatmentReview.deleted' => 0])->all();
            
            $data_comments = array();
            if (!empty($ent_comments)) {
                foreach ($ent_comments as $row) {
                    
                    $data_comments[] = array(
                        'score' => intval($row['score']),
                        'comments' => $row['comments'],
                        'created' => $row['created']->format("Y-M-d"),
                        'name' => $row->User['name'],
                    );
                }
            }

             $this->set('comments', $data_comments);
        }

        if (USER_TYPE == 'examiner' || USER_TYPE == 'injector' || USER_TYPE == 'gfe+ci') {

            $this->loadModel('SpaLiveV1.SysLicence');
            $licenceItem = $this->SysLicence->find()->select(['SysLicence.id','SysLicence.number'])->where(['SysLicence.user_id' => USER_ID, 'SysLicence.deleted' => 0])->all();
            $this->set('licenses', $licenceItem);
        }

        $shouldRequestStripe = $this->checkStripeACcount();
        $this->set('request_stripe', $shouldRequestStripe);

    }

    public function remove_license(){

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

        $this->loadModel('SpaLiveV1.SysLicence');
        $licenceItem = $this->SysLicence->find()->select(['SysLicence.id'])->where(['SysLicence.user_id' => USER_ID,'SysLicence.id' => get('license_id')])->first();

        if (!empty($licenceItem)) {
            $licenceItem->deleted = 1;
            $this->SysLicence->save($licenceItem);
            $this->success();
        }

    }

    // Network

    // public function get_network() {

    //     $token = get('token', '');
        
    //     if(!empty($token)){
    //         $user = $this->AppToken->validateToken($token, true);
    //         if($user === false){
    //             $this->message('Invalid token.');
    //             $this->set('session', false);
    //             return;
    //         }
    //         $this->set('session', true);
    //     } else {
    //         $this->message('Invalid token.');
    //         $this->set('session', false);
    //         return;
    //     }

    //     $userType = $user['user_role'];

    //     if ($userType != "injector") {
    //         $this->message('Invalid user.');
    //         return;
    //     }

    //     $this->loadModel("SpaLiveV1.DataNetwork");

    //     $result_array = array();
    //     $arr_search = array();
    //     $grand_total = 0;
    //     $arr_search[] = array('user_id' => USER_ID);

    //     for($i = 1; $i <= 5; $i++) {
    //         $arr_search = $this->get_tree($arr_search);
    //         if (empty($arr_search))
    //             break;
    //         $result_array[] = array(
    //             'level' => $i,
    //             'data' => $arr_search
    //         );
    //         $grand_total += count($arr_search);
    //     }
            
    //     $this->set('data', $result_array);
    //     $this->set('total', $grand_total);
    //     $this->success();

    // }

    private function get_tree($arr_network, $addEmail = false) {

        $this->loadModel("SpaLiveV1.DataNetwork");

        $result_array = array();        

        foreach ($arr_network as $row) {
            
            $parent_id = $row['user_id'];

            $ent_network = $this->DataNetwork->find()->select(["DataNetwork.parent_id","DataNetwork.user_id","User.short_uid","User.name","User.email","User.lname","User.short_uid","User.active"])
            ->join([
                'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataNetwork.user_id AND User.login_status = \'READY\'']
            ])
            ->where(["DataNetwork.parent_id" => $parent_id,"User.deleted" => 0])->all();
                
                
            if(!empty($ent_network)){
                $inter_array = array();
                
                foreach ($ent_network as $row) {
                    $tmp = array(
                        'name' => $row['User']['name'] . ' ' . $row['User']['lname'],
                        'short_uid' => $row['User']['short_uid'],
                        'user_id' => $row['user_id'],
                        'active' => $row['User']['active']
                    );
                    if($addEmail == true)$tmp['email'] = $row['User']['email'];
                    $result_array[] = $tmp;
                } 
            }

        }


        return $result_array;

    }

    // private function get_leveltree($user_id) {

    //     if ($user_id == 0) return array();
    //     $this->loadModel("SpaLiveV1.DataNetwork");

    //     $result_array = array();        

    //         $parent_level = 0;
    //         $ent_level = $this->DataNetwork->find()->where(['DataNetwork.user_id' => $user_id])->first();
    //         if(!empty($ent_level)) {
    //             $parent_level = $ent_level->level;
    //         }

    //         if ($parent_level == 0) {
    //             $this->loadModel("SpaLiveV1.SysUsers");
    //             $_fields = ['SysUsers.id','SysUsers.name','SysUsers.lname','SysUsers.short_uid','DN.level'];
    //             $_where = ['SysUsers.id >' => $user_id];
    //             $_where['SysUsers.type'] = 'injector';
    //             $_where['SysUsers.deleted'] = 0;
    //             $_where['SysUsers.active'] = 1;
    //             $_where['SysUsers.login_status'] = 'READY';
    //             $_where['OR'] = [['DN.user_id IS' => null],['DN.parent_id' => 0]];
    //             $ent_user = $this->SysUsers->find()->select($_fields)
    //             ->join([
    //                 'DN' => ['table' => 'data_network', 'type' => 'LEFT', 'conditions' => 'DN.user_id = SysUsers.id']
    //             ]) 
    //             ->where($_where)
    //             ->order(['SysUsers.id' => 'ASC'])->first();

    //             if (!empty($ent_user)) {
    //                 $result_array = array(
    //                     'name' => $ent_user->name . ' ' . $ent_user->lname,
    //                     'short_uid' => $ent_user->short_uid,
    //                     'user_id' => $ent_user->id,
    //                     // 'level' => $ent_user['DN']['level']
    //                 );
    //             }

    //         }

    //     return $result_array;

    // }

    // private function notify_devices($message, $arr_users, $notify_push = false, $notify_email = false, $shouldSave = true) {
    public function notify_devices($message, $arr_users, $notify_push = false, $notify_email = false, $shouldSave = true, $data = array(),$body_extra = '', $constants = array(), $notify_sms = false) {

        $is_dev = env('IS_DEV', false);
        $av_result = $this->gfeAvailability();
        // if (!$av_result) return;

        $this->loadModel('SpaLiveV1.CatNotifications');
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $message])->first();
         
        if (!empty($ent_notification)) {
            $msg_mail = $ent_notification['body'];
            $msg_push = $ent_notification['body_push'];
            foreach($constants as $key => $value){
                $msg_mail = str_replace($key, $value, $msg_mail);
                $msg_push = str_replace($key, $value, $msg_push);
            }

            $conf_subject = $ent_notification['subject'];
            $conf_body = $msg_mail;
            $conf_body_push = $msg_push;
            $conf_body .= '<br><br>' . $body_extra;
        } else {
            $conf_subject = 'SpaLiveMD Notification';
            $conf_body = $message;
            $conf_body_push = $message;
        }

        
        if ($notify_email && $av_result) {

            $this->loadModel('SpaLiveV1.SysUsers');
            $str_str_users = implode(",",$arr_users);
            
            
            $str_query = "
                SELECT 
                    GROUP_CONCAT(SU.email) emails
                FROM sys_users SU
                WHERE SU.deleted = 0 AND SU.enable_notifications = 1 AND (SU.login_status = \"READY\" OR SU.login_status = \"CHANGEPASSWORD\" OR SU.login_status = \"PAYMENT\") AND FIND_IN_SET(SU.id,'{$str_str_users}')";


            $ent_query = $this->SysUsers->getConnection()->execute($str_query)->fetchAll('assoc');
            
            if (!empty($ent_query)) {
                $ems = $ent_query[0]['emails'];
                $this->send_new_email($conf_body,$ems,$conf_subject);
            }
            
        
        }

        if ($notify_push && $av_result) {

            $array_conditions = [
                'ApiDevice.application_id' => APP_ID
            ];

            $array_conditions['ApiDevice.user_id IN'] = $arr_users;
            
            $this->loadModel('SpaLiveV1.ApiDevice');
            $ent_devices = $this->ApiDevice->find()->where($array_conditions)->toArray();

            $arr_devices = array();

            foreach ($ent_devices as $row) {
                $arr_devices[] = $row->id;
            } 

            $this->loadModel('SpaLiveV1.DataNotification');
            if (!defined('USER_ID')) define('USER_ID', 0);
            $arrSave = array(
                'type' => 'NOTIFICATION',
                'message' => $conf_body_push,
                'json_users' => json_encode($arr_users),
                'json_data' => json_encode($data),
                'user_id' => USER_ID,
            );
            $ent_noti = $this->DataNotification->newEntity($arrSave);
            if(!$ent_noti->hasErrors()){
                $this->DataNotification->save($ent_noti);
            }

            $this->send($conf_body_push,$data,$arr_devices);

        }

        if ($shouldSave) {

             foreach ($arr_users as $user_id) {
                
                $array_save = array(
                    'type' => 'NOTIFICATION',
                    'id_from' => 0,
                    'id_to' => $user_id,
                    'message' => $conf_body_push,
                    'extra' => '',
                    'deleted' => 0,
                    'readed' => 0,
                    'created' => date('Y-m-d H:i:s'),
                );

                $this->loadModel('SpaLiveV1.DataMessages');
                $c_entity = $this->DataMessages->newEntity($array_save);

                if(!$c_entity->hasErrors()) 
                    $this->DataMessages->save($c_entity);
                
            }
        }

        if ($notify_sms && $av_result && $is_dev === false) {

            $this->loadModel('SpaLiveV1.SysUsers');
            $array_conditions = [];
            $array_conditions['SysUsers.id IN'] = $arr_users;
            $array_conditions['SysUsers.is_test'] = 0;
            
            $ent_devices = $this->SysUsers->find()->where($array_conditions)->toArray();

            $fixed_numbers = array();

            foreach($fixed_numbers as $num) {
                
                try {           
                    $sid    = env('TWILIO_ACCOUNT_SID'); 
                    $token  = env('TWILIO_AUTH_TOKEN'); 
                    $twilio = new Client($sid, $token); 
                     
                    $message = $twilio->messages 
                              ->create($num, // to 
                                       array(  
                                           "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                           "body" => $conf_body_push 
                                       ) 
                              ); 
                 } catch (TwilioException $e) {
                 }
            }
            

            foreach($ent_devices as $ele) {

                $phone_number = '+1' . $ele->phone;

                try {           
                    $sid    = env('TWILIO_ACCOUNT_SID'); 
                    $token  = env('TWILIO_AUTH_TOKEN'); 
                    $twilio = new Client($sid, $token); 
                     
                    $message = $twilio->messages 
                              ->create($phone_number, // to 
                                       array(  
                                           "messagingServiceSid" => "MG65978a5932f4ba9dd465e05d7b22195e",      
                                           "body" => $conf_body_push 
                                       ) 
                              ); 
                 } catch (TwilioException $e) {
                 }
                

            }

        }

    }


    public function send_new_email($html_content,$email,$subject = "New alert from SpaLiveMD") {

           $data = array(
                'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                'to'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                'bcc'      => $email,
                'subject' => $subject,
                'html'    => $html_content,
            );

            $mailgunKey = $this->getMailgunKey();


            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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



    public function invite_injector() {

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

        if (USER_TYPE != "injector" && USER_TYPE != "gfe+ci") {
            $this->message('Invalid user.');
            return;
        }

        $str_email = get('email','');
        if(empty($str_email)) {
            $this->message('Empty email.');
            return;
        }


        $this->loadModel('SpaLiveV1.DataNetworkInvitations');
        $this->loadModel('SpaLiveV1.SysUsers');

        $existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower($str_email)])->first();

        if(!empty($existUser)){
            $this->message('This email has already been invited.');
            return;
        }

        $existUser2 = $this->SysUsers->find()->where(['SysUsers.email LIKE' => strtolower($str_email), 'SysUsers.deleted' => 0])->first();

        if(!empty($existUser2)){
            $this->message('This email has already been registered.');
            return;
        }


        $this->loadModel("SpaLiveV1.CatNotifications");
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => 'CI_TO_CI_INVITE'])->first();
        if (!empty($ent_notification)) {

            $msg_mail = $ent_notification['body'];

            $e_user = $this->SysUsers->find()->where(['SysUsers.id' => USER_ID])->first();
 
            $constants = [
                '[CNT/CIName]' => $e_user['name'],
                '[CNT/CILastName]' => $e_user['lname'],
            ];
            foreach($constants as $key => $value){
                    $msg_mail = str_replace($key, $value, $msg_mail);
                }
                            

            $html_content = '<img src="https://app.spalivemd.com/panel/img/logo_colored.png" width="100px"/>' . $msg_mail;

             $data=array(
                'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                'to'      => $str_email,
                'subject' => 'New message from SpaLiveMD',
                'html'    => $html_content,
            );

            $mailgunKey = $this->getMailgunKey();

            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
        

        $this->loadModel("SpaLiveV1.DataNetworkInvitations");


        $array_save = array(
            'email' => $str_email,
            'parent_id' => USER_ID,
        );

        $c_entity = $this->DataNetworkInvitations->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            $this->DataNetworkInvitations->save($c_entity);    
        }

        $this->success();

    }

    public function upload_user_photo(){
        
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

        
        $array_save = array(
            'id' => USER_ID,
            'photo_id' => $_file_id,
        );

        $this->loadModel("SpaLiveV1.SysUsers");

        $c_entity = $this->SysUsers->newEntity($array_save);
        if(!$c_entity->hasErrors()) {
            if ($this->SysUsers->save($c_entity)) {
                $this->set('photo_id', $_file_id);
                $this->success();
            }
        }

    }

    public function save_bug(){
        $this->loadModel('SpaLiveV1.AppBug');
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
        

        $title       = get('title', '');
        $description = get('description', '');

        $errors = array();
        if(empty($title) || strlen($title) < 8){
            $errors[] = "Invalid title. minimum 8 characters";
        }
        if(empty($description) || strlen($description) < 20){
            $errors[] = "Invalid description. minimum 20 characters";
        }

        if(!empty($errors)){
            $msg = implode('\n', $errors);
            $this->message($msg);
            return;
        }


        $arraySave = array(
            'uid'         => $this->AppBug->new_uid(),
            'title'       => $title,
            'description' => $description,
            'op_system'   => get('op_system', ''),
            'ver_system'  => get('ver_system', ''),
            'file_id'     => 0
        );

        if(isset($_FILES['file'])){
            if(isset($_FILES['file']['name'])) {
                $str_name = $_FILES['file']['name'];
                $_file_id = $this->Files->upload([
                    'name' => $str_name,
                    'type' => $_FILES['file']['type'],
                    'path' => $_FILES['file']['tmp_name'],
                    'size' => $_FILES['file']['size'],
                ]);

                if($_file_id > 0){
                    $arraySave['file_id'] = $_file_id;
                }      
            }    
        }

        $ent_bug = $this->AppBug->newEntity($arraySave);
        if(!$ent_bug->hasErrors()) {
            if ($this->AppBug->save($ent_bug)) {
                $this->success();
            }
        }

    }


    //**************************************** Meeting Network ***********************************************/
        public function meeting_network(){
        $this->loadModel("SpaLiveV1.SysUsers");
        $this->loadModel("SpaLiveV1.DataNetwork");
        $this->loadModel("SpaLiveV1.DataNetworkMeeting");
        $this->loadModel("SpaLiveV1.DataNetworkInvitees");

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

        $schedule_date = get('schedule_date', '');
        if(empty($schedule_date)){
            $this->message('Invalid scheduled date.');
            return;
        }

        $str_invitees = get('invitees', '');
        if(empty($str_invitees)){
            $this->message('Empty invitees.');
            retutrn;
        }
        $arrUids = explode('||', $str_invitees);

        if(empty($arrUids)){
            $this->message('Invalid invitees.');
            retutrn;   
        }

        $invitees = $this->SysUsers->find()->select(['SysUsers.id','SysUsers.name', 'SysUsers.lname', 'SysUsers.email'])
        ->where(['SysUsers.uid IN' => $arrUids])->toArray();
        
        $zoom_token = $this->renewZoomToken();
        // $zoom_token = "eyJhbGciOiJIUzUxMiIsInYiOiIyLjAiLCJraWQiOiIzMGM4Y2JkNi05ZGJlLTQzODUtYjkyYi1hMmNiMjhhYjQ0YzIifQ.eyJ2ZXIiOjcsImF1aWQiOiJlZDZjMTk3ZDZiZmZlZmVlNThiMGMzMWNjM2M2NzI3NCIsImNvZGUiOiJGNDNMem5IUlVtX3dWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJpc3MiOiJ6bTpjaWQ6ODRxM1ZTMWtSNmVWd0dPSmFTNnhKQSIsImdubyI6MCwidHlwZSI6MCwidGlkIjo3MzMsImF1ZCI6Imh0dHBzOi8vb2F1dGguem9vbS51cyIsInVpZCI6IndWOFJ5Ulp0UmhDUkxRSUljTHFBdFEiLCJuYmYiOjE2MzAwMDM4OTEsImV4cCI6MTYzMDAwNzQ5MSwiaWF0IjoxNjMwMDAzODkxLCJhaWQiOiJoYXdxSkVSeVJlVzFZekN5N1plTGRnIiwianRpIjoiNmIxMzQzNmEtMjEwMy00MWJjLWI4MDEtOWRhMmZjY2Q1ZmNkIn0.Llo9k07ZpwvzwZM1J3oOTq13SdjqCVw0SnwKKAewYJhrN9gT-NGdUu7j7bzchEJ9Q7ateVaddKUltUBb-lcmXA";
        
        if ($zoom_token) {
            $password_meeting = $this->generateRandomString();
            $meeting_settings = '{
               "topic":"Network Meeting",
               "type":2,
               "start_time":"' . $schedule_date . '",
               "password":"' . $password_meeting . '",
               "agenda":"Network Consultation",
               "settings":{
                  "host_video":true,
                  "participant_video":true,
                  "join_before_host":true,
                  "jbh_time":0,
                  "mute_upon_entry":true,
                  "use_pmi":false,
                  "waiting_room":false,
                  "approval_type":2,
                  "allow_multiple_devices":true,
                  "registrants_email_notification":true
               }
            }';


            $u_email = $this->generateZoomUser($zoom_token);
            if (!$u_email) {
                return false;
            }
            $str_url = "https://api.zoom.us/v2/users/" . $u_email . "/meetings";

            
            $curl = curl_init();

            curl_setopt_array($curl, array(
              CURLOPT_URL => $str_url,
              CURLOPT_RETURNTRANSFER => true,
              CURLOPT_ENCODING => "",
              CURLOPT_MAXREDIRS => 10,
              CURLOPT_TIMEOUT => 30,
              CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
              CURLOPT_CUSTOMREQUEST => "POST",
              CURLOPT_POSTFIELDS => $meeting_settings,
              CURLOPT_HTTPHEADER => array(
                "authorization: Bearer " . $zoom_token,
                "content-type: application/json"
              ),
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);
            
            if (!$err) {
                $arr_response = json_decode($response,true);  
                // pr($arr_response); exit;
                if ($arr_response) {
                    $str_registrants_url = "https://api.zoom.us/v2/meetings/" . $arr_response['id'];
                    $users_link = array();

                    $curl = curl_init();

                    curl_setopt_array($curl, array(
                            CURLOPT_URL => $str_registrants_url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => "",
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 30,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => "GET",
                            CURLOPT_HTTPHEADER => array(
                            "authorization: Bearer " . $zoom_token,
                            "content-type: application/json"
                        ),
                    ));

                    $meetDetail = curl_exec($curl);
                    $err = curl_error($curl);
                    curl_close($curl);
                    
                    $arr_mmetDetail = json_decode($meetDetail,true);  
                    // pr($arr_response); exit;
                    if ($arr_mmetDetail) {
                        $users_link = $arr_mmetDetail;

                        $skd_date = date('l dS \o\f F Y h:i:s A', strtotime($schedule_date));


                        $html_content_creator = 'Hi,
                        <br><br>
                        This is the link to your meeting scheduled for '.$skd_date.'. Meeting link <a href="'. $arr_mmetDetail['join_url'] .'" link style="color:#60537A;text-decoration:underline"><strong>'.$arr_mmetDetail['join_url'].'</strong></a>.';
                            
                        $data=array(
                            'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                            'to'      => $user['email'],
                            'subject' => 'Network meeting SpaLiveMD',
                            'html'    => $html_content_creator,
                        );

                        $mailgunKey = $this->getMailgunKey();

                        $curl = curl_init();
                        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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



                        $html_content = 'Hi,
                        <br><br>
                        you have received a inviting to a scheduled Zoom meeting on '.$skd_date.'. Meeting link <a href="'. $arr_mmetDetail['join_url'] .'" link style="color:#60537A;text-decoration:underline"><strong>'.$arr_mmetDetail['join_url'].'</strong></a>.';

                        $saveMeetPend = [
                            'uid' => Text::uuid(),
                            'scheduled_date' => $schedule_date,
                            'createdby' => USER_ID,
                            'zoom_meeting_id' => $arr_response['id']
                        ];
                        
                        $meetEntity = $this->DataNetworkMeeting->newEntity($saveMeetPend);
                        if(!$meetEntity->hasErrors()){
                            $meetSave = $this->DataNetworkMeeting->save($meetEntity);
                            if($meetEntity){
                                $meetingID = $meetSave->id;
                                foreach($invitees as $item){
                                    //$arr_name = explode(' ', $item['name']);   

                                    $invtSave = [
                                        'meeting_id' => $meetingID,
                                        'user_id' => $item->id
                                    ];

                                    $invtEntity = $this->DataNetworkInvitees->newEntity($invtSave);
                                    $this->DataNetworkInvitees->save($invtEntity);
                                    
                                    $data=array(
                                        'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                                        'to'      => $item['email'],
                                        'subject' => 'Network meeting SpaLiveMD',
                                        'html'    => $html_content,
                                    );

                                    $mailgunKey = $this->getMailgunKey();

                                    $curl = curl_init();
                                    curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
                        }


                        // $this->set('meeting_id', $arr_response['id']);
                        // $this->set('meeting_pass', $arr_response['password']);
                        // $this->set('user_links', $users_link);
                        $this->success();
                    }
                }
            }
            
        }

    }

    public function get_labels_register(){
        $this->loadModel('SpaLiveV1.CatLabels');

        $model = get('model', 'REGISTER');

        $userType = get('usertype', '');
        $where = ['CatLabels.deleted' => 0, 'CatLabels.tipo' => $model];

        if(!empty($userType)){
            $where['CatLabels.key_field'] = $userType == 'patient' ? 'register_patient' : ( $userType == 'examiner' ? 'register_gfe' : ( $userType == 'clinic' ? 'register_clinic' : ($userType == 'injector' ? 'register_ci' : '') ) );
        }
        
        $findLabels = $this->CatLabels->find()->select(['CatLabels.key_field', 'CatLabels.value'])->where($where)->toArray();

        if(!empty($findLabels)){
            foreach($findLabels as $item){
                $this->set($item->key_field, $item->value);
            }

            $this->success();
        }
    }

    // UTILS

    private function distance($lat1, $lon1, $lat2, $lon2, $unit) {
        if (($lat1 == $lat2) && ($lon1 == $lon2)) {
            return 0;
        }
        else {
            $theta = $lon1 - $lon2;
            $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
            $dist = acos($dist);
            $dist = rad2deg($dist);
            $miles = $dist * 60 * 1.1515;
            $unit = strtoupper($unit);

            if ($unit == "K") {
                return ($miles * 1.609344);
            } else if ($unit == "N") {
                return ($miles * 0.8684);
            } else {
                return $miles;
            }
        }
    }


    public function refund_payment(){
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
        
        $id = get('id','');
        // $uid = get('uid','');
        $type = get('type','');
        
        if(empty($id) || $id <= 0){
            $this->message('Invalid id.');
            return;
        }

        if(empty($type)){
            $this->message('Invalid type.');
            return;
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.SysUsers');

        $dataPay = $this->DataPayment->find()->where(['DataPayment.id' => $id, 'DataPayment.type' => $type])->first();

        if(!empty($dataPay)){
            if ($dataPay->comission_payed == 1) return;
            $amount = $dataPay->total;

            $error = "";
            try {

                if($type == 'REFUND CI REGISTER'){
                    // $user = $this->SysUsers->find()->select(['SysUsers.state'])->where(['SysUsers.uid' => $dataPay->uid])->first();

                     if($amount > 0){

                        
                            $re = \Stripe\Refund::create([
                                'amount' => $amount,
                                'payment_intent' => $dataPay->intent,
                            ]);

                            if ($re) {
                                $dataPay->comission_payed = 1;
                                $dataPay->payment = $re->id;
                                $this->DataPayment->save($dataPay);
                                $this->success();
                            }
                    }else{
                        $this->message('Insufficient quantity.');
                    }
                } else if ($type == 'GFE COMMISSION') {

                    $this->loadModel('SpaLiveV1.DataConsultation');

                    $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $dataPay->service_uid, 'DataConsultation.deleted' => 0])->first();
                    if (empty($ent_consultation)) return;

                    $ent_examiner = $this->SysUsers->find()->where(['SysUsers.id' => $ent_consultation->assistance_id])->first();

                    if (!empty($ent_examiner)) {

                        if (!empty($ent_examiner->stripe_account) && $ent_examiner->stripe_account_confirm == 1) {

                            $transfer = \Stripe\Transfer::create([
                              'amount' => $dataPay->total,
                              'currency' => 'USD',
                              'description' => 'GFE COMMISSION PAYMENT',
                              'destination' => $ent_examiner->stripe_account,
                              // 'transfer_group' => $ent_consultation->uid,
                              'source_transaction' => $dataPay->payment
                            ]);

                             if ($transfer) {
                                $dataPay->comission_payed = 1;
                                // $dataPay->payment = $re->id;
                                $this->DataPayment->save($dataPay);
                                $this->success();

                                $constants_not = [
                                    '[CNT/Total]' => '$' . number_format($dataPay->total / 100,2),
                                ];
                                $this->notify_devices('PAYMENT_SENT',array($ent_consultation->assistance_id), false, false, true, array(),'', $constants_not, false);

                                $this->send_receipt('PAYMENT_SENT',$ent_examiner->email, $dataPay->id, $dataPay->uid, $ent_examiner->uid, $constants_not);
                            }
                        } else {
                            $this->message('The Good Faith Examiner has no Stripe confirmed account.');
                        }
                    }

                
                }  else if ($type == 'CI COMMISSION') {

                    $this->loadModel('SpaLiveV1.DataTreatment');

                    // $ent_treatment = $this->DataTreatment->find()->where(['DataTreatment.id' => $dataPay->id, 'DataTreatment.deleted' => 0])->first();
                    // if (empty($ent_treatment)) return;

                    $ent_examiner = $this->SysUsers->find()->where(['SysUsers.id' => $dataPay->id_to])->first();

                    if (!empty($ent_examiner)) {

                        if (!empty($ent_examiner->stripe_account) && $ent_examiner->stripe_account_confirm == 1) {

                            $transfer = \Stripe\Transfer::create([
                              'amount' => $dataPay->total,
                              'currency' => 'USD',
                              'description' => 'CI COMMISSION PAYMENT',
                              'destination' => $ent_examiner->stripe_account,
                              'transfer_group' => $dataPay->uid,
                              'source_transaction' => $dataPay->payment
                            ]);

                            if ($transfer) {
                                $dataPay->comission_payed = 1;
                                // $dataPay->payment = $re->id;
                                $this->DataPayment->save($dataPay);
                                $this->success();

                                $constants_not = [
                                    '[CNT/Total]' => '$' . number_format($dataPay->total / 100,2),
                                ];
                                $this->notify_devices('PAYMENT_SENT',array($dataPay->id_to), false, true, true, array(),'', $constants_not, false);
                                $this->send_receipt('PAYMENT_SENT', $ent_examiner->email, $dataPay->id, $dataPay->uid, $ent_examiner->uid, $constants_not);
                            }
                        } else {
                            $this->message('The Certified Injector has no Stripe confirmed account.');
                        }
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
            } catch (\Stripe\Exception\RateLimitException $e) {
              // Too many requests made to the API too quickly
            } catch (\Stripe\Exception\InvalidRequestException $e) {
              // Invalid parameters were supplied to Stripe's API
            } catch (\Stripe\Exception\AuthenticationException $e) {
              // Authentication with Stripe's API failed
              // (maybe you changed API keys recently)
            } catch (\Stripe\Exception\ApiErrorException $e) {
              // Display a very generic error to the user, and maybe send
              // yourself an email
              $error = $e->getMessage();
            }

        }
    }

    public function notifyPayError() {

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
        $this->loadModel('SpaLiveV1.DataPayment');
        $last_pay = $this->DataPayment->find()->select(['DataPayment.intent'])->where(['DataPayment.id_to' => 0, 'DataPayment.id_from' => USER_ID])->order(['DataPayment.id' => 'DESC'])->first();
        $msg_stripe = '';
        if(!empty($last_pay)){
            $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
            $payInfo = $stripe->paymentIntents->retrieve($last_pay->intent,[]);
            $msg_stripe = isset($payInfo['last_payment_error']['message']) ? $payInfo['last_payment_error']['message'] : '';
        }


        $msg = 'This is a notification showing a failed payment attempt. The details are below: <br><br> <b>User:</b> ' . $user['email'] . '<br><b>Error message:</b> ' . get('message','') . '<br><br><b>Error message:</b><br>' . $msg_stripe;

        // $this->send_new_email($msg,'khanzab@gmail.com');
        // $this->send_new_email($msg,'dev@spalivemd.com');
        $this->send_new_email($msg,'ashlan@spalivemd.com,cassandra@spalivemd.com,dev@spalivemd.com');
    }

    private function notifyPayErrorI($usr = '',$msgs = '') {
        $msg = 'SpaLiveMD App payment error report from API: <br><br> <b>User:</b> ' . $usr . '<br><b>Error message:</b> ' . $msgs;
        $this->send_new_email($msg,'dev@spalivemd.com');
    }


    public function verify_info_key(){
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
        $passwd = get('password', '');
        $mstr_pass = env('MASTER_PASSWD', 'd3V$p4L1v3MD');

        if($passwd == $mstr_pass){
            $this->success();
        }else{
            $this->message('Invalid password.');
        }
    }

    public function get_trainings(){
        $this->loadModel('SpaLiveV1.CatTrainigs');
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

        $trainings = $this->CatTrainigs->find()->select(['CatTrainigs.title', 'CatTrainigs.scheduled', 'CatTrainigs.neurotoxins', 'CatTrainigs.fillers', 'CatTrainigs.materials', 'id' => 'DataTrainigs.id'])
        ->join(['DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id']])
        ->where(['DataTrainigs.user_id' => USER_ID, 'DataTrainigs.deleted' => 0, 'CatTrainigs.deleted' => 0])->toArray();

        foreach($trainings as $item){
            $str_trat = 'Treatments with ';
            $str_prch = 'Purchase ';
            if($item->fillers > 0){
                $str_trat .= 'Botox';
                $str_prch .= 'Botox';
            }

            if($item->neurotoxins > 0 && $item->fillers > 0){
                $str_trat .= ' & Neurotoxins';
            }else if($item->neurotoxins > 0 && $item->fillers == 0){
                $str_trat .= 'Neurotoxins';
            }

            if($item->neurotoxins > 0 && $item->fillers > 0 && $item->materials == 0){
                $str_prch .= ' & Neurotoxins';
            }else if($item->neurotoxins > 0 && $item->fillers > 0 && $item->materials > 0){
                $str_prch .= ', Neurotoxins';
            }else if($item->neurotoxins > 0 && $item->fillers == 0){
                $str_prch .= 'Neurotoxins';
            }

            if($item->materials > 0){
                $str_prch .= ' & Materials';
            }

            $item['treat'] = $str_trat;
            $item['purchase'] = $str_prch;
        }

        $this->set('data', $trainings);
        $this->success();
    }

    public function cat_instructions(){
        $this->loadModel('SpaLiveV1.CatInstruction');

        $ent_instructions = $this->CatInstruction->find()->where(['CatInstruction.deleted' => 0])->order(['CatInstruction.order' => 'ASC'])->all();

        if(!empty($ent_instructions)){
            $result = array();
            foreach ($ent_instructions as $row) {
                $result[] = array(
                    'id'            => $row['id'],
                    'title'         => $row['title'],
                    'instruction'   => $row['instruction'],
                    'order'         => $row['order'],
                );
            }
        }

        $this->set('data', $result);
        $this->success();
    }

    public function cat_faqs(){
        $this->loadModel('SpaLiveV1.CatFaqs');

        $ent_faqs = $this->CatFaqs->find()->select(['CatFaqs.question', 'CatFaqs.answer'])->where(['CatFaqs.deleted' => 0])
        //->order(['CatFaqs.order' => 'ASC'])
        ->toArray();


        $this->set('data', $ent_faqs);
        $this->success();
    }

    public function reschedule_appointment(){
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

        $patient = $this->SysUsers->find()->where(['SysUsers.uid' => get('patient_uid',''), 'SysUsers.login_status' => 'READY'])->first();
        if(empty($patient)){
            
        }else{
            //$this->notify_devices()
            $this->message('Notification was sent to the patient.');
        }
        $this->success();

    }

    public function override_gfe(){
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();
        if (empty($ent_consultation)) {
            $this->message('consultation not found');
            return;
        }

        $ent_consultation->assistance_id = -1;
        $ent_consultation->status = 'CERTIFICATE';

        if ($this->DataConsultation->save($ent_consultation)) {
            $this->success();
        }
    }

    public function check_gfe_service() {

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
        $patient_uid = get('patient_uid','');
        if (!empty($patient_uid)) {
            $this->loadModel('SpaLiveV1.SysUsers');
            $ent_user = $this->SysUsers->find()->where(['SysUsers.uid' => $patient_uid])->first();
            if (!empty($ent_user)) {
                $user_id = $ent_user->id;
            }
        }

        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => $user_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();

        $av_result = $this->gfeAvailability();
    

        $this->set('request_payment', empty($ent_payment) ? true : false);
        $this->set('amount', empty($ent_payment) ? $this->get_amount() : $ent_payment->total);
        $this->set('available', $av_result);
        $this->set('available_message', "Thank you for signing up as a patient with SpaLiveMD! Our good faith examiners are available Monday-Saturday from 8 a.m - 8 p.m. If you have reached us outside of our business hours, holidays, etc., please feel free to schedule your exam for a specific date and time or reach back out to us during business hours OR (reconnect with us during business hours) Thank you!");
        
        $this->success();

    }

    private function gfeAvailability($str_date = '') {

        if (empty($str_date)) {
            $hour = date('G'); // 0 to 23 hr 
            $minutes = date('i'); // 0 to 23 hr 
            $day = date('N'); // 0 to 23 hr 
        } else {
            $date_str = strtotime($str_date);
            $hour = date('G', $date_str);
            $minutes = date('i', $date_str);
            $day = date('N', $date_str);
        }

        $this->loadModel('SpaLiveV1.DataGfeAvailability');
        $ent_r = $this->DataGfeAvailability->find()->order(['DataGfeAvailability.id' => 'DESC'])->first();

        $av_result = true;
        if ($ent_r->available == 1 || !empty($str_date)) {
            if (strpos($ent_r->days, $day) === false) $av_result = false;
            $f_hour = $hour . $minutes; // now
            $s_hour = str_replace(':', '', $ent_r->start); //schedule start
            $e_hour = str_replace(':', '', $ent_r->end); //schedule end
            if ($f_hour < $s_hour || $f_hour > $e_hour) $av_result = false;
        } else {
            $av_result = false;
        }

        return $av_result;

    }

    public function override_gfe_prepaid(){
        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_uid empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();
        if (empty($ent_consultation)) {
            $this->message('consultation not found');
            return;
        }


        $this->loadModel('SpaLiveV1.DataPayment');
        $ent_payment = $this->DataPayment->find()
        ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();
        if (!empty($ent_payment)) {
            $ent_payment->service_uid = $ent_consultation->uid;
            $this->DataPayment->save($ent_payment);
        } else {
            $this->message('payment not found');
            return;
        }

        $ent_consultation->assistance_id = -1;
        $ent_consultation->status = 'CERTIFICATE';
        $ent_consultation->payment_intent = $ent_payment->intent;
        $ent_consultation->payment = $ent_payment->intent;
        $ent_consultation->amount = $ent_payment->total;


        $consultation_id = $ent_consultation->id;

        if ($this->DataConsultation->save($ent_consultation)) {

           


            $this->loadModel('SpaLiveV1.CatTreatments');
            $this->loadModel('SpaLiveV1.DataConsultationPlan');
            $this->loadModel('SpaLiveV1.DataCertificates');

            $treatments = $this->CatTreatments->find()->select(['CatTreatments.id'])
                ->where([ 'CatTreatments.parent_id IN ('.$ent_consultation->treatments.')', 'CatTreatments.deleted' => 0 ])->toArray();

            foreach($treatments as $item){
                $array_save_a = array(
                    'uid' => Text::uuid(),
                    'consultation_id' => $consultation_id,
                    'detail' => '',
                    'treatment_id' => $item->id,
                    'plan' => '',
                    'proceed' => 1,
                    'deleted' => 0,
                );

                $cp_entity = $this->DataConsultationPlan->newEntity($array_save_a);
                if(!$cp_entity->hasErrors())
                    $this->DataConsultationPlan->save($cp_entity);
            }

            $oneYearOn = date('Y-m-d',strtotime(date("Y-m-d", time()) . " + 365 day"));
            $array_save_c = array(
                'uid' => Text::uuid(),
                'consultation_id' => $consultation_id,
                'date_start' => Date('Y-m-d'),
                'date_expiration' => $oneYearOn,
                'deleted' => 0,
            );

            $cpc_entity = $this->DataCertificates->newEntity($array_save_c);
            if(!$cpc_entity->hasErrors()){
                $this->DataCertificates->save($cpc_entity);
            }


            $this->success();
        }
    }


    public function save_pre_register_site() {
        $this->loadModel('SpaLiveV1.DataPreRegister');

        // Array ( 
        //     [email] => test01@test.com,
        //     [name] => Test,
        //     [mname] => Test,
        //     [lname] => Test,
        //     [statename] => Texas,
        //     [state] => 43 )

        $ent_preregister = $this->DataPreRegister->new_entity([
            'uid'           => $this->DataPreRegister->new_uid(),
            'email'         => get('email', ''),
            'name'          => get('name', ''),
            'mname'         => get('mname', ''),
            'lname'         => get('lname', ''),
            'type'          => get('type', ''),
            'state'         => get('statename', ''),
            'state_id'      => get('state', 0),
            'street'        => get('street', ''),
            'suite'        => get('suite', ''),
            'city'          => get('city', ''),
            'zip'           => get('zip', ''),
            'phone'         => get('phone', ''),
            'business_name' => get('business_name', ''),
            'business_ein'  => get('business_ein', ''),
            'interface'     => get('interface', ''),
            // 'origin'    => get('origin', ''),
            'status'        => get('status', 'PENDING FORM'),
        ]);

        if ($ent_preregister) $this->success();
    }


    // public function test_pay() {

    //     $this->loadModel('SpaLiveV1.SysUsers');

    //    $token = get('token', '');
        
    //     if(!empty($token)){
    //         $user = $this->AppToken->validateToken($token, true);
    //         if($user === false){
    //             $this->message('Invalid token.');
    //             $this->set('session', false);
    //             return;
    //         }
    //         $this->set('session', true);
    //     } else {
    //         $this->message('Invalid token.');
    //         $this->set('session', false);
    //         return;
    //     }
        
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

    //     // $pm = 'pm_1Jm0W8D0WNkFIbmKCjOSCPrx';
    //     $pm = 'pm_1JrSLwD0WNkFIbmKqJVUfjLD';
    //     $intent = \Stripe\PaymentIntent::create([
    //       'customer' => $customer->id,
    //       'amount' => 1000,
    //       'currency' => 'USD',
    //       // 'metadata' => ['consultation_uid' => get('consultation_uid','')],
    //       // 'receipt_email' => $user['email'],
    //        // 'transfer_group' => $consultation_uid,
    //       'payment_method' => $pm,
    //         'off_session' => true,
    //         'confirm' => true,
    //     ]);
    //     pr($intent); exit;
    //     $this->success();
        
    // }

     public function upload_treatment_image(){
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentImage');
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

        $treatment = $this->DataTreatment->find()->where(['DataTreatment.uid' => get('uid', '')])->first();

        if(empty($treatment)){
            $this->message('Invalid treatment.');
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

        $arrSave = [
            'treatment_id' => $treatment->id,
            'file_id' => $_file_id
        ];

        $ti_entity = $this->DataTreatmentImage->newEntity($arrSave);
        if(!$ti_entity->hasErrors()) {
            if ($this->DataTreatmentImage->save($ti_entity)) {
                $this->success();
            }
        }else{
            $this->message('Error in save file to treatment.');
        }
    }

    public function get_img() {
        $this->loadModel('SpaLiveV1.SysUsers');
        
        //l3n4p=6092482f7ce858.91169218
        $panel = get('l3n4p', '');
        $photo_id = get('id', '');
        if(empty($panel) || (!empty($panel) && $panel != '6092482f7ce858.91169218')){
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
    
            $file = $this->Files->output($photo_id);
            exit;    
        }else{
            //$file_id = $this->Files->uid_to_id(get('uid', ''));
            //$file = $this->Files->output($file_id);
            
            $file = $this->Files->output($photo_id);
            exit;
        }
    }

    public function get_w9() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataWN');
        $uid = get('uid', '');
        $token = get('token', '');
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));
        
        $user_id = $this->SysUsers->uid_to_id($uid);

        if ($user_id == 0) {
            $html2pdf->writeHTML("
                <page>
                    <h1>Invalid user" . $uid . "</h1>
                </page>");
            $html2pdf->Output('InvalidUserw9-' . $uid . '.pdf');
            exit;
        } else {
            $W9 = $this->DataWN->find()
                ->where(['DataWN.user_id' => $user_id])
                ->order(['DataWN.id' => 'DESC']);
            
            if ($W9->count() == 0) {
                $html2pdf->writeHTML("
                    <page>
                        <h1>The user" . $uid . " does not have a valid W9 Form.</h1>
                    </page>");
                $html2pdf->Output('Invalidw9-' . $uid . '.pdf');
                exit;
            } else {
                $W9 = $W9->first();

                if ($W9->ssn) {
                    $W9->ssn = str_replace('-', '', $W9->ssn);
                    // <span style=''>X</span>
                    // <span style='position: absolute; left: 5mm;'>X</span>
                    // <span style='position: absolute; left: 10mm;'>X</span>
                    // <span style='position: absolute; left: 20mm;'>X</span>
                    // <span style='position: absolute; left: 25mm;'>X</span>
                    // <span style='position: absolute; left: 35mm;'>X</span>
                    // <span style='position: absolute; left: 40mm;'>X</span>
                    // <span style='position: absolute; left: 45mm;'>X</span>
                    // <span style='position: absolute; left: 50mm;'>X</span>
                    $arr_ssn_spaces = [0,5,10,20,25,35,40,45,50];
                    $arr_ssn = str_split($W9->ssn);
                    $str_ssn = '';
                    foreach($arr_ssn as $index => $ssn){
                        if ($index == 0) $str_ssn .= "<span style=''>";
                        else {
                            $spacing = (isset($arr_ssn_spaces[$index]) ? $arr_ssn_spaces[$index] : ($spacing + 5));
                            $str_ssn .= "<span style='position: absolute; left: {$spacing}mm;'>";
                        }
                        
                        $str_ssn .= "{$ssn}</span>";
                    }
                }
                if ($W9->ein) {
                    $W9->ein = str_replace('-', '', $W9->ein);
                    // <span style=''>X</span>
                    // <span style='position: absolute; left: 5mm;'>X</span>
                    // <span style='position: absolute; left: 15mm;'>X</span>
                    // <span style='position: absolute; left: 20mm;'>X</span>
                    // <span style='position: absolute; left: 25mm;'>X</span>
                    // <span style='position: absolute; left: 30mm;'>X</span>
                    // <span style='position: absolute; left: 35mm;'>X</span>
                    // <span style='position: absolute; left: 40mm;'>X</span>
                    // <span style='position: absolute; left: 45mm;'>X</span>
                    $arr_ein_spaces = [0,5,15,20,25,30,35,40,45];
                    $arr_ein = str_split($W9->ein);
                    $str_ein = '';
                    foreach($arr_ein as $index => $ein){
                        if ($index == 0) $str_ein .= "<span style=''>";
                        else {
                            $spacing = (isset($arr_ein_spaces[$index]) ? $arr_ein_spaces[$index] : ($spacing + 5));
                            $str_ein .= "<span style='position: absolute; left: {$spacing}mm;'>";
                        }
                        
                        $str_ein .= "{$ein}</span>";
                    }
                }

                // PÁGINA 1
                $html2pdf->writeHTML("
                    <page>
                        <div style='width: 210mm; height: 295mm; position:relative;'>
                            <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-1.jpg' />
                            <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                <div style='margin-left: 25mm; margin-top: 35.5mm; font-size: 12px;'>
                                    <div style='position: absolute;left: 190mm;top: 63mm;'>{$W9->payee}</div>
                                    <div style='position: absolute;left: 175mm;top: 76.5mm;'>{$W9->fatca}</div>
                                    <div style='position: absolute;left: 135mm;top: 93mm; width: 62mm; height: 14mm; text-align: justify; font-size: 11px;'>
                                        {$W9->requesters}
                                    </div>
                                    <p style='margin: 0;'>{$W9->name}</p>
                                    <p style='margin: 5.5mm 0 0 0;'>{$W9->bname}</p>
                                    <div style='margin-top: 11.2mm; margin-left: -2.1mm; position: relative;'>
                                        <span>" . ($W9->cat == 'INDIVIDUAL' ? 'X' : '') . "</span>
                                        <span style='position: absolute; left: 39.17mm; top: -0.4mm;'>" . ($W9->cat == 'C' ? 'X' : '') . "</span>
                                        <span style='position: absolute; left: 63.9mm; top: -0.4mm;'>" . ($W9->cat == 'S' ? 'X' : '') . "</span>
                                        <span style='position: absolute; left: 88.6mm; top: -0.4mm;'>" . ($W9->cat == 'PARTNERSHIP' ? 'X' : '') . "</span>
                                        <span style='position: absolute; left: 113.3mm; top: -0.4mm;'>" . ($W9->cat == 'TRUST' ? 'X' : '') . "</span>
                                    </div>
                                    <div style='margin-top: 5.5mm; margin-left: -2.1mm; position: relative;'>
                                        <span>" . ($W9->cat == 'LLC' ? 'X' : '') . "</span>
                                        <span style='position: absolute; left: 124mm; top: -0.4mm;'>" . ($W9->cat == 'LLC' ? $W9->tax : '') . "</span>
                                    </div>
                                    <div style='margin-top: 14mm; margin-left: -2.1mm; position: relative;'>
                                        <span>" . ($W9->cat == 'OTHER' ? 'X' : '') . "</span>
                                    </div>
                                    <p style='margin-top: 4.5mm;'>{$W9->address}</p>
                                    <p style='margin-top: 2.5mm;'>{$W9->city}</p>
                                    <p style='margin-top: 2.5mm;'>{$W9->account}</p>
                                    <div style='top: 128mm; margin-left: 144.5mm; position: absolute;'>
                                        {$str_ssn}
                                    </div>
                                    <div style='top: 146mm; margin-left: 144.5mm; position: absolute;'>
                                        {$str_ein}
                                    </div>
                                    
                                    <img src='" . env('URL_ROOT') . "api/?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&action=get-img&id={$W9->sign_id}&token={$token}' style='top: 200.5mm;margin-left: 60mm; position: absolute; width: 40mm;'/>
                                    <p style='top: 202.5mm;padding-left: 117mm; position: absolute;'>" . date('m-d-Y H:i:s') . "</p>
                                </div>
                            </div>
                        </div>
                    </page>");

                // PÁGINA 2
                $html2pdf->writeHTML("
                    <page>
                        <div style='width: 210mm; height: 295mm; position:relative;'>
                            <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-2.jpg' />
                            <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                <h1></h1>
                            </div>
                        </div>
                    </page>");
        
                // PÁGINA 3
                $html2pdf->writeHTML("
                    <page>
                        <div style='width: 210mm; height: 295mm; position:relative;'>
                            <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-3.jpg' />
                            <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                <h1></h1>
                            </div>
                        </div>
                    </page>");
        
                // PÁGINA 4
                $html2pdf->writeHTML("
                    <page>
                        <div style='width: 210mm; height: 295mm; position:relative;'>
                            <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-4.jpg' />
                            <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                <h1></h1>
                            </div>
                        </div>
                    </page>");
        
                // PÁGINA 5
                $html2pdf->writeHTML("
                    <page>
                        <div style='width: 210mm; height: 295mm; position:relative;'>
                            <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-5.jpg' />
                            <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                <h1></h1>
                            </div>
                        </div>
                    </page>");
        
                // PÁGINA 6
                $html2pdf->writeHTML("
                    <page>
                        <div style='width: 210mm; height: 295mm; position:relative;'>
                            <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-6.jpg' />
                            <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                <h1></h1>
                            </div>
                        </div>
                    </page>");
                
                $html2pdf->output('w9-' . $uid . '.pdf');
                exit;
            }
    
        }
    }

    public function get_w9_bulk() {
        set_time_limit(0);
        ini_set('memory_limit','-1');

        // $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataWN');
        // $token = get('token', '');
        $type = str_replace('-', '+', get('type', ''));
        $date_from = get('date_from', '');
        $date_to = get('date_to', '');
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));

        $arr_types = ['clinic','examiner','injector','gfe+ci'];

        if (!$date_from|| !$date_to) {
            echo '<h1>You must specify a range of dates.</h1>';
            exit;
        }

        if (!in_array($type, $arr_types)) {
            $html2pdf->writeHTML("
                <page>
                    <h1>Invalid type" . $type . "</h1>
                </page>");
            $html2pdf->Output('InvalidTypew9-BULK.pdf');
            exit;
        } else {
            $W9_form = $this->DataWN->find()
                ->where([
                    'SysUsers.type' => $type,
                    'SysUsers.deleted' => 0,
                    'DATE(SysUsers.created) >=' => $date_from,
                    'DATE(SysUsers.created) <=' => $date_to,
                ])
                ->join([
                    'SysUsers' => [
                        'table' => 'sys_users',
                        // 'type' => 'LEFT',
                        'conditions' => 'DataWN.user_id = SysUsers.id'
                    ]
                ])
                ->group(['SysUsers.id'])
                ->order(['DataWN.id' => 'DESC']);

            if ($W9_form->count() == 0) {
                $html2pdf->writeHTML("
                    <page>
                        <h1>There's no W9 certificates for " . str_replace('+', '-', $type) . " users.</h1>
                    </page>");
                $html2pdf->Output('Empty' . str_replace('+', '-', $type) . 'w9-BULK.pdf');
                exit;
            } else {
                // <div style='width: 100%; height: 100%;background-color: red;background-image: url(" . env('URL_ROOT') . "assets/media/bg/fw9-1.jpg);background-size: 100px !important; background-repeat: no-repeat; background-position:center;'>

                // print_r($W9_form);
                // exit;
                $aux = 0;
                foreach($W9_form as $i => $W9){
                    if ($W9->ssn) {
                        $W9->ssn = str_replace('-', '', $W9->ssn);
                        // <span style=''>X</span>
                        // <span style='position: absolute; left: 5mm;'>X</span>
                        // <span style='position: absolute; left: 10mm;'>X</span>
                        // <span style='position: absolute; left: 20mm;'>X</span>
                        // <span style='position: absolute; left: 25mm;'>X</span>
                        // <span style='position: absolute; left: 35mm;'>X</span>
                        // <span style='position: absolute; left: 40mm;'>X</span>
                        // <span style='position: absolute; left: 45mm;'>X</span>
                        // <span style='position: absolute; left: 50mm;'>X</span>
                        $arr_ssn_spaces = [0,5,10,20,25,35,40,45,50];
                        $arr_ssn = str_split($W9->ssn);
                        $str_ssn = '';
                        foreach($arr_ssn as $index => $ssn){
                            if ($index == 0) $str_ssn .= "<span style=''>";
                            else {
                                $spacing = (isset($arr_ssn_spaces[$index]) ? $arr_ssn_spaces[$index] : ($spacing + 5));
                                $str_ssn .= "<span style='position: absolute; left: {$spacing}mm;'>";
                            }
                            
                            $str_ssn .= "{$ssn}</span>";
                        }
                    }
                    if ($W9->ein) {
                        $W9->ein = str_replace('-', '', $W9->ein);
                        // <span style=''>X</span>
                        // <span style='position: absolute; left: 5mm;'>X</span>
                        // <span style='position: absolute; left: 15mm;'>X</span>
                        // <span style='position: absolute; left: 20mm;'>X</span>
                        // <span style='position: absolute; left: 25mm;'>X</span>
                        // <span style='position: absolute; left: 30mm;'>X</span>
                        // <span style='position: absolute; left: 35mm;'>X</span>
                        // <span style='position: absolute; left: 40mm;'>X</span>
                        // <span style='position: absolute; left: 45mm;'>X</span>
                        $arr_ein_spaces = [0,5,15,20,25,30,35,40,45];
                        $arr_ein = str_split($W9->ein);
                        $str_ein = '';
                        foreach($arr_ein as $index => $ein){
                            if ($index == 0) $str_ein .= "<span style=''>";
                            else {
                                $spacing = (isset($arr_ein_spaces[$index]) ? $arr_ein_spaces[$index] : ($spacing + 5));
                                $str_ein .= "<span style='position: absolute; left: {$spacing}mm;'>";
                            }
                            
                            $str_ein .= "{$ein}</span>";
                        }
                    }
                    // print_r($W9);
                    // exit;
                    // PÁGINA 1
                    $html2pdf->writeHTML("
                        <page>
                            <div style='width: 210mm; height: 295mm; position:relative;'>
                                <img style='width:210mm; height: 295mm; position:absolute; z-index: 1;' src='" . env('URL_ROOT') . "assets/media/bg/fw9-1.jpg' />
                                <div style='width:210mm; height: 295mm; position:absolute; z-index: 2;'>
                                    <div style='margin-left: 25mm; margin-top: 35.5mm; font-size: 12px;'>
                                        <div style='position: absolute;left: 190mm;top: 63mm;'>{$W9->payee}</div>
                                        <div style='position: absolute;left: 175mm;top: 76.5mm;'>{$W9->fatca}</div>
                                        <div style='position: absolute;left: 135mm;top: 93mm; width: 62mm; height: 14mm; text-align: justify; font-size: 11px;'>
                                            {$W9->requesters}
                                        </div>
                                        <p style='margin: 0;'>{$W9->name}</p>
                                        <div style='margin: 5.5mm 0 0 0; height: 3mm;'>{$W9->bname}</div>
                                        <div style='margin-top: 11.2mm; margin-left: -2.1mm; position: relative;'>
                                            <span>" . ($W9->cat == 'INDIVIDUAL' ? 'X' : '') . "</span>
                                            <span style='position: absolute; left: 39.17mm; top: -0.4mm;'>" . ($W9->cat == 'C' ? 'X' : '') . "</span>
                                            <span style='position: absolute; left: 63.9mm; top: -0.4mm;'>" . ($W9->cat == 'S' ? 'X' : '') . "</span>
                                            <span style='position: absolute; left: 88.6mm; top: -0.4mm;'>" . ($W9->cat == 'PARTNERSHIP' ? 'X' : '') . "</span>
                                            <span style='position: absolute; left: 113.3mm; top: -0.4mm;'>" . ($W9->cat == 'TRUST' ? 'X' : '') . "</span>
                                        </div>
                                        <div style='margin-top: 5.5mm; margin-left: -2.1mm; position: relative;'>
                                            <span>" . ($W9->cat == 'LLC' ? 'X' : '') . "</span>
                                            <span style='position: absolute; left: 124mm; top: -0.4mm;'>" . ($W9->cat == 'LLC' ? $W9->tax : '') . "</span>
                                        </div>
                                        <div style='margin-top: 14mm; margin-left: -2.1mm; position: relative;'>
                                            <span>" . ($W9->cat == 'OTHER' ? 'X' : '') . "</span>
                                        </div>
                                        <p style='margin-top: 4.5mm;'>{$W9->address}</p>
                                        <p style='margin-top: 2.5mm;'>{$W9->city}</p>
                                        <p style='margin-top: 2.5mm;'>{$W9->account}</p>
                                        <div style='top: 128mm; margin-left: 144.5mm; position: absolute;'>
                                            {$str_ssn}
                                        </div>
                                        <div style='top: 146mm; margin-left: 144.5mm; position: absolute;'>
                                            {$str_ein}
                                        </div>

                                        <img src='" . env('URL_ROOT') . "api/?key=225sadfgasd123fgkhijjdsadfg16578g12gg3gh&l3n4p=6092482f7ce858.91169218&action=get-file&id={$W9->sign_id}' style='top: 200.5mm;margin-left: 60mm; position: absolute; width: 40mm;'/>
                                        <p style='top: 202.5mm;padding-left: 117mm; position: absolute;'>" . date('m-d-Y H:i:s') . "</p>
                                    </div>
                                </div>
                            </div>
                        </page>");

                    // if ($aux == 10) break;
                    $aux++;
                    
                }

                $html2pdf->output($type . 'w9-BULK.pdf');
                exit;

            }
        }
    }

    public function get_training_cert(){
        $this->loadModel('SpaLiveV1.CatTrainigs');


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

        $certItem = $this->CatTrainigs->find()
        ->join(['DataTrainigs' => ['table' => 'data_trainings', 'type' => 'INNER', 'conditions' => 'CatTrainigs.id = DataTrainigs.training_id']])
        ->where(['DataTrainigs.user_id' => USER_ID, 'DataTrainigs.id' => get('id', 0)])->first();

        if(empty($certItem)){
            $this->message('Training not found.');
            return;
        }

        $name = trim($user['name'].' '.((isset($user['mname']) && !empty($user['mname']) ?$user['mname'] : '' )).' '.$user['lname']);
        $dates = $certItem['scheduled']->i18nFormat('MM-dd-yyyy');
        
        $type_space = imagettfbbox(30, 0, './font/Bison-Regular.ttf', $name);
        $text_width = abs($type_space[4] - $type_space[0]) + 10;

        $type_spaceTr = imagettfbbox(30, 0, './font/Bison-Regular.ttf', $certItem['title']);
        $text_widthTr = abs($type_spaceTr[4] - $type_spaceTr[0]) + 10;

        $im   = imagecreatefromjpeg("files/training_certificate_tmp.jpg");
        $gray = imagecolorallocate($im, 30, 30, 30);
        $px   = (imagesx($im) - $text_width) / 2;
        imagettftext($im, 30, 0, (int)$px, 416, $gray, './font/Bison-Regular.ttf', $name);

        $pxTr = (imagesx($im) - $text_widthTr) / 2;
        imagettftext($im, 30, 0, (int)$pxTr, 530, $gray, './font/Bison-Regular.ttf', $certItem['title']);

        imagettftext($im, 21, 0, 428, 605, $gray, './font/Bison-Regular.ttf', $dates);
        imagepng($im, "files/tmp_Train_Cert.png");
        imagedestroy($im);


        $hhtml = "<page><img src=\"" . $this->URL_ROOT . "api/files/tmp_Train_Cert.png\" style=\"height:100%; position: absolute; left: 0;\"></page>";
        
        $html2pdf = new HTML2PDF('L','A4','en', true, 'UTF-8', array(12, 0, 10, 0));
        $html2pdf->WriteHTML($hhtml);
        $html2pdf->Output('certificate_training.pdf', 'I'); //,'D'


        if (get('ry34','') == 'v2rib982jfjbos93kgda2rg') {
            $str_quer = "UPDATE app_tokens SET `deleted` = 1 WHERE token = '{$token}'";
            $this->AppToken->getConnection()->execute($str_quer);
        }

        exit;
    }

    public function reserve_consultation() {
        // $this->success(true);
        // return false;

        $this->loadModel('SpaLiveV1.DataConsultation');

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

        $consultation_uid = get('consultation_uid', '');
        if(empty($consultation_uid)){
            $this->message('consultation_id empty.');
            return;
        }

        $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.assistance_id','DataConsultation.patient_id'])
        ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0, 'DataConsultation.reserve_examiner_id' => 0])->first();

        if (empty($ent_consultation)) {
            $this->success(false);
            $this->message('The consultation is not available.');
            return false;
        }

        // $array_save = array(
        //     'id'                    => $ent_consultation->id,
        //     'reserve_examiner_id'   => USER_ID
        // );

        
        $this->DataConsultation->updateAll(
            [
                'reserve_examiner_id'   => USER_ID
            ],
            ['id' =>  $ent_consultation->id]
        );
        
        $this->success();

        $str_cmd = Configure::read('App.COMMAND_PATH') . " reserve " . $consultation_uid . " > /dev/null 2>&1 &";
        shell_exec($str_cmd);

        // sleep(20);

        // $ent_consultation = $this->DataConsultation->find()->select(['DataConsultation.id', 'DataConsultation.assistance_id','DataConsultation.patient_id'])
        // ->where(['DataConsultation.uid' => $consultation_uid, 'DataConsultation.deleted' => 0])->first();

        // if ($ent_consultation->assistance_id == 0) {
        //     $this->DataConsultation->updateAll(
        //         [
        //             'reserve_examiner_id'   => 0
        //         ],
        //         ['id' =>  $ent_consultation->id]
        //     );
        // }
    }


     public function regenerateMeeting() {
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

        $this->loadModel('SpaLiveV1.DataConsultation');

        $consultation_uid = get('consultation_uid', '');

        $ent_consultation = $this->DataConsultation->find()
            ->select([
                'DataConsultation.id',
                'DataConsultation.patient_id',
                'DataConsultation.treatments',
                'uPatient.uid',
                'uPatient.name',
                'uPatient.lname',
                'uInjector.uid',
                'uInjector.name',
                'uInjector.lname',
            ])
            ->where([
                'OR' => [
                    ['DataConsultation.status <>' => "DONE"], ['DataConsultation.status <>' => "CERTIFICATE"]
                ],
                'DataConsultation.uid'  => $consultation_uid
            ])->join([
                'uPatient' => [
                    'table' => 'sys_users',
                    // 'type' => 'LEFT',
                    'conditions' => 'uPatient.id = DataConsultation.patient_id'
                ],
                'uInjector' => [
                    'table' => 'sys_users',
                    // 'type' => 'LEFT',
                    'conditions' => 'uInjector.id = DataConsultation.createdby'
                ],
            ])->first();
            // 'INIT','DONE','CERTIFICATE','ONLINE','CANCEL'

        if (!empty($ent_consultation)) {
            $this->loadModel('SpaLiveV1.DataPayment');
            $ent_payment = $this->DataPayment->find()
                ->where(['DataPayment.id_from' => $ent_consultation->patient_id, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();

            if (!empty($ent_payment)) {
                $schedule_date = date('Y-m-d H:i:s');
        
                $zoom_token = $this->renewZoomToken();
    
                if ($zoom_token) {
                    $password_meeting = $this->generateRandomString();
                    $meeting_settings = '{
                        "topic":"SpaLiveMD Meeting",
                        "type":2,
                        "start_time":"' . $schedule_date . '",
                        "password":"' . $password_meeting . '",
                        "agenda":"SpaLiveMD Consultation",
                        "settings":{
                            "host_video":true,
                            "participant_video":true,
                            "join_before_host":true,
                            "jbh_time":0,
                            "mute_upon_entry":true,
                            "use_pmi":false,
                            "waiting_room":false,
                            "approval_type":2,
                            "allow_multiple_devices":true
                        }
                    }';
        
        
                    $u_email = $this->generateZoomUser($zoom_token);
                    if (!$u_email) {
                        return false;
                    }
                    $str_url = "https://api.zoom.us/v2/users/" . $u_email . "/meetings";
        
        
                    $curl = curl_init();
        
                    curl_setopt_array($curl, array(
                        CURLOPT_URL => $str_url,
                        // CURLOPT_URL => "https://api.zoom.us/v2/users/khanzab@gmail.com/meetings",
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_ENCODING => "",
                        CURLOPT_MAXREDIRS => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CUSTOMREQUEST => "POST",
                        CURLOPT_POSTFIELDS => $meeting_settings,
                        CURLOPT_HTTPHEADER => array(
                            "authorization: Bearer " . $zoom_token,
                            "content-type: application/json"
                        ),
                    ));
        
                    $response = curl_exec($curl);
                    $err = curl_error($curl);
        
                    curl_close($curl);
        
                    if (!$err) {
                        $arr_response = json_decode($response,true);
        
                        if ($arr_response) {
                            $this->set('meeting_id', $arr_response['id']);
                            $this->set('meeting_pass', $arr_response['password']);
                            $this->set('join_url', $arr_response['join_url']);
                            $this->set('treatments', $ent_consultation->treatments);
                            $this->set('schedule_date', $schedule_date);
                            $this->set('patient_uid', $ent_consultation->uPatient['uid']);
                            $this->set('patient_name', $ent_consultation->uPatient['name'] . ' ' . $ent_consultation->uPatient['lname']);
                            $this->set('injector_uid', $ent_consultation->uInjector['uid']);
                            $this->set('injector_name', $ent_consultation->uInjector['name'] . ' ' . $ent_consultation->uInjector['lname']);
                            $this->success();                    

                            $this->DataConsultation->updateAll(
                                [
                                    'schedule_by'   => 0,
                                    'participants'  => 0,
                                    'assistance_id' => 0,
                                    'status'        => 'INIT',
                                    'schedule_date' => $schedule_date,
                                    'meeting'       => $arr_response['id'],
                                    'meeting_pass'  => $arr_response['password'],
                                    'join_url'      => $arr_response['join_url'],
                                ],
                                ['id' =>  $ent_consultation->id]
                            );
                        }
                    } else {
                        $this->message('An error occurred trying to generate a new meeting. Please try again.');
                        return false;
                    }
                }
            } else {
                $this->message('The consultation does not have an available credit.');
                return false;
            }
        } else {
            $this->message('The consultation already finished.');
            return false;
        }
    }

    public function get_network(){
        $this->loadModel('SpaLiveV1.DataNetwork');
        $this->loadModel('SpaLiveV1.DataNetworkMeeting');
        // $this->DataNetwork->addBehavior('SpaLiveV1.MyTree');
        // $this->DataNetwork->recover_tree();

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

        $user_node = $this->DataNetwork->find()->select(['DataNetwork.lft', 'DataNetwork.rght'])->where(['DataNetwork.user_id' => USER_ID])->first();
        
        if(empty($user_node)){
            $this->message('The user is not in the pyramid');
            return;
        }

        $tot_network = $this->DataNetwork->find()->select(['SysUsers.name'])
        ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataNetwork.user_id']])
        ->where([ 'DataNetwork.lft >' => $user_node->lft, 'DataNetwork.rght <' => $user_node->rght, 'SysUsers.active' => 1,'SysUsers.deleted' => 0, 
            'SysUsers.login_status' => 'READY'])->count();

        $user_id = USER_ID;
        $str_meeting = "
        SELECT DISTINCT DNM.id, DNM.scheduled_date, DNM.createdby, DNM.zoom_meeting_id
        FROM data_network_meeting DNM
        JOIN data_network_invitees DNI ON DNI.meeting_id = DNM.id
        WHERE DNM.deleted = 0 AND DNM.scheduled_date > NOW() AND (DNM.createdby = {$user_id} OR DNI.user_id = {$user_id})
        ";

        $result = [];
        $meetings = $this->DataNetworkMeeting->getConnection()->execute($str_meeting)->fetchAll('assoc');
        foreach ($meetings as $item) {
            $result[] = [
                'id' => $item['id'],
                'scheduled_date' => $item['scheduled_date'],
                'zoom_meeting_id' => $item['zoom_meeting_id'],
                'is_owner' => $item['createdby'] == USER_ID ? 1 : 0,
            ];
        }


        $this->set('pending_meetings', $result);
        $this->set('total_network', $tot_network);
        $this->success();
    }

    public function get_invitees(){
        $this->loadModel('SpaLiveV1.DataNetwork');
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

        $user_node = $this->DataNetwork->find()->select(['DataNetwork.user_id', 'DataNetwork.lft', 'DataNetwork.rght'])->where(['DataNetwork.user_id' => USER_ID])->first();

        if(empty($user_node)){
            $this->set('invitees', []);
            return;
        }

        // $network = $this->DataNetwork->find()->select(['SysUsers.uid', 'SysUsers.name', 'SysUsers.mname', 'SysUsers.lname'])
        // ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataNetwork.user_id']])
        // ->where([ 'DataNetwork.lft >' => $user_node->lft, 'DataNetwork.rght <' => $user_node->rght, 'SysUsers.active' => 1,'SysUsers.deleted' => 0, 
        //     'SysUsers.login_status' => 'READY'])->toArray();

        // $result = [];
        // foreach($network as $item){
        //     $result[] = [
        //         'uid' => $item->SysUsers['uid'],
        //         'name' => $item->SysUsers['name'],
        //         'mname' => $item->SysUsers['mname'],
        //         'lname' => $item->SysUsers['lname'],
        //         'isChecked' => false
        //     ];
        // }
        $level = 0;
        $result = $this->__child_invitees($level, $user_node->user_id);

        $this->set('invitees', $result);
        $this->success();
    }

    private function __child_invitees(&$level, $parent_id){
        $add_level = true;
        $network = $this->DataNetwork->find()->select(['SysUsers.id','SysUsers.uid', 'SysUsers.name', 'SysUsers.mname', 'SysUsers.lname', 'SysUsers.active'])
        ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataNetwork.user_id']])
        ->where([ 'DataNetwork.parent_id' => $parent_id, 'SysUsers.deleted' => 0, 
            'SysUsers.login_status' => 'READY'])->toArray();

        $result = [];
        foreach($network as $item){
            if($item->SysUsers['active'] == 1){
                if($add_level == true){
                    $level += 1;
                    $add_level = false;
                }
                $result[] = [
                    'uid' => $item->SysUsers['uid'],
                    'name' => $item->SysUsers['name'],
                    'mname' => $item->SysUsers['mname'],
                    'lname' => $item->SysUsers['lname'],
                    'level' => $level,
                    'isChecked' => false
                ];
            }
            $result = array_merge($result, $this->__child_invitees($level, $item->SysUsers['id']));
        }



        return $result;
    }

    public function get_invitees_meeting(){
        $this->loadModel('SpaLiveV1.DataNetworkMeeting');
        $this->loadModel('SpaLiveV1.DataNetworkInvitees');
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

        $meeting_id = get('id', 0);
        if($meeting_id <= 0){
            $this->message('Invalid meeting id.');
            return;
        }

        $owner = $this->DataNetworkMeeting->find()->select(['SysUsers.uid', 'SysUsers.name', 'SysUsers.mname', 'SysUsers.lname'])
        ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataNetworkMeeting.createdby']])
        ->where(['DataNetworkMeeting.id' => $meeting_id])->first();

        $invitees = $this->DataNetworkInvitees->find()->select(['SysUsers.uid', 'SysUsers.name', 'SysUsers.mname', 'SysUsers.lname'])
        ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataNetworkInvitees.user_id']])
        ->where(['DataNetworkInvitees.meeting_id' => $meeting_id])->toArray();

        $result = [];
        foreach($invitees as $item){
            $result[] = [
                'uid' => $item->SysUsers['uid'],
                'name' => $item->SysUsers['name'],
                'mname' => $item->SysUsers['mname'],
                'lname' => $item->SysUsers['lname'],
            ];
        }

        $item_owner = [
            'uid' => $owner->SysUsers['uid'],
            'full_name' => $owner->SysUsers['name'] . ( !empty($owner->SysUsers['mname']) ? ' ' . $owner->SysUsers['mname'] : '')
                . (!empty($owner->SysUsers['lname']) ? ' ' . $owner->SysUsers['lname'] : '')
        ];

        $this->set('invitees', $result);
        $this->set('owner', $item_owner);
        $this->success();
    }

    public function cancel_meeting(){
        $this->loadModel('SpaLiveV1.DataNetworkMeeting');
        
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


        $meeting_id = get('id', 0);
        if($meeting_id <= 0){
            $this->message('Invalid meeting.');
            return;
        }

        $meetingData = $this->DataNetworkMeeting->find()->where(['DataNetworkMeeting.id' => $meeting_id])->first();
        if(empty($meetingData)){
            $this->message('Invalid meeting.');
            return;
        }

        $zoom_token = $this->renewZoomToken();

        $str_url = "https://api.zoom.us/v2/meetings/" . $meetingData->zoom_meeting_id;
        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $str_url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "DELETE",
          CURLOPT_HTTPHEADER => array(
            "authorization: Bearer " . $zoom_token,
            "content-type: application/json"
          ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        if(!isset($err) || empty($err)){
            $meetingData->deleted = 1;
            $this->DataNetworkMeeting->save($meetingData);

            $skd_date = $meetingData->scheduled_date->i18nFormat('l dS \o\f F Y h:i:s A');
            $html_content = 'Hi,
                <br><br>
                The meeting scheduled on '.$skd_date.' was cancelled.';

            $str_qry_inv = "
            SELECT User.email FROM data_network_invitees DNI 
            INNER JOIN sys_users User ON User.id = DNI.user_id WHERE DNI.meeting_id = {$meeting_id}
            ";
            $invitees = $this->DataNetworkMeeting->getConnection()->execute($str_qry_inv)->fetchAll('assoc');
            
            foreach($invitees as $item){
                $data=array(
                    'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                    'to'      => $item['email'],
                    'subject' => 'Network meeting cancelled',
                    'html'    => $html_content,
                );

                $mailgunKey = $this->getMailgunKey();

                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
        }
    }

    public function refund_payment_global(){
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
        
        $uid = get('uid', '');
        $id = get('id', 0);
        $total = get('total', 0);
        
        $this->loadModel('SpaLiveV1.DataPayment');

        
        $ent_payment = $this->DataPayment->find()->where([
            'DataPayment.id' => $id,
            'DataPayment.uid' => $uid,
            // 'DataPayment.refund_id' => 0,
            'DataPayment.is_visible' => 1,
        ])->first();

        // pr($ent_payment);
        // exit;

        if (!empty($ent_payment)) {
            $total_refund = get('total_refund', 0);
            if ($total_refund <= 0 || $total_refund > $ent_payment->total) {
                $this->message('Invalid refund mount.');
                return false;
            }

            $total_refund = $total_refund * 100;
        } else {
            $this->message("You can't refund this payment.");
            return false;
        }

        $ent_payment_refunds = $this->DataPayment->find()->where([
            'DataPayment.uid' => $ent_payment->uid,
            'OR' => [
                ['DataPayment.type' => 'REFUND'],
                ['DataPayment.type' => 'REFUND PRODUCT'],
            ],
            'DataPayment.is_visible' => 1,
        ]);

        if (!empty($ent_payment_refunds)) {
            // pr($ent_payment_refunds->toArray());
            // exit;
            $total_refunded = 0;
            foreach ($ent_payment_refunds as $key => $ref) {
                $total_refunded += $ref->total;
            }

            $max_refund = ($ent_payment->total - $total_refunded);

            if ($max_refund <= 0) {
                $this->message('This payment has no more money to refund.');
                return false;
            }
        }

        $error = "";
        try {
            $re = \Stripe\Refund::create([
                'amount' => $total_refund,
                'payment_intent' => $ent_payment->intent,
            ]);

            // pr($this->set('re', $re));
            // return;
            if ($re) {
                $arr_payment_refund = [
                    'id_from'           => 0,
                    'id_to'             => $ent_payment->id_from,
                    'uid'               => $ent_payment->uid,
                    'type'              => 'REFUND',
                    'intent'            => $ent_payment->intent,
                    'payment'           => '',
                    'receipt'           => $ent_payment->receipt,
                    'discount_credits'  => 0,
                    'promo_discount'    => '',
                    'promo_code'        => '',
                    'subtotal'          => $total_refund,
                    'total'             => $total_refund,
                    'prod'              => 1,
                    'is_visible'        => 1,
                    'comission_payed'   => 1,
                    'comission_generated'   => 0,
                    'prepaid'           => 0,
                    'created'           => date('Y-m-d H:i:s'),
                    'createdby'         => defined('USER_ID') ? USER_ID : 0,
                ];

                $c_entity = $this->DataPayment->newEntity($arr_payment_refund);

                if(!$c_entity->hasErrors()) {
                    $ent_refund = $this->DataPayment->save($c_entity);

                    // $ent_payment->refund_id = $ent_refund->id;
                    $this->success();
                    $this->message('Payment refunded successfully.');
                } else {
                    $this->message('The was an error trying to refund the payment.');
                    return false;
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

        if ($error) {
            $this->message($error);
            return false;
        }
    }

    public function refund_product(){
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
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
        
        $uid = get('uid','');
        $amount = get('amount', 0);

        $purchase = $this->DataPurchases->find()->where(['DataPurchases.uid' => $uid])->first();
        if(empty($purchase)){
            $this->message('Invalid purchase.');
            return;
        }


        if(!empty($purchase)){
            
            $error = "";
            try {
                if($amount > 0){
                    $re = \Stripe\Refund::create([
                        'amount' => $amount,
                        'payment_intent' => $purchase->payment,
                    ]);

                    if ($re) {
                        $this->success();
                    }
                }else{
                    $this->message('Insufficient quantity.');
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
            } catch (\Stripe\Exception\RateLimitException $e) {
              // Too many requests made to the API too quickly
            } catch (\Stripe\Exception\InvalidRequestException $e) {
              // Invalid parameters were supplied to Stripe's API
            } catch (\Stripe\Exception\AuthenticationException $e) {
              // Authentication with Stripe's API failed
              // (maybe you changed API keys recently)
            } catch (\Stripe\Exception\ApiErrorException $e) {
              // Display a very generic error to the user, and maybe send
              // yourself an email
              $error = $e->getMessage();
            }

        }
    }

    public function check_account(){
        $this->loadModel("SpaLiveV1.SysUsers");
        $this->loadModel("SpaLiveV1.DataPayment");
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
        
        // $url_panel = 'https://app.spalivemd.com/api';
        $url_panel = 'https://dev.spalivemd.com/api'; 

        $usr_obj = false;
        $arr_user_types = [
            'patient',
            'gfe',
            'ci',
            'gfe-ci',
            'clinic',
        ];
        $userType = get('userType', '');
        $userUid = get('userUid', '');
        if ($userUid) {
            $user = $this->SysUsers->find()->where(['SysUsers.uid' => $userUid])->first();

            if (!empty($user)) {
                $user_id = $user->id;
                $usr_obj = true;
                $userType = $user->type;
            } else {
                $this->message('Invalid user.');
                return false;
            }
        } else {
            $user_id = USER_ID;
            $userType = $user['user_role'];
        }

        $labelDetail = [
            'GFE' => "Certificate request",
            'TREATMENT' => "Treatment services",
            'PURCHASE' => "Product purchase",
        ];

        $data = [
            'bank' => "",
            'amount_transfers' => 0,
            'disabled_reason' => "",
            'incomes' => [],
            'expenses' => [],
        ];
        $fields = ['DataPayment.type','DataPayment.total','DataPayment.subtotal','DataPayment.receipt','DataPayment.created','DataPayment.id_to','DataPayment.uid',
            "provider_by" => "
            (IF(
                DataPayment.type = 'GFE', 
                IFNULL( (SELECT CONCAT_WS(' ', Ug.name, Ug.lname) FROM data_consultation Cons INNER JOIN sys_users Ug ON Ug.id = Cons.assistance_id WHERE Cons.uid = DataPayment.service_uid), '' ) , 
                IFNULL( (SELECT CONCAT_WS(' ', Ug.name, Ug.lname) FROM data_treatment Treat INNER JOIN sys_users Ug ON Ug.id = Treat.assistance_id WHERE Treat.uid = DataPayment.uid), '' )
            ))", 'UsrPay.uid'
        ];
        // $this->set('test', $userType);
        //     return;

        if(strtoupper($userType) == 'PATIENT'){
            $payments = $this->DataPayment->find()->select($fields)
            ->join([
                'Treatment' => ['table' => 'data_treatment', 'type' => 'LEFT', 'conditions' => 'Treatment.uid = DataPayment.uid'],
                'UsrPay' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'UsrPay.id = DataPayment.id_to'],
            ])
            ->where([
                'DataPayment.type IN' => ['GFE','TREATMENT'], 'DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1, 
                'OR' => ['DataPayment.id_from' => $user_id, 'Treatment.patient_id' => $user_id],
            ])
            ->toArray();
            foreach($payments as $item){
                $rr_type = $item["type"];
                $tm_arr = [
                    "detail"   => $labelDetail[$rr_type],
                    "total"    => $item["total"],
                    "id_to"    => $item["id_to"],
                    "created"  => $item["created"]->i18nFormat('Y-MM-dd HH:mm:ss'),
                    "subtotal" => $item["subtotal"],
                    "receipt"  => $item["receipt"],
                    "status"   => 'Paid',//$item->DataPurchases['status'],
                    "provider" => ((!empty($item) && !empty($item->provider)) ? $item->provider : ($item->id_to == 0 ? 'SpaLiveMD' : '')),
                    "transfer_group" => $item["uid"],
                ];

                if ($rr_type == 'GFE' || $rr_type == 'TREATMENT' || $rr_type == 'PURCHASE' || $rr_type == 'CI REGISTER'){
                    $tm_arr['spalive_receipt'] = "{$url_panel}/?action=rcpt_purchase&trgp=".$item['uid'];
                }

                $data['expenses'][] = $tm_arr;
            }
        }else{
            if(strtoupper($userType) != 'CLINIC'){
                // $this->set('test', 'SOY ELSE');
                // return;

                // $user_id = USER_ID;
                // $userType = $user['user_role'];
                $accountUsr = $this->SysUsers->find()->where(['SysUsers.id' => $user_id])->first();

                $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));

                $account = $stripe->accounts->retrieve($accountUsr->stripe_account,[]);
                // $transfers = $stripe->transfers->all(['destination' => $accountUsr->stripe_account]);
                // $account = $stripe->accounts->retrieve('acct_1JgRseDFndy4Avk1',[]);
                // $transfers = $stripe->transfers->all(['destination' => 'acct_1JgRseDFndy4Avk1']);

                // $this->set('transfers',$transfers);
                // return;            

                $data['payouts_enabled'] = $account->payouts_enabled ? 1 : 0;

                $load_more = true;

                // if (count($transfers->data) >= 100) {
                $arr_transfers = [];
                $arr_stripe_conditions = ['destination' => $accountUsr->stripe_account, 'limit' => 100];
                $transfers = $stripe->transfers->all($arr_stripe_conditions);
                $last_obj = null;

                while ($load_more) {
                    $transfers = $stripe->transfers->all($arr_stripe_conditions);

                    foreach ($transfers->data as $key => $tr) {
                        $arr_transfers[] = $tr;
                        $last_obj = $tr;
                    }

                    if (count($transfers->data) < 100) $load_more = false;
                    else {
                        if (!isset($arr_stripe_conditions['starting_after'])) $arr_stripe_conditions['starting_after'] = $last_obj;
                    }
                }
                // $customer = $stripe->customers->all([
                //     "email" => $user['email'],
                //     "limit" => 1,
                // ]);

                // $account = $stripe->accounts->retrieve('acct_1JgRseDFndy4Avk1',[]);
                // $transfers = $stripe->transfers->all(['destination' => 'acct_1JgRseDFndy4Avk1']);

                // $customer = $stripe->accounts->retrieve('acct_1JgRseDFndy4Avk1',[]);
                // $customer = $stripe->transfers->all(['transfer_group' => 'eb094e70-fd6d-4105-8dca-e5d0046feb63']);
                // $customer = $stripe->transfers->all(['destination' => 'acct_1JgRseDFndy4Avk1']);
                // $customer = $stripe->payouts->all(['destination' => 'acct_1JgRseDFndy4Avk1']);
                //$customer = $stripe->payouts->all(['destination' => 'ba_1JgSAFDFndy4Avk1bCT5dQ0a']);
            

                $extAccounts = isset($account->external_accounts) ? $account->external_accounts->data : null;
                $requirements = $account->requirements;
                if(!empty($extAccounts)){
                    $data['bank'] = $extAccounts[0]['bank_name'];
                }

                if(!empty($requirements)){
                    $data['disabled_reason'] = isset($requirements->disabled_reason) ? str_replace('_', ' ', $requirements->disabled_reason) : '';
                }

                foreach($transfers->data as $item){
                    $data['amount_transfers'] += $item['amount'];
                    $desc = $item['description'];
                    $payBay = $this->DataPayment->find()->select(['pay_by' => "CONCAT_WS(' ', UsrPay.name, UsrPay.lname)", 'UsrPay.uid','DataPayment.id_from', 'DataPayment.type', 'DataPayment.uid'])->join([
                        'UsrPay' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'UsrPay.id = DataPayment.id_from'],
                    ])->where([
                        'OR' => [
                            ['DataPayment.type' => 'CI COMMISSION'],
                            ['DataPayment.type' => 'GFE COMMISSION'],
                        ],
                        'DataPayment.id_to' => $user_id,
                        'DataPayment.uid' => $item['transfer_group']])->first();

                    $tm_arr = [
                        "amount" => $item["amount"],
                        "amount_reversed" => $item["amount_reversed"],
                        "created" => date( 'Y-m-d H:i:s', $item["created"]),
                        "currency" => $item["currency"],
                        "description" => ($payBay->id_from == 0 && strpos($item["description"], 'CI COMMISSION') !== false ? 'CI commision' : 
                            ($payBay->id_from > 0 && strpos($item["description"], 'CI COMMISSION') !== false ? 'Network commission' : $item["description"])),
                        "transfer_group" => $item["transfer_group"],
                        "status" => 'Paid',
                        "origin_by" => ((!empty($payBay) && !empty($payBay->pay_by)) ? $payBay->pay_by : ($payBay->id_from == 0 ? 'SpaLiveMD' : '')),
                        "id_from"   => $payBay->id_from
                    ];
                    $rr_type = $payBay->type;
                    if ($rr_type == 'GFE COMMISSION' || $rr_type == 'CI COMMISSION'){
                        $tgp_uid = $payBay->uid;
                        $usr_uid = $user['uid'];
                        $tm_arr['spalive_receipt'] = "{$url_panel}/?action=rcpt&uid={$usr_uid}&trgp={$tgp_uid}";
                    }

                    $data['incomes'][] = $tm_arr;
                }
            }

            $fields['provider'] = "CONCAT_WS(' ', UsrPay.name, UsrPay.lname)";
            $fields[] = 'DataPurchases.status';
            $payments = $this->DataPayment->find()->select($fields)->join([
                'UsrPay' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'UsrPay.id = DataPayment.id_to'],
                'DataPurchases' => ['table' => 'data_purchases', 'type' => 'INNER', 'conditions' => 'DataPurchases.uid = DataPayment.uid'],
            ])->where(['DataPayment.type IN' => ['PURCHASE', 'CI REGISTER'], 'DataPayment.payment <>' => '', 'DataPayment.is_visible' => 1, 'DataPayment.id_from' => $user_id])->toArray();

            foreach($payments as $item){
                $rr_type = $item["type"];
                $tm_arr = [
                    "detail"   => $labelDetail[$rr_type],
                    "total"    => $item["total"],
                    "id_to"    => $item["id_to"],
                    "created"  => $item["created"]->i18nFormat('Y-MM-dd HH:mm:ss'),
                    "subtotal" => $item["subtotal"],
                    "receipt"  => $item["receipt"],
                    "status"   => 'Paid',//$item->DataPurchases['status'],
                    "provider" => ((!empty($item) && !empty($item->provider)) ? $item->provider : ($item->id_to == 0 ? 'SpaLiveMD' : '')),
                    "transfer_group" => $item["uid"],
                ];

                if ($rr_type == 'GFE' || $rr_type == 'TREATMENT' || $rr_type == 'PURCHASE' || $rr_type == 'CI REGISTER'){
                    $tm_arr['spalive_receipt'] = "{$url_panel}/?action=rcpt_purchase&trgp=".$item['uid'];
                }

                $data['expenses'][] = $tm_arr;
            }

        }


        $this->set('data', $data);
        $this->success();
    }

    // public function change_email(){
    //     $this->loadModel('SpaLiveV1.SysUsers');
    //     $users = $this->SysUsers->find()->where(['SysUsers.id >' => 1])->toArray();

    //     foreach ($users as $user) {
    //         $emailSep = explode('@', $user->email);
    //         if(!empty($emailSep) && isset($emailSep[1])){
    //             $user->email = $emailSep[0] . 'spalive.md@'. $emailSep[1];
    //             $this->SysUsers->save($user);
    //         }
    //     }

    // }

    // public function gen_comission(){
    //     $this->loadModel('SpaLiveV1.DataPayment');
    //     $this->loadModel('SpaLiveV1.DataConsultation');
    //     $purch = $this->DataPayment->find()->where(['DataPayment.id IN' => [732,733,741,764,781,788,823,876,891,899,927,933,945,946,957,958,970,981,1007,1017,1018,1027,1032,1040,1046,1054,1055,1078,1121,1138,1140,1148,1155,1160,1171,1172,1181]])->toArray();

    //     foreach ($purch as $item) {
    //         $this->payGFEComissions($item->service_uid);
    //     }

    // }

    public function send_panel_notification(){
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

        $user_uid = get('user_uid', '');
        $type = get('type','');
        $body = get('body',''); 

        $this->loadModel('SpaLiveV1.SysUsers');
        $ent_user = $this->SysUsers->find()->where(['SysUsers.deleted' => 0, 'SysUsers.login_status' => 'READY','SysUsers.uid' => $user_uid])->first();

        if (!empty($ent_user)){
            $users_array = array( $ent_user->id );
            if($type == 'NOTIFICATION'){
                $this->notify_devices($body,$users_array,true,false);
            }else if('SMS'){
                $this->notify_devices($body,$users_array,false,false, true, array(), '', array(), true);
            }

        }

    }

    public function verify_schedule_disponibility() {
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

    }

    public function fill_treatments() {
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.DataPayment');

        $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));

        $load_more = true;
        $arr_transfers = [];
        $arr_stripe_conditions = ['limit' => 100];
        $transfers = $stripe->transfers->all($arr_stripe_conditions);
        $last_obj = null;

        $arr_transfer_unrelated = [];

        while ($load_more) {
            $transfers = $stripe->transfers->all($arr_stripe_conditions);

            foreach ($transfers->data as $key => $tr) {
                // $arr_transfers[] = $tr;
                $ent_user = $this->SysUsers->find()
                    ->select(['SysUsers.id', 'SysUsers.uid'])
                    ->where([
                        'SysUsers.stripe_account' => $tr->destination
                    ])
                    ->first();

                if (!empty($ent_user)) {
                    $this->DataPayment->updateAll(
                        [
                            'transfer' => $tr->id,
                            'receipt' => 'https://dev.spalivemd.com/panel/user-receipt/?action=rcpt&uid='.$ent_user->uid.'&amnt='.$tr->amount.'&trgp='
                                .$tr->transfer_group 
                        ],
                        [
                            'OR' => [
                                ['type' => 'CI COMMISSION'],
                                ['type' => 'GFE COMMISSION'],
                            ],
                            'id_to' => $ent_user->id,
                            'uid' => $tr->transfer_group
                        ]
                    );
                } else {
                    $arr_transfer_unrelated[] = $tr;
                }
            }

            if (count($transfers->data) < 100) $load_more = false;
            else {
                if (!isset($arr_stripe_conditions['starting_after'])) $arr_stripe_conditions['starting_after'] = $last_obj;
            }
        }

        $this->success();
        $this->set('unrelated', $arr_transfer_unrelated);

    }

    public function load_treatment() {
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');

        $uid = get('uid', '');

        $treatment_id = $this->DataTreatment->uid_to_id($uid);

        if ($treatment_id == 0) {
            $this->message('Invalid treatment');
            return false;
        }

        $ent_treatment = $this->DataTreatment->find()
            ->select([
                'DataTreatment.id',
                'DataTreatment.status',
                'DataTreatment.address',
                'DataTreatment.suite',
                'DataTreatment.zip',
                'DataTreatment.city',
                'State.name',
                'DataTreatment.schedule_date',
                'Patient.uid',
                'Patient.name',
                'Patient.lname',
                'Injector.id',
                'Injector.uid',
                'Injector.name',
                'Injector.lname',
                'Review.id',
                'Review.score',
                'Review.comments',
                'Review.half_review',
                'Review.deleted',
            ])
            ->join([
                'Patient' => ['table' => 'sys_users', 'conditions' => 'DataTreatment.patient_id = Patient.id'],
                'Injector' => ['table' => 'sys_users', 'conditions' => 'DataTreatment.assistance_id = Injector.id'],
                'State' => ['table' => 'cat_states', 'conditions' => 'DataTreatment.state = State.id'],
                'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'DataTreatment.id = Review.treatment_id'],
            ])
            ->where(['DataTreatment.id' => $treatment_id])
            ->first();
        // pr($ent_treatment);
        // exit;
        if ($ent_treatment->Review['id'] && $ent_treatment->Review['half_review'] == 0 && $ent_treatment->Review['deleted'] == 0) {
            $this->success(false);
            $this->message('The treatment was already reviewed. To update the review, please access from the SpaLiveMD app.');
        } else {
            $score = intval(get('score', 0));

            if ($score <= 0 || $score > 5) {
                $this->success(true);
                $this->set('treatment', $ent_treatment);
            } else {
                if ($ent_treatment->Review['id'] && $ent_treatment->Review['id'] > 0 && $ent_treatment->Review['deleted'] == 0) {
                    $this->DataTreatmentReview->updateAll(
                        ['score'    => (intval($score) * 10)],
                        ['id'       => $ent_treatment->Review['id']]
                    );
                } else {
                    $c_entity = $this->DataTreatmentReview->newEntity([
                        'uid'           => $this->DataTreatmentReview->new_uid(),
                        'treatment_id'  => $treatment_id,
                        'injector_id'   => $ent_treatment->Injector['id'],
                        'half_review'   => 1,
                        'score'         => (intval($score) * 10),
                        'created'       => date('Y-m-d H:i:s'),
                        'createdby'     => defined('USER_ID') ? USER_ID : 0,
                    ]);

                    if(!$c_entity->hasErrors()) $this->DataTreatmentReview->save($c_entity);
                }

                $ent_rev = $this->DataTreatmentReview->find()
                    ->where([
                        'DataTreatmentReview.injector_id' => $ent_treatment->Injector['id'],
                        'DataTreatmentReview.deleted' => 0
                    ])->all();

                if (!empty($ent_rev)) {
                    $this->loadModel('SpaLiveV1.SysUsers');

                    $total = 0;
                    $count = 0;
                    foreach($ent_rev as $reg) {
                        $total += $reg['score'];
                        $count++;
                    }

                    $prom = $total/$count;
                    $prom = round( $prom / 5 ) * 5;

                    $existUser = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->Injector['id'], 'SysUsers.deleted' => 0])->first();
                    if (!empty($existUser)) {
                        $existUser->score = $prom;
                        $this->SysUsers->save($existUser);
                    }
                }

                $this->success();
                $this->set('treatment', $ent_treatment);
            }
        }
    }

    public function save_review_web() {
        $this->loadModel('SpaLiveV1.DataTreatment');
        $this->loadModel('SpaLiveV1.DataTreatmentReview');

        $treatment_uid = get('treatment_uid', '');
        $comments = get('comments', '');
        $score = get('score', 0);

        $treatment_id = $this->DataTreatment->uid_to_id($treatment_uid);

        if ($treatment_id == 0) {
            $this->message('Invalid treatment.');
            return false;
        }

        $score = intval(get('score', 0));

        if ($score <= 0 || $score > 5) {
            $this->success(false);
            $this->message('You must select a valid rating for the treatment.');
            return false;
        }

        if (!$comments) {
            $this->success(false);
            $this->message('You must enter valid comments for the treatment.');
            return false;
        }

        $ent_treatment = $this->DataTreatment->find()
            ->select([
                'DataTreatment.id',
                'Injector.id',
                'Injector.uid',
                'Injector.name',
                'Injector.lname',
                'Review.id',
                'Review.score',
                'Review.comments',
                'Review.half_review',
                'Review.deleted',
            ])
            ->join([
                'Injector' => ['table' => 'sys_users', 'conditions' => 'DataTreatment.assistance_id = Injector.id'],
                'Review' => ['table' => 'data_treatment_reviews', 'type' => 'LEFT', 'conditions' => 'DataTreatment.id = Review.treatment_id'],
            ])
            ->where(['DataTreatment.id' => $treatment_id])
            ->first();
        
        if (!$ent_treatment->Review['id'] || $ent_treatment->Review['deleted'] == 1) {
            $c_entity = $this->DataTreatmentReview->newEntity([
                'uid'           => $this->DataTreatmentReview->new_uid(),
                'treatment_id'  => $treatment_id,
                'injector_id'   => $ent_treatment->Injector['id'],
                'half_review'   => 0,
                'score'         => (intval($score) * 10),
                'comments'      => $comments,
                'created'       => date('Y-m-d H:i:s'),
                'createdby'     => defined('USER_ID') ? USER_ID : 0,
            ]);

            if(!$c_entity->hasErrors()) $this->DataTreatmentReview->save($c_entity);
        } else {
            if ($ent_treatment->Review['half_review'] == 0) {
                $this->success(false);
                $this->message('The treatment was already reviewed. To update the review, please access from the SpaLiveMD app.');
                return false;
            }

            $this->DataTreatmentReview->updateAll(
                [
                    'score'     => (intval($score) * 10),
                    'comments'  => $comments,
                    'half_review'  => 0,
                ],
                ['id'       => $ent_treatment->Review['id']]
            );

        }
        
        $this->success(true);

        $ent_rev = $this->DataTreatmentReview->find()
            ->where([
                'DataTreatmentReview.injector_id' => $ent_treatment->Injector['id'],
                'DataTreatmentReview.deleted' => 0
            ])->all();

        if (!empty($ent_rev)) {
            $this->loadModel('SpaLiveV1.SysUsers');

            $total = 0;
            $count = 0;
            foreach($ent_rev as $reg) {
                $total += $reg['score'];
                $count++;
            }

            $prom = $total/$count;
            $prom = round( $prom / 5 ) * 5;

            $existUser = $this->SysUsers->find()->where(['SysUsers.id' => $ent_treatment->Injector['id'], 'SysUsers.deleted' => 0])->first();
            if (!empty($existUser)) {
                $existUser->score = $prom;
                $this->SysUsers->save($existUser);
            }
        }
    }

    public function testTreatmentReview() {
        $this->sendTreatmentReview('EMAIL_AFTER_TREATMENT', 1043, 1044, '245c07f1-8bd1-4292-82fa-6a0f774616cb', '2021-12-10 12:30:00');
    }

    public function sendTreatmentReview($message, $patient_id, $injector_id, $treatment_uid, $schedule_date) {
        $this->loadModel('SpaLiveV1.SysUsers');

        $ent_user = $this->SysUsers->find()
            ->select([
                'SysUsers.name',
                'SysUsers.lname',
                'SysUsers.email'
            ])->where([
                'SysUsers.id' => $patient_id
            ])->first();

        $ent_injector = $this->SysUsers->find()
            ->select([
                'SysUsers.name',
                'SysUsers.lname',
                'SysUsers.email'
            ])->where([
                'SysUsers.id' => $injector_id
            ])->first();

        $this->loadModel('SpaLiveV1.CatNotifications');
        $ent_notification = $this->CatNotifications->find()->where(['CatNotifications.title' => $message])->first();
        
        if (!empty($ent_notification)) {
            $str_stars = '<a href="'. $this->URL_ROOT . 'web/treatment-evaluation/' . $treatment_uid .'?rate=1" style="margin-right: 7px;"><img style="width: 30px;" src="https://app.spalivemd.com/assets/media/bg/star.png"></a>'
            . '<a href="'. $this->URL_ROOT . 'web/treatment-evaluation/' . $treatment_uid .'?rate=2" style="margin-right: 7px;"><img style="width: 30px;" src="https://app.spalivemd.com/assets/media/bg/star.png"></a>'
            . '<a href="'. $this->URL_ROOT . 'web/treatment-evaluation/' . $treatment_uid .'?rate=3" style="margin-right: 7px;"><img style="width: 30px;" src="https://app.spalivemd.com/assets/media/bg/star.png"></a>'
            . '<a href="'. $this->URL_ROOT . 'web/treatment-evaluation/' . $treatment_uid .'?rate=4" style="margin-right: 7px;"><img style="width: 30px;" src="https://app.spalivemd.com/assets/media/bg/star.png"></a>'
            . '<a href="'. $this->URL_ROOT . 'web/treatment-evaluation/' . $treatment_uid .'?rate=5" style="margin-right: 7px;"><img style="width: 30px;" src="https://app.spalivemd.com/assets/media/bg/star.png"></a>';
            
            $constants = [
                '[CNT/TreatmentDate]'   => $schedule_date->i18nFormat('MM/DD/YYYY hh:mmA'),
                // '[CNT/TreatmentDate]'   => $schedule_date,
                '[CNT/ScoreStars]'      => $str_stars,
                '[CNT/InjectorName]'    => $ent_injector->name . ' ' . $ent_injector->lname,
            ];

            $msg_mail = $ent_notification['body'];

            foreach($constants as $key => $value){
                $msg_mail = str_replace($key, $value, $msg_mail);
            }

            $conf_subject = $ent_notification['subject'];
            $conf_body = $msg_mail;

            // $array_save_history = array(
            //     'type' => 'TREATMENT',
            //     'id_from' => 1,
            //     'id_to' => $patient_id,
            //     'message' => $msg_mail,
            //     'created' => date('Y-m-d H:i:s'),
            // );
            

            // $this->loadModel('DataMessages');
            // $c_entity = $this->DataMessages->newEntity($array_save_history);
    
            // if(!$c_entity->hasErrors()) $this->DataMessages->save($c_entity);
    
            $str_email = $ent_user->email;
    
            $str_message = '
                <!doctype html>
                    <html>
                        <head>
                        <meta name="viewport" content="width=device-width">
                        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                        <title>SpaLiveMD Message</title>
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
                        <span class="preheader" style="color: transparent; display: none; height: 0; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all; visibility: hidden; width: 0;">SpaLiveMD Message.</span>
                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: #f6f6f6;">
                            <tr>
                            <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                            <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                                <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
                                <img src="https://app.spalivemd.com/panel/img/logo_colored.png" width="100px"/>
                                <!-- START CENTERED WHITE CONTAINER -->
                                <table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: #ffffff; border-radius: 3px;">
                                    <!-- START MAIN CONTENT AREA -->
                                    <tr>
                                    <td class="wrapper" style="font-family: sans-serif; font-size: 14px; vertical-align: top; box-sizing: border-box; padding: 20px;">
                                        <table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                        <tr>
                                            <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">
                                            <p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px;">'
                                            . $msg_mail .
                                            '</p>
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
                                        <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;">Visit us at <a href="https://spalivemd.com/">SpaLiveMD</a></span>
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

            $data = array(
                'from'      => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                // 'to'    => 'jorge.alejandro.puente@gmail.com',
                'to'        => $str_email,
                'subject'   => $conf_subject,
                'html'      => $str_message,
            );

            $mailgunKey = $this->getMailgunKey();
    
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
    }

    public function rcpt($return_path = false, $pay_uid = '', $user_uid = ''){
        $this->loadModel('SpaLiveV1.DataPayment');
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

        $user = $this->SysUsers->find()->where(['SysUsers.uid' => (!empty($user_uid) ? $user_uid : get('uid', '')) ])->first();
        $trans_group = (!empty($pay_uid)) ? $pay_uid : get('trgp','');

        if(empty($trans_group)){
            $this->Response->message("Invalid trgp.");
            return;
        }

        if(empty($user)){
            $this->Response->message("Invalid user.");
            return;
        }

        $fields = [
            'DataPayment.transfer', 'DataPayment.id_from', 'DataPayment.created','DataPayment.id', 'DataPayment.total',
            "provider_by" => "
            (IF(
                DataPayment.type = 'GFE COMMISSION', 
                'SpaLiveMD, LLC',
                IF(
                    DataPayment.id_from > 0,
                    (SELECT CONCAT_WS(' ', Ug.name, Ug.lname) FROM sys_users Ug WHERE Ug.id = DataPayment.id_from),
                    'SpaLiveMD, LLC'
                )
            ))"
        ];

        $pay = $this->DataPayment->find()->select($fields)->where([
            'OR' => [
                ['type' => 'CI COMMISSION'],
                ['type' => 'GFE COMMISSION'],
            ],
            'id_to' => $user->id,
            'uid' => $trans_group
        ])->first();

        $amount = number_format($pay->total / 100,2);
        $user_name = $user->name.' '.$user->lname;
        $provider  = $pay->provider_by;
        $transfer  = $pay->transfer;
        $date2 = $pay->created->i18nFormat('MM/dd/yyyy');
        $date = date('M d', strtotime($date2));

        // $url_panel = 'https://app.spalivemd.com/api';
        $url_panel = 'https://dev.spalivemd.com/api'; 
        $invoice = strval($pay->id + 1500);
        $len_inv = strlen($invoice);
        for ($i=$len_inv; $i < 6 ; $i++) { 
            $invoice = '0'.$invoice;
        }

        $filename = ($return_path == true ? TMP . 'reports' . DS : '') . 'receipt_' . ($pay->id+1500) . '.pdf';

        $html_content = "
            <div style=\"padding: 8px 1px 8px 1px; width: 100%\">
                <div style=\"width: 100%; display: inline-flex;\">
                    <img height=\"90\" src=\"{$url_panel}/img/logo_colored.png\">
                    <div style=\"margin-top: -90px; float: right; margin-left: 300px;\">
                        <p style=\"line-height:22px;\">
                            Date: {$date2}
                            <br>
                            Receipt: #{$invoice}
                            <br>
                            EIN: #85-3546576
                        </p>
                    </div>
                </div>
                <div style=\"padding: 0px 16px 0px 16px; margin-top: 24px;\">
                    <p style=\"line-height:20px;\">
                        SpaLiveMD, LLC
                        <br>
                        Address: 2450 East Prosper Trail, Suite 20, Prosper, TX 75078
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

    public function rcpt_purchase($return_path = false, $pay_uid = ''){
        $this->loadModel('SpaLiveV1.DataPayment');
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

        $fields = ['SysUsers.name', 'SysUsers.lname','SysUsers.street','SysUsers.suite','SysUsers.city','State.abv','SysUsers.zip'];
        $user_info = $this->SysUsers->find()->select($fields)->join(['State' => ['table' => 'cat_states', 'type' => 'INNER', 'conditions' => 'State.id = SysUsers.state']])->where(['SysUsers.id' => $payment->id_from])->first();

        if($payment->type == 'GFE'){
            $this->loadModel('SpaLiveV1.DataConsultation');
            $realTotal = $payment->subtotal;
            $consl = $this->DataConsultation->find()->select(['DataConsultation.uid', 'SysUsers.name', 'SysUsers.lname', 'Examiner.name','Examiner.lname'])
            ->join(['SysUsers' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'SysUsers.id = DataConsultation.patient_id']])
            ->join(['Examiner' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'Examiner.id = DataConsultation.assistance_id']])
            ->where(['DataConsultation.uid' => $payment->service_uid])->first();
            $examiner = 'SpaLiveMD';
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

        }else if($payment->type == 'CI REGISTER'){
            $realTotal = $payment->subtotal;
            $concept_row = "
                <tr>
                    <td style=\"text-align: left; width: 610px;\">SpaLiveMD Certified injector application.</td>
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
                $this->Response->message("Invalid treatment.");
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
                $this->Response->message("Invalid purchase.");
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
            $discount = ($totalToPay < 100 ? $discount - (100 - $totalToPay) : $discount);

            $discount = number_format($discount / 100,2);
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

        // $url_panel = 'https://app.spalivemd.com/api';
        $url_panel = 'https://dev.spalivemd.com/api'; 

        $amount = number_format($payment->total / 100,2);
        $html_content = "
            <div style=\"padding: 8px 1px 8px 1px; width: 100%\">
                <div style=\"width: 100%; display: inline-flex;\">
                    
                    <img height=\"90\" src=\"{$url_panel}/img/logo_colored.png\">
                    
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
                        SpaLiveMD, LLC
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
                            <td style=\"text-align: right;\">&nbsp;<br>Total:&nbsp;&nbsp;&nbsp;</td>
                            <td style=\"text-align: right;\">&nbsp;<br>\${$amount}</td>
                        </tr>
                    </tfoot>
                </table>
                
            </div> 
        ";

        // echo ($html_content.$end_hml);exit;

        $html2pdf = new HTML2PDF('P','A4','en', true, 'UTF-8', array(10, 10, 10, 10));
        $html2pdf->WriteHTML($html_content);
        if($return_path == true){
            $html2pdf->Output($filename, 'F'); //,'D'
            return $filename;
        }else $html2pdf->Output($filename, 'I'); //,'D'
        exit;
    }

    private function send_receipt($html_msg, $str_email, $numInvo, $pay_uid, $user_uid = '', $constants = array()){
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
            $conf_body .= '<br><br>' . $body_extra;
        } else {
            $conf_subject = 'SpaLiveMD Notification';
            $conf_body = $html_msg;
        }


        $type = 'Receipt';
        if(empty($user_uid)){
            $type = 'Invoice';
            $filename = $this->rcpt_purchase(true, $pay_uid);
        }else{
            $filename = $this->rcpt(true, $pay_uid, $user_uid);
        }

        if(empty($filename)){
            return;
        }
        
        $subject = 'SpaliveMD '.$type;
        $data = array(
            'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
            'to'    => $str_email,
            'subject' => $subject,
            'html'    => "You have received a {$type} from SpaLiveMD.",
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'SpaLiveMD_' . $type . ($numInvo > 0 ? '_' . ($numInvo + 1500) : '') . '.pdf'),
        );

        $mailgunKey = $this->getMailgunKey();

        $curl = curl_init();
        
        curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
        $this->success();
    }

    public function general_report() {
        $from = get('from','2021-01-01');
    	$to = get('to','2021-12-31');

    	$html = "";

        $this->loadModel('DataPreRegister');
        $this->loadModel('SpaLiveV1.SysReport');

        $today = date('Y-m-d');
        $start_last_week1 = date('Y-m-d', strtotime('-6 day', strtotime($today)));
        $end_last_week1 = $today;
        $start_last_week2 = date('Y-m-d', strtotime('-7 day', strtotime($start_last_week1)));
        $end_last_week2 = date('Y-m-d', strtotime('-1 day', strtotime($start_last_week1)));
        $start_last_week3 = date('Y-m-d', strtotime('-7 day', strtotime($start_last_week2)));
        $end_last_week3 = date('Y-m-d', strtotime('-1 day', strtotime($start_last_week2)));

        $str_total_query = "SELECT 
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'CHANGEPASSWORD' AND deleted = 0 AND active = 1 AND type = 'patient') as total_no_app_patient,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND createdby = 0 AND type = 'patient') as uninvited_patient,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'injector') as total_injector,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'injector') as ready_injector,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'PAYMENT' AND deleted = 0 AND active = 1 AND type = 'injector') as total_ci_pending_payment,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'examiner') as total_examiner,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'examiner') as ready_examiner,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'clinic') as total_clinic,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'clinic') as ready_clinic,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.assistance_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'injector' AND DT.status = 'DONE')  as injector_with_treatment,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.patient_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'patient' AND U.login_status = 'READY' AND DT.status = 'DONE')  as patient_with_treatment,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_trainings DT ON DT.user_id = U.id AND DT.deleted = 0 JOIN cat_trainings CT ON CT.id = DT.training_id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND NOW() > CT.scheduled AND CT.deleted = 0) as total_ci_with_train
        
        UNION ALL
        
        SELECT 
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'CHANGEPASSWORD' AND deleted = 0 AND active = 1 AND type = 'patient' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as total_no_app_patient,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND createdby = 0 AND type = 'patient' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as uninvited_patient,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as total_injector,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as ready_injector,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'PAYMENT' AND deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as total_ci_pending_payment,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'examiner' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as total_examiner,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'examiner' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as ready_examiner,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'clinic' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as total_clinic,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'clinic' AND created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as ready_clinic,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.assistance_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'injector' AND DT.status = 'DONE' AND DT.schedule_date BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')  as injector_with_treatment,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.patient_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'patient' AND U.login_status = 'READY' AND DT.status = 'DONE' AND U.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')  as patient_with_treatment,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_trainings DT ON DT.user_id = U.id AND DT.deleted = 0 JOIN cat_trainings CT ON CT.id = DT.training_id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND NOW() > CT.scheduled AND CT.deleted = 0 AND U.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') as total_ci_with_train
            
        UNION ALL
        
        SELECT 
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'CHANGEPASSWORD' AND deleted = 0 AND active = 1 AND type = 'patient' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as total_no_app_patient,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND createdby = 0 AND type = 'patient' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as uninvited_patient,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as total_injector,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as ready_injector,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'PAYMENT' AND deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as total_ci_pending_payment,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'examiner' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as total_examiner,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'examiner' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as ready_examiner,
            (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'clinic' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as total_clinic,
            (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'clinic' AND created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as ready_clinic,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.assistance_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'injector' AND DT.status = 'DONE' AND DT.schedule_date BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')  as injector_with_treatment,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.patient_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'patient' AND U.login_status = 'READY' AND DT.status = 'DONE' AND U.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')  as patient_with_treatment,
            (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_trainings DT ON DT.user_id = U.id AND DT.deleted = 0 JOIN cat_trainings CT ON CT.id = DT.training_id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND NOW() > CT.scheduled AND CT.deleted = 0 AND U.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') as total_ci_with_train
            
            UNION ALL
            
            SELECT 
                (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'CHANGEPASSWORD' AND deleted = 0 AND active = 1 AND type = 'patient' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as total_no_app_patient,
                (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND createdby = 0 AND type = 'patient' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as uninvited_patient,
                (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as total_injector,
                (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as ready_injector,
                (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'PAYMENT' AND deleted = 0 AND active = 1 AND type = 'injector' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as total_ci_pending_payment,
                (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'examiner' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as total_examiner,
                (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'examiner' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as ready_examiner,
                (SELECT COUNT(U.id) FROM sys_users U WHERE deleted = 0 AND active = 1 AND type = 'clinic' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as total_clinic,
                (SELECT COUNT(U.id) FROM sys_users U WHERE login_status = 'READY' AND deleted = 0 AND active = 1 AND type = 'clinic' AND created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as ready_clinic,
                (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.assistance_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'injector' AND DT.status = 'DONE' AND DT.schedule_date BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')  as injector_with_treatment,
                (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_treatment DT ON DT.patient_id = U.id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND U.type = 'patient' AND U.login_status = 'READY' AND DT.status = 'DONE' AND U.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')  as patient_with_treatment,
                (SELECT COUNT(DISTINCT U.id) FROM sys_users U JOIN data_trainings DT ON DT.user_id = U.id AND DT.deleted = 0 JOIN cat_trainings CT ON CT.id = DT.training_id WHERE U.login_status = 'READY' AND U.deleted = 0 AND U.active = 1 AND NOW() > CT.scheduled AND CT.deleted = 0 AND U.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') as total_ci_with_train";

        $ent_total_query = $this->DataPreRegister->getConnection()->execute($str_total_query)->fetchAll('assoc');
        // $html .= '<br><h1>PURCHASES</h1>';

        $arr_totals = [
            'SALES' => [],
            'PAYMENTS' => [],
        ];

        // pr($today);
        // pr($start_last_week1);
        // pr($end_last_week1);
        // pr($start_last_week2);
        // pr($end_last_week2);
        // pr($start_last_week3);
        // pr($end_last_week3);
        // exit;
    	$str_query_find = "SELECT
                SUM(P.total) AS amount,
                COUNT(P.id) AS total/*,
                (SELECT SUM(P11.total) FROM data_payment P11 WHERE P11.type = 'CI REGISTER' AND P11.payment <> '' AND P11.is_visible = 1 AND P11.prod = 1 AND (P11.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')) AS week1,
                (SELECT SUM(P12.total) FROM data_payment P12 WHERE P12.type = 'CI REGISTER' AND P12.payment <> '' AND P12.is_visible = 1 AND P12.prod = 1 AND (P12.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')) AS week2,
                (SELECT SUM(P13.total) FROM data_payment P13 WHERE P13.type = 'CI REGISTER' AND P13.payment <> '' AND P13.is_visible = 1 AND P13.prod = 1 AND (P13.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')) AS week3*/
            FROM data_payment P
            WHERE P.type = 'CI REGISTER' AND P.payment <> '' AND P.is_visible = 1 AND P.prod = 1 AND P.promo_code = ''

            UNION ALL

            SELECT SUM(P1.total) AS amount, COUNT(P1.id) AS total FROM data_payment P1 WHERE P1.type = 'CI REGISTER' AND P1.payment <> '' AND P1.is_visible = 1 AND P1.prod = 1 AND P1.promo_code = '' AND (P1.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')
            
            UNION ALL

            SELECT SUM(P2.total) AS amount, COUNT(P2.id) AS total FROM data_payment P2 WHERE P2.type = 'CI REGISTER' AND P2.payment <> '' AND P2.is_visible = 1 AND P2.prod = 1 AND P2.promo_code = '' AND (P2.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')
            
            UNION ALL
            
            SELECT SUM(P3.total) AS amount, COUNT(P3.id) AS total FROM data_payment P3 WHERE P3.type = 'CI REGISTER' AND P3.payment <> '' AND P3.is_visible = 1 AND P3.prod = 1 AND P3.promo_code = '' AND (P3.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')";
            // pr($str_query_find);
            // exit;
        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
            // pr($ent_query);
            // exit;
            $arr_totals['SALES']['ci_register'] = [
                'paid_registrations'        => number_format($ent_query[0]['amount'] / 100 ,2),
                'registrations'             => $ent_query[0]['total'],
                'paid_registrations_week1'  => number_format($ent_query[1]['amount'] / 100 ,2),
                'registrations_week1'       => $ent_query[1]['total'],
                'paid_registrations_week2'  => number_format($ent_query[2]['amount'] / 100 ,2),
                'registrations_week2'       => $ent_query[2]['total'],
                'paid_registrations_week3'  => number_format($ent_query[3]['amount'] / 100 ,2),
                'registrations_week3'       => $ent_query[3]['total'],
            ];
        }

        $str_query_find = "SELECT
                SUM(P.total) AS amount,
                COUNT(P.id) AS total
            FROM data_payment P
            WHERE P.type = 'PURCHASE' AND P.payment <> '' AND P.is_visible = 1 AND P.prod = 1 AND P.promo_code = ''
            
            UNION ALL
            
            SELECT SUM(P1.total) AS amount, COUNT(P1.id) AS total FROM data_payment P1 WHERE P1.type = 'PURCHASE' AND P1.payment <> '' AND P1.is_visible = 1 AND P1.prod = 1 AND P1.promo_code = '' AND (P1.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')
            
            UNION ALL

            SELECT SUM(P2.total) AS amount, COUNT(P2.id) AS total FROM data_payment P2 WHERE P2.type = 'PURCHASE' AND P2.payment <> '' AND P2.is_visible = 1 AND P2.prod = 1 AND P2.promo_code = '' AND (P2.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')
            
            UNION ALL
            
            SELECT SUM(P3.total) AS amount, COUNT(P3.id) AS total FROM data_payment P3 WHERE P3.type = 'PURCHASE' AND P3.payment <> '' AND P3.is_visible = 1 AND P3.prod = 1 AND P3.promo_code = '' AND (P3.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')";

        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
            $arr_totals['SALES']['purchases'] = [
                'paid_purchases'        => number_format($ent_query[0]['amount'] / 100 ,2),
                'purchases'             => $ent_query[0]['total'],
                'paid_purchases_week1'  => number_format($ent_query[1]['amount'] / 100 ,2),
                'purchases_week1'       => $ent_query[1]['total'],
                'paid_purchases_week2'  => number_format($ent_query[2]['amount'] / 100 ,2),
                'purchases_week2'       => $ent_query[2]['total'],
                'paid_purchases_week3'  => number_format($ent_query[3]['amount'] / 100 ,2),
                'purchases_week3'       => $ent_query[3]['total'],
            ];
        }

        $str_query_find = "SELECT
                SUM(P.total) AS amount,
                COUNT(P.id) AS total
            FROM data_payment P
            WHERE P.type = 'GFE' AND P.payment <> '' AND P.is_visible = 1 AND P.prod = 1 AND P.promo_code = ''
            
            UNION ALL
            
            SELECT SUM(P1.total) AS amount, COUNT(P1.id) AS total FROM data_payment P1 WHERE P1.type = 'GFE' AND P1.payment <> '' AND P1.is_visible = 1 AND P1.prod = 1 AND P1.promo_code = '' AND (P1.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')
            
            UNION ALL

            SELECT SUM(P2.total) AS amount, COUNT(P2.id) AS total FROM data_payment P2 WHERE P2.type = 'GFE' AND P2.payment <> '' AND P2.is_visible = 1 AND P2.prod = 1 AND P2.promo_code = '' AND (P2.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')
            
            UNION ALL
            
            SELECT SUM(P3.total) AS amount, COUNT(P3.id) AS total FROM data_payment P3 WHERE P3.type = 'GFE' AND P3.payment <> '' AND P3.is_visible = 1 AND P3.prod = 1 AND P3.promo_code = '' AND (P3.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')";

        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
        	$arr_totals['SALES']['gfe'] = [
                'paid_exams'        => number_format($ent_query[0]['amount'] / 100 ,2),
                'exams'             => $ent_query[0]['total'],
                'paid_exams_week1'  => number_format($ent_query[1]['amount'] / 100 ,2),
                'exams_week1'       => $ent_query[1]['total'],
                'paid_exams_week2'  => number_format($ent_query[2]['amount'] / 100 ,2),
                'exams_week2'       => $ent_query[2]['total'],
                'paid_exams_week3'  => number_format($ent_query[3]['amount'] / 100 ,2),
                'exams_week3'       => $ent_query[3]['total'],
            ];
        }

        $str_query_find = "SELECT
                SUM(P.total) AS amount,
                COUNT(P.id) AS total,
                AVG(P.total) AS average
            FROM data_payment P
            WHERE P.type = 'TREATMENT' AND P.payment <> '' AND P.is_visible = 1 AND P.prod = 1 AND P.promo_code = ''
            
            UNION ALL
            
            SELECT SUM(P1.total) AS amount, COUNT(P1.id) AS total, AVG(P1.total) AS average FROM data_payment P1 WHERE P1.type = 'TREATMENT' AND P1.payment <> '' AND P1.is_visible = 1 AND P1.prod = 1 AND P1.promo_code = '' AND (P1.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')
            
            UNION ALL

            SELECT SUM(P2.total) AS amount, COUNT(P2.id) AS total, AVG(P2.total) AS average FROM data_payment P2 WHERE P2.type = 'TREATMENT' AND P2.payment <> '' AND P2.is_visible = 1 AND P2.prod = 1 AND P2.promo_code = '' AND (P2.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')
            
            UNION ALL
            
            SELECT SUM(P3.total) AS amount, COUNT(P3.id) AS total, AVG(P3.total) AS average FROM data_payment P3 WHERE P3.type = 'TREATMENT' AND P3.payment <> '' AND P3.is_visible = 1 AND P3.prod = 1 AND P3.promo_code = '' AND (P3.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')";
        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
        	$arr_totals['SALES']['treatments'] = [
                'paid_treatments' => number_format($ent_query[0]['amount'] / 100 ,2),
                'treatments' => $ent_query[0]['total'],
                'ci_treatments' => $ent_total_query[0]['injector_with_treatment'],
                'average_treatments_price' => number_format($ent_query[0]['average'] / 100 ,2),

                'paid_treatments_week1' => number_format($ent_query[1]['amount'] / 100 ,2),
                'treatments_week1' => $ent_query[1]['total'],
                'ci_treatments_week1' => $ent_total_query[1]['injector_with_treatment'],
                'average_treatments_price_week1' => number_format($ent_query[1]['average'] / 100 ,2),

                'paid_treatments_week2' => number_format($ent_query[2]['amount'] / 100 ,2),
                'treatments_week2' => $ent_query[2]['total'],
                'ci_treatments_week2' => $ent_total_query[2]['injector_with_treatment'],
                'average_treatments_price_week2' => number_format($ent_query[2]['average'] / 100 ,2),

                'paid_treatments_week3' => number_format($ent_query[3]['amount'] / 100 ,2),
                'treatments_week3' => $ent_query[3]['total'],
                'ci_treatments_week3' => $ent_total_query[3]['injector_with_treatment'],
                'average_treatments_price_week3' => number_format($ent_query[3]['average'] / 100 ,2),
            ];
        }

		$arr_join = [ 'SysUser' => [ 'table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'SysUser.email = DataPreRegister.email' ] ];
        $count = $this->DataPreRegister->find()
            ->where([
                'DataPreRegister.deleted' => 0,
                'ISNULL(SysUser.email)',
                'DataPreRegister.type' => 'injector'
            ])->join($arr_join)
            ->group(['DataPreRegister.email'])
            ->count();

        $count_week1 = $this->DataPreRegister->find()
            ->where([
                'DataPreRegister.deleted' => 0,
                'ISNULL(SysUser.email)',
                'DataPreRegister.type' => 'injector',
                'DATE(DataPreRegister.created) >=' => $start_last_week1,
                'DATE(DataPreRegister.created) <=' => $end_last_week1])
            ->join($arr_join)
            ->group(['DataPreRegister.email'])
            ->count();

        $count_week2 = $this->DataPreRegister->find()
            ->where([
                'DataPreRegister.deleted' => 0,
                'ISNULL(SysUser.email)',
                'DataPreRegister.type' => 'injector',
                'DATE(DataPreRegister.created) >=' => $start_last_week2,
                'DATE(DataPreRegister.created) <=' => $end_last_week2])
            ->join($arr_join)
            ->group(['DataPreRegister.email'])
            ->count();

        $count_week3 = $this->DataPreRegister->find()
            ->where([
                'DataPreRegister.deleted' => 0,
                'ISNULL(SysUser.email)',
                'DataPreRegister.type' => 'injector',
                'DATE(DataPreRegister.created) >=' => $start_last_week3,
                'DATE(DataPreRegister.created) <=' => $end_last_week3])
            ->join($arr_join)
            ->group(['DataPreRegister.email'])
            ->count();

        if ($ent_total_query) {
        	// $html .= '<br><br><h1>USERS</h1><b>Certified Injectors</b><br>';
            $arr_totals['SALES']['users'] = [];

			$calc_tot = $count + $ent_total_query[0]['total_ci_pending_payment'] + $ent_total_query[0]['ready_injector'];
        	$arr_totals['SALES']['users']['partial_registrations'] = $count;
        	$arr_totals['SALES']['users']['ci_pending_payment'] = $ent_total_query[0]['total_ci_pending_payment'];
        	$arr_totals['SALES']['users']['ci_ready'] = $ent_total_query[0]['ready_injector'];
        	$arr_totals['SALES']['users']['ci_different'] = $calc_tot;
        	$arr_totals['SALES']['users']['ci_with_train'] = $ent_total_query[0]['total_ci_with_train'];

        	$arr_totals['SALES']['users']['gfe_ready'] = $ent_total_query[0]['ready_examiner'];
        	$arr_totals['SALES']['users']['gfe_total'] = $ent_total_query[0]['total_examiner'];

        	$arr_totals['SALES']['users']['patient_uninvited'] = $ent_total_query[0]['uninvited_patient'];
        	$arr_totals['SALES']['users']['patient_noapp'] = $ent_total_query[0]['total_no_app_patient'];
        	$arr_totals['SALES']['users']['patient_with_treatment'] = $ent_total_query[0]['patient_with_treatment'];

        	$arr_totals['SALES']['users']['clinic_ready'] = $ent_total_query[0]['ready_clinic'];
        	$arr_totals['SALES']['users']['clinic_total'] = $ent_total_query[0]['total_clinic'];
			

            $calc_tot_week1 = $count_week1 + $ent_total_query[1]['total_ci_pending_payment'] + $ent_total_query[1]['ready_injector'];
        	$arr_totals['SALES']['users']['partial_registrations_week1'] = $count_week1;
        	$arr_totals['SALES']['users']['ci_pending_payment_week1'] = $ent_total_query[1]['total_ci_pending_payment'];
        	$arr_totals['SALES']['users']['ci_ready_week1'] = $ent_total_query[1]['ready_injector'];
        	$arr_totals['SALES']['users']['ci_different_week1'] = $calc_tot_week1;
        	$arr_totals['SALES']['users']['ci_with_train_week1'] = $ent_total_query[1]['total_ci_with_train'];

        	$arr_totals['SALES']['users']['gfe_ready_week1'] = $ent_total_query[1]['ready_examiner'];
        	$arr_totals['SALES']['users']['gfe_total_week1'] = $ent_total_query[1]['total_examiner'];

        	$arr_totals['SALES']['users']['patient_uninvited_week1'] = $ent_total_query[1]['uninvited_patient'];
        	$arr_totals['SALES']['users']['patient_noapp_week1'] = $ent_total_query[1]['total_no_app_patient'];
        	$arr_totals['SALES']['users']['patient_with_treatment_week1'] = $ent_total_query[1]['patient_with_treatment'];

        	$arr_totals['SALES']['users']['clinic_ready_week1'] = $ent_total_query[1]['ready_clinic'];
        	$arr_totals['SALES']['users']['clinic_total_week1'] = $ent_total_query[1]['total_clinic'];


            $calc_tot_week2 = $count_week2 + $ent_total_query[2]['total_ci_pending_payment'] + $ent_total_query[2]['ready_injector'];
        	$arr_totals['SALES']['users']['partial_registrations_week2'] = $count_week2;
        	$arr_totals['SALES']['users']['ci_pending_payment_week2'] = $ent_total_query[2]['total_ci_pending_payment'];
        	$arr_totals['SALES']['users']['ci_ready_week2'] = $ent_total_query[2]['ready_injector'];
        	$arr_totals['SALES']['users']['ci_different_week2'] = $calc_tot_week2;
        	$arr_totals['SALES']['users']['ci_with_train_week2'] = $ent_total_query[2]['total_ci_with_train'];

        	$arr_totals['SALES']['users']['gfe_ready_week2'] = $ent_total_query[2]['ready_examiner'];
        	$arr_totals['SALES']['users']['gfe_total_week2'] = $ent_total_query[2]['total_examiner'];

        	$arr_totals['SALES']['users']['patient_uninvited_week2'] = $ent_total_query[2]['uninvited_patient'];
        	$arr_totals['SALES']['users']['patient_noapp_week2'] = $ent_total_query[2]['total_no_app_patient'];
        	$arr_totals['SALES']['users']['patient_with_treatment_week2'] = $ent_total_query[2]['patient_with_treatment'];

        	$arr_totals['SALES']['users']['clinic_ready_week2'] = $ent_total_query[2]['ready_clinic'];
        	$arr_totals['SALES']['users']['clinic_total_week2'] = $ent_total_query[2]['total_clinic'];


            $calc_tot_week3 = $count_week3 + $ent_total_query[3]['total_ci_pending_payment'] + $ent_total_query[3]['ready_injector'];
        	$arr_totals['SALES']['users']['partial_registrations_week3'] = $count_week3;
        	$arr_totals['SALES']['users']['ci_pending_payment_week3'] = $ent_total_query[3]['total_ci_pending_payment'];
        	$arr_totals['SALES']['users']['ci_ready_week3'] = $ent_total_query[3]['ready_injector'];
        	$arr_totals['SALES']['users']['ci_different_week3'] = $calc_tot_week3;
        	$arr_totals['SALES']['users']['ci_with_train_week3'] = $ent_total_query[3]['total_ci_with_train'];

        	$arr_totals['SALES']['users']['gfe_ready_week3'] = $ent_total_query[3]['ready_examiner'];
        	$arr_totals['SALES']['users']['gfe_total_week3'] = $ent_total_query[3]['total_examiner'];

        	$arr_totals['SALES']['users']['patient_uninvited_week3'] = $ent_total_query[3]['uninvited_patient'];
        	$arr_totals['SALES']['users']['patient_noapp_week3'] = $ent_total_query[3]['total_no_app_patient'];
        	$arr_totals['SALES']['users']['patient_with_treatment_week3'] = $ent_total_query[3]['patient_with_treatment'];

        	$arr_totals['SALES']['users']['clinic_ready_week3'] = $ent_total_query[3]['ready_clinic'];
        	$arr_totals['SALES']['users']['clinic_total_week3'] = $ent_total_query[3]['total_clinic'];
        }

        // pr($arr_totals['SALES']['ci_register']['paid_registrations']);
        // pr($arr_totals['SALES']['ci_register']['paid_registrations_week3']);
        // pr($arr_totals);
        // exit;

        $str_query_find = "SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'CI COMMISSION' AND P.payment <> '' AND P.is_visible = 1 AND P.prod = 1

            UNION ALL

            SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'CI COMMISSION' AND P.payment <> '' AND (P.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') AND P.is_visible = 1 AND P.prod = 1

            UNION ALL

            SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'CI COMMISSION' AND P.payment <> '' AND (P.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') AND P.is_visible = 1 AND P.prod = 1

            UNION ALL

            SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'CI COMMISSION' AND P.payment <> '' AND (P.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') AND P.is_visible = 1 AND P.prod = 1
        ";
        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
        	$arr_totals['PAYMENTS']['ci_paid_amount'] = number_format($ent_query[0]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_unpaid_amount'] = number_format($ent_query[0]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_total_amount'] = number_format($ent_query[0]['total_amount'] / 100 ,2);

        	$arr_totals['PAYMENTS']['ci_paid_amount_week1'] = number_format($ent_query[1]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_unpaid_amount_week1'] = number_format($ent_query[1]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_total_amount_week1'] = number_format($ent_query[1]['total_amount'] / 100 ,2);

        	$arr_totals['PAYMENTS']['ci_paid_amount_week2'] = number_format($ent_query[2]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_unpaid_amount_week2'] = number_format($ent_query[2]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_total_amount_week2'] = number_format($ent_query[2]['total_amount'] / 100 ,2);

        	$arr_totals['PAYMENTS']['ci_paid_amount_week3'] = number_format($ent_query[3]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_unpaid_amount_week3'] = number_format($ent_query[3]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['ci_total_amount_week3'] = number_format($ent_query[3]['total_amount'] / 100 ,2);
        }

		$str_query_find = "SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'GFE COMMISSION' AND P.payment <> '' AND P.is_visible = 1 AND P.prod = 1

            UNION ALL
            
            SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'GFE COMMISSION' AND P.payment <> '' AND (P.created BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59') AND P.is_visible = 1 AND P.prod = 1

            UNION ALL
            
            SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'GFE COMMISSION' AND P.payment <> '' AND (P.created BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59') AND P.is_visible = 1 AND P.prod = 1

            UNION ALL
            
            SELECT 
				SUM(P.total) AS total_amount,
				SUM(IF(P.comission_payed = 0, P.total, 0)) AS unpaid_amount,
				SUM(IF(P.comission_payed = 1, P.total, 0)) AS paid_amount
			FROM data_payment P
			WHERE P.type = 'GFE COMMISSION' AND P.payment <> '' AND (P.created BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59') AND P.is_visible = 1 AND P.prod = 1
            ";
        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
        	$arr_totals['PAYMENTS']['gfe_paid_amount'] = number_format($ent_query[0]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_unpaid_amount'] = number_format($ent_query[0]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_total_amount'] = number_format($ent_query[0]['total_amount'] / 100 ,2);

            $arr_totals['PAYMENTS']['gfe_paid_amount_week1'] = number_format($ent_query[1]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_unpaid_amount_week1'] = number_format($ent_query[1]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_total_amount_week1'] = number_format($ent_query[1]['total_amount'] / 100 ,2);

            $arr_totals['PAYMENTS']['gfe_paid_amount_week2'] = number_format($ent_query[2]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_unpaid_amount_week2'] = number_format($ent_query[2]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_total_amount_week2'] = number_format($ent_query[2]['total_amount'] / 100 ,2);

            $arr_totals['PAYMENTS']['gfe_paid_amount_week3'] = number_format($ent_query[3]['paid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_unpaid_amount_week3'] = number_format($ent_query[3]['unpaid_amount'] / 100 ,2);
        	$arr_totals['PAYMENTS']['gfe_total_amount_week3'] = number_format($ent_query[3]['total_amount'] / 100 ,2);
        }

		$str_query_find = "SELECT 
				COUNT(DISTINCT T.id) * 3.00 AS total,
                SUM(T.amount) * .03 AS total_comission
			FROM data_treatment T
			WHERE T.status = 'DONE' AND T.approved <> 'PENDING' AND T.payment <> '' AND T.assigned_doctor > 0 AND T.deleted = 0

            UNION ALL

            SELECT 
				COUNT(DISTINCT T.id) * 3.00 AS total,
                SUM(T.amount) * .03 AS total_comission
			FROM data_treatment T
			WHERE T.status = 'DONE' AND T.approved <> 'PENDING' AND T.payment <> '' AND T.assigned_doctor > 0 AND T.deleted = 0 AND (T.modified BETWEEN '{$start_last_week1} 00:00:00' AND '{$end_last_week1} 23:59:59')

            UNION ALL

            SELECT 
				COUNT(DISTINCT T.id) * 3.00 AS total,
                SUM(T.amount) * .03 AS total_comission
			FROM data_treatment T
			WHERE T.status = 'DONE' AND T.approved <> 'PENDING' AND T.payment <> '' AND T.assigned_doctor > 0 AND T.deleted = 0 AND (T.modified BETWEEN '{$start_last_week2} 00:00:00' AND '{$end_last_week2} 23:59:59')

            UNION ALL

            SELECT 
				COUNT(DISTINCT T.id) * 3.00 AS total,
                SUM(T.amount) * .03 AS total_comission
			FROM data_treatment T
			WHERE T.status = 'DONE' AND T.approved <> 'PENDING' AND T.payment <> '' AND T.assigned_doctor > 0 AND T.deleted = 0 AND (T.modified BETWEEN '{$start_last_week3} 00:00:00' AND '{$end_last_week3} 23:59:59')
            ";
        
        $ent_query = $this->DataPreRegister->getConnection()->execute($str_query_find)->fetchAll('assoc');
        if ($ent_query) {
        	$arr_totals['PAYMENTS']['doctor_total_tobepaid'] = number_format((doubleval($ent_query[0]['total']) + doubleval($ent_query[0]['total_comission'] / 100)), 2);

        	$arr_totals['PAYMENTS']['doctor_total_tobepaid_week1'] = number_format((doubleval($ent_query[1]['total']) + doubleval($ent_query[1]['total_comission'] / 100)), 2);
        	$arr_totals['PAYMENTS']['doctor_total_tobepaid_week2'] = number_format((doubleval($ent_query[2]['total']) + doubleval($ent_query[2]['total_comission'] / 100)), 2);
        	$arr_totals['PAYMENTS']['doctor_total_tobepaid_week3'] = number_format((doubleval($ent_query[3]['total']) + doubleval($ent_query[3]['total_comission'] / 100)), 2);
        }

    	$this->success(true);
    	// $this->set('data', $arr_totals);

        // pr($arr_totals);
        // exit;

        // $this->loadModel('SpaLiveV1.SysUsers');
        $html2pdf = new HTML2PDF('F','A4','en', true, 'UTF-8', array(0,0,0,0));

        // $url_root = $this->URL_ROOT . "api";
        $url_root = 'http://localhost/apispalive';
            // $html2pdf->writeHTML("
            //     <page>
            //         <div style='width: 190mm; height: 277mm; position:relative;padding: 10mm; color: #373a48;'>
            //             <table style=\"width: 190mm;\">
            //                 <tr style=\"width: 100%;\">
            //                     <td style=\"width: 45mm;height: 15mm; padding-top: 3mm;\">
            //                         <img src=\"" . $url_root . "/img/logo.png\" style=\"width:38mm;\">
            //                     </td>
            //                     <td style=\"width: 95mm;height: 20mm;\">
            //                         <h1 style=\"font-size: 20px; text-align: center;\">WEEKLY REPORT FOR SALES AND PAYMENTS FROM SPALIVEMD</h1>
            //                     </td>
            //                     <td style=\"width: 45mm;height: 20mm; font-size: 16px; text-align: right;\">
            //                         <p>12/24/2021 <br> 03:00PM</p>
            //                     </td>
            //                 </tr>
            //             </table>
            //             <!-- <div style=\"width: 190mm; height: .3mm; background-color: #9686b7;\"></div> -->
            //             <div style=\"width: 190mm; height: .3mm; border-bottom: 2.5mm double #9686b7\"></div>

            //             <h1 style=\"font-size: 20px; text-align: center;\">SALES</h1>

            //             <table style=\"width: 190mm;\">
            //                 <tr style=\"width: 100%;\">
            //                     <td style=\"width: 93mm;height: 20mm;\">
            //                         <h2 style=\"font-size: 17px;\">PURCHASES</h2>

            //                         <table style=\"width: 100%; background-color: #f6f6f6;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>CI Registrations</b></td>
            //                                 </tr>
            //                                 <tr>
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the registration:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$4,232.70</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$99,461.50</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Number of registrations:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>

            //                         <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 7mm;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>Products</b></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the registration:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$4,232.70</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$99,461.50</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Number of purchases:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>
            //                     </td>
            //                     <td style=\"width: 93mm;height: 20mm; text-align: right;\">
            //                         <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 13.4mm;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>Exams</b></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the exams:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$4,232.70</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$99,461.50</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Number of exams:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>

            //                         <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 7mm;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>Treatments</b></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the treatments:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$4,232.70</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$99,461.50</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Number of treatments:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># CI who made at least a treatment:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b>$ Average treatment price:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>
            //                     </td>
            //                 </tr>
            //             </table>


            //             <table style=\"width: 190mm;\">
            //                 <tr style=\"width: 100%;\">
            //                     <td style=\"width: 93mm;height: 20mm;\">
            //                         <h2 style=\"font-size: 17px;\">USERS</h2>

            //                         <table style=\"width: 100%; background-color: #f6f6f6;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>Certified injectors</b></td>
            //                                 </tr>
            //                                 <tr>
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Partial registrations:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Complete registration without payment:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Complete registration with payment:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b>Total different injectors:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Ready with training:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>

            //                         <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 7mm;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>GFE</b></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Ready:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Total:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>
            //                     </td>
            //                     <td style=\"width: 93mm;height: 20mm; text-align: right;\">
            //                         <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 13.4mm;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>Patients</b></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Without app:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># With app:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Number of patients with an app who had a treatment:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 1mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>

            //                         <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 7mm;\">
            //                             <thead>
            //                                 <tr>
            //                                     <th style=\"width: 40%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                     <th style=\"width: 30%;\"></th>
            //                                 </tr>
            //                             </thead>

            //                             <tbody>
            //                                 <tr>
            //                                     <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"3\"><b>Clinic</b></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Last 3 weeks</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Ready:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                                 <tr style=\"\">
            //                                     <td style=\"width: 40%; text-align: right;\"><p style=\"margin:0;\"><b># Total:</b></p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">71</p></td>
            //                                     <td style=\"width: 30%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">227</p></td>
            //                                 </tr>
            //                             </tbody>
            //                         </table>
            //                     </td>
            //                 </tr>
            //             </table>
                        
            //         </div>
            //     </page>");

                $parsed_start_last_week1 = date('m/d/Y', strtotime($start_last_week1));
                $parsed_end_last_week1 = date('m/d/Y', strtotime($end_last_week1));
                $parsed_start_last_week2 = date('m/d/Y', strtotime($start_last_week2));
                $parsed_end_last_week2 = date('m/d/Y', strtotime($end_last_week2));
                $parsed_start_last_week3 = date('m/d/Y', strtotime($start_last_week3));
                $parsed_end_last_week3 = date('m/d/Y', strtotime($end_last_week3));

                // $html2pdf->writeHTML("
                //     <!DOCTYPE html>
                //     <html lang=\"en\">
                //         <head>
                //             <meta charset=\"UTF-8\">
                //             <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">
                //             <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
                //             <title>Document</title>
                //         </head>
                //         <body>");
                $html2pdf->writeHTML("
                <page>
                    <div style='width: 190mm; height: 277mm; position:relative;padding: 10mm; color: #373a48;'>
                        <table style=\"width: 190mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"width: 45mm;height: 15mm; padding-top: 3mm;\">
                                    <img src=\"" . $url_root . "/img/logo.png\" style=\"width:38mm;\">
                                </td>
                                <td style=\"width: 95mm;height: 20mm;\">
                                    <h1 style=\"font-size: 20px; text-align: center;\">WEEKLY REPORT FOR SALES AND PAYMENTS FROM SPALIVEMD</h1>
                                </td>
                                <td style=\"width: 45mm;height: 20mm; font-size: 16px; text-align: right;\">
                                    <p>" . date('m/d/Y') . " <br> " . date('h:iA') . "</p>
                                </td>
                            </tr>
                        </table>
                        <!-- <div style=\"width: 190mm; height: .3mm; background-color: #9686b7;\"></div> -->
                        <div style=\"width: 190mm; height: .3mm; border-bottom: 2.5mm double #9686b7\"></div>

                        <h1 style=\"font-size: 20px; text-align: center;\">SALES</h1>

                        <table style=\"width: 190mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"width: 186mm;height: 20mm;\">
                                    <h2 style=\"font-size: 17px;\"></h2>

                                    <table style=\"width: 100%; background-color: #f6f6f6;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>CI Registrations</b></td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the registration:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of registrations:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Products</b></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the registration:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of purchases:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Exams</b></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the exams:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of exams:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Treatments</b></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the treatments:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of treatments:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># CI who made at least a treatment:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Average treatment price:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <page_footer>
                        <div style=\"padding-bottom: 4mm; text-align: center; font-size: 15px;\">
                            [[page_cu]]/[[page_nb]]
                        </div>
                    </page_footer>
                </page>");

                $html2pdf->writeHTML("
                <page>
                    <div style='width: 190mm; height: 277mm; position:relative;padding: 10mm; color: #373a48;'>
                        <table style=\"width: 190mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"width: 45mm;height: 15mm; padding-top: 3mm;\">
                                    <img src=\"" . $url_root . "/img/logo.png\" style=\"width:38mm;\">
                                </td>
                                <td style=\"width: 95mm;height: 20mm;\">
                                    <h1 style=\"font-size: 20px; text-align: center;\">WEEKLY REPORT FOR SALES AND PAYMENTS FROM SPALIVEMD</h1>
                                </td>
                                <td style=\"width: 45mm;height: 20mm; font-size: 16px; text-align: right;\">
                                    <p>" . date('m/d/Y') . " <br> " . date('h:iA') . "</p>
                                </td>
                            </tr>
                        </table>
                        <!-- <div style=\"width: 190mm; height: .3mm; background-color: #9686b7;\"></div> -->
                        <div style=\"width: 190mm; height: .3mm; border-bottom: 2.5mm double #9686b7\"></div>

                        <table style=\"width: 190mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"width: 186mm;height: 20mm;\">
                                    <h2 style=\"font-size: 17px;\">USERS</h2>

                                    <table style=\"width: 100%; background-color: #f6f6f6;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Certified injectors</b></td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Partial registrations:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['partial_registrations_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['partial_registrations_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['partial_registrations_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['partial_registrations'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Complete registration without payment:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_pending_payment_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_pending_payment_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_pending_payment_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_pending_payment'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Complete registration with payment:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_ready_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_ready_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_ready_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_ready'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>Total different injectors:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_different_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_different_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_different_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_different'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Ready with training:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_with_train_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_with_train_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_with_train_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['ci_with_train'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 7mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>GFE</b></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Ready:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_ready_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_ready_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_ready_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_ready'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Total:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_total_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_total_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_total_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['gfe_total'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 13.4mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Patients</b></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Without app:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_uninvited_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_uninvited_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_uninvited_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_uninvited'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># With app:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_noapp_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_noapp_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_noapp_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_noapp'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of patients with an app who had a treatment:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_with_treatment_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_with_treatment_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_with_treatment_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['patient_with_treatment'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 7mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Clinic</b></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Ready:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_ready_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_ready_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_ready_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_ready'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Total:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_total_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_total_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_total_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['users']['clinic_total'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <page_footer>
                        <div style=\"padding-bottom: 4mm; text-align: center; font-size: 15px;\">
                            [[page_cu]]/[[page_nb]]
                        </div>
                    </page_footer>
                </page>");

                $html2pdf->writeHTML("
                <page>
                    <div style='width: 190mm; height: 277mm; position:relative;padding: 10mm; color: #373a48;'>
                        <table style=\"width: 190mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"width: 45mm;height: 15mm; padding-top: 3mm;\">
                                    <img src=\"" . $url_root . "/img/logo.png\" style=\"width:38mm;\">
                                </td>
                                <td style=\"width: 95mm;height: 20mm;\">
                                    <h1 style=\"font-size: 20px; text-align: center;\">WEEKLY REPORT FOR SALES AND PAYMENTS FROM SPALIVEMD</h1>
                                </td>
                                <td style=\"width: 45mm;height: 20mm; font-size: 16px; text-align: right;\">
                                    <p>" . date('m/d/Y') . " <br> " . date('h:iA') . "</p>
                                </td>
                            </tr>
                        </table>
                        <!-- <div style=\"width: 190mm; height: .3mm; background-color: #9686b7;\"></div> -->
                        <div style=\"width: 190mm; height: .3mm; border-bottom: 2.5mm double #9686b7\"></div>

                        <h1 style=\"font-size: 20px; text-align: center;\">PAYMENTS FROM SPALIVE</h1>

                        <table style=\"width: 190mm;\">
                            <tr style=\"width: 100%;\">
                                <td style=\"width: 186mm;height: 20mm;\">

                                    <table style=\"width: 100%; background-color: #f6f6f6;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Certified Injectors</b></td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_paid_amount_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_paid_amount_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_paid_amount_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_paid_amount'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total unpaid:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_unpaid_amount_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_unpaid_amount_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_unpaid_amount_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_unpaid_amount'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total (paid + unpaid):</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_total_amount_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_total_amount_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_total_amount_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['ci_total_amount'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Examiners</b></td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_paid_amount_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_paid_amount_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_paid_amount_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_paid_amount'] . "</p></td>
                                            </tr>
                                            <tr style=\"\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total unpaid:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_unpaid_amount_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_unpaid_amount_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_unpaid_amount_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_unpaid_amount'] . "</p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total (paid + unpaid):</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_total_amount_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_total_amount_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_total_amount_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['gfe_total_amount'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
                                        <thead>
                                            <tr>
                                                <th style=\"width: 28%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                                <th style=\"width: 18%;\"></th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            <tr>
                                                <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Doctors</b></td>
                                            </tr>
                                            <tr>
                                                <td style=\"width: 28%; text-align: right;\"></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
                                                } - {$parsed_end_last_week3}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
                                                } - {$parsed_end_last_week2}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
                                                } - {$parsed_end_last_week1}</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
                                            </tr>
                                            <tr style=\"background-color: #fcfcfc;\">
                                                <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total to be paid:</b></p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['doctor_total_tobepaid_week3'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['doctor_total_tobepaid_week2'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['doctor_total_tobepaid_week1'] . "</p></td>
                                                <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['PAYMENTS']['doctor_total_tobepaid'] . "</p></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <page_footer>
                        <div style=\"padding-bottom: 4mm; text-align: center; font-size: 15px;\">
                            [[page_cu]]/[[page_nb]]
                        </div>
                    </page_footer>
                </page>");

            $txt_report = 'generalreport_' . date('mdY-hiA') . '.pdf';
            $filename = TMP . 'reports' . DS . $txt_report;

            $this->SysReport->new_entity([
                'uid'           => $this->SysReport->new_uid(),
                'name'          => $txt_report,
                'report_from'   => $start_last_week3 ,
                'report_to'     => $today,
            ]);

            $html2pdf->Output($filename, 'F'); //,'D'

            // $data = array(
            //     'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
            //     // 'to'      => 'oscar.caldera@advantedigital.com',
            //     'to'      => 'jorge.alejandro.puente@gmail.com',
            //     // 'to'      => 'khanzab@gmail.com',
            //     'subject' => 'General report SpaLiveMD',
            //     'html'    => 'This is the general report from ' . date('m/d/Y', strtotime($start_last_week3)) . ' to ' . date('m/d/Y', strtotime($today)) . '<br><br> This was generated ' . date('m/d/Y h:ia'),
            //     // 'attachment[1]' => curl_file_create($html2pdf->output('generalreport_' . date('mdY-hiA') . '.pdf', 'S'), 'application/pdf', 'generalreport_' . date('mdY-hiA') . '.pdf')
            //     // 'attachment[1]' => curl_file_create($html2pdf->output('generalreport_' . date('mdY-hiA') . '.pdf', 'E'), 'application/pdf', 'generalreport_' . date('mdY-hiA') . '.pdf')
            //     // 'attachment[1]' => $html2pdf->output('generalreport_' . date('mdY-hiA') . '.pdf', 'S'),
            //     // 'attachment[1]' => new \CURLFile($html2pdf->output('generalreport_' . date('mdY-hiA') . '.pdf', 'S'), 'application/pdf', 'report.pdf')
            //     // 'attachment[1]' => ['filePath' => $txt_file, 'filename' => 'test.pdf']
            //     'attachment[1]' => curl_file_create($filename, 'application/pdf', $txt_report),
            // );


            // $curl = curl_init();
            // curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
            // curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            // curl_setopt($curl, CURLOPT_USERPWD, 'api:' . $mailgunKey);
            // curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
            // curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
            // curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
            // curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
            // curl_setopt($curl, CURLOPT_POST, true); 
            // curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
            // curl_setopt($curl, CURLOPT_POSTFIELDS, $data);

            // $result = curl_exec($curl);
            // curl_close($curl);

            $this->success();
    }

    public function download_last_report() {
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
        }

        $this->loadModel('SpaLiveV1.SysReport');

		$_where = ['SysReport.deleted' => 0];

		$ent_report = $this->SysReport->find()
			->where($_where)
			->order(['SysReport.report_to' => 'DESC'])
			->first();
		
		if (empty($ent_report)) {
			$this->success(false);
			$this->message("There's not generated reports.");
		} else {
            $filepath = TMP . 'reports' . DS . $ent_report->name;
            $size = filesize($filepath);
            
            // $this->set('filepath', $filepath);
            // $this->set('test', $ent_report->name);return;

            // header("Content-Type: application/pdf");
            // header("Content-Disposition: attachment; filename={$ent_report->name}");
            // header("Content-Length: {$size}");
            // echo file_get_contents($filepath)
            
            // $this->set('file', file_get_contents($filepath));
            $this->set('size', filesize($filepath));
            $this->set('name', $ent_report->name);
            $this->set('filepath', $filepath);
            $this->success(true);

            // exit;
        }
    }

    public function send_general_report() {
        
        $this->loadModel('SpaLiveV1.SysReport');

		$_where = ['SysReport.deleted' => 0];

		$ent_report = $this->SysReport->find()
			->where($_where)
			->order(['SysReport.report_to' => 'DESC'])
			->first();
		
		if (empty($ent_report)) {
			$this->success(false);
			$this->message("There's not generated reports.");
		} else {
            $filepath = TMP . 'reports' . DS . $ent_report->name;

            $data = array(
                'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
                // 'to'      => 'oscar.caldera@advantedigital.com',
                'to'      => 'jorge.alejandro.puente@gmail.com',
                // 'to'      => 'khanzab@gmail.com',
                'subject' => 'General report SpaLiveMD',
                'html'    => 'This is the general report from ' . $ent_report->report_from->i18nFormat('MM/dd/yyyy') . ' to ' . $ent_report->report_to->i18nFormat('MM/dd/yyyy') . '<br><br> This was generated ' . $ent_report->created->i18nFormat('MM/dd/yyyy hh:mm a'),
                'attachment[1]' => curl_file_create($filepath, 'application/pdf', $ent_report->name),
            );

            $mailgunKey = $this->getMailgunKey();
    
    
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, 'https://api.mailgun.net/v3/mg.spalivemd.com/messages');
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
            
            
            $this->success(true);
        }
    }

    // public function monthly_statement() {
    //     $html2pdf->writeHTML("
    //         <page>
    //             <div style='width: 190mm; height: 277mm; position:relative;padding: 10mm; color: #373a48;'>
    //                 <table style=\"width: 190mm;\">
    //                     <tr style=\"width: 100%;\">
    //                         <td style=\"width: 45mm;height: 15mm; padding-top: 3mm;\">
    //                             <img src=\"" . $url_root . "/img/logo.png\" style=\"width:38mm;\">
    //                         </td>
    //                         <td style=\"width: 95mm;height: 20mm;\">
    //                             <h1 style=\"font-size: 20px; text-align: center;\">WEEKLY REPORT FOR SALES AND PAYMENTS FROM SPALIVEMD</h1>
    //                         </td>
    //                         <td style=\"width: 45mm;height: 20mm; font-size: 16px; text-align: right;\">
    //                             <p>" . date('m/d/Y') . " <br> " . date('h:iA') . "</p>
    //                         </td>
    //                     </tr>
    //                 </table>
    //                 <!-- <div style=\"width: 190mm; height: .3mm; background-color: #9686b7;\"></div> -->
    //                 <div style=\"width: 190mm; height: .3mm; border-bottom: 2.5mm double #9686b7\"></div>

    //                 <h1 style=\"font-size: 20px; text-align: center;\">SALES</h1>

    //                 <table style=\"width: 190mm;\">
    //                     <tr style=\"width: 100%;\">
    //                         <td style=\"width: 186mm;height: 20mm;\">
    //                             <h2 style=\"font-size: 17px;\"></h2>

    //                             <table style=\"width: 100%; background-color: #f6f6f6;\">
    //                                 <thead>
    //                                     <tr>
    //                                         <th style=\"width: 28%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                     </tr>
    //                                 </thead>

    //                                 <tbody>
    //                                     <tr>
    //                                         <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>CI Registrations</b></td>
    //                                     </tr>
    //                                     <tr>
    //                                         <td style=\"width: 28%; text-align: right;\"></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
    //                                         } - {$parsed_end_last_week3}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
    //                                         } - {$parsed_end_last_week2}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
    //                                         } - {$parsed_end_last_week1}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
    //                                     </tr>
    //                                     <tr style=\"background-color: #fcfcfc;\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the registration:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['ci_register']['paid_registrations'] . "</p></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of registrations:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['ci_register']['registrations'] . "</p></td>
    //                                     </tr>
    //                                 </tbody>
    //                             </table>

    //                             <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
    //                                 <thead>
    //                                     <tr>
    //                                         <th style=\"width: 28%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                     </tr>
    //                                 </thead>

    //                                 <tbody>
    //                                     <tr>
    //                                         <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Products</b></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
    //                                         } - {$parsed_end_last_week3}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
    //                                         } - {$parsed_end_last_week2}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
    //                                         } - {$parsed_end_last_week1}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
    //                                     </tr>
    //                                     <tr style=\"background-color: #fcfcfc;\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the registration:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['purchases']['paid_purchases'] . "</p></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of purchases:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['purchases']['purchases'] . "</p></td>
    //                                     </tr>
    //                                 </tbody>
    //                             </table>

    //                             <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
    //                                 <thead>
    //                                     <tr>
    //                                         <th style=\"width: 28%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                     </tr>
    //                                 </thead>

    //                                 <tbody>
    //                                     <tr>
    //                                         <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Exams</b></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
    //                                         } - {$parsed_end_last_week3}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
    //                                         } - {$parsed_end_last_week2}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
    //                                         } - {$parsed_end_last_week1}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
    //                                     </tr>
    //                                     <tr style=\"background-color: #fcfcfc;\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the exams:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['gfe']['paid_exams'] . "</p></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of exams:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['gfe']['exams'] . "</p></td>
    //                                     </tr>
    //                                 </tbody>
    //                             </table>

    //                             <table style=\"width: 100%; background-color: #f6f6f6; margin-top: 6mm;\">
    //                                 <thead>
    //                                     <tr>
    //                                         <th style=\"width: 28%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                         <th style=\"width: 18%;\"></th>
    //                                     </tr>
    //                                 </thead>

    //                                 <tbody>
    //                                     <tr>
    //                                         <td style=\"font-size: 15px; border-bottom: 1ps solid #373a48; padding-bottom: 1mm; text-align: center;\" colspan=\"5\"><b>Treatments</b></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week3
    //                                         } - {$parsed_end_last_week3}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week2
    //                                         } - {$parsed_end_last_week2}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>{$parsed_start_last_week1
    //                                         } - {$parsed_end_last_week1}</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding: 1mm 0;\"><p style=\"margin:0;\"><b>Total</b></p></td>
    //                                     </tr>
    //                                     <tr style=\"background-color: #fcfcfc;\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Total paid from the treatments:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['paid_treatments'] . "</p></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># Number of treatments:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['treatments'] . "</p></td>
    //                                     </tr>
    //                                     <tr style=\"background-color: #fcfcfc;\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b># CI who made at least a treatment:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: 2mm;\"><p style=\"margin:0;\">" . $arr_totals['SALES']['treatments']['ci_treatments'] . "</p></td>
    //                                     </tr>
    //                                     <tr style=\"\">
    //                                         <td style=\"width: 28%; text-align: right;\"><p style=\"margin:0;\"><b>$ Average treatment price:</b></p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price_week3'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price_week2'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price_week1'] . "</p></td>
    //                                         <td style=\"width: 18%; text-align: center; padding-top: .1mm;\"><p style=\"margin:0;\">$ " . $arr_totals['SALES']['treatments']['average_treatments_price'] . "</p></td>
    //                                     </tr>
    //                                 </tbody>
    //                             </table>
    //                         </td>
    //                     </tr>
    //                 </table>
    //             </div>
    //             <page_footer>
    //                 <div style=\"padding-bottom: 4mm; text-align: center; font-size: 15px;\">
    //                     [[page_cu]]/[[page_nb]]
    //                 </div>
    //             </page_footer>
    //         </page>");
    // }

}