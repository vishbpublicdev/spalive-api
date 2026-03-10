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
require_once(ROOT . DS . 'vendor' . DS  . 'Html2pdf' . DS . 'html2pdf.class.php');
use HTML2PDF;
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
		// $Main->notify_test();

		$input = file_get_contents('php://input');
		$data = json_decode($input, true);

		$type = $data['type'];
		$data = json_decode(json_encode($data['data']));

		if ($type == 'payment_intent.succeeded' || $type == 'checkout.session.completed') {
			$metadata = $data->object->metadata;
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
					$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url, true);
					$this->success($updateRecord);
					break;

				case 'purchase':
					$ent_purchase = $this->DataPurchases->find()
						->where([
							'DataPurchases.uid' => $metadata->uid,
							'DataPurchases.payment' => '',
							'DataPurchases.receipt_url' => '',
						])->first();

					if (!empty($ent_purchase)) {
						$arr_purchase = array(
							'id'				=> $ent_purchase->id,
							'payment'			=> $data->object->charges->data[0]->id,
							'receipt_url'		=> $data->object->charges->data[0]->receipt_url,
						);
		
						$payment = $this->DataPurchases->newEntity($arr_purchase);
						$this->DataPurchases->save($payment);

						$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);

						$html_content = 'New purchase, Order #' . $ent_purchase->id . '<br><br>';
                            // You have received a new purchase';

						$ent_purchases = $this->DataPurchases->find()->select(['DataPurchases.id', 'DataPurchases.status', 'DataPurchases.tracking','DataPurchases.delivery_company','DataPurchases.created','DataPurchases.shipping_date','DataPurchases.shipping_cost','User.id','User.name','User.lname','User.bname','User.type','User.email'])
						->join([
							'User' => ['table' => 'sys_users', 'type' => 'INNER', 'conditions' => 'User.id = DataPurchases.user_id']
						])->where(['DataPurchases.uid' => $metadata->uid])->order(['DataPurchases.id' => 'DESC'])->first();

						

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
							
							//$Main->send_new_email($html_content,'info@spalivemd.com');
							$Main->send_new_email($html_content,'khanzab@gmail.com');
						}

						$this->success($updateRecord);
					}

					break;
				case 'treatment':
					$ent_treatment = $this->DataTreatment->find()
						->where([
							'DataTreatment.uid' => $metadata->uid,
							'DataTreatment.payment' => '',
							'DataTreatment.receipt_url' => '',
						])->first();

					if (!empty($ent_treatment)) {
						$arr_treatment = array(
							'id'				=> $ent_treatment->id,
							'payment'			=> $data->object->charges->data[0]->id,
							'receipt_url'		=> $data->object->charges->data[0]->receipt_url,
						);
		
						$payment = $this->DataTreatment->newEntity($arr_treatment);
						$this->DataTreatment->save($payment);

						$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);
						$this->success($updateRecord);
						$Main->payCIComissions($metadata->uid);
						$Main->sendTreatmentReview('EMAIL_AFTER_TREATMENT', $ent_treatment->patient_id, $ent_treatment->assistance_id, $metadata->uid, $ent_treatment->schedule_date);
					}
					break;

				case 'register':
					$ent_user = $this->SysUsers->find()
						->where([
							'SysUsers.uid' => $metadata->uid,
							'SysUsers.payment' => '',
							'SysUsers.receipt_url' => '',
						])->first();

					if (!empty($ent_user)) {
						$arr_user = array(
							'id'			=> $ent_user->id,
							'payment'		=> $data->object->charges->data[0]->id,
							'receipt_url'	=> $data->object->charges->data[0]->receipt_url,
							'login_status' 	=> ($ent_user->type == 'injector' ? 'APPROVE' : $ent_user->login_status),
						);
		
						$payment = $this->SysUsers->newEntity($arr_user);
						$this->SysUsers->save($payment);

						$updateRecord = $this->updatePaymentRegister($metadata->uid, $data->object->charges->data[0]->id, $data->object->charges->data[0]->receipt_url);

						/* --- COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED START --*/
						/* if (empty($ent_user->tracers)) {
							$this->check_tracers($ent_user);
						} */
						/* COMMENTED OUT FOR NOW - BACKGROUND CHECK DISABLED END --- */

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

						$this->success($updateRecord);
						$Main->notify_devices('CI_REGISTRATION_PAYMENT',array($ent_user->id),true,true);
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
				'event'	=> $data['type'],
				'payload'	=> $input,
			]);
		}
		$this->success();
	}

	private function updatePaymentRegister($metadata_uid, $payment, $receipt_url, $isExam = false) {
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

	private function check_tracers($ent) {

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


    private function send_receipt($str_email, $Main, $numInvo = 0){
        if (empty($str_email)) return;

        //$type = 'Receipt';
        $type = 'Invoice';
        $filename = $Main->rcpt_purchase(true);

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
        $this->Response->success();
    }


}