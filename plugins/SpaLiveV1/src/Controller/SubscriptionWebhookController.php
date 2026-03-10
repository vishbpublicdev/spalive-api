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


class SubscriptionWebhookController extends AppPluginController {
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
		$data = json_decode(json_encode($data));


	
		// if ($type == 'checkout.session.completed') {
		if ($type == 'setup_intent.succeeded' || $type == 'checkout.session.completed') {

			if (isset($data->data->object->metadata->user_uid)) {
				shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . $data->data->object->metadata->user_uid . ' > /dev/null 2>&1 &');
			}

			if (!isset($data->data->object->metadata->uid)) return;
			
			$ent_user = $this->SysUsers->find()
						->where([
							'SysUsers.uid' => $data->data->object->metadata->uid,
						])->first();
			if (empty($ent_user)) {
				$this->message('Cant find user');
				return;
			}

			// $ent_subscription = $this->DataSubscriptions->find()
			// ->where([
			// 	'DataSubscriptions.user_id' => $ent_user->id,
			// 	'DataSubscriptions.status' => 'ACTIVE',
			// 	'DataSubscriptions.subscription_type' => $data->data->object->metadata->type,

			// ])->first();

			if (isset($data->data->object->metadata->agreement_uid)) {
				$this->loadModel('SpaLiveV1.DataAgreements');
				 $ent = $this->DataAgreements->find()
		            ->where(['DataAgreements.uid' => $data->data->object->metadata->agreement_uid])->first();
		        if (!empty($ent)) {
		        	$ent->deleted = 0;
		        	$this->DataAgreements->save($ent);
		        }
			}

			if($data->data->object->metadata->type == 'SUBSCRIPTIONMD' || $data->data->object->metadata->type == 'SUBSCRIPTIONMSL'){
				$this->loadModel('SpaLiveV1.DataSubscriptions');

				$ent_subscription = $this->DataSubscriptions->find()->where([
					'DataSubscriptions.subscription_type' => $data->data->object->metadata->type, 
					'DataSubscriptions.user_id' => $ent_user->id,
					'DataSubscriptions.status' => 'ACTIVE',
					'DataSubscriptions.deleted' => 0
				])->first();

				if(!empty($ent_subscription)){
                    $this->message('You already have an active subscription.');
                    return;
                }
	
				$this->DataSubscriptions->new_entity([
					'uid'		=> $this->DataSubscriptions->new_uid(),
					'event'		=> $type,
					'payload'	=> $input,
					'user_id'	=> $ent_user->id,
					'request_id'	=> $data->id,
					'status' => 'ACTIVE',
					'data_object_id' => $data->data->object->id,
					'customer_id' => $data->data->object->customer,
					'payment_method' => isset($data->data->object->payment_method) ? $data->data->object->payment_method : '',
					'subscription_type' => $data->data->object->metadata->type,
					'total' => $data->data->object->metadata->total,
					'subtotal' => $data->data->object->metadata->subtotal,
					'promo_code' => isset($data->data->object->metadata->promo_code) ? $data->data->object->metadata->promo_code : '',
					'agreement_id' => isset($data->data->object->metadata->agreement_id) ? $data->data->object->metadata->agreement_id : 0,
				]);
			}

			shell_exec(env('COMMAND_PATH', '') . ' subscriptions ' . $ent_user->uid . ' > /dev/null 2>&1 &');
			
		} else {
			// $this->loadModel('SpaLiveV1.DataSubscriptions');

			// $this->DataSubscriptions->new_entity([
			// 	'uid'	=> Text::uuid(),
			// 	'event'	=> isset($data->type) ? $data->type : '',
			// 	'payload'	=> $input,
			// ]);
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

    private function send_receipt($str_email, $Main, $numInvo = 0){
        if (empty($str_email)) return;

        //$type = 'Receipt';
        $type = 'Invoice';
        $filename = $Main->rcpt_purchase(true);

        if(empty($filename)){
            return;
        }
        
        $subject = 'SpaliveMD '.$type;
        $data = array(
            'from'    => 'SpaLiveMD <noreply@mg.spalivemd.com>',
            'to'    => $str_email,
            'subject' => $subject,
            'html'    => "You have received a {$type} from SpaLiveMD.",
            'attachment[1]' => curl_file_create($filename, 'application/pdf', 'SpaLiveMD_' . $type . '_' . $numInvo . '.pdf'),
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
        $this->Response->success();
    }
}