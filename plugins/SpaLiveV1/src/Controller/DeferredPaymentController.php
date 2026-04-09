<?php
namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
use Cake\Utility\Text;
use Cake\I18n\FrozenTime;
use Cake\Core\Configure;

require_once(ROOT . DS . 'vendor' . DS  . 'stripe' . DS . 'init.php');
use Stripe\Stripe;
use \Stripe\Error;

/**
 * DeferredPaymentController
 * 
 * API Controller para gestionar pagos diferidos/programados
 */
class DeferredPaymentController extends AppPluginController {

    private $training_basic = 79500;
    private $training_advanced = 89500;
    private $level_3_fillers = 150000;//level 3 fillers
    private $level_3_medical = 99500;//level 3 medical
    private $level_1_to_1 = 19999;//level 1 to 1
    
    public function initialize() : void{
        parent::initialize();
        date_default_timezone_set("America/Chicago");
        $this->URL_API = env('URL_API', 'https://api.myspalive.com/');
        $this->URL_WEB = env('URL_WEB', 'https://app.myspalive.com/');
        $this->URL_ASSETS = env('URL_ASSETS', 'https://api.myspalive.com/assets/');
        $this->URL_PANEL = env('URL_PANEL', 'https://panel.myspalive.com/');
        $this->loadModel('SpaLiveV1.AppToken');
        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        
        $token = get('token',"");
        if(isset($token)){
            $user = $this->AppToken->checkToken($token);
            if($user !== false){

            }

            $ver = get('version', '');
            $ver = str_replace('version ', '', $ver);
        } else {
            // TEXAS
        }
    }
    
    /**
     * Programa un nuevo pago diferido
     * 
     * POST /api/v1/deferred-payment/schedule
     * 
     * Body:
     * {
     *   "payment_method": "pm_XXXXX",
     *   "level": "LEVEL 1",
     *   "scheduled_date": "2025-11-07"
     * }
     * 
     * Nota: reference_type puede ser el course_type para identificar el tipo de curso
     */
    public function schedule() {
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

        $this->loadModel('SpaLiveV1.DataPromoCodes');

        $level = get('level', '');
        if(empty($level)){
            $this->message('Invalid level.');
            return;
        }

        $payment_method = get('payment_method', '');

        if(empty($payment_method) || $payment_method === ''){
            $this->message('The payment method has failed or is empty.');
            return;
        }
 
        try {
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
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            $this->message('Invalid payment method: ' . $e->getMessage());
            return;
        } catch (\Stripe\Exception\AuthenticationException $e) {
            $this->message('Stripe authentication failed.');
            return;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->message('Stripe API error: ' . $e->getMessage());
            return;
        }
 
        $stripe_user_email = $user['email'];
        $stripe_user_name = $user['name'];
  
        try {
            $oldCustomer = $stripe->customers->all([
                "email" => $stripe_user_email,
                "limit" => 1,
            ]);
         
            if (count($oldCustomer) == 0) {
                $customer = $stripe->customers->create([
                    'description' => $stripe_user_name,
                    'email' => $stripe_user_email,
                ]);
            } else {
                $customer = $oldCustomer->data[0];
            }
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $this->message('Error creating/retrieving customer: ' . $e->getMessage());
            return;
        }

        $amount = 0;
        $type = '';

        $levels = [
            'LEVEL 1',
            'LEVEL 2',
            'LEVEL 3 FILLERS',
            'LEVEL 3 MEDICAL', 
            'LEVEL 1-1 NEUROTOXINS',
        ];

        $discount = 0;
        
        
        
        if(in_array($level, $levels)){
            $d_code = '';
            switch($level){
                case 'LEVEL 1':
                    $amount = $this->training_basic;
                    $type = 'BASIC COURSE';
                    $d_code = 'ELITE300B';
                    break;
                case 'LEVEL 2':
                    $amount = $this->training_advanced;
                    $type = 'ADVANCED COURSE';
                    // $d_code = 'ELITE300A';
                    $d_code = '';
                    break;
                case 'LEVEL 3 FILLERS':
                    $amount = $this->level_3_fillers;
                    $type = 'FILLERS COURSE';
                    $d_code = '';
                    break;
                case 'LEVEL 3 MEDICAL':
                    $amount = $this->level_3_medical;
                    $type = 'ADVANCED TECHNIQUES MEDICAL';
                    $d_code = 'ELITE300';
                    break;
                case 'LEVEL 1-1 NEUROTOXINS':
                    $amount = $this->level_1_to_1;
                    $type = 'LEVEL 1-1 NEUROTOXINS';
                    $d_code = '';
                    break;
            }

             if (!empty($d_code)) {
                $this->set('promo_code', $d_code);
                $ent_code = $this->DataPromoCodes->find()->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.code' => $d_code])->last();
                if (!empty($ent_code)) {
                    $discount = $this->validateDefaultPromoCode($ent_code,$amount);
                }
            }

        }else{
            $this->loadModel('SpaLiveV1.CatCoursesType');
            $course_type = $this->CatCoursesType->find()->where(['CatCoursesType.name_key' => $level])->first();
            if(!empty($course_type)){
                $amount = $course_type->price;
                $type = $course_type->name_key;
            }


            if ($course_type->discount_id != 0) {
                $ent_code = $this->DataPromoCodes->find()->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.id' => $course_type->discount_id])->first();
                if (!empty($ent_code)) {
                    $discount = $this->validateDefaultPromoCode($ent_code,$course_type->price);
                }
            }

        }

        $amount = intval($amount - $discount);
        $stripe_fee = intval($amount * 0.0315);
        $total = intval($amount + $stripe_fee);

        if($total == 0){
            $this->message('Invalid level amount.');
            return;
        }

        $scheduled_date = get('scheduled_date', '');

        if(empty($scheduled_date)){
            $this->message('Invalid scheduled date.');
            return;
        }

        // Validar que la fecha sea válida y futura
        $scheduled_timestamp = strtotime($scheduled_date);
        if($scheduled_timestamp === false || $scheduled_timestamp < time()){
            $this->message('Invalid scheduled date. Must be a valid future date.');
            return;
        }
        
        // Verificar que USER_ID esté definida
        if (!defined('USER_ID')) {
            $this->message('User session not found.');
            return;
        }

        try {
            
            // Crear el pago diferido
            $entity = $this->DataDeferredPayments->newEntity([
                'uid' => Text::uuid(),
                'user_id' => USER_ID,
                'customer_id' => $customer->id,
                'payment_method' => $payment_method,
                'amount' => $total,
                'currency' => 'usd',
                'description' => $type, // valor de enum en data_payment
                'type' => $level, // valor de enum en data_trainings
                'reference_id' => null,
                'reference_type' => $level,  // Puede ser course_type
                'scheduled_date' => $scheduled_timestamp,
                'status' => 'PENDING',
                'metadata' => '',
                'created' => date('Y-m-d H:i:s'),
                'created_by' => USER_ID,
                'modified' => date('Y-m-d H:i:s'),
                'modified_by' => USER_ID,
                'deleted' => 0
            ]);
            
            if ($entity->hasErrors()) {
                throw new \Exception("Error al crear el pago: " . json_encode($entity->getErrors()));
            }
            
            $deferred_payment = $this->DataDeferredPayments->save($entity);

            $this->loadModel('SpaLiveV1.DataPayment');
            $commision_payed = 1;

            $array_save = array(
                'id_from' => USER_ID,
                'id_to' => 0,
                'uid' => $deferred_payment->uid,
                'type' => $type,
                'intent' => '',
                'payment' => '',
                'receipt' => '',
                'discount_credits' => 0,
                'promo_discount' => 0,
                'promo_code' =>  '',
                'subtotal' => $amount,
                'total' => 0,
                'prod' => 1,
                'is_visible' => 1,
                'comission_payed' => $commision_payed,
                'comission_generated' => 0,
                'prepaid' => 0,
                'created' => date('Y-m-d H:i:s'),
                'createdby' => USER_ID,
                'payment_option' => 0,
                'state' => USER_STATE,
                'total_cash' => 0,
                'deferred_payment_id' => $deferred_payment->id,
            );
            $c_entity = $this->DataPayment->newEntity($array_save);
            if(!$c_entity->hasErrors()) {
                $this->DataPayment->save($c_entity); 
            } else {

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
                $tag = $Ghl->addTag($contactId, $array_ghl['email'], $array_ghl['phone'], 'deferred payment');
                $this->set('tag', $tag);
            }

            $this->success();
                
        } catch (\Exception $e) {
            $this->message($e->getMessage());
        }
    }

    private function validateDefaultPromoCode($ent_codes,$subtotal) {
        $total = $subtotal;
        if ($ent_codes->type == 'PERCENTAGE') {   
            $total = $subtotal * (100 - $ent_codes->discount) / 100;
            if ($total < 100) $total = 100;
        } else if ($ent_codes->type == 'AMOUNT') { 
            $total = $subtotal - $ent_codes->discount;
            if ($total < 100) $total = 100;
        }
        
        return round($subtotal - $total);

    }
    
    /**
     * Lista los pagos diferidos de un usuario
     * 
     * GET /api/v1/deferred-payment/list/{user_id}
     */
    public function listByUser($user_id = null) {
        $this->request->allowMethod(['get']);
        
        try {
            if (empty($user_id)) {
                throw new \Exception("User ID requerido");
            }
            
            $payments = $this->DataDeferredPayments->find()
                ->select([
                    'id',
                    'uid',
                    'amount',
                    'currency',
                    'description',
                    'type',
                    'reference_id',
                    'reference_type',
                    'scheduled_date',
                    'executed_date',
                    'status',
                    'receipt_url',
                    'error_message',
                    'created'
                ])
                ->where([
                    'user_id' => $user_id,
                    'deleted' => 0
                ])
                ->order(['scheduled_date' => 'DESC'])
                ->all();
            
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => true,
                    'count' => $payments->count(),
                    'data' => $payments->toArray()
                ]));
                
        } catch (\Exception $e) {
            return $this->response
                ->withType('application/json')
                ->withStatus(400)
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Obtiene el detalle de un pago diferido
     * 
     * GET /api/v1/deferred-payment/detail/{uid}
     */
    public function detail($uid = null) {
        $this->request->allowMethod(['get']);
        
        try {
            if (empty($uid)) {
                throw new \Exception("UID requerido");
            }
            
            $payment = $this->DataDeferredPayments->find()
                ->select([
                    'DataDeferredPayments.id',
                    'DataDeferredPayments.uid',
                    'DataDeferredPayments.user_id',
                    'DataDeferredPayments.amount',
                    'DataDeferredPayments.currency',
                    'DataDeferredPayments.description',
                    'DataDeferredPayments.type',
                    'DataDeferredPayments.reference_id',
                    'DataDeferredPayments.reference_type',
                    'DataDeferredPayments.scheduled_date',
                    'DataDeferredPayments.executed_date',
                    'DataDeferredPayments.status',
                    'DataDeferredPayments.payment_intent_id',
                    'DataDeferredPayments.charge_id',
                    'DataDeferredPayments.receipt_url',
                    'DataDeferredPayments.error_message',
                    'DataDeferredPayments.email_sent',
                    'DataDeferredPayments.metadata',
                    'DataDeferredPayments.created',
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
                    'DataDeferredPayments.uid' => $uid,
                    'DataDeferredPayments.deleted' => 0
                ])
                ->first();
            
            if (!$payment) {
                throw new \Exception("Pago no encontrado");
            }
            
            // Obtener el log de intentos
            $attempts = $this->DataDeferredPaymentsLog->find()
                ->select([
                    'attempt_number',
                    'attempt_date',
                    'status',
                    'error_message'
                ])
                ->where(['deferred_payment_id' => $payment->id])
                ->order(['attempt_number' => 'DESC'])
                ->all();
            
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => true,
                    'data' => [
                        'payment' => $payment,
                        'attempts' => $attempts->toArray()
                    ]
                ]));
                
        } catch (\Exception $e) {
            return $this->response
                ->withType('application/json')
                ->withStatus(400)
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
        }
    }
    
    /**
     * Cancela un pago diferido pendiente
     * 
     * PUT /api/v1/deferred-payment/cancel/{uid}
     */
    public function cancel() {
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

        $level = get('level', '');
        if(empty($level)){
            $this->message('Invalid level.');
            return;
        }

        $deferred_pay = $this->DataDeferredPayments->find()->where(['user_id' => USER_ID, 'status' => 'PENDING', 'type' => $level])->first();

        if(empty($deferred_pay)){
            $this->message('Deferred payment not found.');
            return;
        }

        $deferred_pay->status = 'CANCELLED';
        $deferred_pay->modified = date('Y-m-d H:i:s');
        $deferred_pay->modified_by = USER_ID;
        $deferred_pay->deleted = 1;
        $this->DataDeferredPayments->save($deferred_pay);

        $this->success();
    }
    
    /**
     * Obtiene estadísticas de pagos diferidos
     * 
     * GET /api/v1/deferred-payment/stats
     */
    public function stats() {
        $this->request->allowMethod(['get']);
        
        try {
            // Pagos por status
            $statsByStatus = $this->DataDeferredPayments->find()
                ->select([
                    'status',
                    'count' => $this->DataDeferredPayments->query()->func()->count('*'),
                    'total_amount' => $this->DataDeferredPayments->query()->func()->sum('amount')
                ])
                ->where(['deleted' => 0])
                ->group(['status'])
                ->all();
            
            // Pagos de hoy
            $todayPayments = $this->DataDeferredPayments->find()
                ->select([
                    'count' => $this->DataDeferredPayments->query()->func()->count('*'),
                    'total_amount' => $this->DataDeferredPayments->query()->func()->sum('amount')
                ])
                ->where([
                    'scheduled_date' => date('Y-m-d'),
                    'deleted' => 0
                ])
                ->first();
            
            // Pagos pendientes próximos 7 días
            $upcomingPayments = $this->DataDeferredPayments->find()
                ->select([
                    'count' => $this->DataDeferredPayments->query()->func()->count('*'),
                    'total_amount' => $this->DataDeferredPayments->query()->func()->sum('amount')
                ])
                ->where([
                    'scheduled_date >=' => date('Y-m-d'),
                    'scheduled_date <=' => date('Y-m-d', strtotime('+7 days')),
                    'status' => 'PENDING',
                    'deleted' => 0
                ])
                ->first();
            
            return $this->response
                ->withType('application/json')
                ->withStringBody(json_encode([
                    'success' => true,
                    'data' => [
                        'by_status' => $statsByStatus->toArray(),
                        'today' => $todayPayments,
                        'upcoming_7_days' => $upcomingPayments
                    ]
                ]));
                
        } catch (\Exception $e) {
            return $this->response
                ->withType('application/json')
                ->withStatus(400)
                ->withStringBody(json_encode([
                    'success' => false,
                    'error' => $e->getMessage()
                ]));
        }
    }

    /**
     * Obtiene información para diferir pago
     * 
     * GET /api/v1/deferred-payment/get-info-payment
     */
    public function get_info_payment(){

        $this->loadModel('SpaLiveV1.DataPromoCodes');
        
        
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

        $level = get('level', '');
        if(empty($level)){
            $this->message('Invalid level.');
            return;
        }
        
        $levels = [
            'LEVEL 1',
            'LEVEL 2',
            'LEVEL 3 FILLERS',
            'LEVEL 3 MEDICAL', 
            'LEVEL 1-1 NEUROTOXINS',
        ];

        $title = '';
        $text = '';
        $partially = get('partially',0);
        $discount = 0;

        if(in_array($level, $levels)){
            
            $d_code = '';
            switch($level){
                case 'LEVEL 1':
                case 'BASIC COURSE':
                    $amount = $this->training_basic;
                    $type = 'BASIC COURSE';
                    $d_code = 'ELITE300B';
                    break;
                case 'LEVEL 2':
                case 'ADVANCED COURSE':
                    $amount = $this->training_advanced;
                    $type = 'ADVANCED COURSE';
                    // $type = 'LEVEL 2';
                    // $d_code = 'ELITE300A';
                    $d_code = '';
                    break;
                case 'LEVEL 3 FILLERS':
                case 'FILLERS COURSE':
                    $amount = $this->level_3_fillers;
                    $type = 'FILLERS COURSE';
                    $d_code = '';
                    break;
                case 'LEVEL 3 MEDICAL':
                case 'ADVANCED TECHNIQUES MEDICAL':
                    $amount = $this->level_3_medical;
                    $type = 'ADVANCED TECHNIQUES MEDICAL';
                    $d_code = 'ELITE300';
                    break;
                case 'LEVEL 1-1 NEUROTOXINS':
                    $amount = $this->level_1_to_1;
                    $type = 'LEVEL 1-1 NEUROTOXINS';
                    $d_code = '';
                    break;
            }

             if (!empty($d_code)) {
                $this->set('promo_code', $d_code);
                $ent_code = $this->DataPromoCodes->find()->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.code' => $d_code])->last();
                if (!empty($ent_code)) {
                    $discount = $this->validateDefaultPromoCode($ent_code,$amount);
                }
            }


            
        }else{
            $this->loadModel('SpaLiveV1.CatCoursesType');
            $course_type = $this->CatCoursesType->find()->where(['CatCoursesType.name_key' => $level])->first();
            if(!empty($course_type)){
                $amount = $course_type->price;
                $type = $course_type->name_key;
            }


            if ($course_type->discount_id != 0) {
                $ent_code = $this->DataPromoCodes->find()->where(['DataPromoCodes.deleted' => 0,'DataPromoCodes.active' => 1,'DataPromoCodes.id' => $course_type->discount_id])->first();
                if (!empty($ent_code)) {
                    $discount = $this->validateDefaultPromoCode($ent_code,$course_type->price);
                }
            }

        }

        $new_amount = intval($amount - $discount); 
        $stripe_fee = intval($new_amount * 0.0315); 
        $total = intval($new_amount + $stripe_fee); 
        
        $amount =  number_format($amount / 100, 2);
        $new_amount =  number_format($new_amount / 100, 2);
        $stripe_fee =  number_format($stripe_fee / 100, 2);
        $total = number_format($total / 100, 2);

        $_where = ['user_id' => USER_ID, 'status IN' => ['PENDING', 'FAILED'], 'type' => $type];
        $_where['source'] = 'stripe';
        if ($partially == 1 || $partially == '1') {
            $_where['source'] = 'partially';
        }
        $deferred = $this->DataDeferredPayments->find()->where($_where)->order(['created' => 'DESC'])->first();
        if (empty($deferred)) {
            $_where['type'] = $level;
            $deferred = $this->DataDeferredPayments->find()->where($_where)->order(['created' => 'DESC'])->first();
        }
        if(empty($deferred)){
            // $text = '
            //     <p>Spots are limited and often fill up quickly. Paying now secures your place immediately. If you continue with deferred payment, your course date won\'t be confirmed until your first payment is made.</p>
            // ';

            if ($partially == 1 || $partially == '1') {

                $text = <<<HTML
                    <p>
                        Spots are limited and often fill up quickly. Paying now secures your place immediately.
                        If you continue with deferred payment, your course date won't be confirmed until your
                        payment is made.
                    </p>

                    <div style="width:100%; margin-top:15px;">

                        <div style="margin: 8px 0;">
                            <span>Course:</span>
                            <span style="float:right;">\$${amount}</span>
                            <div style="clear:both;"></div>
                        </div>
                    </div>
                HTML;
                
            } else {
            

                $discountRow = "";
                if ($discount > 0) {
                    $discountRow = "
                        <div style='margin: 8px 0;'>
                            <span>Today's Discount:</span>
                            <span style='float:right;'>- \$" . number_format($discount / 100, 2) . "</span>
                            <div style='clear:both;'></div>
                        </div>
                    ";
                }

                $text = <<<HTML
                <p>
                    Spots are limited and often fill up quickly. Paying now secures your place immediately.
                    If you continue with deferred payment, your course date won't be confirmed until your
                    payment is made.
                </p>

                <div style="width:100%; margin-top:15px;">

                    <div style="margin: 8px 0;">
                        <span>Course:</span>
                        <span style="float:right;">\$${amount}</span>
                        <div style="clear:both;"></div>
                    </div>

                    $discountRow

                    <div style="margin: 8px 0;">
                        <span>Credit Card Processing Fee:</span>
                        <span style="float:right;">\$${stripe_fee}</span>
                        <div style="clear:both;"></div>
                    </div>

                    <div style="margin: 12px 0;">
                        <hr>
                    </div>

                    <div style="margin: 8px 0;">
                        <strong>Total:</strong>
                        <strong style="float:right;">\$${total}</strong>
                        <div style="clear:both;"></div>
                    </div>

                </div>
                HTML;
            }

            $scheduled = false;
        }else{
            
            $scheduled = false;
            
            // Check if the deferred payment has FAILED status and is from partially
            if ($deferred->status == 'FAILED' && ($partially == 1 || $partially == '1')) {
                $text = '
                    <p>Your payment has failed. We will automatically retry the payment in the next days.</p>
                    <br>
                    <br>
                    <p style="text-align: center;">If you want to cancel your deferred payment contact us at:</p>
                    <p style="text-align: center;">
                    <a href="mailto:support@myspalive.com" 
                        style="color: white; text-decoration: none;">
                        support@myspalive.com
                    </a>
                    </p>

                    <p style="text-align: center;">
                    <a href="tel:+14692770897" 
                        style="color: white; text-decoration: none;">
                        (469) 2770897
                    </a>
                    </p>
                ';
                
                // Set scheduled to true so the UI shows the message and doesn't allow creating a new one
                $scheduled = true;
            } else if ($partially == 0) {
                // Consultar Stripe para obtener los últimos 4 dígitos de la tarjeta
                $card_last4 = '';
                try {
                    \Stripe\Stripe::setApiKey(Configure::read('App.stripe_secret_key'));
                    $stripe = new \Stripe\StripeClient(Configure::read('App.stripe_secret_key'));
                    
                    $payment_method = $stripe->paymentMethods->retrieve(
                        $deferred->payment_method,
                        []
                    );

                    if (!empty($payment_method) && isset($payment_method->card->last4)) {
                        $card_last4 = $payment_method->card->last4;
                    } else {
                        // Si no se puede obtener, usar el ID como fallback
                        $card_last4 = $deferred->payment_method;
                    }

                     $text = '
                        <p>Booking this class date will be available on ' . $deferred->scheduled_date . ', after we charge your card ended in ' . $card_last4 . '.</p>

                        <p>Once we have received your payment, we will send you an email with the details, and you will be able to book your course date.</p>

                        <p>You can cancel this deferred payment by using the button below, which will take you back to the home page. From there, select the course you purchased under the deferred payment option, and you will be able to cancel it.</p>
                    ';
                } catch (\Exception $e) {
                    // En caso de error, usar el ID como fallback
                     $text = '
                        <p>Booking this class date will be available on ' . $deferred->scheduled_date . '.</p>

                        <p>Once we have received your payment, we will send you an email with the details, and you will be able to book your course date.</p>

                        <p>You can cancel this deferred payment by using the button below, which will take you back to the home page. From there, select the course you purchased under the deferred payment option, and you will be able to cancel it.</p>
                    ';

                }

                if ($deferred->source == 'stripe') {
                    $scheduled = true;
                }

            } else {
                
                if ($deferred->source == 'partially') {
                    $scheduled = true;
                }
                $text = '
                    <p>Booking this class date will be available on ' . $deferred->scheduled_date . '.</p>

                    <p>Once we have received your payment, we will send you an email with the details, and you will be able to book your course date.</p>
                    <br>
                    <br>
                    <p style="text-align: center;">If you want to cancel your deferred payment contact us at:</p>
                    <p style="text-align: center;">
                    <a href="mailto:support@myspalive.com" 
                        style="color: white; text-decoration: none;">
                        support@myspalive.com
                    </a>
                    </p>

                    <p style="text-align: center;">
                    <a href="tel:+14692770897" 
                        style="color: white; text-decoration: none;">
                        (469) 2770897
                    </a>
                    </p>


                ';
            }


        }


        $this->set('title', $title);
        $this->set('text', $text);
        $this->set('scheduled', $scheduled);
        $this->success();
    }
    
    /**
     * Procesa manualmente un deferred payment desde el panel
     * 
     * POST /api/?action=DeferredPayment____process_manual
     * 
     * Parámetros:
     * - token: Token de autenticación
     * - payment_id: ID del pago en data_payment
     */
    public function process_manual() {
        $token = get('token', '');
        if(empty($token)){
            $this->message('Invalid token.');
            return;
        }
        
        $payment_id = get('payment_id', 0);
        if ($payment_id == 0) {
            $this->message('Invalid payment ID.');
            return;
        }
        
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('SpaLiveV1.DataDeferredPayments');
        $this->loadModel('SpaLiveV1.DataDeferredPaymentsLog');
        $this->loadModel('SpaLiveV1.SysUsers');
        
        // Configurar Stripe
        $stripeSecretKey = Configure::read('App.stripe_secret_key');
        \Stripe\Stripe::setApiKey($stripeSecretKey);
        
        // Buscar el pago con su deferred payment y datos del usuario
        $payment = $this->DataPayment->find()
            ->select([
                'DataPayment.id',
                'DataPayment.uid',
                'DataPayment.deferred_payment_id',
                'DataPayment.total',
                'DataDeferredPayments.id',
                'DataDeferredPayments.uid',
                'DataDeferredPayments.user_id',
                'DataDeferredPayments.customer_id',
                'DataDeferredPayments.payment_method',
                'DataDeferredPayments.amount',
                'DataDeferredPayments.currency',
                'DataDeferredPayments.description',
                'DataDeferredPayments.type',
                'DataDeferredPayments.reference_id',
                'DataDeferredPayments.reference_type',
                'DataDeferredPayments.scheduled_date',
                'DataDeferredPayments.status',
                'User.id',
                'User.name',
                'User.lname',
                'User.email',
                'User.phone',
                'User.stripe_account',
                'User.stripe_account_confirm'
            ])
            ->join([
                'DataDeferredPayments' => [
                    'table' => 'data_deferred_payments',
                    'type' => 'INNER',
                    'conditions' => 'DataDeferredPayments.id = DataPayment.deferred_payment_id'
                ],
                'User' => [
                    'table' => 'sys_users',
                    'type' => 'INNER',
                    'conditions' => 'User.id = DataDeferredPayments.user_id'
                ]
            ])
            ->where([
                'DataPayment.id' => $payment_id,
                'DataDeferredPayments.status' => 'PENDING',
                'DataDeferredPayments.deleted' => 0
            ])
            ->first();
        
        if (empty($payment)) {
            $this->message('Deferred payment not found or already processed.');
            return;
        }
        
        // Marcar como procesando
        $this->DataDeferredPayments->updateAll(
            ['status' => 'PROCESSING'],
            ['id' => $payment['DataDeferredPayments']['id']]
        );
        
        // Ejecutar el pago (igual que en el comando)
        $result = $this->executePayment($payment['DataDeferredPayments'], $payment['User']);
        
        if ($result['success']) {
            // Actualizar el pago como completado
            $this->updatePaymentAsCompleted($payment['DataDeferredPayments']['id'], $result);
            
            // Actualizar data_payment
            $this->DataPayment->updateAll([
                'intent' => $result['payment_intent_id'],
                'payment' => $result['charge_id'],
                'receipt' => $result['receipt_url'],
                'total' => $result['amount'],
            ], [
                'deferred_payment_id' => $payment['DataDeferredPayments']['id']
            ]);
            
            // Enviar correo de confirmación
            $this->sendSuccessEmail($payment, $result);
            
            // Log del intento
            $this->logPaymentAttempt($payment['DataDeferredPayments']['id'], 1, $result);
            
            $this->set('message', 'Payment processed successfully.');
            $this->success();
        } else {
            // Para pagos manuales, no marcar como fallido, revertir a PENDING
            $this->DataDeferredPayments->updateAll([
                'status' => 'PENDING',
                'modified' => date('Y-m-d H:i:s')
            ], [
                'id' => $payment['DataDeferredPayments']['id']
            ]);
            
            // Log del intento (para registro)
            $this->logPaymentAttempt($payment['DataDeferredPayments']['id'], 1, $result);
            
            $this->message('Payment failed: ' . $result['error'] . '. The payment has been kept as PENDING so you can try again.');
        }
    }
    
    /**
     * Ejecuta un pago usando Stripe (igual que en el comando)
     */
    private function executePayment($deferredPayment, $user = null) {
        $result = [
            'success' => false,
            'payment_intent_id' => null,
            'charge_id' => null,
            'receipt_url' => null,
            'error' => null,
            'stripe_response' => null,
            'amount' => $deferredPayment['amount']
        ];
        
        try {
            $paymentIntentParams = [
                'amount' => $deferredPayment['amount'],
                'currency' => $deferredPayment['currency'],
                'customer' => $deferredPayment['customer_id'],
                'payment_method' => $deferredPayment['payment_method'],
                'off_session' => true,
                'confirm' => true,
                'description' => $deferredPayment['description'],
                'metadata' => [
                    'deferred_payment_id' => $deferredPayment['id'],
                    'deferred_payment_uid' => $deferredPayment['uid'],
                    'user_id' => $deferredPayment['user_id'],
                    'type' => $deferredPayment['type'],
                    'reference_id' => $deferredPayment['reference_id'],
                    'reference_type' => $deferredPayment['reference_type']
                ]
            ];
            
            $stripeAccount = null;
            if (!empty($user['stripe_account']) && !empty($user['stripe_account_confirm']) && $user['stripe_account_confirm'] == 1) {
                $stripeAccount = $user['stripe_account'];
            }
            
            $paymentIntent = null;
            $error = null;
            
            if ($stripeAccount) {
                // Intentar primero con la cuenta conectada
                try {
                    $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentParams, ['stripe_account' => $stripeAccount]);
                } catch (\Exception $e) {
                    // Si falla, intentar con la cuenta principal
                    try {
                        $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentParams);
                    } catch (\Exception $e2) {
                        $error = $e2;
                    }
                }
            } else {
                // Intentar con la cuenta principal
                try {
                    $paymentIntent = \Stripe\PaymentIntent::create($paymentIntentParams);
                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    // Si el error indica que está en una cuenta conectada, intentar buscar la cuenta del usuario
                    if (strpos($e->getMessage(), 'connected account') !== false || strpos($e->getMessage(), 'No such PaymentMethod') !== false) {
                        $error = $e;
                    } else {
                        $error = $e;
                    }
                } catch (\Exception $e) {
                    $error = $e;
                }
            }
            
            if ($error) {
                throw $error;
            }
            
            // Extraer información del resultado
            if (isset($paymentIntent->charges->data[0])) {
                $charge = $paymentIntent->charges->data[0];
                $result['charge_id'] = $charge->id;
                $result['receipt_url'] = $charge->receipt_url ?? null;
            }
            
            $result['success'] = true;
            $result['payment_intent_id'] = $paymentIntent->id;
            $result['stripe_response'] = json_encode($paymentIntent);
            
        } catch (\Stripe\Exception\CardException $e) {
            // Tarjeta declinada
            $result['error'] = $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Demasiadas requests
            $result['error'] = 'Rate limit: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Parámetros inválidos
            $result['error'] = 'Invalid request: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Error de autenticación
            $result['error'] = 'Authentication error: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Error de conexión
            $result['error'] = 'Connection error: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Error genérico de Stripe
            $result['error'] = 'Stripe error: ' . $e->getMessage();
            $result['stripe_response'] = json_encode($e->getJsonBody());
            
        } catch (\Exception $e) {
            // Cualquier otro error
            $result['error'] = 'Unexpected error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Actualiza un deferred payment como completado
     */
    private function updatePaymentAsCompleted($deferred_payment_id, $result) {
        $this->DataDeferredPayments->updateAll([
            'status' => 'COMPLETED',
            'executed_date' => date('Y-m-d H:i:s'),
            'payment_intent_id' => $result['payment_intent_id'],
            'charge_id' => $result['charge_id'],
            'receipt_url' => $result['receipt_url'],
            'error_message' => null,
            'modified' => date('Y-m-d H:i:s')
        ], [
            'id' => $deferred_payment_id
        ]);
    }
    
    /**
     * Registra un intento de pago en el log
     */
    private function logPaymentAttempt($deferred_payment_id, $attempt_number, $result) {
        $this->loadModel('SpaLiveV1.DataDeferredPaymentsLog');
        $entity = $this->DataDeferredPaymentsLog->newEntity([
            'deferred_payment_id' => $deferred_payment_id,
            'attempt_number' => $attempt_number,
            'attempt_date' => date('Y-m-d H:i:s'),
            'status' => $result['success'] ? 'SUCCESS' : 'FAILED',
            'error_message' => $result['error'],
            'stripe_response' => $result['stripe_response'],
            'created' => date('Y-m-d H:i:s')
        ]);
        
        if (!$entity->hasErrors()) {
            $this->DataDeferredPaymentsLog->save($entity);
        }
    }
    
    /**
     * Envía correo de confirmación de pago exitoso
     */
    private function sendSuccessEmail($payment, $result) {
        $amount_formatted = number_format($payment['DataDeferredPayments']['amount'] / 100, 2);
        $payment_date = date('F d, Y');
        $course_type = $payment['DataDeferredPayments']['description'] ? $payment['DataDeferredPayments']['description'] : $payment['DataDeferredPayments']['type'];
        $user_name = $payment['User']['name'] . ' ' . $payment['User']['lname'];
        $account_url = 'https://app.myspalive.com/';
        
        $html = "
        <!doctype html>
        <html>
        <head>
            <meta name=\"viewport\" content=\"width=device-width\">
            <meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\">
            <title>Payment Confirmation - MySpaLive</title>
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
                                
                                <p>Your scheduled payment for <strong>{$course_type}</strong> has been successfully processed on <strong>{$payment_date}</strong>.</p>
                                
                                <p>You can now reserve your spot for the course. Access your account <a href=\"{$account_url}\">from here</a> and secure your preferred date.</p>
                                
                                <p><strong>Course:</strong> {$course_type}</p>
                                
                                <p><strong>Amount Charged:</strong> \${$amount_formatted}</p>
                                
                                <p style=\"margin-top: 30px;\">Best regards,<br>MySpaLive</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </body>
        </html>
        ";
        
        $this->sendEmail(
            $payment['User']['email'],
            "You're all set! Payment for your course is confirmed",
            $html,
            $payment['DataDeferredPayments']['id']
        );
    }
    
    /**
     * Envía un correo usando Mailgun
     */
    private function sendEmail($to_email, $subject, $html_body, $deferred_payment_id) {
        try {
            $data = [
                'from' => 'MySpaLive <noreply@mg.myspalive.com>',
                'to' => $to_email,
                'subject' => $subject,
                'html' => $html_body,
            ];

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
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            
            if ($http_code == 200) {
                // Marcar email como enviado
                $this->DataDeferredPayments->updateAll(
                    ['email_sent' => 1],
                    ['id' => $deferred_payment_id]
                );
            }
            
        } catch (\Exception $e) {
            // Error silencioso para no interrumpir el flujo
        }
    }

    public function get_options(){
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

        $source = get('source', 0);
        if($source == 0 || empty($source)){
            $this->message('Invalid source.');
            $this->set('session', false);
            return;
        }

        if($source == 1){
            $this->set('pay_now', 'Pay now to lock in your $300 promotion and secure your course date immediately');
            $this->set('pay_later1', 'Schedule your payment for a later date to lock in today’s $300 off promotion.
            ');
            $this->set('pay_later2', 'Please note that course seats can fill up quickly, so your preferred date may not be available by the time your payment processes.');
        }else if($source == 2){
            $this->set('pay_now', 'Pay now to activate your 0% interest plan and secure your course date immediately.');
            $this->set('pay_later1', 'Schedule your payment for a later date to lock in your 0% financing.
            ');
            $this->set('pay_later2', 'Please note that course seats can fill up quickly, so your preferred date may not be available by the time your first payment processes.');
        }
        $this->success();
    }
}
