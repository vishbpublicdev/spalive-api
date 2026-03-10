<?php
declare(strict_types=1);

namespace SpaLiveV1\Controller;
use App\Controller\AppPluginController;
require_once(ROOT . DS . 'vendor' . DS  . 'guzzlehttp' . DS . 'init.php');
use GuzzleHttp\Client;
use Cake\Utility\Text;

use SpaLiveV1\Controller\MainController;
use SpaLiveV1\Controller\GhlController;

class SezzleController extends AppPluginController {

  private $paymente_gfe = 1800;
  private $training_advanced = 89500;
  private $register_total = 79500;
  private $register_refund = 3500;
  private $shipping_cost = 1000;
  private $shipping_cost_both = 4000;
  private $shipping_cost_inj = 3000;
  private $shipping_cost_mat = 1000;
  private $shipping_cost_misc = 1000;
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
        $this->loadModel('SpaLiveV1.DataPurchases');
        $this->loadModel('SpaLiveV1.DataPurchasesDetail');
        $this->loadModel('SpaLiveV1.DataPayment');
        $this->loadModel('CatProducts');
        $this->loadModel('SpaLiveV1.DataWebhook');
        $this->loadModel('SpaLiveV1.DataAssignedToRegister');
        $this->loadModel('SpaLiveV1.DataNetworkInvitations');
        $this->loadModel('SpaLiveV1.SysUsers');
        $this->loadModel('SpaLiveV1.CatStates');
        $this->loadModel('SpaLiveV1.CatCourses');

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

    /**************************CREACIÓN DE ORDENES SEZZLE ********************************/
    public function create_orden_in_registration(){
      //Pago desde registro
      // Payments____payment_intent_gfe

      /* ******************** obtener datos y validar ****************/
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

      $description = get('type_course','');
      if(empty($description)){
          $this->message('Type course empty.');
          return;
      }

      $promo = get('promo_code','');

      /*************************************** */

      /*$ent_payment = $this->DataPayment->find()
      ->where(['DataPayment.id_from' => USER_ID, 'DataPayment.id_to' => 0,'DataPayment.type' => 'GFE', 'DataPayment.service_uid' => '','DataPayment.payment <>' => '','DataPayment.prepaid' => 1, 'DataPayment.comission_payed' => 1])->first();

      if (!empty($ent_payment)) {
          $this->message('You already have a credit, it is not necessary to pay.');
          return;
      }*/

      $prepaid = 1;
      $consultation_id = 0;
      $total_amount = 0;
      $total_order = 0;

      //$Payments = new PaymentsController();

      $purchase_type = "register";

      if($description == 'NEUROTOXINS BASIC'){
        //$total_amount = $Payments->validateCode($promo,$this->register_total,'REGISTER');
        $total_order = $this->register_total;
      }else if($description == 'NEUROTOXINS ADVANCED'){
        $purchase_type = "purchase_register";
        //$total_amount = $Payments->validateCode($promo,$this->training_advanced,'TRAINING');
        $total_order = $this->training_advanced;
      }

      if ($total_order < 100) $total_order = 100;

      $this->set('total_order', $total_order);

      //obtener sesion de sezzle
      $res = $this->get_sezzle_session();

      if($res->token){

        $ent_purchase = array(
          'uid'             => "registration_".Text::uuid(),
          'address'         => $user["street"],
          'city'            => $user["city"],
          'zip'             => $user["zip"],
          'shipping_cost'   => 0,
          'state'           => $user["user_state"],
        );

        $ent_purchase = (object) $ent_purchase;

        $purchases =  (object) array(
          'name'     => $description,
          'sku'      => '',
          'quantity' => 1,
          'price'    => $total_order,
        );

        $_ent_purchases = array();

        array_push($_ent_purchases,$purchases);

        $_ent_purchases = $_ent_purchases;

        $sezzle_checkout_url = $this->get_sezzle_orden($res->token,$user,$ent_purchase,$description,$_ent_purchases,$promo,$total_order,$total_amount,$purchase_type);

        $this->set('checkout_url', $sezzle_checkout_url);
        $this->success();
      }else{
        $this->message('Error in get Sezzle authentication.');
        return;
      }

    }

    public function create_order() {
      //pago desde shop
        $token = get('token','');
        $sezzle_url = env('SEZZLE_URL', '');

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

        $purchase_uid = get('uid','');
        if(empty($purchase_uid)){
            $this->message('Purchase uid empty.');
            return;
        }

        $fields_pur = ['DataPurchases.id','DataPurchases.address','DataPurchases.city','DataPurchases.zip','DataPurchases.shipping_cost',
                       'DataPurchases.amount','DataPurchases.uid','State.name'
                    ];

        $join_pur = [
            'State' => ['table' => 'cat_states','type' => 'INNER','conditions' => 'State.id = DataPurchases.state'],
        ];

        $ent_purchase = $this->DataPurchases->find()->select($fields_pur)->where(['DataPurchases.uid' => $purchase_uid])->join($join_pur)->first();
        if(empty($ent_purchase)){
            $this->message('Purchase not found');
            return;
        }

        $promo = get('promo_code','');

        $res = $this->get_sezzle_session();

        if($res->token){

          $fields = ['DataPurchasesDetail.id','DataPurchasesDetail.product_id','DataPurchasesDetail.price','DataPurchasesDetail.qty',
                      'Product.category','Product.name','Product.sku'
                    ];

          $join = [
              'Product' => ['table' => 'cat_products','type' => 'INNER','conditions' => 'Product.id = DataPurchasesDetail.product_id'],
          ];

          $_ent_purchases =$this->DataPurchasesDetail->find()->select($fields)->where(['DataPurchasesDetail.purchase_id' => $ent_purchase->id])->join($join)->toArray();

          $flag_is_training = false;

          if (!empty($_ent_purchases)) {
            if (count($_ent_purchases) == 1) {
                if ($_ent_purchases[0]->product_id == 44){
                  $flag_is_training = true;
                }
            }
          }

          $total_amount = 0;
          $description = "";
          $total_order = 0;

          //$Payments = new PaymentsController();

          if($flag_is_training){
            //$total_amount = $Payments->validateCode($promo,$this->training_advanced,'TRAINING');
            $description = 'ADVANCED COURSE';
            $total_order = $this->training_advanced;
          }else{
            //$total_amount = $Payments->validateCode($promo,($ent_purchase->amount + $ent_purchase->shipping_cost),'PURCHASE');
            $description = 'PURCHASE';
            $total_order = ($ent_purchase->amount + $ent_purchase->shipping_cost);
          }

          if ($total_order < 100) $total_order = 100;

          $ent_purchase["state"] = $ent_purchase["State"]["name"];

          foreach($_ent_purchases as $e_p) {
            $e_p["name"] = $e_p["Product"]["name"];
            $e_p["sku"] = $e_p["Product"]["sku"];
          }

          $sezzle_checkout_url = $this->get_sezzle_orden($res->token,$user,$ent_purchase,$description,$_ent_purchases,$promo,$total_order,$total_amount,"purchase");

          $this->set('checkout_url', $sezzle_checkout_url);
          $this->success();
          
        }else{
          $this->message('Error in get Sezzle authentication.');
          return;
        }
    }

    /************************** WEBHOOK RESPONSE ********************************/

    public function webhook_success(){

      return;

      
      $input = file_get_contents('php://input');
      $data = json_decode($input, true);

      $uuid = $data["data"]["uuid"];

      $this->set('uuid', $uuid);

      if(empty($uuid)){
          $this->message('Uuid empty.');
          return;
      }

      $res = $this->get_sezzle_session();

      if($res->token){
        $sezzle_url = env('SEZZLE_URL', '');
        $Main = new MainController();
        $Ghl = new GhlController();

        //Obtener la orden
        $client = new \GuzzleHttp\Client();

        if($uuid==""){
          $this->message('Order uuid empty.');
          return;
        }

        $response_order = $client->request('GET', $sezzle_url.'order/'.$uuid, [
          'headers' => [
            'Authorization' => 'Bearer '.$res->token,
            'accept' => 'application/json',
          ],
        ]);

        $res_order = json_decode($response_order->getBody()->getContents());

        if($res_order->uuid){
          //crear payment, crear data webhook y actualizar purchase
	
          $this->DataWebhook->new_entity([
            'uid'		    => $this->DataWebhook->new_uid(),
            'event'		  => $res_order->metadata->event,
            'model'		  => $res_order->metadata->model,
            'model_uid'	=> $res_order->reference_id,
            'payload'	  => json_encode($res_order),
          ]);

          switch ($res_order->metadata->model) {

            case 'purchase':
              
              $ent_purchase = $this->DataPurchases->find()
                ->where([
                  'DataPurchases.uid' => $res_order->reference_id,
                  'DataPurchases.payment' => '',
                  'DataPurchases.receipt_url' => '',
                ])->last();

              if (!empty($ent_purchase)) {
                $arr_purchase = array(
                  'id'				      => $ent_purchase->id,
                  'payment'		      => $res_order->uuid,
                  'payment_intent'  => "Sezzle_".$res_order->uuid,
                  'receipt_url'	    => $res_order->authorization->sezzle_order_id,
                );

                $payment = $this->DataPurchases->newEntity($arr_purchase);
                if($this->DataPurchases->save($payment)){

                  $html_content = 'New purchase, Order #' . $ent_purchase->id . '<br><br>';
                                  // You have received a new purchase';

                  $ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','DataPurchases.shipping_cost','User.id','User.name','User.lname','User.bname','User.type','User.email'])
                  ->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
                  ])->where(['DataPurchases.uid' => $res_order->reference_id])->order(['DataPurchases.id' => 'DESC'])->last();

                  if(!empty($ent_purchases)){

                    $flag_payment = true;

                    $array_payment_save = array(
                      'id_from'             => $res_order->metadata->user_id,
                      'uid'                 => $res_order->reference_id,
                      'intent'              => 'Sezzle_'.$res_order->uuid,
                      'type'                => $res_order->description,
                      'payment'             => $res_order->uuid,
                      'receipt'             => $res_order->authorization->sezzle_order_id,
                      'promo_discount'      => 0,
                      'promo_code'          => "",
                      'subtotal'            => $res_order->order_amount->amount_in_cents,
                      'total'               => $res_order->order_amount->amount_in_cents,
                      'prod'                => 1,
                      'prepaid'             => 0,
                      'is_visible'          => 1,
                      'comission_generated' => 0,
                      'comission_payed'     => 1,
                      'created'             => date('Y-m-d H:i:s'),
                      'createdby'           => $res_order->metadata->user_id,
                      'payment_option'      => 0,
                      'payment_platform'    => 'sezzle',
                    );

                    $this->set('array_payment_save', $array_payment_save);

                    $s_payment = $this->DataPayment->newEntity($array_payment_save);
                    if(!$s_payment->hasErrors()) {
                        if (!$this->DataPayment->save($s_payment)) {
                          $flag_payment = false;
                        }
                    }

                    if($flag_payment){
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

                        //$html_content .= '</table><br><br><b>Shipping cost: $' . number_format($ent_purchases['shipping_cost'] / 100,2) . '</b>';
                        $html_content .= '</table><br><br><b>Total: $' . number_format(($grand_total + $ent_purchases['shipping_cost']) / 100,2) . '</b>';

                        /*if (!empty($receipt_url)) {
                          $html_content .= '<br><br><a href="' . $receipt_url . '"">Download receipt</a>';
                        }*/

                        $Main->notify_devices('NEW_PURCHASE',array($ent_purchases['User']['id']),false,true,true,array(),$html_content);
                        
                        $this->set('Email', $ent_purchases['User']['email']);
                        $this->set('Payment', $res_order->authorization->sezzle_order_id);
                        $this->set('PurchaseId', $res_order->reference_id);
                        
                        $is_dev = env('IS_DEV', false);
                        if($is_dev === false){
                            $Main->send_new_email($html_content,'info@spalivemd.com');
                        }
                        else {
                            $Main->send_new_email($html_content,'khanzab@gmail.com');
                        }
                    }//flag payment

                  }
                  
                }else{
                  $this->message('Error in update purchase.');
                  return;
                }

                $this->success();

              }else{
                $this->set('FromPurchase', 'empty purchase');
              }

              break;
            
              case 'purchase_register':

                $flag_payment = true;

                $pay_type = "";

                if($res_order->description=="NEUROTOXINS BASIC"){
                  $pay_type = "BASIC COURSE";
                }else if ($res_order->description=="NEUROTOXINS ADVANCED"){
                  $pay_type = "ADVANCED COURSE";
                }
              
                $array_payment_save = array(
                  'id_from'             => $res_order->metadata->user_id,
                  'uid'                 => $res_order->reference_id,
                  'intent'              => 'Sezzle_'.$res_order->uuid,
                  'type'                => $pay_type,
                  'payment'             => $res_order->uuid,
                  'receipt'             => $res_order->authorization->sezzle_order_id,
                  'promo_discount'      => 0,
                  'promo_code'          => "",
                  'subtotal'            => $res_order->order_amount->amount_in_cents,
                  'total'               => $res_order->order_amount->amount_in_cents,
                  'prod'                => 1,
                  'prepaid'             => 0,
                  'is_visible'          => 1,
                  'comission_generated' => 0,
                  'comission_payed'     => 1,
                  'created'             => date('Y-m-d H:i:s'),
                  'createdby'           => $res_order->metadata->user_id,
                  'payment_option'      => 0,
                  'payment_platform'    => 'sezzle',
                );

                $this->set('array_payment_save', $array_payment_save);

                $s_payment = $this->DataPayment->newEntity($array_payment_save);
                if(!$s_payment->hasErrors()) {
                    if (!$this->DataPayment->save($s_payment)) {
                      $flag_payment = false;
                    }
                }

                if($flag_payment){
                  
                  $pay = $this->DataPayment->find()->select(['User.id','User.uid','User.name','User.lname','User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
                  ])->where(['DataPayment.id' => $s_payment->id, 'DataPayment.id_to' => "0"])->first();
      
                  $Main = new MainController();
                  $Webhook = new WebhookController();
                  
                  $Main->send_receipt('CI_REGISTRATION_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid);
  
                  $Main->notify_devices('CI_REGISTRATION_PAYMENT',array($pay->id_from),true,false);
  
                  $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id'])->join([
                    'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                  ])->where(['DataAssignedToRegister.user_id' => $pay['id_from'],'DataAssignedToRegister.deleted' => 0])->first();
  
                  if (!empty($assignedRep)) {
                    $Webhook->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $pay['User']['name'] . ' ' . $pay['User']['lname'] . ', ' . $this->formatPhoneNumber($pay['User']['phone']) . ', has completed the basic training purchase for $' . $pay->total / 100, $Main);
                  }
                  
                  $this->success();

                }else{
                  $this->message('Error in create payment.');
                  return;
                }

                break;
              
            case 'register':

              $flag_payment = true;

              $pay_type = "";

              if($res_order->description=="NEUROTOXINS BASIC"){
                $pay_type = "BASIC COURSE";
              }else if ($res_order->description=="NEUROTOXINS ADVANCED"){
                $pay_type = "ADVANCED COURSE";
              }
              
              $array_payment_save = array(
                'id_from'             => $res_order->metadata->user_id,
                'uid'                 => $res_order->reference_id,
                'intent'              => 'Sezzle_'.$res_order->uuid,
                'type'                => $pay_type,
                'payment'             => $res_order->uuid,
                'receipt'             => $res_order->authorization->sezzle_order_id,
                'promo_discount'      => 0,
                'promo_code'          => "",
                'subtotal'            => $res_order->order_amount->amount_in_cents,
                'total'               => $res_order->order_amount->amount_in_cents,
                'prod'                => 1,
                'prepaid'             => 0,
                'is_visible'          => 1,
                'comission_generated' => 0,
                'comission_payed'     => 1,
                'created'             => date('Y-m-d H:i:s'),
                'createdby'           => $res_order->metadata->user_id,
                'payment_option'      => 0,
                'payment_platform'    => 'sezzle',
              );

              $this->set('array_payment_save', $array_payment_save);

              $s_payment = $this->DataPayment->newEntity($array_payment_save);
              if(!$s_payment->hasErrors()) {
                  if (!$this->DataPayment->save($s_payment)) {
                    $flag_payment = false;
                  }
              }

              if($flag_payment){

                $pay = $this->DataPayment->find()->select(['User.id','User.uid','User.name','User.lname','User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
                  'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
                ])->where(['DataPayment.id' => $s_payment->id, 'DataPayment.id_to' => "0"])->first();
    
                $Main = new MainController();
                $Webhook = new WebhookController();
                $Ghl = new GhlController();
                
                $Main->send_receipt('CI_REGISTRATION_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid);

                $Main->notify_devices('CI_REGISTRATION_PAYMENT',array($pay->id_from),true,false);

                $assignedRep = $this->DataAssignedToRegister->find()->select(['User.id'])->join([
                  'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                ])->where(['DataAssignedToRegister.user_id' => $pay['id_from'],'DataAssignedToRegister.deleted' => 0])->first();

                if (!empty($assignedRep)) {
                  $Webhook->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $pay['User']['name'] . ' ' . $pay['User']['lname'] . ', ' . $this->formatPhoneNumber($pay['User']['phone']) . ', has completed the basic training purchase for $' . $pay->total / 100, $Main);
                }

                $ent_user = $this->SysUsers->find()
                ->where([
                  'SysUsers.id' => $res_order->metadata->user_id,
                  'SysUsers.payment' => '',
                  'SysUsers.receipt_url' => '',
                ])->first();

                if (!empty($ent_user->receipt_url) && !empty($ent_user->payment)) {
                  break;
                }

                if (!empty($ent_user)) {
                  $arr_user = array(
                    'id'			=> $ent_user->id,
                    'payment'		=> $res_order->uuid,
                    'receipt_url'	=> $res_order->authorization->sezzle_order_id,
                    'login_status' 	=> ($ent_user->type == 'injector' ? 'READY' : $ent_user->login_status),
                    'steps' => 'SELECTBASICCOURSE',
                  );

                  $this->set('arr_user', $arr_user);

                  $payment = $this->SysUsers->newEntity($arr_user);
						      $this->SysUsers->save($payment);

                  $updateRecord = $Webhook->updatePaymentRegister($res_order->metadata->user_id, $res_order->uuid, $res_order->authorization->sezzle_order_id);

                  $Main->send_receipt('CI_REGISTRATION_PAYMENT', $ent_user->email, $pay->id, $pay->uid);

                  $array_data = array(
                    'email' => $ent_user->email,
                    'name' => $ent_user->name,
                    'lname' => $ent_user->lname,
                    'phone' => $ent_user->phone,
                    'costo' => $pay->total / 100,
                    'course' => $pay_type == "BASIC COURSE" ? 'Basic' : 'Advanced',
                  );

                  if(!env('IS_DEV', false)){
                    $Ghl->updateOpportunity($array_data);
                  }

                  $existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower($ent_user->email)])->first();

                  if(!empty($existUser)){
                    $this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');

                    $array_save = array(
                      'uid' => Text::uuid(),
                      'payment_id' => $pay->id,
                      'amount' => 10000,
                      'user_id' => $existUser->parent_id,
                      'payment_uid' => '',
                      'description' => '',
                      'payload' => '',
                      'deleted' => 1,
                      'created' => date('Y-m-d H:i:s'),
                      'createdby' => defined('USER_ID') ? USER_ID : 0,
                    );
            
                    $c_entity = $this->DataSalesRepresentativePayments->newEntity($array_save);
                    $this->DataSalesRepresentativePayments->save($c_entity);
                    
                  }

                  if ($ent_user->type == "injector") {
                     /* --- COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED START --*/
                   /* $background_check = false;
                    $is_dev = env('IS_DEV', false);
                    if ($is_dev == false) {
                        if (empty($ent_user->tracers)) {
                            $background_check = $Main->check_tracers($ent_user);
                            if ($background_check) {
                                $Main->auto_approve($ent_user->id);
                            }
                        }        
                    } else {
                        $Main->auto_approve($ent_user->id);                            
                    } */
                     /* COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED END --- */

                     // Always auto-approve injectors after payment (background check bypassed)
                    $Main->auto_approve($ent_user->id);

                  }

                  $this->success($updateRecord);

                }

                $this->set('total', $res_order->order_amount->amount_in_cents);
                $this->success();
              }else{
                $this->message('Error in create payment.');
                return;
              } 

              break;

            default:
              # code...
              break;

          }

        }else{
          $this->DataWebhook->new_entity([
            'uid'	=> $this->DataWebhook->new_uid(),
            'event'	=> isset($res_order->metadata->event) ? $res_order->metadata->event : '',
            'payload'	=> json_encode($res_order),
          ]);

          $this->message('Error in get Sezzle order.');
          return;
        }
        
      }else{
        $this->message('Error in get Sezzle authentication .');
        return;
      }

      $this->success();

    }

    public function check_purchase_status(){
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

      $purchase_uid = get('purchase_uid','');
      if(empty($purchase_uid)){
          $this->message('Purchase uid empty.');
          return;
      }

      $ent_purchase = $this->DataPurchases->find()->where(['DataPurchases.uid' => $purchase_uid])->first();
      if(empty($ent_purchase)){
          $this->message('Purchase not found');
          return;
      }

      if($ent_purchase->payment!=""&&$ent_purchase->receipt_url!=""){
        $this->set('status', "Payment made");
      }else{
        $this->set('status', "Payment not made");
      }
      
      $this->success();
    }

    public function check_payment_status(){
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

      $type_course = get('type_course','');
      if(empty($type_course)){
          $this->message('Type course empty.');
          return;
      }

      $pay_type = "";

      if($type_course=="NEUROTOXINS BASIC"){
        $pay_type = "BASIC COURSE";
      }else if ($type_course=="NEUROTOXINS ADVANCED"){
        $pay_type = "ADVANCED COURSE";
      }

      $ent_payment = $this->DataPayment->find()->where(['DataPayment.type' => $pay_type, 'DataPayment.id_from' => $user["user_id"], 
                                                        'DataPayment.payment_platform' => 'sezzle'])->first();

      $this->set('ent_payment', $ent_payment);

      if(empty($ent_payment)){
        $this->set('status', "Payment not made");
      }else{
        if($ent_payment->payment!=""&&$ent_payment->receipt!=""){
          $this->set('status', "Payment made");
        }else{
          $this->set('status', "Payment not made");
        }
      }
        
        $this->success();
    }

    private function get_sezzle_session(){
      $public_key = env('SEZZLE_PUBLIC_KEY', '');
      $private_key = env('SEZZLE_PRIVATE_KEY', '');
      $sezzle_url = env('SEZZLE_URL', '');

      $client = new \GuzzleHttp\Client();

      //Obtener token de Sezzle 
      $response = $client->request('POST', $sezzle_url.'authentication', [
          'body' => '{"private_key":"'.$private_key.'","public_key":"'.$public_key.'"}',
          'headers' => [
              'accept' => 'application/json',
              'content-type' => 'application/json',
          ],
      ]);

      return json_decode($response->getBody()->getContents());
    }

    private function get_sezzle_orden($sezzle_token,$user,$ent_purchase,$description,$_ent_purchases,$promo,$total_order,$total_amount,$type){
      $sezzle_url = env('SEZZLE_URL', '');

      $success_url = "https://blog.myspalive.com/payment/success.html";
      $fail_url = "https://blog.myspalive.com/payment/fail.html";

      $is_dev = env('IS_DEV', false);

      if($is_dev === true){
        $success_url = "https://blog.myspalive.com/payment/success_dev.html";
        $fail_url = "https://blog.myspalive.com/payment/fail_dev.html";
      }

      $this->set('success_url', $success_url);
      $this->set('fail_url', $fail_url);

      $order_body = '{
        "cancel_url": {
          "href": "'.$fail_url.'",
          "method": "GET"
        },
        "complete_url": {
          "href": "'.$success_url.'",
          "method": "GET"
        },
        "customer": {
          "email": "'.$user["email"].'",
          "first_name": "'.$user["name"].'",
          "last_name": "'.$user["lname"].'",
          "phone": "'.$user["phone"].'",
          "dob": "'.$user["dob"].'",
          "billing_address": {
            "name": "'.$user["name"].'",
            "street": "'.$user["street"].'",
            "city": "'.$user["city"].'",
            "state": "'.$user["user_state"].'",
            "postal_code": "'.$user["zip"].'",
            "country_code": "US",
            "phone_number": "'.$user["phone"].'"
          },
          "shipping_address": {
            "name": "'.$user["name"].'",
            "street": "'.$ent_purchase->address.'",
            "city": "'.$ent_purchase->city.'",
            "state": "'.$ent_purchase->state.'",
            "postal_code": "'.$ent_purchase->zip.'",
            "country_code": "US",
            "phone_number": "'.$user["phone"].'"
          }
        },
        "order": {
          "intent": "CAPTURE",
          "reference_id": "'.$ent_purchase->uid.'",
          "description": "'.$description.'",
          "items": [';

          for ($i=0; $i < count($_ent_purchases) ; $i++) { 
            $order_body .= '{
                              "name": "'.$_ent_purchases[$i]->name.'",
                              "sku": "'.$_ent_purchases[$i]->sku.'",
                              "quantity": '.intval($_ent_purchases[$i]->quantity).',
                              "price": {
                                "amount_in_cents": '.intval($_ent_purchases[$i]->price).',
                                "currency": "USD"
                              }
                            }';
            if($i+1<count($_ent_purchases)){
              $order_body .= ',';
            }
          }
        
      $order_body .= '],';
      
      if($promo!=""){
        $order_body .= '"discounts": [
            {
              "name": "'.$promo.'",
              "amount": {
                "amount_in_cents": '.intval($total_order - $total_amount).',
                "currency": "USD"
              }
            }
          ],';
      }

      $order_body .= '"metadata": {
            "model": "'.$type.'",
            "event": "order.captured",
            "promo_code": "'.$promo.'",
            "promo_discount": "'.intval($total_order - $total_amount).'",
            "user_id": "'.$user["user_id"].'"
          },"shipping_amount": {
            "amount_in_cents": '.intval($ent_purchase->shipping_cost).',
            "currency": "USD"
          },
          "tax_amount": {
            "amount_in_cents": 0,
            "currency": "USD"
          },
          "order_amount": {
            "amount_in_cents": '.intval($total_order).',
            "currency": "USD"
          }
        }
      }';

      //********************** send and generate order **********************
      $client = new \GuzzleHttp\Client();
      
      $response_order = $client->request('POST', $sezzle_url.'session', [
          'body' => $order_body,
          'headers' => [
              'Authorization' => 'Bearer '.$sezzle_token,
              'accept' => 'application/json',
              'content-type' => 'application/json',
          ],
      ]);

      $res_order = json_decode($response_order->getBody()->getContents());

      $this->set('links', $res_order->order->links);

      return $res_order->order->checkout_url;
      //return $order_body;
    }

}

?>