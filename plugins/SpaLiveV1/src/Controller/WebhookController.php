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
// require_once(ROOT . DS . 'vendor' . DS  . 'Html2pdf' . DS . 'html2pdf.class.php');
// use HTML2PDF;

use Spipu\Html2Pdf\Html2Pdf;
use Spipu\Html2Pdf\Exception\Html2PdfException;
use Spipu\Html2Pdf\Exception\ExceptionFormatter;

use SpaLiveV1\Controller\MainController;


class WebhookController extends AppPluginController {

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
        $this->loadModel('SpaLiveV1.DataPurchases');
		$this->loadModel('SpaLiveV1.DataPayment');
		$this->loadModel('SpaLiveV1.DataTreatment');
		$this->loadModel('SpaLiveV1.SysUsers');
    }

	public function payment_succeed() {
		// $methods = get_class_methods('SpaLiveV1\Controller\MainController');
		$Main = new MainController();
		$Ghl = new GhlController();
		// $Main->notify_test();

		$input = file_get_contents('php://input');
		$data = json_decode($input, true);

		$type = $data['type'];
		$data = json_decode(json_encode($data['data']));

		$ppi = count($data->object->charges->data) > 0 && isset($data->object->charges->data[0]->payment_intent) ? $data->object->charges->data[0]->payment_intent : false;
		$chr = count($data->object->charges->data) > 0 && isset($data->object->charges->data[0]->id) ? $data->object->charges->data[0]->id : false;
		$html_content = '<div>
		<br>
			We have received a payment that is not registered in our system. Please review stripe to register the payment.<br><br>
			<br>
			<b>payment_intent: </b>' . $ppi . '<br>
			<b>charge_id: </b>' . $chr . '<br>
			<b>amount: </b>$' . $data->object->charges->data[0]->amount / 100 . '<br>
		</div>';

		if ($type == 'payment_intent.succeeded' /*|| $type == 'checkout.session.completed'*/) {
			$metadata = $data->object->metadata;
			if(empty($metadata->type)){
				$this->set('message', "Subscription payment");
				$this->success(true);


				$subPayment =  $this->DataSubscriptionPayments->find()->where(['DataSubscriptionPayments.charge_id' => $chr])->first();
				if (empty($subPayment)) {
					$array_save = array(
						'id_from' => 0,
						'id_to' => 0,
						'uid' => Text::uuid(),
						'type' => 'UNKNOWN', //'CI REGISTER','PURCHASE','GFE','TREATMENT','COMISSION','REFUND'
						'intent' => $ppi,
						'payment' => $chr,
						'receipt' => $data->object->charges->data[0]->receipt_url,
						'discount_credits' => 0,
						'promo_discount' => '',
						'promo_code' =>  '',
						'subtotal' => $data->object->charges->data[0]->amount,
						'total' => $data->object->charges->data[0]->amount,
						'prod' => 1,
						'is_visible' => 1,
						'comission_payed' => 1,
						'comission_generated' => 0,
						'prepaid' => 0,
						'created' => date('Y-m-d H:i:s'),
						'createdby' => 0,
						'payment_option' => 0,
						'payment_platform' => 'stripe',
						'state' => 43
					);
			
					$c_entity = $this->DataPayment->newEntity($array_save);
					if(!$c_entity->hasErrors()) {
						// $this->DataPayment->save($c_entity);
						// $Main->send_new_email($html_content,'luis@advantedigital.com,francisco@advantedigital.com,oscar.caldera@advantedigital.com', 'Unknown payment received');
					}
				}
				
				return;
			} else {
				if ($ppi) {
					$pay = $this->DataPayment->find()->where(['DataPayment.intent' => $ppi])->first();
		
					if (empty($pay)) {
						$array_save = array(
							'id_from' => 0,
							'id_to' => 0,
							'uid' => Text::uuid(),
							'type' => 'UNKNOWN', //'CI REGISTER','PURCHASE','GFE','TREATMENT','COMISSION','REFUND'
							'intent' => $ppi,
							'payment' => $chr,
							'receipt' => $data->object->charges->data[0]->receipt_url,
							'discount_credits' => 0,
							'promo_discount' => '',
							'promo_code' =>  '',
							'subtotal' => $data->object->charges->data[0]->amount,
							'total' => $data->object->charges->data[0]->amount,
							'prod' => 1,
							'is_visible' => 1,
							'comission_payed' => 1,
							'comission_generated' => 0,
							'prepaid' => 0,
							'created' => date('Y-m-d H:i:s'),
							'createdby' => 0,
							'payment_option' => 0,
							'payment_platform' => 'stripe',
							'state' => 43
						);
				
				
						$c_entity = $this->DataPayment->newEntity($array_save);
						if(!$c_entity->hasErrors()) {
							// $this->DataPayment->save($c_entity);
							// $Main->send_new_email($html_content,'luis@advantedigital.com,francisco@advantedigital.com,oscar.caldera@advantedigital.com', 'Unknown payment received');
						}
					}
				}
		
			}
			$this->loadModel('SpaLiveV1.DataWebhook');
	
			$this->DataWebhook->new_entity([
				'uid'		=> $this->DataWebhook->new_uid(),
				'event'		=> $type,
				'model'		=> $metadata->type,
				'model_uid'	=> $metadata->uid,
				// 'payload'	=> file_get_contents('php://input'),
				'payload'	=> $input,
				// 'metadata'	=> json_encode((array)$data->object->metadata),
			]);

			switch ($metadata->type) {
				case 'exam':
					$pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
						'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
					])->where(['DataPayment.uid' => $metadata->uid, 'DataPayment.type' => "GFE", 'DataPayment.id_to' => "0"])->first();


					/* $Main->notify_devices('GFE_EXAM_PAYMENT',array($pay->id_from),true,false);
					$Main->send_receipt('GFE_EXAM_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid); */

					$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url, true);
					$this->success($updateRecord);
					$this->set('FromExam', $updateRecord);
					break;

				case 'purchase':
					$ent_purchase = $this->DataPurchases->find()
						->where([
							'DataPurchases.uid' => $metadata->uid,
							'DataPurchases.payment' => '',
							'DataPurchases.receipt_url' => '',
						])->last();

					if (!empty($ent_purchase)) {
						$arr_purchase = array(
							'id'				=> $ent_purchase->id,
							'payment'			=> $data->object->charges->data[0]->payment_intent,
							'receipt_url'		=> $data->object->charges->data[0]->receipt_url,
						);
		
						$payment = $this->DataPurchases->newEntity($arr_purchase);
						$this->DataPurchases->save($payment);

						$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);
						$subject = 'New purchase, Order #' . $ent_purchase->id ;
						$html_content = 'New purchase, Order #' . $ent_purchase->id . '<br><br>';
                            // You have received a new purchase';

						$ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','DataPurchases.shipping_cost','User.id','User.name','User.lname','User.bname','User.type','User.email'])
						->join([
							'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
						])->where(['DataPurchases.uid' => $metadata->uid])->order(['DataPurchases.id' => 'DESC'])->last();

						$this->set('FromPurchaseUpdateRecord', $updateRecord);
						

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


							$Main->notify_devices('NEW_PURCHASE',array($ent_purchases['User']['id']),false,true,true,array(),$html_content);
							

							$this->set('Email', $ent_purchases['User']['email']);
							$this->set('PaymentIntent', $data->object->charges->data[0]->payment_intent);
							$this->set('PurchaseId', $metadata->uid);
							
							 $is_dev = env('IS_DEV', false);
                                if($is_dev === false){
                                    $Main->send_new_email($html_content,'info@spalivemd.com,ashlan@myspalive.com',$subject);
                                }
                                else {
                                    $Main->send_new_email($html_content,'francisco@advantedigital.com,francisco@advantedigital.com', $subject);
                                }
						}else{
							$this->set('FromPurchases', 'empty purchasess');
						}
						// $Main->payCIComissionsOnPurchase($metadata->uid);
						$this->success($updateRecord);
					}else{
						$this->set('FromPurchase', 'empty purchase');
					}

					break;
				case 'Training treatment':

				 	$this->loadModel('SpaLiveV1.DataCustomPayments');
        			$ent_payment = $this->DataCustomPayments->find()->where(['DataCustomPayments.uid' => $metadata->uid])->first();

        			if (empty($ent_payment)) break;

        			$array_save = array(
						'id'				=> $ent_payment->id,
						'payment'			=> $data->object->charges->data[0]->id,
						'receipt'		=> $data->object->charges->data[0]->receipt_url,
					);
        			
        			$c_entity = $this->DataCustomPayments->newEntity($array_save);
        			$this->DataCustomPayments->save($c_entity);


                    $pay = $this->DataPayment->find()->where(['DataPayment.uid' => $metadata->uid, 'DataPayment.intent' => $ent_payment->payment_intent, 'DataPayment.id_to' => 0])->first();
                    $updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);
	               

        			
					$this->set('FromTreatment', $updateRecord);
					$this->success($updateRecord);

	                break;
				case '_treatment_':

					$pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
						'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
					])->where(['DataPayment.uid' => $metadata->uid, 'DataPayment.type' => "GFE", 'DataPayment.id_to' => "0"])->first();


					$html_msg_ = "
                    Thank you, your treatment was paid.<br>

                    Please find the invoice attached. We appreciate you working with us. <br><br>

                    If you have any questions, please email us at info@myspalive.com
                    ";

					$Main->send_receipt($html_msg_, $pay['User']['email'], $pay->id, $pay->uid);   
                        

					$ent_treatment = $this->DataTreatment->find()
						->where([
							'DataTreatment.uid' => $metadata->uid,
							'DataTreatment.payment' => '',
							'DataTreatment.receipt_url' => '',
						])->first();
					$this->set('metadata', $metadata);

					if (!empty($ent_treatment)) {
						$arr_treatment = array(
							'id'				=> $ent_treatment->id,
							'payment'			=> $data->object->charges->data[0]->id,
							'receipt_url'		=> $data->object->charges->data[0]->receipt_url,
						);
		
						$payment = $this->DataTreatment->newEntity($arr_treatment);
						$this->DataTreatment->save($payment);
						
						$treatment_uid = $metadata->uid;
						if(empty($treatment_uid)){
							$this->message('treatment_uid empty.');
							return;
						}

						$this->loadModel('SpaLiveV1.DataTreatment');
						$ent_treatment = $this->DataTreatment->find()->select(['DataTreatment.id', 'DataTreatment.payment_intent', 'DataTreatment.patient_id', 'DataTreatment.assistance_id', 'DataTreatment.schedule_date'])
						->where(['DataTreatment.uid' => $treatment_uid])->first();

						$patient = $this->SysUsers->find()->select(['SysUsers.email'])->where(['SysUsers.id' => $ent_treatment->patient_id])->first();
                        $pay = $this->DataPayment->find()->where(['DataPayment.uid' => $treatment_uid, 'DataPayment.intent' => $ent_treatment->payment_intent, 'DataPayment.id_to' => 0])->first();
                        $this->set('Pay', $pay);

                        $Main->sendAfterCareEmail($ent_treatment->patient_id, $ent_treatment->id);
						

						$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);
						$this->set('FromTreatment', $updateRecord);
						$this->success($updateRecord);
						$Main->payCIComissions($metadata->uid);
						$Main->sendTreatmentReview('EMAIL_AFTER_TREATMENT', $ent_treatment->patient_id, $ent_treatment->assistance_id, $metadata->uid, $ent_treatment->schedule_date);
					}else{
						$this->set('FromTreatment', 'empty treatment');
					}
					break;

				case 'register':

					$pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid','User.uid','User.name','User.lname','User.phone','DataPayment.total'])->join([
						'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
					])->where(['DataPayment.uid' => $metadata->uid, 'DataPayment.type IN' => array('BASIC COURSE','REGISTER'), 'DataPayment.id_to' => "0", "DataPayment.intent" => $data->object->charges->data[0]->payment_intent])->first();


					$Main->notify_devices('CI_REGISTRATION_PAYMENT',array($pay->id_from),true,false);
					

					$this->loadModel('SpaLiveV1.DataWebhook');
					$this->loadModel('SpaLiveV1.DataAssignedToRegister');

					$assignedRep = $this->DataAssignedToRegister->find()->select(['User.id'])->join([
                        'User' => ['table' => 'sys_users', 'type' => 'LEFT', 'conditions' => 'User.id = DataAssignedToRegister.representative_id'],
                        'DSR' => ['table' => 'data_sales_representative', 'type' => 'LEFT', 'conditions' => 'DSR.user_id = User.id'],
                    ])->where(['DataAssignedToRegister.user_id' => $pay['id_from'],'DataAssignedToRegister.deleted' => 0, 'DSR.deleted' => 0])->first();

			        if (!empty($assignedRep)) {
			        	$this->notificateSMS($assignedRep['User']['id'],'MySpaLive - ' . $pay['User']['name'] . ' ' . $pay['User']['lname'] . ', ' . $this->formatPhoneNumber($pay['User']['phone']) . ', has completed the basic training purchase for $' . $pay->total / 100, $Main);
			        }

					$ent_user = $this->SysUsers->find()
						->where([
							'SysUsers.uid' => $metadata->uid,
							'SysUsers.payment' => '',
							'SysUsers.receipt_url' => '',
						])->first();

					if (!empty($ent_user->receipt_url) && !empty($ent_user->payment)) {
			            break;
			        }

					if (!empty($ent_user)) {
						$arr_user = array(
							'id'			=> $ent_user->id,
							'payment'		=> $data->object->charges->data[0]->id,
							'receipt_url'	=> $data->object->charges->data[0]->receipt_url,
							'login_status' 	=> ($ent_user->type == 'injector' ? 'READY' : $ent_user->login_status),
							'steps' => 'SELECTBASICCOURSE',
						);
		
						$payment = $this->SysUsers->newEntity($arr_user);
						$this->SysUsers->save($payment);

						$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);

						$Main->send_receipt('CI_REGISTRATION_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid);

						#region Pay comission to sales representative
						if (!empty($assignedRep)) {
						$this->loadModel('SpaLiveV1.DataSalesRepresentativePayments');

						$array_save_comission = array(
							'uid' => Text::uuid(),
							'payment_id' => $pay->id,
							'amount' => 10000,
							'user_id' => $assignedRep['User']['id'],
							'payment_uid' => '',
							'description' => 'SALES TEAM',
							'payload' => '',
							'deleted' => 1,
							'created' => date('Y-m-d H:i:s'),
							'createdby' => defined('USER_ID') ? USER_ID : 0,
						);
		
						$c_entity_comission = $this->DataSalesRepresentativePayments->newEntity($array_save_comission);
						$this->DataSalesRepresentativePayments->save($c_entity_comission);
						}
						#endregion

						$array_data = array(
							'email' => $ent_user->email,
							'name' => $ent_user->name,
							'lname' => $ent_user->lname,
							'phone' => $ent_user->phone,
							'costo' => $pay->total / 100,
							'course' => 'Basic'
						);

						if(!env('IS_DEV', false)){
							$Ghl->updateOpportunity($array_data);
						}
						
						#region Pay Commision invitation injector
						$this->loadModel('SpaLiveV1.DataNetworkInvitations');

        				$existUser = $this->DataNetworkInvitations->find()->where(['DataNetworkInvitations.email LIKE' => strtolower($ent_user->email)])->first();

						if(!empty($existUser)){
							$this->loadModel('SpaLiveV1.DataSalesRepresentative');
							$parentRepRow = $this->DataSalesRepresentative->find()->where([
								'DataSalesRepresentative.user_id' => $existUser->parent_id,
								'DataSalesRepresentative.deleted' => 0,
							])->first();

							if (!empty($parentRepRow)) {
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
						}
						#endregion

						if ($ent_user->type == "injector") {
							$background_check = false;
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
	                        }
							
                    	}
						if ($ent_user->type == "examiner") {
							$this->loadModel('SpaLiveV1.DataRequestGfeCi');

							$requestItem = $this->DataRequestGfeCi->find()->where(['DataRequestGfeCi.user_id' => $ent_user->id])->first();
							if(empty($requestItem)){

								 $request_save = [
									'user_id' => $ent_user->id,
									'created' => date('Y-m-d H:i:s'),
									'status' => 'INIT',
								];

								$entRequestSave = $this->DataRequestGfeCi->newEntity($request_save);
								if(!$entRequestSave->hasErrors()){
									$this->DataRequestGfeCi->save($entRequestSave);
								}

							}
						}
						//$pay = $this->DataPayment->find()->where(['DataPayment.uid' => $metadata->uid, 'DataPayment.intent' => $ent_user->payment_intent, 'DataPayment.id_to' => 0])->first();
                        //    $this->updatePaymentRegister($metadata->uid, $ent_user->payment_intent, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);

						$this->success($updateRecord);
						
					}
					break;

				default:
					# code...
					break;
			}
		} else {
			$this->loadModel('SpaLiveV1.DataWebhook');

			$this->DataWebhook->new_entity([
				'uid'	=> $this->DataWebhook->new_uid(),
				'event'	=> isset($data->type) ? $data->type : '',
				'payload'	=> $input,
			]);
		}
		$this->success();
	}

	public function notificateSMS($user_id,$body,$Main) {
	 	$users_array = array( $user_id );
        $Main->notify_devices($body,$users_array,false,false, true, array(), '', array(), true, true);
	}

	private function formatPhoneNumber($str_phone) {
        //(469) 891 9026
        if (strlen($str_phone) != 10) return $str_phone;
        $restul = '(' . $str_phone[0] . $str_phone[1] . $str_phone[2] . ')' . ' ' . $str_phone[3] .  $str_phone[4] . $str_phone[5] . ' ' . $str_phone[6] .  $str_phone[7] .  $str_phone[8] .  $str_phone[9];
        return $str_phone;
    }

	public function updatePaymentRegister($metadata_uid, $payment, $receipt_url, $isExam = false) {
		$Main = new MainController();

		$ent_payment = $this->DataPayment->find()
			->where([
				'DataPayment.uid' => $metadata_uid,
				'DataPayment.payment' => '',
				'DataPayment.receipt' => '',
				'DataPayment.id_to' => 0
			])->order(['DataPayment.id' => 'DESC'])->first();

		if (!empty($ent_payment)) {
			$array_save = array(
				'id'				=> $ent_payment->id,
				'payment'			=> $payment,
				'receipt'			=> $receipt_url,
				'is_visible'		=> 1,
				'comission_payed'	=> 1
			);

			$payment = $this->DataPayment->newEntity($array_save);
			if($isExam){
				$Main->notify_devices('GFE_EXAM_PAYMENT',array($ent_payment->id_from),true,true);
			}

			$this->DataPayment->save($payment);

			$user = $this->SysUsers->find()->select(['SysUsers.email'])->where(['SysUsers.id' => $ent_payment->id_from])->first();
			if(!empty($user)){
				$this->send_receipt($user->email, $Main, ($ent_payment->id + 1500));
			}
		} else {
			$this->message('No pending payment record for ' . $metadata_uid);
			return false;
		}

		return true;
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
        $filename = $Main->rcpt_purchase(true, $uid, true);
		
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

    /*public function get_twilio_messages(){
    	$account_sid = get('account_sid', '');

    	//$info_decode = json_decode($info_message);

    	if($account_sid!=""){

    		$phone_number = get('from', '');
    		$message = get('body', '');

    		//Guardar mensaje
    		$array_save = array(
	            'phone_number' => $phone_number,
	            'message' => $message,
	            'created' => date('Y-m-d H:i:s'),
	            'deleted' => 0,
	        );

	        /*$this->set('data', $array_save);
	       	$this->success();

	        $entity = $this->DataTwilio->newEntity($array_save);
	        if(!$entity->hasErrors()){
	            $this->DataTwilio->save($entity);

	            //checar si es de paciente

		            /*si body == Yes
						require_once 'vendor/autoload.php'; // Loads the library
						use Twilio\TwiML\MessagingResponse;

						$response = new MessagingResponse();
						$response->message("Congrats. Registrer complete!");
						print $response;
		            */

		            /* si no ??
						require_once 'vendor/autoload.php'; // Loads the library
						use Twilio\TwiML\MessagingResponse;

						$response = new MessagingResponse();
						$response->message("lo sentimos completo tu registro!");
						print $response;
		            

				//si no es paciente

	            $body = "Message received by Twilio. <br> Phone Number: ".$phone_number." <br> Message: ".$message;

	    		$data = array(
	                'from'    => 'MySpaLive <info@mg.myspalive.com>',
	                'to'    => 'SupportMySpaLive <support@myspalive.com>',
	                //'bcc'      => $email_string,
	                'subject' => "Forward answer Twilio",
	                //'text' => $body,
	                //'html'    => $this->getEmailFormat($body),
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

	    		$this->set('info_decode->account_sid', $info_decode->account_sid);
	        	$this->success();

	        	//respuesta a twilio

	        }else{
	        	return;
	        }

    	}else{
    		return;
    	}

    }*/

	public function refund_logs() {
		// $methods = get_class_methods('SpaLiveV1\Controller\MainController');
		$Main = new MainController();
		$Ghl = new GhlController();
		// $Main->notify_test();

		$input = file_get_contents('php://input');
		$data = json_decode($input, true);

		$type = $data['type'];
		$data = json_decode(json_encode($data['data']));
 		$this->log(__LINE__ . ' ' . json_encode($data));
		$this->log(__LINE__ . ' ' . json_encode($data->object->payment_intent));$this->log(__LINE__ . ' ' . json_encode($data->object->description));
		//if ($type == 'transfer.reversed' || $type == 'transfer.refunded') {
			$metadata = $data->object->metadata;
			
			$this->loadModel('SpaLiveV1.DataWebhook');
	
			$this->DataWebhook->new_entity([
				'uid'		=> $this->DataWebhook->new_uid(),
				'event'		=> 'transfer.reversed.refunded',
				'model'		=> empty($data->object->description) ? 'transfer.reversed.refunded':$data->object->description,
				'model_uid'	=> $data->object->payment_intent,				
				'payload'	=> $input,
				 'metadata'	=> json_encode((array)$data->object->metadata),
			]);

			switch ($metadata->type) {
				case 'exam':
					$pay = $this->DataPayment->find()->select(['User.email','DataPayment.id','DataPayment.id_from','DataPayment.uid'])->join([
						'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPayment.id_from']
					])->where(['DataPayment.uid' => $metadata->uid, 'DataPayment.type' => "GFE", 'DataPayment.id_to' => "0"])->first();


					/* $Main->notify_devices('GFE_EXAM_PAYMENT',array($pay->id_from),true,false);
					$Main->send_receipt('GFE_EXAM_PAYMENT', $pay['User']['email'], $pay->id, $pay->uid); */

					$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url, true);
					$this->success($updateRecord);
					$this->set('FromExam', $updateRecord);
					break;

				

				

				default:
					# code...
					break;
			}
		/*} else {
			$this->loadModel('SpaLiveV1.DataWebhook');

			$this->DataWebhook->new_entity([
				'uid'	=> $this->DataWebhook->new_uid(),
				'event'	=> isset($data->type) ? $data->type : '',
				'payload'	=> $input,
			]);
		}*/
		$this->success();
	}

	public function mailchimp() {
		$input = file_get_contents('php://input');
		$data = json_decode($input, true);
		/*{ //actualiza perfil
			"type": "profile",
			"fired_at": "2024-06-05 18:26:31",
			"data": {
				"id": "5583fabfd3",
				"email": "deidramcdearman@hotmail.com",
				"email_type": "html",
				"ip_opt": "189.232.197.42",
				"web_id": "798",
				"merges": {
					"EMAIL": "deidramcdearman@hotmail.com",
					"FNAME": "Deidra",
					"LNAME": "Mares",
					"ADDRESS": "",
					"PHONE": ""
				},
				"list_id": "1b44829b7f"
			}
		}

		{ //registra o subscribe usuario
    "type": "subscribe",
    "fired_at": "2024-06-06 15:43:20",
    "data": {
        "id": "d3efb6a79a",
        "email": "francisco@advantedigital.com",
        "email_type": "html",
        "ip_opt": "137.184.192.4",
        "web_id": "1547",
        "merges": {
            "EMAIL": "francisco@advantedigital.com",
            "FNAME": "Francisco 1639",
            "LNAME": "Freyre",
            "ADDRESS": "",
            "PHONE": ""
        },

		{// envia camapaña
    "type": "campaign",
    "fired_at": "2024-06-06 15:43:31",
    "data": {
        "id": "79389c520a",
        "subject": " Notification",
        "status": "sent",
        "reason": "",
        "list_id": "ad0feb767b"
    }
}


{"type":"unsubscribe","fired_at":"2024-06-07 22:36:17","data":{"action":"archive","reason":"manual","id":"6ee57bd778","email":"Aubellebeautystudio.spalivemd@gmail.com","email_type":"html","ip_opt":"137.184.192.4","web_id":"1749","merges":{"EMAIL":"Aubellebeautystudio.spalivemd@gmail.com","FNAME":"Lina","LNAME":"Do","ADDRESS":"","PHONE":""},"list_id":"856fa8f9f3"}}
		*/
		$type = get('type','');//subscribe => new ,  profile => update , campaign
		$fired_at = get('fired_at','');
		$data = get('data','');

		$this->log(__LINE__ . ' ' . json_encode($type));
		$this->log(__LINE__ . ' ' . json_encode($fired_at));
		$this->log(__LINE__ . ' ' . json_encode($data));

		$this->loadModel('SpaLiveV1.DataMailchimpUser');
		$this->loadModel('SpaLiveV1.SysUsers');

		$data_arr = [];
		if(!empty($type)){
			$data_arr['type'] = $type;
		}
		if(!empty($fired_at)){
			$data_arr['fired_at'] = $fired_at;
		}
		if(!empty($data) && ($type == 'profile' || $type == 'subscribe' || $type == 'unsubscribe')){
			
			$data_arr['data'] = $data;
			
			$ent_user = $this->SysUsers->find()->where(["SysUsers.email" => $data_arr['data']['email']])->first();
			$id_sys_user=0;
			if (!empty($ent_user)) {
				$id_sys_user = $ent_user->id;
			}

			$array_save = array(
				'id_sys_uses' => $id_sys_user,
				'hash_id_audience' => $data_arr['data']['list_id'],
				'hash_id_mailchimp' => 0,
				'unique_email_id' => $data_arr['data']['id'],
				'email' => $data_arr['data']['email'],
				'subscribed' =>'subscribed',
				'deleted' => 0,				
				//'updated' => date('Y-m-d H:i:s'),				
				//'created' => date('Y-m-d H:i:s'),
				'payload' => json_encode($data_arr),
			);

			if($type == 'unsubscribe'){
				$array_save['subscribed']='unsubscribe';
			}
			
			$ent_user_chimp = $this->DataMailchimpUser->find()->where(["DataMailchimpUser.unique_email_id" => $data_arr['data']['id']])->first();
			$id_sys_user=0;
			if (!empty($ent_user_chimp)) {
				$array_save['updated'] = date('Y-m-d H:i:s');
				$this->DataMailchimpUser->updateAll(
					$array_save,
					['id' => $ent_user_chimp->id]
				);
			}else{					
				$array_save['created'] = date('Y-m-d H:i:s');
				$this->log(__LINE__ . ' ' . json_encode($array_save));
				$c_entity = $this->DataMailchimpUser->newEntity($array_save);
				if(!$c_entity->hasErrors()) {
					$this->DataMailchimpUser->save($c_entity); 
				}
			}
		}
			
			

		$this->success();
	}
}